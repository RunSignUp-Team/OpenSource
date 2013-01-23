<?php

/* Copyright: Bickel Advisory Services, LLC. */

require_once('LoadTestingSession.class.php');

/** Load Testing Test Exception */
class LoadTestingTestException extends Exception {};

/** Load Testing Test */
abstract class LoadTestingTest
{
	/** Session */
	protected $session = null;
	
	/** Verbose mode */
	protected $verbose = false;
	
	/**
	 * Constructor
	 * @param int $testNum Test Number
	 * @param string $resourceUrl Optional URL specifying host that resources will
	 * 	be loaded for.  The hostname is grabbed from this URL.
	 */
	public function __construct($testNum, $resourceUrl = null)
	{
		// Set up session
		$this->session = new LoadTestingSession($testNum);
		
		// Load resource only from base url
		if ($resourceUrl && preg_match('/^(https?:\\/\\/[^\\/]+)(?:\\/.*)?$/', $resourceUrl, $match))
			$this->session->loadableResourceBaseUrl = $match[1] . '/';
	}
	
	/** Enable resource loading */
	public function enableResourceLoading() {
		$this->session->enableResourceLoading();
	}
	
	/** Disable resource loading */
	public function disableResourceLoading() {
		$this->session->disableResourceLoading();
	}
	
	/**
	 * Set delay between page loads
	 * @param int $minDelayMs Minimum delay in ms
	 * @param int $maxDelayMs Maximum delay in ms
	 */
	public function setDelay($minDelayMs, $maxDelayMs) {
		$this->session->setDelay($minDelayMs, $maxDelayMs);
	}
	
	/** Verbose */
	public function verbose() {
		$this->verbose = true;
		$this->session->verbose();
	}
	
	/** Non-verbose */
	public function nonVerbose() {
		$this->verbose = false;
		$this->session->nonVerbose();
	}
	
	/** Start the test */
	public abstract function startTest();
}

?>