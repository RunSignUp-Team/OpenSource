<?php

// Tech partner key and secret
$partnerKey = 'jashL605';
$secret = 'partnersecret';

function triggerError($err)
{
	// Indicate error
	header('500', true, 500);
	die($err);
}

// Check for data
if (!isset($_POST['json']) || !isset($_POST['signature']))
	triggerError('Invalid request.');

// Check signature
$signature = hash_hmac('sha1', $_POST['json'], $secret);
if ($signature !== $_POST['signature'])
	triggerError('Invalid signature.');

// Parse JSON
$json = json_decode($_POST['json'], true);

// Set up database
$dbh = new SQLite3('./techPartner.db');

// Get tech partner registration id
$techPartnerUniqueId = $json['tech_partner_unique_id'];
$sql = 'SELECT registration_id FROM runsignup_registrations WHERE runsignup_unique_id = :id';
if (!$stmt = @$dbh->prepare($sql))
	triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
$stmt->bindValue(':id', $techPartnerUniqueId, SQLITE3_TEXT);
if (!($result = $stmt->execute()))
	triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);

// Fetch
$row = $result->fetchArray(SQLITE3_ASSOC);
$result->finalize();
// Check for result
if (!$row)
	triggerError('Unique id not found.');
$registrationId = $row['registration_id'];

// Check type
// Registered
if ($json['type'] == 'registered')
{
	// Prepare SQL to set completed time
	$sql = 'UPDATE runsignup_registrations SET registration_completed = CURRENT_TIMESTAMP WHERE registration_id = :a1';
	if (!$stmt = @$dbh->prepare($sql))
		triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	// Bind and execute
	$stmt->bindValue(':a1', $registrationId, SQLITE3_INTEGER);
	if (!($result = $stmt->execute()))
			triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
		
	// Build hash of registrant keys to user ids
	$registrantKeyToUserId = array();
	foreach ($json['users'] as $tmp)
		$registrantKeyToUserId[$tmp['registrant_key']] = $tmp['user_id'];
	
	// Prepare SQL to set registrations as completed
	$sql = 'UPDATE registrant_event_details SET runsignup_registration_id = :rsuRegId, runsignup_user_id = :rsuUserId WHERE registration_id = :a1 AND runsignup_registrant_key = :a2 AND runsignup_event_id = :a3';
	if (!$stmt = @$dbh->prepare($sql))
		triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	
	// Update each registration
	foreach ($json['registrations'] as $tmp)
	{
		$rsuUserId = $registrantKeyToUserId[$tmp['registrant_key']];
		$stmt->bindValue(':rsuRegId', $tmp['registration_id'], SQLITE3_INTEGER);
		$stmt->bindValue(':rsuUserId', $rsuUserId, SQLITE3_INTEGER);
		$stmt->bindValue(':a1', $registrationId, SQLITE3_INTEGER);
		$stmt->bindValue(':a2', $tmp['registrant_key'], SQLITE3_INTEGER);
		$stmt->bindValue(':a3', $tmp['event_id'], SQLITE3_INTEGER);
		if (!($result = $stmt->execute()))
			triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	}
}
// Cleared
else if ($json['type'] == 'cleared')
{
	// Prepare SQL to set completed time
	$sql = 'UPDATE registrant_event_details SET removed = \'T\' WHERE registration_id = :a1';
	if (!$stmt = @$dbh->prepare($sql))
		triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	// Bind and execute
	$stmt->bindValue(':a1', $registrationId, SQLITE3_INTEGER);
	if (!($result = $stmt->execute()))
			triggerError('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
}
// Other types
else
{
	// Ignore other types
}


?>
