<?php

header('Content-Type: text/plain');

// Fill in your key and secret
define('OAUTH_CONSUMER_KEY', 'abc');
define('OAUTH_CONSUMER_SECRET', '123');

// Set up endpoints
define('RUNSIGNUP_URL', 'https://runsignup.com');
$oauthReqUrl = RUNSIGNUP_URL.'/oauth/requestToken.php';
$authUrl = RUNSIGNUP_URL.'/OAuth/Verify';
$oauthAccessUrl = RUNSIGNUP_URL.'/oauth/accessToken.php';

// Start session
session_start();

// Set up object
$oauth = new OAuth(OAUTH_CONSUMER_KEY, OAUTH_CONSUMER_SECRET, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_FORM);
$oauth->setAuthType(OAUTH_AUTH_TYPE_URI);

// Check if you have the OAuth token and secret on your side already
if (!empty($_SESSION['oauth_token']) && !empty($_SESSION['oauth_token_secret']))
{
  $oauth->setToken($_SESSION['oauth_token'], $_SESSION['oauth_token_secret']);
	
	// Make API requests
	$url = 'https://runsignup.com/rest/user';
	
	// Set up curl options
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$postParams = array();
	
	
	// OAuth headers
	$headers = array();
	$headers[] = 'Authorization: ' . $oauth->getRequestHeader('GET', $url, $postParams);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	
	// Make request
	$resp = curl_exec($ch);
	echo $resp;
}
// Need
else
{
	// First step: Get request token
	if (empty($_GET['oauth_token']) || empty($_SESSION['oauth_secret']))
	{
		// Set callback URL to this script (you probably should have an absolute URL here)
		$cbUrl = 'http'.(isset($_SERVER['HTTPS'])?'s':'').'://'.$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI'];
    
		// Build request token
		$requestToken = $oauth->getRequestToken($oauthReqUrl, $cbUrl);
		
		// Check for errors
		if (!$requestToken || !isset($requestToken['oauth_token']) || !isset($requestToken['oauth_token_secret']))
			die('OAuth error');
		else
		{
			// Store secret in session and redirect to RunSignUp for user to log in
			$token = $requestToken['oauth_token'];
			$_SESSION['oauth_secret'] = $requestToken['oauth_token_secret'];
			$url = $authUrl.'?oauth_token='.urlencode($token).'&oauth_callback='.urlencode($cbUrl);
			header('Location: '. $url);
			exit;
		}
	}
	// Second step: Get access token (oauth_token is in URL, but not in session yet)
	else if (empty($_SESSION['oauth_token']))
	{
		$oauth->setToken($_GET['oauth_token'], $_SESSION['oauth_secret']);
		$accessToken = $oauth->getAccessToken($oauthAccessUrl);
	
		// Check for errors
		if (!$accessToken || !isset($accessToken['oauth_token']) || !isset($accessToken['oauth_token_secret']))
			die('OAuth error');
		else
		{
			// Store info in session
			$_SESSION['oauth_token'] = $accessToken['oauth_token'];
			$_SESSION['oauth_token_secret'] = $accessToken['oauth_token_secret'];
			unset($_SESSION['oauth_secret']);
			
			// Redirect to your landing page after registration
			header('Location: ' . $_SERVER['REQUEST_URI']);
			exit;
		}
	}
}