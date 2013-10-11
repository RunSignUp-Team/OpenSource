<?php

define('ENDPOINT', 'runsignup.com/rest/');
define('PROTOCOL', 'https');
define('CLUB_ID', 102);
define('API_KEY', '<put your key here>');
define('API_SECRET', '<put your secret here>');

$url = PROTOCOL . '://'. ENDPOINT . 'club/'. CLUB_ID . '?api_key='. API_KEY .'&api_secret='. API_SECRET;
$url .= '&format=xml';
$url .= '&include_membership_levels=T';
$xml = simplexml_load_file($url);

// Club name
echo 'Club: ' . $xml->name . PHP_EOL;

// Membership levels
echo "Membership Levels\n";
foreach ($xml->membership_levels->membership_level as $level)
{
	echo "\tMembership Level #{$level->club_membership_level_id}: {$level->level_name}\n";
}

?>
