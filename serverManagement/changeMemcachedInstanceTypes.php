<?php

set_time_limit(0);

$start = microtime(true);

chdir('../www');

// Get constants
require_once('constants.php');

// Configure
$startupFlags = STARTUP_SKIP_ALL;
require('config.php');

// Show all errors
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Don't buffer
ob_implicit_flush();
ob_flush();
flush();
ob_end_flush();

include('static/config.php');

// Get options from args
$longopts  = array(
	'instance-type:',
	'memcached-type:'
);
$options = getopt('', $longopts);
if (empty($options['instance-type']) || empty($options['memcached-type']))
{
	echo "Usage: php {$argv[0]} --instance-type=<instance-type> --memcached-type=<memcached-type>\n";
	exit;
}
	
// Get instance type from command line
$newInstanceType = $options['instance-type'];
if (
	$newInstanceType != 'm1.small' && $newInstanceType != 'm1.medium' && $newInstanceType != 'm1.large' && $newInstanceType != 'm1.xlarge' &&
	$newInstanceType != 'm3.xlarge' && $newInstanceType != 'm3.2xlarge' &&
	$newInstanceType != 'c1.medium' && $newInstanceType != 'c1.xlarge'
)
{
	echo "Invalid instance type.\n";
	exit(1);
}

// Determine memcached type from command line
$forSessions = ($options['memcached-type'] == 'sessions');
if (!$forSessions && $options['memcached-type'] != 'data')
{
	echo "Invalid memcached type.\n";
	exit(1);
}	

// Set up server manager
require_once('RsuServerManager.class.php');
$serverManager = new RsuServerManager();

// Set up AmazonEC2 class
require_once('amazonSDK/sdk.class.php');
$ec2 = new AmazonEC2();

// Get running servers
$servers = $serverManager->getInstanceList(array(
	'instance-state-name' => 'running',
	'tag:Name' => $forSessions ? 'Memcached Sessions *' : 'Memcached Data *'
));

// Get stopped servers
$stoppedServers = $serverManager->getInstanceList(array(
	'instance-state-name' => 'stopped',
	'tag:Name' => $forSessions ? 'Memcached Sessions *' : 'Memcached Data *'
));

// Check that there are the same number of started and stopped servers
if (count($servers) > count($stoppedServers))
	throw new LogicException('Not enough stopped servers to replace running servers.');

// Group servers by subnet
$subnetGroupedServers = array();
foreach ($servers as &$server)
{
	$subnet = ip2long($server['privateIpAddress']) & 0xffffff00;
	$subnetGroupedServers[$subnet][] = &$server;
}
unset($server);

// Group stopped servers by subnet
$subnetGroupedStoppedServers = array();
foreach ($stoppedServers as &$server)
{
	$subnet = ip2long($server['privateIpAddress']) & 0xffffff00;
	$subnetGroupedStoppedServers[$subnet][] = &$server;
}
unset($server);

// Check that there are the same number of started and stopped servers
foreach (array_keys($subnetGroupedServers) as $subnet)
	if (count($subnetGroupedServers[$subnet]) > count($subnetGroupedStoppedServers[$subnet]))
		throw new LogicException('Not enough stopped servers to replace running servers.');

// Start stopped servers
echo "Starting servers.\n";
$toStartInstanceIds = array();
$toStartServers = array();
$instanceTypeChangeNeeded = false;
foreach (array_keys($subnetGroupedServers) as $subnet)
{
	$num = count($subnetGroupedServers[$subnet]);
	foreach ($subnetGroupedStoppedServers[$subnet] as $server)
	{
		if ($num-- == 0)
			break;
		$toStartInstanceIds[] = $server['instanceId'];
		$toStartServers[] = $server;
		
		// Change instance type of server if needed
		if ($server['instanceType'] != $newInstanceType)
		{
			$instanceTypeChangeNeeded = true;
			echo "Changing instance type of server: {$server['privateIpAddress']}\n";
			
			// Change instance type
			$response = $ec2->modify_instance_attribute($server['instanceId'], array(
				'InstanceType.Value' => $newInstanceType
			));
			if (!$response->isOK())
			{
				echo PHP_EOL;
				echo str_repeat('*', 80) . PHP_EOL;
				echo 'Failed to change instance type.' . PHP_EOL;
				echo 'Continuing anyway.' . PHP_EOL;
				echo str_repeat('*', 80) . PHP_EOL;
				echo PHP_EOL;
			}
		}
	}
}

// Until AWS fixes their bug, use this sleep as a workaround
if ($instanceTypeChangeNeeded)
	sleep(30);

$response = $ec2->start_instances($toStartInstanceIds);
if (!$response->isOK())
	throw new LogicException('Failed to start instances.');

// Wait for the servers to come up
sleep(30);

// Wait for servers to start up
foreach ($toStartServers as $server)
{
	echo "Waiting For Server to start: {$server['privateIpAddress']}\n";
	
	$t1 = microtime(true);
	while (!$serverManager->isPortOpen($server['privateIpAddress'], 22) && microtime(true) - $t1 < 600)
		sleep(15);
}

// SSH Options
$sshOpts = '-o StrictHostKeyChecking=no -o ConnectTimeout=30';

// New private IP add to secondary address hash
$newServerIps = array();

// Work on each subnet
foreach ($subnetGroupedServers as $subnet=>$group)
{
	echo 'Working on subnet ' . long2ip($subnet) . '/24' . PHP_EOL;
	
	// Figure out servers to update
	$newGroup = array();
	while (count($group) > 0)
	{
		$server = array_pop($group);
		if ($server['instanceType'] != $newInstanceType)
			$newGroup[] = $server;
	}
	$group = $newGroup;
	
	// Update each server
	foreach ($group as $server)
	{
		// Pick other server
		$replacementServer = array_shift($subnetGroupedStoppedServers[$subnet]);
		
		// Move IPs to other server
		$newIpAddresses = array();
		foreach ($server['privateIpAddresses'] as $addr)
			if ($addr != $server['privateIpAddress'])
				$newIpAddresses[] = $addr;
		$newServerIps[$replacementServer['privateIpAddress']] = $newIpAddresses;
		
		// Move IPs
		echo "Moving IPs to server: {$replacementServer['privateIpAddress']}\n";
		$interfaceId = array_keys($replacementServer['networkInterfaces']);
		$interfaceId = $interfaceId[0];
		$response = $ec2->assign_private_ip_addresses($interfaceId, array(
			'PrivateIpAddress' => $newIpAddresses,
			'AllowReassignment' => true
		));
		if (!$response->isOK())
			throw new LogicException('Failed to assign private IP addresses.');
		
		// Update network interfaces
		$cmd = "ssh -t -t {$sshOpts} {$replacementServer['privateIpAddress']} 'sudo service network restart'";
		echo $cmd . PHP_EOL;
		exec($cmd);
		
		// Save memcached data for sessions
		if ($forSessions)
		{
			$cmd = "ssh {$sshOpts} {$server['privateIpAddress']} 'memcached-tool 127.0.0.1:11211 dump > data.dat'";
			echo $cmd . PHP_EOL;
			exec($cmd);
			
			// Import
			$cmd = "scp {$sshOpts} {$server['privateIpAddress']}:data.dat .";
			$cmd = "ssh {$sshOpts} {$replacementServer['privateIpAddress']} " . escapeshellarg($cmd);
			echo $cmd . PHP_EOL;
			exec($cmd);
			$cmd = "ssh {$sshOpts} {$replacementServer['privateIpAddress']} 'nc 127.0.0.1 11211 < data.dat'";
			echo $cmd . PHP_EOL;
			exec($cmd);
		}
		
		// Shutdown
		$cmd = "ssh -t -t {$sshOpts} {$server['privateIpAddress']} 'sudo shutdown -h now'";
		echo $cmd . PHP_EOL;
		exec($cmd);
	}
}

// Restart networking if needed
echo "Checking SSH access to servers.\n";
foreach ($newServerIps as $primaryIp=>$secondaryIp)
{
	// Check each IP
	$needsRestart = false;
	foreach ($secondaryIp as $ip)
	{
		$shortSshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5';
		$cmd = "ssh -t -t {$shortSshOpts} {$ip} 'echo 1'";
		echo "Checking {$ip}.\n";
		if (trim(exec($cmd)) !== '1')
		{
			$needsRestart = true;
			break;
		}
	}
	
	// Does it need a restart
	if ($needsRestart)
	{
		echo "Restarting networking on {$primaryIp}.\n";
		$cmd = "ssh -t -t {$sshOpts} {$primaryIp} 'sudo service network restart'";
		exec($cmd);
	}
}

$totalTime = microtime(true) - $start;
echo "Completed in {$totalTime} seconds.\n";

?>
