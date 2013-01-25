<?php

// Print date
echo date('n/j/Y g:i:sa') . PHP_EOL;

// Change to the directory that the file is located in
chdir(__DIR__);

require_once('config.php');
require_once('amazonSDK/sdk.class.php');

// Let this run for up to a day
set_time_limit(86400);

// Get options from args
$longopts  = array(
	'alert-type:',
	'hostname:',
	'state:',
	'service-state:',
	'state-type:'
);
$options = getopt('', $longopts);
if (empty($options['alert-type']) || empty($options['hostname']) || empty($options['state']) || empty($options['state-type']))
{
	echo "Usage: php {$argv[0]} --alert-type=<alert-type> --hostname=<hostname> --state=<state> --state-type=<state-type>\n";
	exit(1);
}

// Wait for HARD state type
if ($options['state-type'] != 'HARD')
{
	echo "Ignoring non-HARD state type: \"{$options['state-type']}\".\n";
	exit(0);
}

// Check if this is a load blanacer alert
if ($options['alert-type'] != 'load-balancer')
{
	echo "Ignoring alert type: \"{$options['alert-type']}\".\n";
	exit(0);
}

// Check if this the host is up
if ($options['state'] == 'UP' && (!isset($options['service-state']) || $options['service-state'] == 'OK'))
{
	echo "Host is up... Ignoring..\n";
	exit(0);
}

// Get IP address of host
$ip = gethostbyname($options['hostname']);

// Set up EC2 class
$ec2 = new AmazonEC2(array(
	'key' => RsuConfigs::AWS_KEY,
	'secret' => RsuConfigs::AWS_SECRET
));

// Get running load balancers
$opts = array(
	'Filter' => array(
		array('Name' => 'instance-state-name', 'Value' => 'running'),
		array('Name' => 'tag:type', 'Value' => 'load-balancer')
));
$response = $ec2->describe_instances($opts);
if ($response->isOK())
{
	// Construct list of instances and figure out the instance that the ip is currently assigned to
	$instances = array();
	$failedInstance = null;
	$failedPrivateIp = null;
	foreach ($response->body->reservationSet->item as $item)
	{
		$instances[(string)$item->instancesSet->item->instanceId] = array(
			'instanceId' => (string)$item->instancesSet->item->instanceId,
			'privateIpAddresses' => array(),
			'elasticIpAddresses' => array(),
			'availabilityZone' => (string)$item->instancesSet->item->placement->availabilityZone,
			'vpcId' => (string)$item->instancesSet->item->vpcId
		);
		$instance = &$instances[(string)$item->instancesSet->item->instanceId];
		
		// Get private IPs
		foreach ($item->instancesSet->item->networkInterfaceSet->item as $networkInterface)
		{
			foreach ($networkInterface->privateIpAddressesSet->item as $privateIpItem)
			{
				$instance['privateIpAddresses'][] = (string)$privateIpItem->privateIpAddress;
				
				// Elastic IP
				if (isset($privateIpItem->association->publicIp))
				{
					$instance['elasticIpAddresses'][(string)$privateIpItem->privateIpAddress] = (string)$privateIpItem->association->publicIp;
					
					// Check if this is the failed instance
					if ((string)$privateIpItem->association->publicIp == $ip)
					{
						$failedInstance = &$instance;
						$failedPrivateIp = (string)$privateIpItem->privateIpAddress;
					}
				}
			}
		}
		unset($instance);
	}
	
	// Get route tables associated with this instance
	$problematicRouteTableIds = array();
	$replacementNatInstanceId = null;
	if ($failedInstance)
	{
		// Get all route tables in the vpc
		$response = $ec2->describe_route_tables(array('Filter'=>array('Name'=>'vpc-id', 'Value'=>$failedInstance['vpcId'])));
		if ($response->isOK())
		{
			foreach ($response->body->routeTableSet->item as $item)
			{
				// Check for default route
				$problematic = false;
				if (!empty($item->routeSet->item))
				{
					foreach ($item->routeSet->item as $tmp)
					{
						if ((string)$tmp->destinationCidrBlock == '0.0.0.0/0')
						{
							if ((string)$tmp->instanceId == $failedInstance['instanceId'])
								$problematic = true;
							else
								$replacementNatInstanceId = (string)$tmp->instanceId;
						}
					}
				}
				
				if ($problematic)
					$problematicRouteTableIds[] = (string)$item->routeTableId;
			}
		}
	}
	
	// Update route table routes
	if (!empty($problematicRouteTableIds) && $replacementNatInstanceId)
	{
		foreach ($problematicRouteTableIds as $routeTableId)
		{
			echo "Assigning route table {$routeTableId} to instance {$replacementNatInstanceId}\n";
			$response = $ec2->replace_route($routeTableId, '0.0.0.0/0', array('InstanceId' => $replacementNatInstanceId));
			if (!$response->isOK())
			{
				echo "Failed to Update Route Table:\n";
				print_r($response->body);
				exit(1);
			}
		}
	}
	
	// Find an instance (preferably not in the same AZ) to assign the Elastic IP address to
	$newInstance = null;
	foreach ($instances as &$instance)
	{
		// Check that it is not the same instance
		if ($failedInstance !== $instance)
		{
			// Check if there is a private IP address available
			if (count($instance['elasticIpAddresses']) < count($instance['privateIpAddresses']))
			{
				$newInstance = &$instance;
				
				// Check if this is in a different AZ.  If so, break and use this instance
				if ($newInstance['availabilityZone'] != $failedInstance['availabilityZone'])
					break;
			}
		}
	}
	unset($instance);
	
	// Check if there is an instance to switch to
	if ($newInstance !== null)
	{
		// Get allocation id for Elastic IP
		$allocationId = null;
		$response = $ec2->describe_addresses(array('PublicIp' => $ip));
		if (!$response->isOK() || !isset($response->body->addressesSet->item->allocationId))
		{
			echo "Failed to get Allocation Id for Elastic IP:\n";
			print_r($response->body);
			exit(1);
		}
		$allocationId = (string)$response->body->addressesSet->item->allocationId;
		
		// Figure out the private IP address to associate with
		$availPrivateIps = array_diff($newInstance['privateIpAddresses'], array_keys($newInstance['elasticIpAddresses']));
		$newPrivateIp = current($availPrivateIps);
		
		// Assign Elastic IP
		echo "Assigning {$ip} to {$newPrivateIp} on instance {$newInstance['instanceId']}\n";
		$response = $ec2->associate_address($newInstance['instanceId'], null, array('AllocationId' => $allocationId, 'PrivateIpAddress' => $newPrivateIp, 'AllowReassociation' => true));
		if (!$response->isOK())
		{
			echo "Failed to move Elastic IP:\n";
			print_r($response->body);
			exit(1);
		}
		else
		{
			// Try to switch back every 5 mintues
			$context = stream_context_create(array( 
        'http'=>array( 
					'timeout' => 2
				),
				'https'=>array( 
					'timeout' => 2
				)
      ));
			while (1)
			{
				// Wait 5 minutes
				sleep(300);
				
				// Check for connection
				if (@file_get_contents('https://'.$failedPrivateIp.'/int_servers_list', false, $context))
				{
					echo "Assigning {$ip} back to {$failedPrivateIp} on instance {$failedInstance['instanceId']}\n";
					// Reassociate address
					$response = $ec2->associate_address($failedInstance['instanceId'], null, array('AllocationId' => $allocationId, 'PrivateIpAddress' => $failedPrivateIp, 'AllowReassociation' => true));
					if ($response->isOK())
					{
						// Update route table routes
						if (!empty($problematicRouteTableIds) && $replacementNatInstanceId)
						{
							foreach ($problematicRouteTableIds as $routeTableId)
							{
								echo "Assigning route table {$routeTableId} back to instance {$failedInstance['instanceId']}\n";
								$response = $ec2->replace_route($routeTableId, '0.0.0.0/0', array('InstanceId' => $failedInstance['instanceId']));
								if (!$response->isOK())
								{
									echo "Failed to Update Route Table:\n";
									print_r($response->body);
									exit(1);
								}
							}
						}
						
						// We are now done
						break;
					}
				}
			}
		}
	}
}

?>
