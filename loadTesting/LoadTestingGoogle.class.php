<?php

/* Copyright: Bickel Advisory Services, LLC. */

require_once('LoadTestingTest.class.php');

/** Google Load Testing */
class LoadTestingGoogle extends LoadTestingTest
{
	/**
	 * Constructor
	 */
	public function __construct()
	{
		// Call parent constructor
		parent::__construct(1, 'http://www.google.com');
	}
	
	/**
	 * Output if in verbose mode
	 * @param string $str String to output
	 */
	private function outputLine($str)
	{
		if ($this->verbose)
			echo $str . PHP_EOL;
	}
	
	/**
	 * Output page
	 * @param string $pageName Page name
	 */
	private function outputPageName($pageName)
	{
		$this->outputLine('Page: ' . $pageName);
	}
	
	/** Start the test */
	public function startTest()
	{
		try {
			// Load google
			$page = $this->loadGoogle();
			
			// Do query
			$page = $this->doQuery($page);
			
			echo "Search Complete!\n";
			
			// Clean up session file
			$this->session->cleanup();
			
		} catch (Exception $e) {
			echo "Test failed.\n";
			
			// Throw exception
			throw $e;
		}
	}
	
	/**
	 * Load google
	 * @return LoadTestingPageResponse Google homepage
	 */
	public function loadGoogle()
	{
		$this->outputPageName('Google');
		$page = $this->session->goToUrl('http://www.google.com');
		
		// Simple check for error
		if (!in_array('q', $page->getFormElemNames()))
			throw new LoadTestingTestException('Text box not found.');
		
		return $page;
	}
	
	/**
	 * Do search
	 * @param LoadTestingPageResponse $page Current page
	 * @return LoadTestingPageResponse Next page
	 */
	public function doQuery(LoadTestingPageResponse $page)
	{
		// Build post
		$get = array();
		$formUrl = null;
		foreach ($page->getFormElems() as $elem)
		{
			$name = $elem->getAttribute('name');
			if (!empty($name))
			{
				if ($name == 'q')
				{
					$get[$name] = 'RunSignUp';
					
					// Get form URL
					$elem2 = $elem->parentNode;
					while ($elem2 != null)
					{
						if (isset($elem2->tagName) && strtolower($elem2->tagName) == 'form')
						{
							$formUrl = $elem2->getAttribute('action');
							if (isset($formUrl[0]) && $formUrl[0] = '/')
								$formUrl = 'http://www.google.com' . $formUrl;
							break;
						}
						$elem2 = $elem2->parentNode;
					}
				}
			}
		}
		
		$url = $formUrl . '?' . http_build_query($get);
		$this->outputPageName('Google Search');
		$page = $this->session->goToUrl($url);
		
		return $page;
	}
}

$test = new LoadTestingGoogle();
$test->startTest();

?>