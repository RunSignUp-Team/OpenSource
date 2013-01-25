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

// Get options from args
$longopts  = array(
	'instance-type:'
);
$options = getopt('', $longopts);
if (empty($options['instance-type']))
{
	echo "Usage: php {$argv[0]} --instance-type=<instance-type>\n";
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

// Set up server manager
require_once('RsuServerManager.class.php');
$serverManager = new RsuServerManager();

// Set up AmazonEC2 class
require_once('amazonSDK/sdk.class.php');
$ec2 = new AmazonEC2();

// Get stopped web servers
$servers = $serverManager->getInstanceList(array(
	'instance-state-name' => 'stopped',
	'tag:service' => 'runsignup',
	'tag:type' => 'webserver'
));

// Change instance type of servers
foreach ($servers as $server)
{
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

$totalTime = microtime(true) - $start;
echo "Completed in {$totalTime} seconds.\n";

?>
