<?php

// NOTE: Copy ApiConfig.sample.php to ApiConfig.php and modify
// Get up some config
require('ApiConfig.php');

// Get password
if (defined('API_LOGIN_PASSWORD') && API_LOGIN_PASSWORD)
	$password = API_LOGIN_PASSWORD;
else
{
	echo "Password: ";
	system('stty -echo');
	$password = trim(fgets(STDIN));
	system('stty echo');
	// Add a new line since the user's newline didn't echo
	echo "\n";
}

// Login to API
require('RunSignupRestClient.class.php');
$restClient = new RunSignupRestClient(ENDPOINT, PROTOCOL, null, null);
if (!$restClient->login(API_LOGIN_EMAIL, $password))
	die("Failed to login.\n");
$restClient->setReturnFormat('json');

// Set up URL prefix
$urlPrefix = 'Race/' . RESULTS_TEST2_RACE_ID;

// Get race information
$resp = $restClient->callMethod($urlPrefix, 'GET', null, null, true);
if (!$resp)
	die("Request failed.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);
// Get event info
$event = null;
foreach ($resp['race']['events'] as $tmp)
	if ($tmp['event_id'] == RESULTS_TEST2_EVENT_ID)
		$event = $tmp;
if (!$event)
	die("Event not found.\n");

// Get participants
$participants = array();
$participantsParams = array(
	'event_id' => 2491,
	'page' => 1,
	'results_per_page' => 250,
	'sort' => 'registration_id'
);
do
{
	$resp = $restClient->callMethod($urlPrefix . '/participants', 'GET', $participantsParams, null, true);
	if (!$resp)
		die("Request failed.\n".$restClient->lastRawResponse);
	if (isset($resp['error']))
		die(print_r($resp,1) . PHP_EOL);
	// Get participants
	foreach ($resp[0]['participants'] as $tmp)
		$participants[] = $tmp;
	
	$loop = count($resp[0]['participants']) == $participantsParams['results_per_page'];
	$participantsParams['page']++;
} while ($loop);
$numRegistrants = count($participants);

// Parameters
$getParams = $postParams = array();
$getParams['event_id'] = RESULTS_TEST2_EVENT_ID;
$postParams['request_format'] = 'json';

// Set result set id
$postParams['individual_result_set_id'] = RESULTS_TEST2_RESULT_SET_ID;

// Clear results
$postParams['request_type'] = 'clear-results';
unset($postParams['request']);
$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
if (!$resp)
	die("Failed to clear result set.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);

// Set up splits
unset($postParams['request_type']);
$request = array(
	'result_set_splits' > array()
);
$mileSplits = array(2, 4);
$splitIds = array();
foreach ($mileSplits as $miles)
{
	$request['result_set_splits'][] = array(
		'split_name' => $miles . ' Mile',
		'split_distance' => $miles,
		'split_distance_units' => 'M'
	);
}
$postParams['request'] = json_encode($request);
$resp = $restClient->callMethod($urlPrefix . '/Results/Result-Set-Splits', 'POST', $getParams, $postParams, true);
if (!$resp)
	die("Failed to set up splits.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);
foreach ($resp['result_set_splits'] as $split)
	$splitIds[] = $split['split_id'];
unset($postParams['request']);

// Randomize participant order
shuffle($participants);

// Generate some random clock times
$timesMs = array();
$times = array();
$chipTimes = array();
$lastTimeMs = 28*60*1000;
for ($i = 1; $i <= $numRegistrants; $i++)
{
	$timeMs = $lastTimeMs + rand(0, 10000);
	$timesMs[] = $timeMs;
	$times[] = sprintf("%01d:%02d:%02d.%03d", (int)($timeMs / 3600000), (int)(($timeMs % 3600000) / 60000), (int)((($timeMs % 3600000) % 60000) / 1000), ((($timeMs % 3600000) % 60000) % 1000));
	$lastTimeMs = $timeMs;
	$timeMs -= rand(0,15000);
	$chipTimes[] = sprintf("%01d:%02d:%02d.%03d", (int)($timeMs / 3600000), (int)(($timeMs % 3600000) / 60000), (int)((($timeMs % 3600000) % 60000) / 1000), ((($timeMs % 3600000) % 60000) % 1000));
}

// Build splits
$splitTimes = array();
foreach ($mileSplits as $miles)
{
	$splitTimes[$miles] = array();
	foreach ($timesMs as $timeMs)
	{
		$timeMs = $miles/6.2 * $timeMs;
		$splitTimes[$miles][] = sprintf("%01d:%02d:%02d.%03d", (int)($timeMs / 3600000), (int)(($timeMs % 3600000) / 60000), (int)((($timeMs % 3600000) % 60000) / 1000), ((($timeMs % 3600000) % 60000) % 1000));
	}
}

// Simulate splits (for this example, all times are posted for each split before moving to the next split)
$batchSize = RESULTS_TEST2_BATCH_SIZE;
foreach ($mileSplits as $splitKey=>$miles)
{
	for ($i = 0; $i < $numRegistrants; $i+=$batchSize)
	{
		// Full results
		$postParams['request_type'] = 'full-results';
		$results = array();
		for ($j = 0; $j < $batchSize && $i+$j < $numRegistrants; $j++)
		{
			// Get registration
			$registration = &$participants[$i+$j];
			
			// Get split id
			$splitId = $splitIds[$splitKey];
			
			// Get time
			$time = $splitTimes[$miles][$i+$j];
			
			echo "{$registration['user']['first_name']} {$registration['user']['last_name']}: {$time}\n";
			$results[] = array(
				'registration_id' => $registration['registration_id'],
				'split-'.$splitId => $time
			);
		}
		
		if ($results)
		{
			$postParams['request'] = json_encode(array('results' => $results));
			
			$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
			if (!$resp)
				die("Request failed.\n".$restClient->lastRawResponse);
			if (isset($resp['error']))
				die(print_r($resp,1) . PHP_EOL);
			
			sleep(RESULTS_TEST2_SLEEP_TIME);
		}
	}
}

// Simulate finishing
for ($i = 0; $i < $numRegistrants; $i+=$batchSize)
{
	// Full results
	$postParams['request_type'] = 'full-results';
	
	$results = array();
	for ($j = 0; $j < $batchSize && $i+$j < $numRegistrants; $j++)
	{
		// Get registration
		$registration = &$participants[$i+$j];
		
		// Get time
		$time = $times[$i+$j];
		$chipTime = $chipTimes[$i+$j];
		
		echo "{$registration['user']['first_name']} {$registration['user']['last_name']}: {$time}\n";
		$results[] = array(
			'registration_id' => $registration['registration_id'],
			'place' => $i+1+$j,
			'clock_time' => $time,
			'chip_time' => $chipTime,
		);
	}
	if ($results)
	{
		$postParams['request'] = json_encode(array('results' => $results));
		
		$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
		if (!$resp)
			die("Request failed.\n".$restClient->lastRawResponse);
		if (isset($resp['error']))
			die(print_r($resp,1) . PHP_EOL);
		
		sleep(RESULTS_TEST2_SLEEP_TIME);
	}
}

// Logout
$restClient->logout();

?>
