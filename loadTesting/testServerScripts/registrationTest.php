<?php

require_once('LoadTestingRegistration.class.php');
	
try {
	// Get options from args
	$longopts  = array(
		'race-url:',
		'test-num:',
		'event-id:',
		'min-delay:',
		'max-delay:',
		'initial-load-delay:',
		'signup-link-min-delay:',
		'signup-link-max-delay:',
		'refund-delay:',
		'load-resources'
	);
	$options = getopt('', $longopts);
	if (empty($options['test-num']) || empty($options['race-url']))
	{
		echo "Usage: php {$argv[0]} --test-num=<test-number> --race-url=<race-url>\n";
		exit;
	}
	$testNum = $options['test-num'];
	$raceUrl = $options['race-url'];
	$eventId = empty($options['event-id']) ? null : $options['event-id'];
	$initialLoadDelay = empty($options['initial-load-delay']) || !preg_match('/^[0-9]+$/AD', $options['initial-load-delay']) ? 0 : (int)$options['initial-load-delay'];
	$signupLinkMinDelay = empty($options['signup-link-min-delay']) || !preg_match('/^[0-9]+$/AD', $options['signup-link-min-delay']) ? 0 : (int)$options['signup-link-min-delay'];
	$signupLinkMaxDelay = empty($options['signup-link-max-delay']) || !preg_match('/^[0-9]+$/AD', $options['signup-link-max-delay']) ? 0 : (int)$options['signup-link-max-delay'];
	$refundDelay = empty($options['refund-delay']) || !preg_match('/^[0-9]+$/AD', $options['refund-delay']) ? 0 : (int)$options['refund-delay'];
	
	// Set time limit
	set_time_limit(max(300, $refundDelay + 180));
	
	// Set up tester
	$tester = new LoadTestingRegistration($testNum, $raceUrl, $eventId, $initialLoadDelay, $signupLinkMinDelay, $signupLinkMaxDelay, $refundDelay);
	$tester->verbose();
	
	// Set delay
	$tester->setDelay(
		empty($options['min-delay']) || !preg_match('/^[0-9]+$/AD', $options['min-delay']) ? 0 : (int)$options['min-delay'] * 1000,
		empty($options['max-delay']) || !preg_match('/^[0-9]+$/AD', $options['max-delay']) ? 0 : (int)$options['max-delay'] * 1000
	);
	
	// Enable resource loading
	if (isset($options['load-resources']))
		$tester->enableResourceLoading();
	
	// Start test
	$tester->startTest();
	
	echo "Completed!\n";
} catch(Exception $e) {
	echo "Error at line " . $e->getLine() . ":\n";
	$msg = $e->getMessage();
	if (!empty($msg))
		echo $msg . PHP_EOL;
	else if (!empty($page))
		print_r($page);
}

?>