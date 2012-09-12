<?php

/** Copyright: Bickel Advisory Services, LLC. */

/** REST Client Functions */
class RunSignupRestClient
{
	/** Base url for Rest services */
	private $urlBase = null;

	/** http or https protocol */
	private $protocol = 'http';

	/** API key */
	private $apiKey = null;

	/** API secret */
	private $apiSecret = null;
	
	/** Temporary key */
	private $tmpKey = null;

	/** Temporary secret */
	private $tmpSecret = null;

	/** Format in which data will be returned.  Default is xml. */
	private $format = 'xml';

	/** CuRL object to use to fetch data if available */
	private $curl = null;
	
	/** Last raw response */
	public $lastRawResponse = null;

	/**
  * Constructor
  *
  * @param string $urlBase Base url for Rest services
  * @param string $protocol "http" or "https"
  * @param string $apiKey API key
  * @param string $apiSecret API secret
  * @param string $tmpKey Temporary API key
  * @param string $keySecret Temporary API secret
	*/
	public function __construct($urlBase, $protocol, $apiKey = null, $apiSecret = null, $tmpKey = null, $tmpSecret = null)
	{
		$this->urlBase = $urlBase;
		$this->protocol = $protocol;
	  $this->apiKey = $apiKey;
	  $this->apiSecret = $apiSecret;
	  $this->tmpKey = $tmpKey;
	  $this->tmpSecret = $tmpSecret;

	  // Check if CuRL is installed
	  if (function_exists('curl_init'))
	  	$this->curl = curl_init();
	}

	/**
  * Destructor
	*/
	public function __destruct()
	{
	  // Close CuRL handle
	  if ($this->curl)
	  	curl_close($this->curl);
	}

	/**
  * Set format for returned data
  *
  * @param string $format One of the following formats:
  * 		'XML' - XML format
  * 		'JSON' - JSON format
	*/
	public function setReturnFormat($format)
	{
	  if (preg_match('/^XML|JSON|CSV$/iAD', $format))
	  	$this->format = strtolower($format);
	}

	/**
  * Makes a call to the rest server.  API key and data format are automatically
  * added regardless of whether they are already in parameter array.
  *
  * @param string $method REST method to call
  * @param string $httpMethod HTTP method (ie. 'GET', 'POST')
  * @param array $getParams Associative array of GET parameters
  * @param array $postParams Associative array of POST parameters
  * @param boolean $parse True to parse the data into the correct format (i.e. XML, json, etc)
  * @param array &$debugData Optional array for debugging info
  *
  * @return string Returned content
	*/
	public function callMethod($method, $httpMethod, $getParams, $postParams, $parse = true, &$debugData = array())
	{
		// Add auth parameters
		if ($this->apiKey && $this->apiSecret)
		{
			$getParams['api_key'] = $this->apiKey;
			$getParams['api_secret'] = $this->apiSecret;
		}
		else if ($this->tmpKey && $this->tmpSecret)
		{
			$getParams['tmp_key'] = $this->tmpKey;
			$getParams['tmp_secret'] = $this->tmpSecret;
		}
		
		// Set format
		$getParams['format'] = $this->format;

	  // Construct URL
	  $url = $this->protocol . '://' . $this->urlBase . $method . '/';
		$url .= '?' . http_build_query($getParams);
		
	  // Try to get url with curl
	  $data = null;
	  if ($this->curl)
	  {
	  	// Set up curl options
	  	curl_setopt($this->curl, CURLOPT_URL, $url);
			curl_setopt($this->curl, CURLOPT_HEADER, 0);
			curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
			// For debugging
			curl_setopt($this->curl, CURLINFO_HEADER_OUT, 1);
			
			// Determine HTTP method
			if ($httpMethod == 'GET')
				curl_setopt($this->curl, CURLOPT_HTTPGET, 1);
			else if ($httpMethod == 'POST')
			{
				curl_setopt($this->curl, CURLOPT_POST, 1);
				curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postParams);
			}
			// DELETE request
			else if ($httpMethod == 'DELETE')
			{
				curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
				if (!empty($postParams))
				{
					curl_setopt($this->curl, CURLOPT_POST, 1);
					curl_setopt($this->curl, CURLOPT_POSTFIELDS, $postParams);
				}
			}
			/*
			else if ($httpMethod == 'PUT')
			{
				curl_setopt($this->curl, CURLOPT_PUT, 1);
				curl_setopt($this->curl, CURLOPT_INFILE, );
				curl_setopt($this->curl, CURLOPT_INFILESIZE, );
			}
			*/
			
			// Make request
			$data = curl_exec($this->curl);
			
			// Store header debugging info
			$debugData['requestHeaders'] = curl_getinfo($this->curl, CURLINFO_HEADER_OUT);
	  }
	  // Use streams
	  else
	  {
			// Set up options
	  	$opts = array(
				$this->protocol => array()
			);
	  	$wrapper = &$opts[$this->protocol];

	  	// Determine HTTP method
			if ($httpMethod == 'GET')
				$wrapper['method'] = 'GET';
			else if ($httpMethod == 'POST')
			{
				$wrapper['method'] = 'POST';
				$wrapper['header'] = 'Content-Type: application/x-www-form-urlencoded';

				// Add parameters
				$tmpParams = array();
				foreach ($postParams as $name=>$value)
					$tmpParams[] = urldecode($name) . '=' . urldecode($value);
				$wrapper['content'] = implode('&', $tmpParams);
			}

	  	// Create stream
	  	$ctx = stream_context_create($opts);

			$data = file_get_contents($url, 0, $ctx);
	  }
		
		// Store last response
		$this->lastRawResponse = $data;
		
	  // Parse response
		if ($parse)
		{
			if ($this->format == 'xml')
				$data = simplexml_load_string($data);
			else if ($this->format == 'json')
				$data = json_decode($data, true);
		}
		
		// Store some debugging info
		$debugData['url'] = $url;
		
		return $data;
	}
	
	/**
	 * Login to get temporary credentials
	 * @param string $email E-mail address
	 * @param string $password Password
	 * @return bool True on successful login
	 */
	public function login($email, $password)
	{
		$oldFormat = $this->format;
		$this->format = 'xml';
		$post = array(
			'email' => $email,
			'password' => $password
		);
		$resp = $this->callMethod('Login', 'POST', array(), $post, true);
		
		// Set old format
		$this->format = $oldFormat;
		
		// Check response
		if (!$resp)
			return false;
		
		// Check for error
		if (isset($resp->error))
			return false;
		
		// Set keys
		$this->tmpKey = (string)$resp->tmp_key;
		$this->tmpSecret = (string)$resp->tmp_secret;
		
		return true;
	}
	
	/**
	 * Logout
	 * @return bool True on successful logout
	 */
	public function logout()
	{
		$oldFormat = $this->format;
		$this->format = 'xml';
		$resp = $this->callMethod('Logout', 'POST', array(), array(), true);
		
		// Set old format
		$this->format = $oldFormat;
		
		// Check response
		if (!$resp)
			return false;
		
		// Check for error
		if (isset($resp->error))
			return false;
		
		// Clear keys
		$this->tmpKey = $this->tmpSecret = null;
		
		return true;
	}
}

?>
