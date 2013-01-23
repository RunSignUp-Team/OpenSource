<?php

/** Copyright: Bickel Advisory Services, LLC. */

/** Server Manager Exception */
class ServerManagerException extends Exception {}

/** Server Manager */
class ServerManager
{
	/** EC2 Client */
	private $ec2 = null;
	
	/** Constructor */
	public function __construct()
	{
		require_once('amazonSDK/sdk.class.php');
		$this->ec2 = new AmazonEC2();
	}
	
	/**
	 * Check if a port is open on a server
	 * @param string $hostname Server host name
	 * @param int $port Port number
	 * @return bool True if the port is open
	 */
	public function isPortOpen($hostname, $port)
	{
		$cmd = 'nc -w 1 -v -z ' . escapeshellarg($hostname) . ' ' . escapeshellarg($port);
		exec($cmd, $out, $rtn);
		return $rtn == 0;
	}
	
	/**
	 * Start instances
	 * @param array $instanceIds List of instance ids
	 * @throws ServerManagerException
	 */
	public function startInstances($instanceIds)
	{
		$resp = $this->ec2->start_instances($instanceIds);
		if (!$resp->isOK())
			throw new ServerManagerException();
	}
	
	/**
	 * Stop/shutdown instances
	 * @param array $instanceIds List of instance ids
	 * @throws ServerManagerException
	 */
	public function stopInstances($instanceIds)
	{
		$resp = $this->ec2->stop_instances($instanceIds);
		if (!$resp->isOK())
			throw new ServerManagerException();
	}
	
	/**
	 * Reboot instances
	 * @param array $instanceIds List of instance ids
	 * @throws ServerManagerException
	 */
	public function rebootInstances($instanceIds)
	{
		$resp = $this->ec2->reboot_instances($instanceIds);
		if (!$resp->isOK())
			throw new ServerManagerException();
	}
	
	/**
	 * Get list of instances
	 * @param array $filters Associative array of filters
	 * @param array $instanceIds Optional list of instance ids
	 * @return array List of instances
	 * @throws ServerManagerException
	 */
	public function getInstanceList($filters = array(), $instanceIds = array())
	{
		$opts = array();
		
		// Add instance ids
		if (!empty($instanceIds))
			$opts['InstanceId'] = $instanceIds;
		
		// Add filters
		if (!empty($filters))
		{
			$opts['Filter'] = array();
			foreach ($filters as $name=>$value)
				$opts['Filter'][] = array('Name' => $name, 'Value' => $value);
		}
		
		// Get instances
		$response = $this->ec2->describe_instances($opts);
		if (!$response->isOK())
			throw new ServerManagerException();
		
		// Construct list of instances
		$instances = array();
		foreach ($response->body->reservationSet->item as $item)
		{
			$instances[''.$item->instancesSet->item->instanceId] = array(
				'instanceId' => ''.$item->instancesSet->item->instanceId,
				'imageId' => ''.$item->instancesSet->item->imageId,
				'instanceState' => ''.$item->instancesSet->item->instanceState->name,
				'instanceType' => ''.$item->instancesSet->item->instanceType,
				'dnsName' => ''.$item->instancesSet->item->dnsName,
				'privateDnsName' => ''.$item->instancesSet->item->privateDnsName,
				'privateIpAddress' => ''.$item->instancesSet->item->privateIpAddress,
				'privateIpAddresses' => array(),
				'elasticIpAddresses' => array(),
				'networkInterfaces' => array(),
				'availabilityZone' => ''.$item->instancesSet->item->placement->availabilityZone,
				'tags' => array()
			);
			if (!empty($item->instancesSet->item->tagSet->item))
				foreach ($item->instancesSet->item->tagSet->item as $tmp)
					$instances[''.$item->instancesSet->item->instanceId]['tags'][''.$tmp->key] = ''.$tmp->value;
			
			// Get private IPs
			$instance = &$instances[''.$item->instancesSet->item->instanceId];
			if (!empty($item->instancesSet->item->networkInterfaceSet->item))
			{
				foreach ($item->instancesSet->item->networkInterfaceSet->item as $networkInterface)
				{
					$networkInterfaceId = (string)$networkInterface->networkInterfaceId;
					$instance['networkInterfaces'][$networkInterfaceId] = array();
					foreach ($networkInterface->privateIpAddressesSet->item as $privateIpItem)
					{
						$instance['privateIpAddresses'][] = (string)$privateIpItem->privateIpAddress;
						$instance['networkInterfaces'][$networkInterfaceId][] = (string)$privateIpItem->privateIpAddress;
						
						// Elastic IP
						if (isset($privateIpItem->association->publicIp))
							$instance['elasticIpAddresses'][(string)$privateIpItem->privateIpAddress] = (string)$privateIpItem->association->publicIp;
					}
				}
			}
			unset($instance);
		}
		
		return $instances;
	}
	
	/**
	 * Get the number of web servers currently running
	 * @return int Number of web servers running
	 * @throws ServerManagerException
	 */
	public function getNumRunningWebServers() {
		return count($this->getRunningWebServersList());
	}
	
	/**
	 * Get list of web servers currently running
	 * @return array List of running instances
	 * @throws ServerManagerException
	 */
	public function getRunningWebServersList()
	{
		return $this->getInstanceList(array(
			'instance-state-name' => 'running',
			'tag:type' => 'webserver',
			'tag:service' => 'runsignup'
		));
	}
	
	/**
	 * Get list of web servers currently shut down
	 * @return array List of running instances
	 * @throws ServerManagerException
	 */
	public function getStoppedWebServersList()
	{
		return $this->getInstanceList(array(
			'instance-state-name' => 'stopped',
			'tag:type' => 'webserver',
			'tag:service' => 'runsignup'
		));
	}
	
	/**
	 * Get latest web server image
	 * @return string AMI of latest web server image
	 * @throws ServerManagerException
	 */
	public function getWebServerImage()
	{
		$images = $this->getOurImageList();
		$webServerImageIds = array();
		foreach ($images as $image)
			if (preg_match('/^rsu-webserver-([0-9]{8})$/AD', $image['name'], $match))
				$webServerImageIds[$match[1]] = $image['imageId'];
		krsort($webServerImageIds);
		return empty($webServerImageIds) ? null : reset($webServerImageIds);
	}
	
	/**
	 * Add ip addresses to load balancer
	 * @param array $addrs IP Addresses to add
	 */
	public function addIpAddressesToLoadBalancer($addrs)
	{
		// Publish new IP addresses to SNS
		$sns = new AmazonSNS();
		$sns->publish(Configs::WEB_SERVER_ADD_SNS_TOP_ARN, implode("\n", $addrs));
	}
	
	/**
	 * Remove ip addresses from load balancer
	 * @param array $addrs IP Addresses to remove
	 */
	public function removeIpAddressesFromLoadBalancer($addrs)
	{
		// Publish new IP addresses to SNS
		$sns = new AmazonSNS();
		$sns->publish(Configs::WEB_SERVER_REMOVE_SNS_TOP_ARN, implode("\n", $addrs));
	}
	
	/**
	 * Get list of subnets
	 * @param array $opts Options (See describe_subnets documentation)
	 * @return array List of subnets
	 * @throws ServerManagerException
	 */
	public function getSubnetLists($opts = array())
	{
		// Get subnets
		$response = $this->ec2->describe_subnets($opts);
		if (!$response->isOK())
			throw new ServerManagerException();
		
		// Construct list of subnets
		$subnets = array();
		foreach ($response->body->subnetSet->item as $item)
		{
			$subnets[''.$item->subnetId] = array(
				'subnetId' => ''.$item->subnetId,
				'vpcId' => ''.$item->vpcId,
				'state' => ''.$item->state,
				'cidrBlock' => ''.$item->cidrBlock,
				'availableIpAddressCount' => ''.$item->availableIpAddressCount,
				'availabilityZone' => ''.$item->availabilityZone,
				'tags' => array()
			);
			if (isset($item->tagSet))
				foreach ($item->tagSet->item as $tmp)
					$subnets[''.$item->imageId]['tags'][''.$tmp->key] = ''.$tmp->value;
		}
		
		return $subnets;
	}
	
	/**
	 * Add webservers
	 * @param int $numWebServers Number of additional web servers to start
	 * @param bool $forceNew True to force new web servers to be created
	 * @param array $warnings Array where any warnings will be appended
	 * @return int Number of web servers successfully started.
	 * @throws ServerManagerException
	 */
	public function addWebServers($numWebServers, $forceNew = false, &$warnings = null)
	{
		$numStarted = 0;
		
		// IP addresses added
		$newIpAddrs = array();
		$instanceIdForIp = array();
		
		// Get stopped web servers
		$stoppedServers = $this->getStoppedWebServersList();
		
		// Start stopped servers unless we need new ones
		if (!$forceNew)
		{
			foreach ($stoppedServers as $instanceId=>$instance)
			{
				$response = $this->ec2->start_instances($instanceId);
				if ($response->isOK())
				{
					$newIpAddrs[] = $instance['privateIpAddress'];
					$instanceIdForIp[$instance['privateIpAddress']] = $instance['instanceId'];
					
					$numStarted++;
					
					// Check if we have enough servers now
					if ($numStarted == $numWebServers)
						break;
				}
			}
		}
		
		// Do we need to create more
		if ($numStarted < $numWebServers)
		{
			// Get image id
			if (!($imageId = $this->getWebServerImage()))
				throw new ServerManagerException();
			
			// Number of web servers per az
			$numWebServersPerAz = array();
			
			// Figure out ip addresses that are used
			$runningServers = $this->getRunningWebServersList();	// Note: It doesn't matter if this now overlaps with $stoppedServers
			$subnetAddrs = array();
			$subnetAddrs[ip2long('10.0.1.0')] = array();
			for ($x = 100; $x < 256; $x++)
				$subnetAddrs[ip2long('10.0.'.$x.'.0')] = array();
			foreach ($runningServers as $server)
			{
				$addr = ip2long($server['privateIpAddress']);
				$subnet = $addr & 0xffffff00;
				$subnetAddrs[$subnet][] = $addr;
				
				// Add to availability zone count
				if (!isset($numWebServersPerAz[$server['availabilityZone']]))
					$numWebServersPerAz[$server['availabilityZone']] = 0;
				$numWebServersPerAz[$server['availabilityZone']]++;
			}
			foreach ($stoppedServers as $server)
			{
				$addr = ip2long($server['privateIpAddress']);
				$subnet = $addr & 0xffffff00;
				$subnetAddrs[$subnet][] = $addr;
				
				// Add to availability zone count
				if (!isset($numWebServersPerAz[$server['availabilityZone']]))
					$numWebServersPerAz[$server['availabilityZone']] = 0;
				$numWebServersPerAz[$server['availabilityZone']]++;
			}
			
			// Build list of IP addresses to pick from
			$subnetAddrPool = array();
			foreach (array_keys($subnetAddrs) as $subnet)
			{
				for ($x = ($subnet == ip2long('10.0.1.0')) ? 201 : 4; $x < 255; $x++)
					$subnetAddrPool[$subnet][] = $subnet + $x;
				$subnetAddrPool[$subnet] = array_diff($subnetAddrPool[$subnet], $subnetAddrs[$subnet]);
			}
			
			// Out of 10.0.1.0/24 addresses (even though a few aren't really used, Ec2 thinks they are)
			$subnetAddrPool[167772416] = array();
			
			// Get subnets
			$subnets = array();
			foreach ($this->getSubnetLists() as $subnet)
			{
				list ($ip, $maskLen) = explode('/', $subnet['cidrBlock']);
				$mask = (0xffffffff >> (32 - $maskLen)) << (32 - $maskLen);
				$subnetIp = ip2long($ip) & $mask;
				if (isset($subnetAddrPool[$subnetIp]))
					$subnets[$subnetIp] = $subnet;
			}
			
			// Split subnets into AZs
			$azSubnets = array();
			foreach ($subnets as $subnetIp=>&$subnet)
				$azSubnets[$subnet['availabilityZone']][] = $subnetIp;
			unset($subnet);
			
			// Make sure we have counts for just the AZs with subnets
			$tmp = $numWebServersPerAz;
			$numWebServersPerAz = array();
			foreach (array_keys($azSubnets) as $az)
				$numWebServersPerAz[$az] = isset($tmp[$az]) ? $tmp[$az] : 0;
			
			// Pick an IP address
			for ($maxAttempts = $numWebServers * 2; $maxAttempts > 0 && $numStarted < $numWebServers; $maxAttempts--)
			{
				$subnetId = null;
				$addr = null;
				do
				{
					// Pick an AZ
					$az = null;
					$count = 0;
					foreach ($numWebServersPerAz as $tmpAz=>$tmpCount)
					{
						if ($az == null || $tmpCount < $count)
						{
							$az = $tmpAz;
							$count = $tmpCount;
						}
					}
					
					// Check if there are any IP addresses left
					if (!$az)
						throw new ServerManagerException('No IP addresses left.');
					
					// Check if there is a subnet
					if (empty($azSubnets[$az]))
					{
						unset($azSubnets[$az]);
						unset($numWebServersPerAz[$az]);
						
						// Check if there are any IP addresses left
						if (empty($azSubnets))
							throw new ServerManagerException('No IP addresses left.');
					}
					else
					{
						// Check if there are IP addresses
						$subnet = $azSubnets[$az][0];
						if (empty($subnetAddrPool[$subnet]))
							array_shift($azSubnets[$az]);
						else
						{
							$addr = array_shift($subnetAddrPool[$subnet]);
							$subnetId = $subnets[$subnet]['subnetId'];
							$numWebServersPerAz[$az]++;
						}
					}
				} while ($addr == null);
				
				// Convert to string
				$addr = long2ip($addr);
				
				// Create new instance
				$response = $this->ec2->run_instances($imageId, 1, 1, array(
					'InstanceType' => Configs::WEB_SERVER_INSTANCE_TYPE,
					'SubnetId' => $subnetId,
					'PrivateIpAddress' => $addr,
					'SecurityGroupId' => Configs::$WEB_SERVER_SECURITY_GROUPS
				));
				if ($response->isOK())
				{
					$instanceId = (string) $response->body->instancesSet->item->instanceId;
					
					// Set tags
					$response = $this->ec2->create_tags($instanceId, array(
						array('Key' => 'service', 'Value' => 'runsignup'),
						array('Key' => 'type', 'Value' => 'webserver'),
						array('Key' => 'Name', 'Value' => 'Webserver ' . $addr),
					));
					
					// Add to list of IP addresses
					$newIpAddrs[] = $addr;
					$instanceIdForIp[$addr] = $instanceId;
					
					// Add to number of started servers
					$numStarted++;
				}
				// Record warnings
				else
				{
					if ($warnings !== null)
						$warnings[] = 'Failed to create instance with address ' . $addr;
				}
			}
		}
		
		// Test connectivity
		$this->testWebServerConnectivity($newIpAddrs, 5);
		
		// Update IP address lists on Nginx servers
		if (!empty($newIpAddrs))
		{
			// Publish new IP addresses to SNS
			$this->addIpAddressesToLoadBalancer($newIpAddrs);
		}
		
		return $numStarted;
	}
	
	/**
	 * Test connectivity to web servers by ip address.  Reboots ones that fail test.
	 * @param array $ipAddresses IP addresses of servers to test.
	 * @param int $attempts Number of attempts
	 * @return array IP addresses that failed the test.
	 */
	public function testWebServerConnectivity($ipAddresses, $attempts)
	{
		// Test https and ssh access (Restart if needed)
		for ($i = 0; !empty($ipAddresses) && $i < $attempts; $i++)
		{
			$newUnverifiedIps = array();
			foreach ($ipAddresses as $ip)
			{
				if (!$this->isPortOpen($ip, 22) || !$this->isPortOpen($ip, 80))
					$newUnverifiedIps[] = $ip;
			}
			$ipAddresses = $newUnverifiedIps;
			
			// Sleep if needed
			if ($i + 1 < $attempts && !empty($ipAddresses))
				sleep(30);
		}
		
		// Reboot if needed
		if (!empty($ipAddresses))
		{
			$instanceIds = array();
			foreach ($ipAddresses as $ip)
				$instanceIds[] = $instanceIdForIp[$ip];
			$this->ec2->reboot_instances($instanceIds);
		}
		
		return $ipAddresses;
	}
	
	/**
	 * Get new test servers
	 * @param int $numServers Number of servers to get
	 * @return array List of test servers, each with 'dnsName' and 'keyfile'
	 * @throws ServerManagerException
	 */
	public function getTestServers($numServers)
	{
		// EC2 API for Region
		$origEc2 = $this->ec2;
		$this->ec2 = new AmazonEC2();
		
		// Split new servers between Oregon and California
		$regionAMIs = array(
			AmazonEC2::REGION_CALIFORNIA => LoadTestingConfig::CALIFORNIA_BASIC_LINUX_AMI,
			AmazonEC2::REGION_OREGON => LoadTestingConfig::OREGON_BASIC_LINUX_AMI
		);
		$numServersPerRegion = array();
		$numServersPerRegion[AmazonEC2::REGION_CALIFORNIA] = floor($numServers/2);
		$numServersPerRegion[AmazonEC2::REGION_OREGON] = $numServers - $numServersPerRegion[AmazonEC2::REGION_CALIFORNIA];
		
		// Remove regions with no servers
		foreach ($numServersPerRegion as $key=>$regionNumServers)
			if ($regionNumServers == 0)
				unset($numServersPerRegion[$key]);
		
		try {
			// Set launch group name
			$launchGrp = 'LoadTest-'.rand(1000000, 9999999);
			
			// Request instances in each region
			foreach ($numServersPerRegion as $region=>$regionNumServers)
			{
				// Set region
				$this->ec2->set_region($region);
			
				// Request spot instances
				$response = $this->ec2->request_spot_instances(LoadTestingConfig::MAX_SPOT_INSTANCE_PRICE, array(
					'Type' => 'one-time',
					'InstanceCount' => $regionNumServers,
					'ValidUntil' => '+5min',
					'LaunchGroup' => $launchGrp,
					'LaunchSpecification' => array(
						'ImageId' => $regionAMIs[$region],
						'KeyName' => LoadTestingConfig::SSH_KEY_NAME,
						'InstanceType' => LoadTestingConfig::TEST_SERVER_INSTANCE_TYPE
					)
				));
				if (!$response->isOK())
					throw new ServerManagerException('Failed to request instances.');
			}
			
			// Check for instances
			$t1 = microtime(true);
			do
			{
				sleep(30);
				$instanceIds = array();
				$instanceIdsByRegion = array();
				// Check each region
				foreach (array_keys($numServersPerRegion) as $region)
				{
					// Set region
					$this->ec2->set_region($region);
					
					// Check requests
					$response = $this->ec2->describe_spot_instance_requests(array(
						'Filter' => array(
							array('Name' => 'launch-group', 'Value' => $launchGrp)
						)
					));
					if ($response->isOK())
					{
						foreach ($response->body->spotInstanceRequestSet->item as $item)
						{
							$instanceId = (string)$item->instanceId;
							if (!empty($instanceId))
							{
								$instanceIds[] = $instanceId;
								$instanceIdsByRegion[$region][] = $instanceId;
							}
						}
					}
				}
			} while (count($instanceIds) != $numServers && microtime(true) - $t1 < 600);
			
			// Check if we have all the instances
			if (count($instanceIds) != $numServers)
				throw new ServerManagerException('Failed to get spot instances.');
			
			// Get servers
			$attempts = 10;
			$servers = array();
			do
			{
				// Check each region
				foreach (array_keys($numServersPerRegion) as $region)
				{
					// Set region
					$this->ec2->set_region($region);
					
					foreach ($this->getInstanceList(array(), $instanceIdsByRegion[$region]) as $instance)
					{
						if (!empty($instance['dnsName']))
						{
							// Check that there is a DNS name
							$servers[] = array(
								'dnsName' => $instance['dnsName'],
								'keyfile' => LoadTestingConfig::SSH_KEY_FILE
							);
						}
					}
				}
				
				// Check that we have the right number of instances
				if (count($servers) != $numServers)
				{
					$servers = array();
					sleep(30);
				}
				
				$attempts--;
			} while (empty($servers) && $attempts > 0);
			
			// Check that we have the right number of instances still
			if (count($servers) != $numServers)
				throw new ServerManagerException('Failed to get spot instances.');
		} catch (Exception $e) {
			// Reset original EC2 object
			$this->ec2 = $origEc2;
			
			throw $e;
		}
		
		// Reset original EC2 object
		$this->ec2 = $origEc2;
		
		return $servers;
	}
}

?>