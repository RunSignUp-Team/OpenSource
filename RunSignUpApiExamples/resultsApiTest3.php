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

// Parameters
$getParams = $postParams = array();
$getParams['event_id'] = RESULTS_TEST2_EVENT_ID;
$postParams['request_format'] = 'json';

// Set result set id
$postParams['individual_result_set_id'] = RESULTS_TEST2_RESULT_SET_ID;

// Clear results
$postParams['request_type'] = 'recalc-division-placements';
unset($postParams['request']);
$resp = $restClient->callMethod($urlPrefix . '/Results', 'POST', $getParams, $postParams, true);
if (!$resp)
	die("Failed to recalculate division placements.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
	die(print_r($resp,1) . PHP_EOL);

// Logout
$restClient->logout();

?>
