<?php

// NOTE: Bib numbers should have been set up for this event already from
// 1 to RESULTS_TEST1_NUM_PARTICIPANTS

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
$urlPrefix = 'Race/' . RESULTS_TEST1_RACE_ID;

// Get race information
$resp = $restClient->callMethod($urlPrefix, 'GET', null, null, true);
if (!$resp)
	die("Request failed.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);
// Get event info
$event = null;
foreach ($resp['race']['events'] as $tmp)
	if ($tmp['event_id'] == RESULTS_TEST1_EVENT_ID)
		$event = $tmp;
if (!$event)
	die("Event not found.\n");

// Parameters
$getParams = $postParams = array();
$getParams['event_id'] = RESULTS_TEST1_EVENT_ID;
$postParams['request_format'] = 'json';

// Get result set id
if (defined('RESULTS_TEST1_RESULT_SET_ID'))
	$resultSetId = RESULTS_TEST1_RESULT_SET_ID;
else
{
	// Create new result set
	$postParams['request_type'] = 'new-result-set';
	$postParams['request'] = json_encode(array(
		'individual_result_set_name' => 'Live Results Test',
		'public_results' => 'T',
		'results_source_name' => 'RunSignUp.com',
		'results_source_url' => 'https://runsignup.com',
	));
	$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
	if (!$resp)
		die("Create result set request failed.\n".$restClient->lastRawResponse);
	if (isset($resp['error']))
		die(print_r($resp,1) . PHP_EOL);
	unset($postParams['individual_result_set_name']);
	
	// Get result set id
	$resultSetId = $resp['individual_result_set_id'];
}

// Set result set id
$postParams['individual_result_set_id'] = $resultSetId;
echo "Result Set Id: {$resultSetId}\n";

// Clear results
$postParams['request_type'] = 'clear-results';
unset($postParams['request']);
$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
if (!$resp)
	die("Failed to clear result set.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);

// Set up divisions
if (RESULTS_TEST1_SETUP_NEW_DIVISIONS)
{
	unset($postParams['request_type']);
	$request = array(
		'overall_division' => array('awards_for_top_num' => 5),
		'race_divisions' > array()
	);
	$ageRanges = array(
		array(null, 10),
		array(11, 15),
		array(16, 19),
		array(20, 29),
		array(30, 39),
		array(40, 49),
		array(50, 59),
		array(60, 69),
		array(70, null)
	);
	foreach ($ageRanges as $range)
	{
		foreach (array('Male', 'Female') as $gender)
		{
			$name = $gender . ' ';
			$shortName = $gender[0];
			if (!$range[0])
			{
				$name .= $range[1] . ' and Under';
				$shortName .= sprintf('00%02d', $range[1]);
			}
			else if (!$range[1])
			{
				$name .= $range[0] . ' and Over';
				$shortName .= sprintf('%02d+', $range[0]);
			}
			else
			{
				$name .= $range[0] . '-' . $range[1];
				$shortName .= sprintf('%02d%02d', $range[0], $range[1]);
			}
			$request['race_divisions'][] = array(
				'division_name' => $name,
				'division_short_name' => $shortName,
				'show_top_num' => 3,
				'allow_winner_of_higher_priority' => 'F',
				'auto_selection_criteria' => array(
					'min_age' => $range[0],
					'max_age' => $range[1],
					'gender' => $gender[0]
				)
			);
		}
	}
	$request['race_divisions'][] = array(
		'division_name' => 'Athena',
		'division_short_name' => 'Athena',
		'show_top_num' => 3,
		'allow_winner_of_higher_priority' => 'F'
	);
	$request['race_divisions'][] = array(
		'division_name' => 'Clydesdale',
		'division_short_name' => 'Clydesdale',
		'show_top_num' => 3,
		'allow_winner_of_higher_priority' => 'F'
	);
	$postParams['request'] = json_encode($request);
	$resp = $restClient->callMethod($urlPrefix . '/Divisions/Divisions', 'POST', $getParams, $postParams, true);
	if (!$resp)
		die("Failed to set up divisions.\n".$restClient->lastRawResponse);
	if (isset($resp['error']))
		die(print_r($resp,1) . PHP_EOL);
	unset($postParams['request']);
}

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

// Max bib nummbers
$bibMax = RESULTS_TEST1_NUM_PARTICIPANTS;

// Generate bib order
$bibs = array();
for ($i = 1; $i <= $bibMax; $i++)
	$bibs[] = $i;
shuffle($bibs);

// Generate some random clock times
$timesMs = array();
$times = array();
$chipTimes = array();
$lastTimeMs = 28*60*1000;
for ($i = 1; $i <= $bibMax; $i++)
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
$batchSize = RESULTS_TEST1_BATCH_SIZE;
foreach ($mileSplits as $splitKey=>$miles)
{
	for ($i = 0; $i < $bibMax; $i+=$batchSize)
	{
		// Full results
		$postParams['request_type'] = 'full-results';
		$results = array();
		for ($j = 0; $j < $batchSize && $i+$j < $bibMax; $j++)
		{
			// Get bib
			$bib = $bibs[$i+$j];
			
			// Get split id
			$splitId = $splitIds[$splitKey];
			
			// Get time
			$time = $splitTimes[$miles][$i+$j];
			
			echo "{$bib}: {$time}\n";
			$results[] = array(
				'bib' => $bib,
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
			
			sleep(RESULTS_TEST1_SLEEP_TIME);
		}
	}
}

// Simulate finishing
for ($i = 0; $i < $bibMax; $i+=$batchSize)
{
	// Full results
	$postParams['request_type'] = 'full-results';
	
	$results = array();
	for ($j = 0; $j < $batchSize && $i+$j < $bibMax; $j++)
	{
		// Get bib
		$bib = $bibs[$i+$j];
		
		// Get time
		$time = $times[$i+$j];
		$chipTime = $chipTimes[$i+$j];
		
		echo "{$bib}: {$time}\n";
		$results[] = array(
			'bib' => $bib,
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
		
		sleep(RESULTS_TEST1_SLEEP_TIME);
	}
}

// Logout
$restClient->logout();

?>
