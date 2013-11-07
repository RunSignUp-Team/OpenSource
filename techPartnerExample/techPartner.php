<?php

// RunSignUp Domain name
$runSignUpBaseUrl = 'https://runsignup.com';

// Tech partner key and secret
$partnerKey = 'jashL605';
$secret = 'partnersecret';

// Check for token
$token = $_GET['token'];

// Set up database
$dbh = new SQLite3('./techPartner.db');

// Set up tables if needed
$sql = 'CREATE TABLE IF NOT EXISTS runsignup_registrations (
	registration_id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
	runsignup_unique_id CHAR(16) NOT NULL,
	registration_started DATETIME NOT NULL,
	registration_completed DATETIME,
	UNIQUE(runsignup_unique_id)
)';
if (!@$dbh->exec($sql))
	die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
// Note: registration_completed is when the registration is completed on RunSignUp

// Set up tables if needed
$sql = 'CREATE TABLE IF NOT EXISTS registrant_event_details (
	registration_id INTEGER NOT NULL,
	runsignup_registration_id BIGINT,
	runsignup_registrant_key INTEGER NOT NULL,
	runsignup_user_id BIGINT,
	runsignup_event_id INTEGER NOT NULL,
	removed CHAR(1) NOT NULL DEFAULT \'F\',
	email TEXT NOT NULL,
	option TEXT NOT NULL,
	PRIMARY KEY(registration_id, runsignup_registrant_key, runsignup_event_id)
)';
if (!@$dbh->exec($sql))
	die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);

// Unique id and our registration id
$registrationId = $techPartnerUniqueId = null;
// Check if the unique id was passed in
if (!empty($_GET['techPartnerUniqueId']))
{
	$sql = 'SELECT registration_id FROM runsignup_registrations WHERE runsignup_unique_id = :id AND registration_completed IS NULL';
	if (!$stmt = @$dbh->prepare($sql))
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	$stmt->bindValue(':id', $_GET['techPartnerUniqueId'], SQLITE3_TEXT);
	if (!($result = $stmt->execute()))
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	
	// Fetch
	$row = $result->fetchArray(SQLITE3_ASSOC);
	$result->finalize();
	
	// Check for result
	if ($row)
	{
		$techPartnerUniqueId = $_GET['techPartnerUniqueId'];
		$registrationId = $row['registration_id'];
		
		// Get existing details
		$sql = 'SELECT * FROM registrant_event_details WHERE registration_id = :id';
		if (!$stmt = @$dbh->prepare($sql))
			die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
		$stmt->bindValue(':id', $registrationId, SQLITE3_INTEGER);
		if (!($result = $stmt->execute()))
			die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
		$existingData = array();
		while ($row = $result->fetchArray(SQLITE3_ASSOC))
			$existingData[$row['runsignup_registrant_key']][$row['runsignup_event_id']] = $row;
		$result->finalize();
	}
}
// Generate unique id
if ($techPartnerUniqueId === null)
{
	// Random string
	$techPartnerUniqueId = substr(str_shuffle(str_repeat('0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', 16)), 0, 16);
	
	// Add
	$sql = 'INSERT INTO runsignup_registrations (runsignup_unique_id, registration_started) VALUES (:id, :date)';
	if (!$stmt = @$dbh->prepare($sql))
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	$stmt->bindValue(':id', $techPartnerUniqueId, SQLITE3_TEXT);
	$stmt->bindValue(':date', date('Y-m-d H:i:s'), SQLITE3_TEXT);
	
	if (!($result = @$stmt->execute()))
	{
		// TODO: Check if we need a different unique id
		
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	}
	
	$registrationId = $dbh->lastInsertRowID();
}

// Get URL from params
$regToken = $_GET['regToken'];
$url = $runSignUpBaseUrl.'/Race/Register/TechPartner/'.$token.'?regToken='. $regToken . '&techPartnerKey='.$partnerKey;

// Add unique id
$url .= '&techPartnerUniqueId='.$techPartnerUniqueId;

/* Note: Instead of Using JSONP AJAX request, you can use curl calls
// Send curl POST (TODO: Error handling)
$ch = curl_init();
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_URL, $url);
if (($content = curl_exec($ch)) === false)
	die(curl_error($ch));
else if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200)
	die('Bad response code: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
else
{
	$json = json_decode($content, true);
	if (!$json)
		die('Invalid JSON: ' . htmlspecialchars($content));
	else if (isset($json['error']))
		die("Error: {$json['error']}");
}
curl_close($ch);
*/

// Add JSONP callback
$url .= '&techPartnerCb=handleCallback';

// Check for POST
if (!empty($_POST))
{
	// TODO: Validate submission
	
	// Delete all details
	$sql = 'DELETE FROM registrant_event_details WHERE registration_id = :id';
	if (!$stmt = @$dbh->prepare($sql))
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	$stmt->bindValue(':id', $registrationId, SQLITE3_INTEGER);
	if (!($result = $stmt->execute()))
		die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	
	// Prepare SQL
	$sql = 'INSERT INTO registrant_event_details (registration_id, runsignup_registrant_key, runsignup_event_id, email, option) VALUES (:a1, :a2, :a3, :a4, :a5)';
	if (!$stmt = @$dbh->prepare($sql))
	die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
	
	// Add details
	$participantingRegistrants = array();
	foreach ($_POST['registrant'] as $registrantKey=>$data)
	{
		foreach ($data as $eventId=>$eventData)
		{
			// Are they participating
			if (!empty($eventData['option']))
			{
				$stmt->bindValue(':a1', $registrationId, SQLITE3_INTEGER);
				$stmt->bindValue(':a2', $registrantKey, SQLITE3_INTEGER);
				$stmt->bindValue(':a3', $eventId, SQLITE3_INTEGER);
				$stmt->bindValue(':a4', $eventData['email'], SQLITE3_TEXT);
				$stmt->bindValue(':a5', $eventData['option'], SQLITE3_TEXT);
				if (!($result = $stmt->execute()))
					die('['.__LINE__.'] Error ' . $dbh->lastErrorCode() . ': ' . $dbh->lastErrorMsg() . PHP_EOL);
				
				// Add to list
				$participantingRegistrants[$registrantKey]['event_ids'][] = $eventId;
				
				// Add add-ons
				$participantingRegistrants[$registrantKey]['event_addons'][$eventId] = array();
				$participantingRegistrants[$registrantKey]['event_addons'][$eventId][] = array(
					'text' => 'Example Add-on',
					'quantity' => 2,
					'addon_amount_in_cents' => 250 // $2.50 each
				);
			}
		}
	}
	
	
	
	// On success, build JSONP request to RunSignUp
	$json = array(
		'success' => true,
		'registrants' => $participantingRegistrants	// Participating registrants
	);
	
	// Encode JSON
	$json = json_encode($json);

	// Build signature
	$signature = hash_hmac('sha1', $techPartnerUniqueId . '|' . $json, $secret);
	
	// Data
	$jsonSuccessData = array(
		'json' => $json,
		'signature' => $signature
	);
	
	// Update URL
	$url .= '&techPartnerSuccess=T';
	
	// Send curl POST (TODO: Error handling)
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($jsonSuccessData));
	if (($content = curl_exec($ch)) === false)
		die(curl_error($ch));
	else if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200)
		die('Bad response code: ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\nResponse Data:\n{$content}");
	else
	{
		$json = json_decode($content, true);
		if (!$json)
			die('Invalid JSON: ' . htmlspecialchars($content));
		else if (isset($json['error']))
			die("Error: {$json['error']}");
	}
	curl_close($ch);
}

?>
<!DOCTYPE html>
<html>
	<head>
		<title>Technology Partner</title>
		
		<!-- Bootstrap -->
		<link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet" />
		<meta name="viewport" content="width=device-width, initial-scale=1.0" />
		<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.8.0/jquery.min.js"></script>
		<script src="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/js/bootstrap.min.js"></script>	
	</head>
	<body style="background-color: transparent;">
		<div class="container">
			<h1>My Technology Partner</h1>
			
			<?php if (isset($jsonSuccessData)): ?>
				<p>
					Thank you for your submission.
					You must complete your registration at RunSignUp.
				</p>
				
				<div class="text-center">
					<button type="button" class="requiresRunSignUpMessaging btn btn-large btn-primary" onclick="requestRunSignUpPopupClose();">Close this Popup</button>
				</div>
				
			<?php else: ?>
				<form method="post">
					<div id="loading" class="progress progress-striped active">
						<div class="bar" style="width: 100%; font-weight: bold;">Loading&hellip;</div>
					</div>
					
					
					<div id="data" style="display: none;">
						Race name: <span id="raceName"></span><br/>
						Location: <span id="raceLocation"></span><br/>
					</div>
					
					<div id="registrants">
						<div id="registrantTemplate">
							<h3 data-placeholder="name"></h3>
							
							<div class="eventTemplate">
								<h4 data-placeholder="event"></h4>
								
								<div class="row-fluid">
									<div class="span6">
										<label>E-mail Address</label>
										<input type="email" name="registrant[:registrantKey][:eventId][email]" />
									</div>
									
									<div class="span6">
										<label>Tech Partner Option</label>
										<select name="registrant[:registrantKey][:eventId][option]">
											<option value="">None</option>
											<option value="opt1">Option 1</option>
											<option value="opt2">Option 2</option>
											<option value="opt3">Option 3</option>
										</select>
									</div>
								</div>
							</div>
						</div>
					</div>
					
					<div>
						<input type="submit" id="submit" class="btn btn-large btn-primary" value="Submit" disabled="disabled"/>
					</div>
				</form>
			<?php endif; ?>
		</div>

<script type="text/javascript">//<![CDATA[


/* RunSignUp Javascript Messaging Support */
function isRunSignUpMessagingSupported()
{
	return (window.JSON && window.postMessage);
}	
	
/** Request that the popup be closed */
function requestRunSignUpPopupClose()
{
	// Check for support
	if (isRunSignUpMessagingSupported())
	{
		var msg = {
			'action': 'close'
		};
		parent.postMessage(JSON.stringify(msg), <?php echo json_encode($runSignUpBaseUrl); ?>);
	}
}

// Remove buttons tha trequire messaging
$(function() {
	if (!isRunSignUpMessagingSupported())
		$("button.requiresRunSignUpMessaging").remove();
});

<?php if (!isset($jsonSuccessData)): ?>
	$(function() {
		$.ajax({
			"url": <?php echo json_encode($url); ?>,
			"dataType": "jsonp"
		}).fail(function() {
			// TODO: Handle error
		});
	});
	
	function handleCallback(data)
	{
		// Check for error
		if (data.error)
			alert(data.error);
		else
		{
			// Set race info
			$("#raceName").text(data.race_name);
			$("#raceLocation").text(data.city + ", " + data.state + " " + data.countrycode + " " + data.zipcode);
			
			// Set up registrant forms
			var registrantsDiv = $("#registrants");
			var template = $("#registrantTemplate");
			template.removeAttr("id");
			template.detach();
			
			// Existing data
			var existingData = <?php echo isset($existingData) ? json_encode($existingData) : 'null'; ?>;
			
			// Add each registrant
			for (key in data.registrants)
			{
				var registrant = data.registrants[key];
				var clone = template.clone();
				
				// Fill in name and E-mail
				clone.find("h3[data-placeholder='name']").text(registrant.user.first_name + " " + registrant.user.last_name);
				
				// Add events
				var stub = $("<div></div>");
				var eventTemplate = clone.find("div.eventTemplate");
				eventTemplate.replaceWith(stub);
				for (var i = 0; i < registrant.event_ids.length; i++)
				{
					var eventId = registrant.event_ids[i];
					var eventClone = eventTemplate.clone();
					stub.after(eventClone);
					
					// Fill in event and E-mail
					eventClone.find("h4[data-placeholder='event']").text(data.event_names[eventId]);
					eventClone.find("input[type='email']").val(registrant.user.email);
					
					// Check for existing data
					if (existingData && existingData[registrant.registrant_key] && existingData[registrant.registrant_key][eventId])
					{
						var tmpData = existingData[registrant.registrant_key][eventId];
						eventClone.find("input[name$='[email]']").val(tmpData.email);
						eventClone.find("select[name$='[option]']").val(tmpData.option);
					}
					
					// Update input names
					eventClone.find(":input").each(function() {
						this.name = this.name.replace(":eventId", eventId);
					});
				}
				stub.remove();
				
				// Update input names
				var inputs = clone.find(":input");
				inputs.each(function() {
					this.name = this.name.replace(":registrantKey", registrant.registrant_key);
				});
				
				// Append
				registrantsDiv.append(clone);
			}
			
			// Remove loading animation
			$("#loading").remove();
			
			// Show container
			var container = $("#data");
			container.show();
			
			// Enable submit button
			$("#submit").prop("disabled", false);
		}
	}
<?php endif; ?>

//]]></script>
		
	</body>
</html>
