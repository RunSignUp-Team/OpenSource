<?php


define('ENDPOINT', 'runsignup.com/rest/');
define('PROTOCOL', 'https');
define('CLUB_ID', 102);
define('API_KEY', '<put your key here>');
define('API_SECRET', '<put your secret here>');

require('RunSignupRestClient.class.php');

// Set up client
$restClient = new RunSignupRestClient(ENDPOINT, PROTOCOL, API_KEY, API_SECRET);

// Set response format
$restClient->setReturnFormat('json');

// Set up URL prefix
$urlPrefix = 'club/' . CLUB_ID;

$getParams = array(
	'include_membership_levels' => 'T'
);

// Get race information
$resp = $restClient->callMethod($urlPrefix, 'GET', $getParams, null, true);
if (!$resp)
	die("Request failed.\n".$restClient->lastRawResponse);
if (isset($resp['error']))
{
	print_r($resp);
	die;
}
	
// Club name
echo 'Club: ' . $resp['name'] . PHP_EOL;

// Membership levels
echo "Membership Levels\n";
foreach ($resp['membership_levels'] as $level)
{
	echo "\tMembership Level #{$level['club_membership_level_id']}: {$level['level_name']}\n";
}

?>
