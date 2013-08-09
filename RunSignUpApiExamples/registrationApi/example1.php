<?php

/*
	Please note that this does not follow best practices and is intended solely
	as a simple example of using the API. For example, this does not prevent
	duplicate form submissions, which should be handled using HTTP redirects.
*/

define('RACE_ID', 21);
define('OAUTH_LOGIN', true);
// TODO: Update URL
define('RUNSIGNUP_URL', 'https://runsignup.com');
// TODO: Fill in your key and secret
define('OAUTH_CONSUMER_KEY', 'abc');
define('OAUTH_CONSUMER_SECRET', '123');

require('../RunSignupRestClient.class.php');
session_start();

/** Controller */
class Controller
{
	/** API Client */
	protected $restClient = null;
	
	/** User id */
	protected $userId = false;
	
	/** OAuth */
	protected $oauth = null;
	
	/** Logged in via OAuth */
	protected $oauthLoggedIn = false;
	
	/** Constructor */
	public function __construct()
	{
		// Check for keys
		$this->userId = isset($_SESSION['userId']) ? $_SESSION['userId'] : null;
		$tmpKey = isset($_SESSION['tmpKey']) ? $_SESSION['tmpKey'] : null;
		$tmpSecret = isset($_SESSION['tmpSecret']) ? $_SESSION['tmpSecret'] : null;
		
		// Check for OAuth access
		$this->oauth = new OAuth(OAUTH_CONSUMER_KEY, OAUTH_CONSUMER_SECRET, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_FORM);
		$this->oauth->setAuthType(OAUTH_AUTH_TYPE_URI);
		// Set token
		if (!empty($_SESSION['oauth_token']) && !empty($_SESSION['oauth_token_secret']))
		{
			$this->oauth->setToken($_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
			$this->oauthLoggedIn = true;
		}
		
		// Set up API client
		$this->restClient = new RunSignupRestClient('mrc.localhost/Rest/', 'https', null, null, $tmpKey, $tmpSecret, $this->oauthLoggedIn ? $this->oauth : null);
		$this->restClient->setReturnFormat('json');
	}
	
	/** Handle Request */
	public function handleRequest()
	{
		$action = isset($_GET['action']) ? $_GET['action'] : null;
		switch ($action)
		{
			case 'signin':
				if (OAUTH_LOGIN)
					$this->handleOAuthLogin();
				else
					$this->handleSimpleLogin();
				break;
			case 'signout':
				$this->handleSimpleLogout();
				break;
			case 'buildForm':
				$this->buildRaceRegistrationForm();
				break;
			default:
				$this->showMenu();
				break;
		}
	}
	
	/** Menu */
	protected function showMenu()
	{
		echo '<html><body>';
		echo '<a href="?action=buildForm">Build Registration Form</a>';
		echo '</body></html>';
	}
	
	/**
	 * Check for API error
	 *
	 * @param array &$resp Response
	 */
	protected function checkForApiError($resp)
	{
		if (!$resp || isset($resp['error']))
		{
			header('Content-type: text/plain');
			echo 'ERROR!'.PHP_EOL;
			print_r($resp);
			exit;
		}
	}
	
	/** Handle simple login */
	protected function handleSimpleLogin()
	{
		// Check for post
		if (isset($_POST['email']) && isset($_POST['password']))
		{
			// Login to API
			if (!$this->restClient->login($_POST['email'], $_POST['password'], $user))
			{
				header('Content-type: text/plain');
				echo "Login Failed!";
			}
			else
			{
				// Store keys in session
				$_SESSION['userId'] = $user['user_id'];
				$_SESSION['tmpKey'] = $this->restClient->getTmpKey();
				$_SESSION['tmpSecret'] = $this->restClient->getTmpSecret();
				
				// Redirect
				header('Location: ?action=buildForm');
				exit;
			}
		}
		else
			include('templates/login.php');
	}
	
	/** Handle simple logout */
	protected function handleSimpleLogout()
	{
		if ($this->userId)
			$this->restClient->logout();
		session_destroy();
		
		// Redirect
		header('Location: ?action=signin');
		exit;
	}
	
	/** Handle OAuth login */
	protected function handleOAuthLogin()
	{
		$oauthReqUrl = RUNSIGNUP_URL.'/oauth/requestToken.php';
		$authUrl = RUNSIGNUP_URL.'/OAuth/Verify';
		$oauthAccessUrl = RUNSIGNUP_URL.'/oauth/accessToken.php';
		
		// Get request token
		if (empty($_GET['oauth_token']) || empty($_SESSION['oauth_secret']))
		{
			$cbUrl = 'http'.(isset($_SERVER['HTTPS'])?'s':'').'://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
			$requestToken = $this->oauth->getRequestToken($oauthReqUrl, $cbUrl);
			
			// Check for errors
			if (!$requestToken || !isset($requestToken['oauth_token']) || !isset($requestToken['oauth_token_secret']))
			{
				die('OAuth error');
			}
			else
			{
				$token = $requestToken['oauth_token'];
				$_SESSION['oauth_secret'] = $requestToken['oauth_token_secret'];
				$url = $authUrl.'?oauth_token='.urlencode($token).'&oauth_callback='.urlencode($cbUrl);
				header('Location: '. $url);
				exit;
			}
		}
		// Get access token
		else if (empty($_SESSION['oauth_token']))
		{
			$this->oauth->setToken($_GET['oauth_token'], $_SESSION['oauth_secret']);
			$accessToken = $this->oauth->getAccessToken($oauthAccessUrl);
			
			// Check for errors
			if (!$accessToken || !isset($accessToken['oauth_token']) || !isset($accessToken['oauth_token_secret']))
			{
				die('OAuth error');
			}
			else
			{
				$_SESSION['oauth_token'] = $accessToken['oauth_token'];
				$_SESSION['oauth_token_secret'] = $accessToken['oauth_token_secret'];
				unset($_SESSION['oauth_secret']);
				
				// Redirect
				header('Location: ?action=buildForm');
				exit;
			}
		}
	}
	
	/** Build race registration form */
	protected function buildRaceRegistrationForm()
	{
		// Check for POST
		if ($_SERVER['REQUEST_METHOD'] == 'POST')
		{
			// Check if this is the payment/confirmation step
			if (isset($_POST['jsonRequest']))
				$this->handleRegistrationConfirmation();
			// Refund
			else if (isset($_POST['refund']))
			{
				// Check for fields
				if (empty($_POST['primaryRegistrationId']) || empty($_POST['primaryConfirmationCode']))
				{
					header('Content-type: text/plain');
					echo "Required fields not sent.\n";
				}
				else
				{
					$jsonRequest = json_encode(array(
						'primary_registration_id' => $_POST['primaryRegistrationId'],
						'primary_confirmation_code' => $_POST['primaryConfirmationCode']
					));
					
					// Make request for price
					$getParams = array(
						'action' => 'refund'
					);
					$postParams = array(
						'request_format' => 'json',
						'request' => $jsonRequest
					);
					$resp = $this->restClient->callMethod('Race/'.RACE_ID.'/Registration', 'POST', $getParams, $postParams, true);
					$this->checkForApiError($resp);
					header('Content-type: text/plain');
					if ($resp['success'])
						echo 'Refund completed!';
					else
						echo 'Refund failed!';
				}
			}
			// First step
			else
				$this->handleRegistrationInfoPost();
		}
		// Show form
		else
		{
			// Get users
			$users = array();
			if ($this->userId || $this->oauthLoggedIn)
			{
				$getParams = array(
					'include_secondary_users' => 'T'
				);
				$url = 'user';
				if ($this->userId)
					$url .= '/'.$this->userId;
				$resp = $this->restClient->callMethod($url, 'GET', $getParams, null, true);
				$this->checkForApiError($resp);
				
				// Get secondary accounts
				$childUsers = null;
				if (isset($resp['user']['secondary_users']))
				{
					$childUsers = $resp['user']['secondary_users'];
					unset($resp['user']['secondary_users']);
				}
				$users[$resp['user']['user_id']] = $resp['user'];
				if ($childUsers)
				{
					foreach($childUsers as $user)
						$users[$user['user']['user_id']] = $user['user'];
				}
			}
			
			// Get race info
			$getParams = array(
				'future_events_only' => 'T',
				'include_waiver' => 'T',
				'include_giveaway_details' => 'T',
				'include_questions' => 'T',
				'include_addons' => 'T',
				'include_membership_settings' => 'T'
			);
			$resp = $this->restClient->callMethod('Race/'.RACE_ID, 'GET', $getParams, null, true);
			$this->checkForApiError($resp);
			include('templates/regForm.php');
		}
	}
	
	/** Handle registration info post */
	protected function handleRegistrationInfoPost()
	{
		// TODO: Validate required fields
		// TODO: Validate inpunt formats (e.g. state, zipcode, phone, gender, date of birth)
		$error = array();
		
		// Check for events
		if (empty($_POST['event']) || !is_array($_POST['event']))
		{
			$error[] = 'Please select an event';
		}
		else
		{
			// Remove non-selected events
			$_POST['event'] = array_filter($_POST['event'], function(&$a) { return isset($a['selected']); });
			
			// Check for events again
			if (empty($_POST['event']))
				$error[] = 'Please select an event';
		}
		
		// Check that the waiver was accepted
		if (!isset($_POST['acceptWaiver']))
		{
			$error[] = 'Please accept the waiver';
		}
		
		// TODO: Validate ids (e.g. user id, event id, giveaway id, question responses, etc)
		
		// TODO: Validate add-ons (min/max quantity, options, custom fields, etc.)
		
		// TODO: Validate memberships
		
		// Check for error
		if (empty($error))
		{
			// Build API data
			$registrationData = array(
				'registrants' => array(),
				'waiver_accepted' => 'T'
			);
			
			// Set up resistrant
			$registrant = array(
				'first_name' => !empty($_POST['first_name']) ? $_POST['first_name'] : null,
				'last_name' => !empty($_POST['last_name']) ? $_POST['last_name'] : null,
				'email' => !empty($_POST['email']) ? $_POST['email'] : null,
				'password' => !empty($_POST['password']) ? $_POST['password'] : null,
				'address1' => !empty($_POST['address1']) ? $_POST['address1'] : null,
				'city' => !empty($_POST['city']) ? $_POST['city'] : null,
				'state' => !empty($_POST['state']) ? $_POST['state'] : null,
				'country_code' => 'US',
				'zipcode' => !empty($_POST['zipcode']) ? $_POST['zipcode'] : null,
				'phone' => !empty($_POST['phone']) ? $_POST['phone'] : null,
				'dob' => !empty($_POST['dob']) ? $_POST['dob'] : null,
				'gender' => !empty($_POST['gender']) ? $_POST['gender'] : null,
				'events' => array()
			);
			
			// Set user id
			if (!empty($_POST['user_id']))
				$registrant['user_id'] = (int)$_POST['user_id'];
			
			// Set events
			foreach ($_POST['event'] as $eventId=>$event)
			{
				$eventData = array(
					'event_id' => $eventId,
					'giveaway_option_id' => !empty($event['giveaway']) ? $event['giveaway'] : null
				);
				
				// Add registrant addons
				$eventData['addon_purchases'] = $this->processAddonPost('registrantEventAddon');
				
				$registrant['events'][] = $eventData;
			}
			
			// Add individual question responses
			$registrant['question_responses'] = array();
			if (!empty($_POST['individualQuestionResponse']))
			{
				foreach ($_POST['individualQuestionResponse'] as $questionId=>$postResp)
				{
					$questionResponse = array(
						'question_id' => $questionId,
						'response' => $postResp
					);
					$registrant['question_responses'][] = $questionResponse;
				}
			}
			
			// Add registrant addons
			$registrant['addon_purchases'] = $this->processAddonPost('registrantAddon');
			
			// Add memberships
			$registrant['memberships'] = $this->processMembershipPost();
			
			// Add resistrant
			$registrationData['registrants'][] = $registrant;
			
			// Add overall question responses
			$registrationData['question_responses'] = array();
			if (!empty($_POST['questionResponse']))
			{
				foreach ($_POST['questionResponse'] as $questionId=>$postResp)
				{
					$questionResponse = array(
						'question_id' => $questionId,
						'response' => $postResp
					);
					$registrationData['question_responses'][] = $questionResponse;
				}
			}
			
			// Add overall addons
			$registrationData['addon_purchases'] = $this->processAddonPost('overallAddon');
			
			// Check for coupon
			if (!empty($_POST['coupon']))
				$registrationData['coupon'] = $_POST['coupon'];
			
			// Construct JSON request
			$jsonRequest = json_encode($registrationData);
			
			// Make request for price
			$getParams = array(
				'action' => 'get-cart'
			);
			$postParams = array(
				'request_format' => 'json',
				'request' => $jsonRequest
			);
			$resp = $this->restClient->callMethod('Race/'.RACE_ID.'/Registration', 'POST', $getParams, $postParams, true);
			$this->checkForApiError($resp);
			include('templates/paymentForm.php');
		}
		else
		{
			header('Content-type: text/plain');
			print_r($error);
		}
	}
	
	/**
	 * Process addon post
	 *
	 * @param string $key POST key
	 *
	 * @return array API purchase data array
	 */
	protected function processAddonPost($key)
	{
		$rtn = array();
		
		if (!empty($_POST[$key]))
		{
			foreach ($_POST[$key] as $addonId=>$post)
			{
				$addonPurchase = array(
					'addon_id' => $addonId,
					'custom_fields' => array()
				);
				// Single quantity
				if (isset($post['quantity']))
					$addonPurchase['quantity'] = $post['quantity'];
				// Options
				else if (isset($post['optionQuantity']))
				{
					$addonPurchase['purchased_options'] = array();
					foreach ($post['optionQuantity'] as $optionId=>$optionQuantity)
					{
						if ((int)$optionQuantity > 0)
						{
							$addonPurchase['purchased_options'][] = array(
								'addon_option_id' => $optionId,
								'quantity' => $optionQuantity
							);
						}
					}
				}
				
				// Add custom fields
				if (!empty($post['customField']))
				{
					foreach ($post['customField'] as $fieldId=>$val)
					{
						$addonPurchase['custom_fields'][] = array(
							'custom_field_id' => $fieldId,
							'field_value' => $val
						);
					}
				}
				
				$rtn[] = $addonPurchase;
			}
		}
		
		return $rtn;
	}
	
	/**
	 * Process membership post
	 *
	 * @return array API data array
	 */
	protected function processMembershipPost()
	{
		$rtn = array();
		if (!empty($_POST['membershipSetting']))
		{
			foreach ($_POST['membershipSetting'] as $membershipId=>$settingPost)
			{
				$arr = array(
					'membership_setting_id' => $membershipId,
					'is_member' => $settingPost['member']
				);
				if (isset($settingPost['addl_field']))
					$arr['addl_field'] = $settingPost['addl_field'];
				if (isset($settingPost['non_member_addl_field']))
					$arr['non_member_addl_field'] = $settingPost['non_member_addl_field'];
				
				$rtn[] = $arr;
			}
		}
		return $rtn;
	}
	
	/** Handle registration confirmation */
	protected function handleRegistrationConfirmation()
	{
		// Check for errors
		$error = array();
		
		// Parse response
		$jsonRequest = json_decode($_POST['jsonRequest'], true);
		if (!$jsonRequest)
		{
			$error[] = 'Invalid submission.';
		}
		
		// Check for total amount
		if (!isset($_POST['totalCost']))
		{
			$error[] = 'Invalid submission.';
		}
		else
		{
			$jsonRequest['total_cost'] = $_POST['totalCost'];
			
			// Check for credit card fields
			if ($jsonRequest['total_cost'] != '$0.00')
			{
				$ccFields = array(
					'cc_first_name' => 'Credit card first name is required.',
					'cc_last_name' => 'Credit card last name is required.',
					'cc_address1' => 'Credit card address is required.',
					'cc_city' => 'Credit card city is required.',
					'cc_state' => 'Credit card state is required.',
					'cc_zipcode' => 'Credit card zip code is required.',
					'cc_num' => 'Credit card number is required.',
					'cc_cvv' => 'Credit card security code is required.',
					'cc_expires' => 'Credit card expiration date is required.'
				);
				foreach ($ccFields as $key=>$errMsg)
				{
					if (!isset($_POST[$key]) || $_POST[$key] === '')
					{
						$error[] = $errMsg;
					}
					// Check CVV
					else if ($key == 'cc_cvv' && !preg_match('/^[0-9]{3,4}$/AD', $_POST[$key]))
					{
						$error[] = 'Card security code is invalid.';
					}
					// Check expiration date
					else if ($key == 'cc_expires' && !preg_match('/^[0-1]?[0-9]\\/[1-2][0-9]{3}$/AD', $_POST[$key]))
					{
						$error[] = 'Card expiration date is invalid.  Please use format mm/yyyy.';
					}
					// Add to request
					else
						$jsonRequest[$key] = $_POST[$key];
				}
			}
		}
		
		// Check for error
		if (empty($error))
		{
			// Construct JSON request
			$jsonRequest = json_encode($jsonRequest);
			
			// Do registration
			$getParams = array(
				'action' => 'register'
			);
			$postParams = array(
				'request_format' => 'json',
				'request' => $jsonRequest
			);
			$resp = $this->restClient->callMethod('Race/'.RACE_ID.'/Registration', 'POST', $getParams, $postParams, true);
			$this->checkForApiError($resp);
			include('templates/confirmation.php');
		}
		else
		{
			header('Content-type: text/plain');
			print_r($error);
		}
	}
}

$controller = new Controller();
$controller->handleRequest();

?>
