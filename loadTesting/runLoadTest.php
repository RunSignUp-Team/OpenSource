<?php

set_time_limit(0);

define('S3_BUCKET', '<S3 Bucket Name>');

// Get constants
require_once('constants.php');

// Configure
$startupFlags = STARTUP_SKIP_ALL & ~STARTUP_SKIP_CACHING;
require('config.php');

require_once('RsuServerManager.class.php');

// Check if we are going to be doing local tests
$localTest = (class_exists('LoadTestingConfig', false) && defined('LoadTestingConfig::ENABLE_LOCAL_LOAD_TESTING') && LoadTestingConfig::ENABLE_LOCAL_LOAD_TESTING);

/** Load Test Controller Exception */
class LoadTestControllerException extends Exception {};

/** Load Test Controller */
class LoadTestController
{
	/** Database handle */
	protected $dbh = null;
	
	/** Load Testing Database Module */
	protected $dbh_loadTesting = null;
	
	/** IP Address of Server Running the Test */
	protected $serverAddr = null;

	/** SSH Processes for each test server */
	protected $sshProcs = array();
	
	/** Load test id */
	protected $loadTestId = null;
	
	/** Load test */
	protected $loadTest = null;
	
	/**
	 * Constructor
	 * @param Database $dbh Database handle
	 * @param string $serverAddr IP Address of Server Running the Test
	 * @param int $loadTestId Load test id
	 */
	public function __construct(Database $dbh, $serverAddr, $loadTestId)
	{
		$this->dbh = $dbh;
		$this->serverAddr = $serverAddr;
		$this->loadTestId = $loadTestId;
		
		// Load load testing module
		require_once('Database/LoadTesting.class.php');
		$this->dbh_loadTesting = new DatabaseLoadTesting($this->dbh);
	}
	
	/**
	 * Get load test
	 * @throws LoadTestControllerException
	 */
	public function getLoadTest()
	{
		try {
			// Get load test
			if (!($this->loadTest = $this->dbh_loadTesting->getLoadTest($this->loadTestId)))
				throw new LogicException('Load test not found.');
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Get status filename for test server
	 * @param array $server Server information
	 * @return string Filename
	 */
	public function getTestStatusFileForServer($server) {
		return '/tmp/loadtest-stats-'.$server['hostname'];
	}
	
	/**
	 * Get SSH process information for a test server
	 * @param array $server Server information
	 * @return array SSH process information
	 */
	public function getTestServerSshProc($server)
	{
		// Check that process is still running
		if (isset($this->sshProcs[$server['hostname']]))
		{
			$status = proc_get_status($this->sshProcs[$server['hostname']]['process']);
			if (!$status['running'])
			{
				fclose($this->sshProcs[$server['hostname']]['pipes'][0]);
				fclose($this->sshProcs[$server['hostname']]['pipes'][1]);
				fclose($this->sshProcs[$server['hostname']]['pipes'][2]);
				proc_close($this->sshProcs[$server['hostname']]['process']);
				unset($this->sshProcs[$server['hostname']]);
			}
		}
		
		// Open new process if needed
		if (!isset($this->sshProcs[$server['hostname']]))
		{
			$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'];
			$descriptorSpec = array(
				0 => array("pipe", "r"),
				1 => array("pipe", "w"),
				2 => array("pipe", "w")
			);
			$process = proc_open($cmd, $descriptorSpec, $pipes);
			if (!is_resource($process))
				return null;
			
			// Store info
			$this->sshProcs[$server['hostname']] = array(
				'process' => $process,
				'pipes' => $pipes
			);
		}
		return $this->sshProcs[$server['hostname']];
	}
	
	/** Flush SSH output of servers */
	public function flushTestServerSshProcOutput()
	{
		foreach ($this->sshProcs as $proc)
		{
			stream_set_blocking($proc['pipes'][1], 0);
			while (fread($proc['pipes'][1], 1024) != '');
			stream_set_blocking($proc['pipes'][1], 1);
		}
	}
	
	/** Close SSH processes to test servers */
	public function closeTestServerSshProcs()
	{
		foreach ($this->sshProcs as $proc)
		{
			fclose($proc['pipes'][0]);
			fclose($proc['pipes'][1]);
			fclose($proc['pipes'][2]);
			proc_close($proc['process']);
		}
		$this->sshProcs = array();
	}
	
	/**
	 * Setup background process to get test status on a server
	 * @param array $server Server information
	 * @param string $cmd Command to run
	 */
	public function setupBackgroundTestStatusForServer($server, $cmd)
	{
		$flagFile = '/tmp/loadtest-background-ssh-flag';
		if (!file_exists($flagFile))
			file_put_contents($flagFile, '1');
		$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
		$cmd = 'tmp=`' . $cmd . '`; echo "$tmp" > ' . escapeshellarg($this->getTestStatusFileForServer($server));
		$cmd = '( while [ -e ' . $flagFile . ' ]; do ' . $cmd . '; sleep 5; done; ) > /dev/null 2>&1 &';
		exec($cmd);
	}
	
	/** Stop background processs to get test status */
	public function stopBackgroundTestStatus()
	{
		$flagFile = '/tmp/loadtest-background-ssh-flag';
		if (file_exists($flagFile))
			unlink($flagFile);
	}
	
	/**
	 * Start test servers
	 * @throws LoadTestControllerException
	 */
	public function startTestServers()
	{
		try {
			// Check if test servers are started
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['test_servers_started']))
			{
				// Indicate that the test servers are starting
				if (!isset($checkpoints['test_servers_starting']))
				{
					$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'test_servers_starting');
					sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
				}
				
				// Check for servers that are already running
				$numServersNeeded = $this->loadTest['num_test_servers'];
				$servers = $this->dbh_loadTesting->getRunningLoadTestTestServerRecords();
				foreach ($servers as $server)
				{
					// Open process
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						// Send command to see if it is running
						fwrite($procInfo['pipes'][0], 'echo 1' . PHP_EOL);
						fflush($procInfo['pipes'][0]);
					}
				}
				foreach ($servers as $server)
				{
					// Get ssh process
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						echo 'Trying test server: ' . $server['hostname'] . PHP_EOL;
						$output = trim(fgets($procInfo['pipes'][1]));
						echo 'Output: ' . $output . PHP_EOL;
						if ($output === '1')
						{
							echo 'Server running.' . PHP_EOL;
							
							// Clean up server
							$cmd = '( cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; if [ -e files.tgz ]; then rm files.tgz; fi; if [ -e .setupcomplete ]; then rm .setupcomplete; fi )';
							fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
							fflush($procInfo['pipes'][0]);
							
							// Add server
							$this->dbh_loadTesting->addLoadTestTestServerRecord($this->loadTestId, $server['hostname'], $server['private_key_filename']);
							if (--$numServersNeeded == 0)
								break;
						}
					}
				}
				
				// Start up test servers
				$numNewTestServers = $this->loadTest['num_test_servers'] - count($this->dbh_loadTesting->getLoadTestTestServerRecords($this->loadTestId));
				if ($numNewTestServers > 0)
				{
					// Check if this is a local test
					if ($this->localTest)
						$this->dbh_loadTesting->addLoadTestTestServerRecord($this->loadTestId, '127.0.0.1', LoadTestingConfig::SSH_KEY_FILE);
					else
					{
						echo 'Starting ' . $numNewTestServers . ' test servers' . PHP_EOL;
						$serverManager = new RsuServerManager();
						$newTestServers = $serverManager->getTestServers($numNewTestServers);
						foreach ($newTestServers as $tmp)
							$this->dbh_loadTesting->addLoadTestTestServerRecord($this->loadTestId, $tmp['dnsName'], $tmp['keyfile']);
					}
				}
				
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'test_servers_started');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Start the test
	 * @throws LoadTestControllerException
	 */
	public function startTest()
	{
		try {
			// Check if the test was started
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['started']))
			{
				// Remove this load balancer from the pool
				if ($this->serverAddr)
				{
					$serverManager = new RsuServerManager();
					$serverManager->removeIpAddressesFromLoadBalancer(array($this->serverAddr));
				}
				
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'started');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/** Clear up after test */
	public function cleanUpTest()
	{
		// Stop background status collectors
		$this->stopBackgroundTestStatus();
		
		// Close SSH processes
		$this->closeTestServerSshProcs();
		
		// Add this load balancer back to the pool
		if ($this->serverAddr)
		{
			$serverManager = new RsuServerManager();
			$serverManager->addIpAddressesToLoadBalancer(array($this->serverAddr));
		}
		
		// Enable notifications
		$this->dbh->loadModule('Notifications');
		$this->dbh->enabledTemporarilyDisabledRaceNotifications($this->loadTest['race_id']);
	}
	
	/**
	 * Format time
	 * @param int $sec Time in seconds
	 * @return string Formatted time
	 */
	public function formatTime($sec)
	{
		$str = '';
		// With hours
		if ($sec > 3600)
		{
			$str .= (int)($sec/3600) . 'hr ';
			$sec = $sec%3600;
			$str .= (int)($sec/60) . 'min ';
			$sec = $sec%60;
		}
		// With minutes
		else if ($sec > 60)
		{
			$str .= (int)($sec/60) . 'min ';
			$sec = $sec%60;
		}
		$str .= number_format($sec, 1) . 'sec';
		return $str;
	}
	
	/**
	 * Merge basic stats
	 * @param object $json1 Stats
	 * @param object $json2 Stats
	 *
	 * @return object Merged stats
	 */
	public function mergeBasicStats($json1, $json2)
	{
		# Merge stats together
		$newJson = unserialize(serialize($json1));
		
		// Times
		if (isset($json1->stats->firstRequestTimestamp) && isset($json2->stats->firstRequestTimestamp))
		{
			$newJson->stats->firstRequestTimestamp = min($json1->stats->firstRequestTimestamp, $json2->stats->firstRequestTimestamp);
			$newJson->stats->firstRequestDate = date('m/d/Y G:i:s', $newJson->stats->firstRequestTimestamp);
		}
		if (isset($json1->stats->lastRequestTimestamp) && isset($json2->stats->lastRequestTimestamp))
		{
			$newJson->stats->lastRequestTimestamp = max($json1->stats->lastRequestTimestamp, $json2->stats->lastRequestTimestamp);
			$newJson->stats->lastRequestDate = date('m/d/Y G:i:s', $newJson->stats->lastRequestTimestamp);
		}
		
		// Counts
		if (isset($json1->stats->numSuccesses) && isset($json2->stats->numSuccesses))
			$newJson->stats->numSuccesses = $json1->stats->numSuccesses + $json2->stats->numSuccesses;
		if (isset($json1->stats->numErrors) && isset($json2->stats->numErrors))
			$newJson->stats->numErrors = $json1->stats->numErrors + $json2->stats->numErrors;
		if (isset($json1->stats->numPages) && isset($json2->stats->numPages))
			$newJson->stats->numPages = $json1->stats->numPages + $json2->stats->numPages;
		
		// Times
		if (isset($json1->stats->cumulativeTimeSec) && isset($json2->stats->cumulativeTimeSec))
		{
			$newJson->stats->cumulativeTimeSec = $json1->stats->cumulativeTimeSec + $json2->stats->cumulativeTimeSec;
			$newJson->stats->cumulativeTime = $this->formatTime($newJson->stats->cumulativeTimeSec);
		}
		if (isset($json1->stats->cumulativeRegistrationTimeSec) && isset($json2->stats->cumulativeRegistrationTimeSec))
		{
			$newJson->stats->cumulativeRegistrationTimeSec = $json1->stats->cumulativeRegistrationTimeSec + $json2->stats->cumulativeRegistrationTimeSec;
			$newJson->stats->cumulativeRegistrationTime = $this->formatTime($newJson->stats->cumulativeRegistrationTimeSec);
		}
		if (isset($json1->stats->avgPageTimeSec) && isset($json2->stats->avgPageTimeSec))
		{
			$newJson->stats->avgPageTimeSec = $json1->stats->numPages/$newJson->stats->numPages*$json1->stats->avgPageTimeSec + $json2->stats->numPages/$newJson->stats->numPages*$json2->stats->avgPageTimeSec;
			$newJson->stats->avgPageTime = $this->formatTime($newJson->stats->avgPageTimeSec);
		}
		
		// Pages
		$pages = array();
		foreach ($json1->stats->pages as $stats)
			$pages[$stats->page] = $stats;
		foreach ($json2->stats->pages as $stats)
		{
			if (!isset($pages[$stats->page]))
				$pages[$stats->page] = $stats;
			else
			{
				$page = &$pages[$stats->page];
				$tmpStats = clone $page;
				$page->total = $tmpStats->total + $stats->total;
				$page->avgTimeSec = $tmpStats->total/$page->total*$tmpStats->avgTimeSec + $stats->total/$page->total*$stats->avgTimeSec;
				$page->avgTime = $this->formatTime($page->avgTimeSec);
				$page->minTimeSec = min($tmpStats->minTimeSec, $stats->minTimeSec);
				$page->minTime = $this->formatTime($page->minTimeSec);
				$page->maxTimeSec = max($tmpStats->maxTimeSec, $stats->maxTimeSec);
				$page->maxTime = $this->formatTime($page->maxTimeSec);
				
				// Loads per second
				if (isset($stats->loadsPerSecond))
				{
					foreach ($stats->loadsPerSecond as $time=>$cnt)
						$page->loadsPerSecond->$time = (isset($page->loadsPerSecond->$time) ? $page->loadsPerSecond->$time : 0) + $cnt;
				}
				
				// Total response times per second
				if (isset($stats->totalTimePerSecond))
				{
					foreach ($stats->totalTimePerSecond as $time=>$cnt)
						$page->totalTimePerSecond->$time = (isset($page->totalTimePerSecond->$time) ? $page->totalTimePerSecond->$time : 0) + $cnt;
				}
				
				unset($tmpStats);
				unset($page);
			}
		}
		$newJson->stats->pages = array();
		foreach ($pages as $stats)
			$newJson->stats->pages[] = $stats;
		
		// Error messages
		$errorMessages = array();
		foreach ($json1->stats->errorMessages as $errorMsg)
			$errorMessages[$errorMsg->msg] = $errorMsg;
		foreach ($json2->stats->errorMessages as $errorMsg)
		{
			if (!isset($errorMessages[$errorMsg->msg]))
				$errorMessages[$errorMsg->msg] = $errorMsg;
			else
			{
				$errorMessage = &$errorMessages[$errorMsg->msg];
				$tmp = $errorMessage;
				$errorMessage->count = $tmp->count + $errorMsg->count;
				$errorMessage->files = array_merge($tmp->files, $errorMsg->files);
				unset($errorMessage);
			}
		}
		$newJson->stats->errorMessages = array();
		foreach ($errorMessages as $errorMessage)
			$newJson->stats->errorMessages[] = $errorMessage;
		
		return $newJson;
	}
}

/** Registration Load Test Controller */
class RegistrationLoadTestController extends LoadTestController
{
	/** Local test flag */
	public $localTest = false;
	
	/** Test servers */
	protected $testServers = null;
	
	/**
	 * Constructor
	 * @param Database $dbh Database handle
	 * @param string $serverAddr IP Address of Server Running the Test
	 * @param int $loadTestId Load test id
	 */
	public function __construct(Database $dbh, $serverAddr, $loadTestId)
	{
		parent::__construct($dbh, $serverAddr, $loadTestId);
	}
	
	/**
	 * Run the load test
	 * @throws LoadTestControllerException
	 */
	public function runLoadTest()
	{
		try {
			$this->getLoadTest();
			$this->startTest();
			
			// Get test servers
			$this->startTestServers();
			
			// Get test servers list
			$this->testServers = $this->dbh_loadTesting->getLoadTestTestServerRecords($this->loadTestId);
			
			// Set up test servers
			$this->setupTestServers();
			
			// Set up background processes to get status of test servers
			$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; cat runningStats.dat 2> /dev/null)';
			foreach ($this->testServers as &$server)
				$this->setupBackgroundTestStatusForServer($server, $cmd);
			unset($server);
			
			// Do initial test
			$this->doInitialTest();
			
			// Set up crontab
			$this->setupCrontab();
			
			// Monitor tests
			$this->monitorTests();
			
			// Stop background status collectors
			$this->stopBackgroundTestStatus();
			
			// Indicate that the tests are complete
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['tests_completed']))
			{
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'tests_completed');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
			
			// Analyze results
			$this->analyzeResults();
			
			// Clean up test
			$this->cleanUpTest();
			
			// Mark test as completed
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['completed']))
			{
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'completed');
			}
		} catch (LoadTestControllerException $e) {
			// Clean up test
			$this->cleanUpTest();
			
			// Indicate that the tests failed
			if (isset($this->loadTest) && $this->loadTest)
			{
				try {
					$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
					if (!isset($checkpoints['failed']))
						$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'failed');
						
					// Mark test as completed
					$this->dbh_loadTesting->updateLoadTestStatus($this->loadTestId, true, 0, 0, 0);
				} catch (Exception $e2) {}
			}
			
			// Rethrow Exception
			throw $e;
		}
	}
	
	/**
	 * Start the test
	 * @throws LoadTestControllerException
	 */
	public function startTest()
	{
		try {
			// Any special initialization goes here
			
			// Call parent
			parent::startTest();
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Set up test servers
	 * @throws LoadTestControllerException
	 */
	public function setupTestServers()
	{
		try {
			// Check that the servers are ready
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['test_servers_ready']))
			{
				// Indicate that the test servers are being setup
				if (!isset($checkpoints['test_servers_being_setup']))
				{
					$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'test_servers_being_setup');
					sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
				}
				
				// Check for spot instances and that ssh is ready
				$t1 = microtime(true);
				$unverifiedTestServers = array();
				foreach ($this->testServers as &$server)
					$unverifiedTestServers[] = &$server;
				// Flush SSH output
				$this->flushTestServerSshProcOutput();
				while (!empty($unverifiedTestServers) && microtime(true) - $t1 < 300)
				{
					$newUnverifiedTestServers = array();
					// Send echo command
					foreach ($unverifiedTestServers as &$server)
					{
						// Open process
						if ($procInfo = $this->getTestServerSshProc($server))
						{
							// Send command to see if it is running
							fwrite($procInfo['pipes'][0], 'echo 1' . PHP_EOL);
							fflush($procInfo['pipes'][0]);
						}
					}
					// Get response
					foreach ($unverifiedTestServers as &$server)
					{
						// Get ssh process
						if ($procInfo = $this->getTestServerSshProc($server))
						{
							stream_set_blocking($procInfo['pipes'][1], 0);
							$output = trim(fgets($procInfo['pipes'][1]));
							stream_set_blocking($procInfo['pipes'][1], 1);
							if ($output !== '1')
								$newUnverifiedTestServers[] = &$server;
						}
						else
							$newUnverifiedTestServers[] = &$server;
					}
					$unverifiedTestServers = $newUnverifiedTestServers;
					
					// Sleep a little if needed
					if (!empty($unverifiedTestServers))
						sleep(10);
				}
				unset($server);
				
				// Report error to user and clean up
				if (!empty($unverifiedTestServers))
					throw new LogicException('Failed to establish connection with test servers');
				
				// Create list of files to copy
				global $DIR;
				$fileStr = '<Files to send to test servers>';	// Build files string to send to test servers
				
				// Create tar
				$tgz = tempnam('/tmp', 'LOADTEST');
				$cmd = 'tar chzf ' . $tgz . ' ' . $fileStr;
				exec($cmd);
				
				// Push file to S3
				require_once('amazonSDK/sdk.class.php');
				$s3 = new AmazonS3();
				$s3Filename = 'tmp/loadTest-'.time().'-files.tgz';
				$s3Resp = $s3->create_object(Configs::S3_PRIVATE_STORAGE_BUCKET, $s3Filename, array(
					'fileUpload' => $tgz,
					'storage' => AmazonS3::STORAGE_REDUCED,
					'acl' => AmazonS3::ACL_PRIVATE
				));
				if (!$s3Resp->isOk())
					throw new LogicException('Failed to push testing scripts and files to S3.');
				
				// Construct signed URL
				$fileDownloadLink = $s3->get_object_url(Configs::S3_PRIVATE_STORAGE_BUCKET, $s3Filename, '+2 days', array('https'=>true));
				
				// Flush SSH output
				$this->flushTestServerSshProcOutput();
				// Do setup
				foreach ($this->testServers as &$server)
				{
					// Check if this is a local test
					if ($this->localTest)
					{
						$cmd = 'cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; touch .sudoupdated';
						$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &';
						exec($cmd);
					}
					else
					{
						echo 'Updating sudo permissions on test server: ' . $server['hostname'] . PHP_EOL;
						// Allow sudo without tty
						$cmd = 'sudo sed -i"" "/requiretty/d" /etc/sudoers; cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; touch .sudoupdated';
						$cmd = 'ssh -t -t ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd) . ' > /dev/null 2>&1 &';
						exec($cmd);
					}
					
					// Run setup
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; ' . (defined('LoadTestingConfig::WGET_PATH') ? LoadTestingConfig::WGET_PATH : 'wget') . ' -q -O files.tgz '. escapeshellarg($fileDownloadLink) .' && tar xzf files.tgz && while [ ! -e .sudoupdated ]; do sleep 1; done && ./setup.sh ' . ($this->localTest ? '--local' : '') . ') > /dev/null 2>&1 &';
						echo $cmd . PHP_EOL;
						fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
						fflush($procInfo['pipes'][0]);
					}
				}
				unset($server);
				
				// Wait for setup to complete
				$cmd = 'cat ' . LoadTestingConfig::TEST_SERVER_DIR . '/.setupcomplete';
				foreach ($this->testServers as &$server)
				{
					echo 'Checking setup on test server: ' . $server['hostname'] . PHP_EOL;
					// Open process
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						$timeout = 60;
						do
						{
							if ($timeout != 60)
								sleep(1);
							
							// Send command
							fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
							fflush($procInfo['pipes'][0]);
							
							// Get response
							stream_set_blocking($procInfo['pipes'][1], 0);
							$output = trim(fgets($procInfo['pipes'][1]));
							echo 'Output: ' . $output . PHP_EOL;
							stream_set_blocking($procInfo['pipes'][1], 1);
							
							$timeout--;
						} while ($timeout > 0 && $output !== '1');
						if ($timeout == 0)
							throw new LogicException($server['hostname'] . ' not set up.');
					}
					else
						throw new LogicException($server['hostname'] . ' not set up.');
				}
				unset($server);
				
				// Delete tar
				unlink($tgz);
				
				// Delete files.tgz in S3
				$s3->delete_object(Configs::S3_PRIVATE_STORAGE_BUCKET, $s3Filename);
				
				// Mark servers as ready
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'test_servers_ready');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Get load test command to run
	 * @param bool $initialTest Is this the initial test
	 * @return string Test command
	 */
	public function getLoadTestCommand($initialTest)
	{
		return '<Command to run on test server>';
	}
	
	/**
	 * Do initial test
	 * @throws LoadTestControllerException
	 */
	public function doInitialTest()
	{
		try {
			// Do a single test first to be sure that everything seems OK
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			$recheckAttempts = 12;
			if (!isset($checkpoints['initial_test_run']))
			{
				// Indicate that the initial test is being run
				if (!isset($checkpoints['initial_test_running']))
				{
					$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'initial_test_running');
					sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
				}
				
				$server = reset($this->testServers);
				$quickTestCmd = $this->getLoadTestCommand(true);
				$cmd = 'cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; ./clearTestData.sh; ( php registrationTestBuildStats.php < /dev/null > /dev/null 2>&1 & ); ./startPhpTests.sh 1 1 ' . escapeshellarg($quickTestCmd);
				$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
				exec($cmd);
			
				// Check status
				$status = $this->getTestStatusOnServer($server);
				while ($status['numRunning'] || ($status['numSuccesses'] != 1 && $recheckAttempts-- > 0))
				{
					sleep(5);
					$status = $this->getTestStatusOnServer($server);
				}
				
				// Check if the test completed successfully
				if ($status['numSuccesses'] != 1)
					throw new LogicException('Initial test failed.');
				
				// Store page information so we can show a progress bar
				if ($status['numPages'])
					$this->dbh_loadTesting->setLoadTestInitialTestTmpStats($this->loadTestId, array('numPages'=>$status['numPages']));
				
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'initial_test_run');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Set up crontab
	 * @throws LoadTestControllerException
	 */
	public function setupCrontab()
	{
		try {
			// Set crontabs to start test
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['crontabs_set']))
			{
				// Indicate that the cron tabs are being set up
				if (!isset($checkpoints['setting_up_crontabs']))
				{
					$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'setting_up_crontabs');
					sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
				}
				
				// Get test command
				$testCmd = $this->getLoadTestCommand(false);
				
				// Set crontab to start in 2 minutes
				if (!defined('LoadTestingConfig::USE_UTC') || LoadTestingConfig::USE_UTC)
					date_default_timezone_set('UTC');
				$time = strtotime('+ 2 minutes');
				$crontabDate = date('i G j n', $time) . ' * ';
				
				// Set up tests to start
				$numServers = count($this->testServers);
				$testsPerServer = floor($this->loadTest['num_registrations'] / $numServers);
				$nextNum = 1;
				foreach ($this->testServers as &$server)
				{
					// Determine number of registrants to include in test
					$numServers--;
					if ($numServers == 0)
						$lastNum = $this->loadTest['num_registrations'];
					else
						$lastNum = ($nextNum + $testsPerServer - 1);
					
					// Generate crontab and clear test data
					echo "Setting Crontab on {$server['hostname']}.\n";
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						$cmd = '( cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; ./clearTestData.sh; cat /dev/null > mycrontab )';
						fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
						
						for ($i = $nextNum; $i <= $lastNum; $i+=25)
						{
							$crontab = $crontabDate . 'cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; ./startPhpTests.sh ' . $i . ' ' . min($i+24, $lastNum) . ' ' . escapeshellarg($testCmd) . ' < /dev/null > /dev/null 2>&1';
							$cmd = '( cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; echo ' . escapeshellarg($crontab) . ' >> mycrontab )';
							fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
						}
						$crontab = $crontabDate . 'cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; php registrationTestBuildStats.php < /dev/null > /dev/null 2>&1';
						$cmd = '( cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; echo ' . escapeshellarg($crontab) . ' >> mycrontab; crontab mycrontab )';
						fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
						fflush($procInfo['pipes'][0]);
						
						echo "\tCrontab set.\n";
					}
					
					$nextNum = $lastNum + 1;
				}
				unset($server);
				
				// Set timezone to Eastern Time
				date_default_timezone_set('America/New_York');
				
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'crontabs_set');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
				
				
				// Sleep since the tests won't start right away
				sleep(60);
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Get test status on a server
	 * @param array $server Server information
	 */
	public function getTestStatusOnServer($server)
	{
		static $attempts = null;
		if (!$attempts)
			$attempts = array();
		
		// Every 500th attempt, try loading stats manually
		if (!isset($attempts[$server['hostname']]))
			$attempts[$server['hostname']] = 1;
		else
			$attempts[$server['hostname']]++;
		if ($attempts[$server['hostname']] % 500 == 0)
		{
			// Return values
			$rtn = array(
				'numErrors' => 0,
				'numSuccesses' => 0,
				'numRunning' => 0
			);
			
			// Get number still running
			$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; ps aux | grep registrationTest.php | grep -v grep | wc -l)';
			$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
			$rtn['numRunning'] = trim(exec($cmd));
			if (preg_match('/^[0-9]+$/AD', $rtn['numRunning']))
				$rtn['numRunning'] = (int)$rtn['numRunning'];
			
			// Get number of errors
			$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; grep -l "Error at line" logs/* 2> /dev/null | wc -l)';
			$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
			$rtn['numErrors'] = trim(exec($cmd));
			if (preg_match('/^[0-9]+$/AD', $rtn['numErrors']))
				$rtn['numErrors'] = (int)$rtn['numErrors'];
			
			// Get number of successes
			$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; grep -l "Completed!" logs/* 2> /dev/null | wc -l)';
			$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
			$rtn['numSuccesses'] = trim(exec($cmd));
			if (preg_match('/^[0-9]+$/AD', $rtn['numSuccesses']))
				$rtn['numSuccesses'] = (int)$rtn['numSuccesses'];
				
			// Get number of pages completed
			$cmd = 'egrep -h \'^Page:\' logs/* | sed \'s/Page: //\' | sort | uniq -c';
			$cmd = '(cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; '.$cmd.' 2> /dev/null)';
			$cmd = 'ssh ' . LoadTestingConfig::LOAD_TEST_SERVER_SSH_FLAGS . ' -o ConnectTimeout=30 -i ' . $server['private_key_filename'] . ' ' . LoadTestingConfig::TEST_SERVER_SSH_USER . '@' . $server['hostname'] . ' ' . escapeshellarg($cmd);
			exec($cmd, $lines);
			$rtn['numPages'] = array();
			foreach ($lines as $line)
				if (preg_match('/^\s+([0-9]+)\s+(.*)$/AD', $line, $match))
					$rtn['numPages'][$match[2]] = (int)$match[1];
		}
		else
		{
			// Return values
			$rtn = array(
				'numErrors' => 0,
				'numSuccesses' => 0,
				'numRunning' => 0
			);
			
			// Get number still running
			$cmd = 'cat ' . escapeshellarg($this->getTestStatusFileForServer($server)) . ' 2> /dev/null';
			echo $cmd . PHP_EOL;
			exec($cmd, $lines, $cmdRtn);
			if ($cmdRtn == 0)
			{
				// Number running
				if (($line = array_shift($lines)))
				{
					$rtn['numRunning'] = trim($line);
					if (preg_match('/^[0-9]+$/AD', $rtn['numRunning']))
						$rtn['numRunning'] = (int)$rtn['numRunning'];
					else
						$rtn['numRunning'] = 0;
				}
				
				// Number of errors
				if (($line = array_shift($lines)))
				{
					$rtn['numErrors'] = trim($line);
					if (preg_match('/^[0-9]+$/AD', $rtn['numErrors']))
						$rtn['numErrors'] = (int)$rtn['numErrors'];
					else
						$rtn['numErrors'] = 0;
				}
				
				// Number of successes
				if (($line = array_shift($lines)))
				{
					$rtn['numSuccesses'] = trim($line);
					if (preg_match('/^[0-9]+$/AD', $rtn['numSuccesses']))
						$rtn['numSuccesses'] = (int)$rtn['numSuccesses'];
					else
						$rtn['numSuccesses'] = 0;
				}
				
				// Get number of pages completed
				$rtn['numPages'] = array();
				foreach ($lines as $line)
					if (preg_match('/^\s*([0-9]+)\s+(.*)$/AD', $line, $match))
						$rtn['numPages'][$match[2]] = (int)$match[1];
			}
		}
		
		return $rtn;
	}
	
	/**
	 * Monitor tests
	 * @throws LoadTestControllerException
	 */
	public function monitorTests()
	{
		try {
			// Update status every 5 seconds
			$completed = false;
			while (!$completed)
			{
				// Reset counters
				$numErrors = $numSuccesses = $numRunning = 0;
				
				// Number of pages
				$numPages = array();
				
				// Get status from each server
				foreach ($this->testServers as &$server)
				{
					$status = $this->getTestStatusOnServer($server);
					$numErrors += $status['numErrors'];
					$numSuccesses += $status['numSuccesses'];
					$numRunning += $status['numRunning'];
					
					// Update number of pages
					if (isset($status['numPages']))
					{
						foreach ($status['numPages'] as $page=>$cnt)
						{
							if (!isset($numPages[$page]))
								$numPages[$page] = $cnt;
							else
								$numPages[$page] += $cnt;
						}
					}
				}
				unset($server);
				
				// Check if it completed (At least 50% must have some status)
				$completed = ($numRunning == 0 && $numSuccesses + $numErrors > $this->loadTest['num_registrations']/2);
				
				// Update database
				$this->dbh_loadTesting->updateLoadTestStatus($this->loadTestId, $completed, $numErrors, $numSuccesses, $numRunning);
				
				// Store page information so we can show a progress bar
				$this->dbh_loadTesting->setLoadTestTmpStats($this->loadTestId, array('numPages'=>$numPages));
				
				// Sleep
				if (!$completed)
					sleep(5);
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/**
	 * Analyze results
	 * @throws LoadTestControllerException
	 */
	public function analyzeResults()
	{
		try {
			// Analyze and move files to S3
			$checkpoints = $this->dbh_loadTesting->getLoadTestCheckpoints($this->loadTestId);
			if (!isset($checkpoints['analyzed_and_archived']))
			{
				// Check if this is NOT a local test
				if (!$this->localTest)
				{
					require_once('amazonSDK/sdk.class.php');
					$s3 = new AmazonS3();
					// Create folder
					$s3Folder = sprintf('%08d', $this->loadTestId) . '/';
					$response = $s3->create_object(S3_BUCKET, $s3Folder, array(
						'body' => '',
						'storage' => AmazonS3::STORAGE_REDUCED
					));
					if (!$response->isOk())
						throw new LogicException('Failed to upload results to S3.');
				}
				
				// Upload test server results and merge stats
				$json1 = null;
				$num = 1;
				
				// Flush SSH output
				$this->flushTestServerSshProcOutput();
				
				// Send command
				foreach ($this->testServers as &$server)
				{
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						$cmd = '( cd ' . LoadTestingConfig::TEST_SERVER_DIR . '; php analyzeAndStoreResults.php';
						// Check if this is NOT a local test
						if (!$this->localTest)
							$cmd .= ' --s3-filename='.$s3Folder.$num.'.tgz;';
						$cmd .= ' )';
						
						fwrite($procInfo['pipes'][0], $cmd . PHP_EOL);
						fflush($procInfo['pipes'][0]);
					}
					$num++;
				}
				
				$t1 = microtime(true);
				// Get results
				foreach ($this->testServers as &$server)
				{
					echo "Getting results from {$server['hostname']}:\n";
					ob_flush();flush();
					
					$output = '';
					$minAttempts = 5;
					$json = null;
					if ($procInfo = $this->getTestServerSshProc($server))
					{
						do {
							// Get response
							stream_set_timeout($procInfo['pipes'][1], 30);
							$output .= fread($procInfo['pipes'][1], 65536);
							echo 'Output: ' . $output . PHP_EOL;
							ob_flush();flush();
						} while ((empty($output) || !($json = json_decode(trim($output)))) && ($minAttempts-- > 0 || microtime(true) - $t1 < 300));
					}
					
					// Check for results
					if ($json)
					{
						// Check results
						if ($json->stats)
						{
							if ($json1 == null)
								$json1 = $json;
							else
								$json1 = $this->mergeStats($json1, $json);
						}
					}
				}
				unset($server);
				
				// Update database
				$this->dbh_loadTesting->setLoadTestStats($this->loadTestId, $json1 ? $json1->stats : null);
				
				// Checkpoint
				$this->dbh_loadTesting->addLoadTestCheckpoint($this->loadTestId, 'analyzed_and_archived');
				sleep(1);	// Sleep 1 second so it gets a unique timestamp and order is preserved
			}
		} catch (Exception $e) {
			throw new LoadTestControllerException(null, 0, $e);
		}
	}
	
	/** Clear up after test */
	public function cleanUpTest()
	{
		// Clear payment sandbox flag
		$sql = 'UPDATE races SET use_braintree_sandbox = \'F\' WHERE race_id = ' . $this->loadTest['race_id'];
		if (!$this->dbh->query($sql))
			throw new LoadTestControllerException('Failed to clear payment sandbox flag');
		$this->dbh->setRaceModified($this->loadTest['race_id']);
		
		// Clean up test
		parent::cleanUpTest();
	}
	
	/**
	 * Merge stats
	 * @param object $json1 Stats
	 * @param object $json2 Stats
	 *
	 * @return object Merged stats
	 */
	public function mergeStats($json1, $json2)
	{
		$newJson = $this->mergeBasicStats($json1, $json2);
		
		// Timestamps
		if (isset($json1->stats->lastRegistrationRequestTimestamp) && isset($json2->stats->lastRegistrationRequestTimestamp))
		{
			$newJson->stats->lastRegistrationRequestTimestamp = max($json1->stats->lastRegistrationRequestTimestamp, $json2->stats->lastRegistrationRequestTimestamp);
			$newJson->stats->lastRegistrationRequestDate = date('m/d/Y G:i:s', $newJson->stats->lastRegistrationRequestTimestamp);
		}
		
		// Times
		if (isset($json1->stats->minRegistrationTimeSec) && isset($json2->stats->minRegistrationTimeSec))
		{
			$newJson->stats->minRegistrationTimeSec = min($json1->stats->minRegistrationTimeSec, $json2->stats->minRegistrationTimeSec);
			$newJson->stats->minRegistrationTime = $this->formatTime($newJson->stats->minRegistrationTimeSec);
		}
		if (isset($json1->stats->maxRegistrationTimeSec) && isset($json2->stats->maxRegistrationTimeSec))
		{
			$newJson->stats->maxRegistrationTimeSec = max($json1->stats->maxRegistrationTimeSec, $json2->stats->maxRegistrationTimeSec);
			$newJson->stats->maxRegistrationTime = $this->formatTime($newJson->stats->maxRegistrationTimeSec);
		}
		if (isset($json1->stats->avgRegistrationTimeSec) && isset($json2->stats->avgRegistrationTimeSec))
		{
			$newJson->stats->avgRegistrationTimeSec = $json1->stats->numSuccesses/$newJson->stats->numSuccesses*$json1->stats->avgRegistrationTimeSec + $json2->stats->numSuccesses/$newJson->stats->numSuccesses*$json2->stats->avgRegistrationTimeSec;
			$newJson->stats->avgRegistrationTime = $this->formatTime($newJson->stats->avgRegistrationTimeSec);
		}
		
		// Registration averages and totals
		if (isset($json1->stats->avgRegistrationsPerSec) && isset($json2->stats->avgRegistrationsPerSec))
			$newJson->stats->avgRegistrationsPerSec = $json1->stats->avgRegistrationsPerSec + $json2->stats->avgRegistrationsPerSec;
		if (isset($json1->stats->avgRegistrationsPerMin) && isset($json2->stats->avgRegistrationsPerMin))
			$newJson->stats->avgRegistrationsPerMin = $json1->stats->avgRegistrationsPerMin + $json2->stats->avgRegistrationsPerMin;
		if (isset($json1->stats->estRegistrationsPerHour) && isset($json2->stats->estRegistrationsPerHour))
			$newJson->stats->estRegistrationsPerHour = $json1->stats->estRegistrationsPerHour + $json2->stats->estRegistrationsPerHour;
			
		// Clock times (registration)
		if (isset($json1->stats->cumulativeRegistrationClockTimeSec) && isset($json2->stats->cumulativeRegistrationClockTimeSec))
		{
			$newJson->stats->cumulativeRegistrationClockTimeSec = $json1->stats->cumulativeRegistrationClockTimeSec + $json2->stats->cumulativeRegistrationClockTimeSec;
			$newJson->stats->cumulativeRegistrationClockTime = $this->formatTime($newJson->stats->cumulativeRegistrationClockTimeSec);
		}
		if (isset($json1->stats->minRegistrationClockTimeSec) && isset($json2->stats->minRegistrationClockTimeSec))
		{
			$newJson->stats->minRegistrationClockTimeSec = min($json1->stats->minRegistrationClockTimeSec, $json2->stats->minRegistrationClockTimeSec);
			$newJson->stats->minRegistrationClockTime = $this->formatTime($newJson->stats->minRegistrationClockTimeSec);
		}
		if (isset($json1->stats->maxRegistrationClockTimeSec) && isset($json2->stats->maxRegistrationClockTimeSec))
		{
			$newJson->stats->maxRegistrationClockTimeSec = max($json1->stats->maxRegistrationClockTimeSec, $json2->stats->maxRegistrationClockTimeSec);
			$newJson->stats->maxRegistrationClockTime = $this->formatTime($newJson->stats->maxRegistrationClockTimeSec);
		}
		if (isset($json1->stats->avgRegistrationClockTimeSec) && isset($json2->stats->avgRegistrationClockTimeSec))
		{
			$newJson->stats->avgRegistrationClockTimeSec = $json1->stats->numSuccesses/$newJson->stats->numSuccesses*$json1->stats->avgRegistrationClockTimeSec + $json2->stats->numSuccesses/$newJson->stats->numSuccesses*$json2->stats->avgRegistrationClockTimeSec;
			$newJson->stats->avgRegistrationClockTime = $this->formatTime($newJson->stats->avgRegistrationClockTimeSec);
		}
		
		// Clock times (refund)
		if (isset($json1->stats->cumulativeRefundClockTimeSec) && isset($json2->stats->cumulativeRefundClockTimeSec))
		{
			$newJson->stats->cumulativeRefundClockTimeSec = $json1->stats->cumulativeRefundClockTimeSec + $json2->stats->cumulativeRefundClockTimeSec;
			$newJson->stats->cumulativeRefundClockTime = $this->formatTime($newJson->stats->cumulativeRefundClockTimeSec);
		}
		if (isset($json1->stats->minRefundClockTimeSec) && isset($json2->stats->minRefundClockTimeSec))
		{
			$newJson->stats->minRefundClockTimeSec = min($json1->stats->minRefundClockTimeSec, $json2->stats->minRefundClockTimeSec);
			$newJson->stats->minRefundClockTime = $this->formatTime($newJson->stats->minRefundClockTimeSec);
		}
		if (isset($json1->stats->maxRefundClockTimeSec) && isset($json2->stats->maxRefundClockTimeSec))
		{
			$newJson->stats->maxRefundClockTimeSec = max($json1->stats->maxRefundClockTimeSec, $json2->stats->maxRefundClockTimeSec);
			$newJson->stats->maxRefundClockTime = $this->formatTime($newJson->stats->maxRefundClockTimeSec);
		}
		if (isset($json1->stats->avgRefundClockTimeSec) && isset($json2->stats->avgRefundClockTimeSec))
		{
			$newJson->stats->avgRefundClockTimeSec = $json1->stats->numSuccesses/$newJson->stats->numSuccesses*$json1->stats->avgRefundClockTimeSec + $json2->stats->numSuccesses/$newJson->stats->numSuccesses*$json2->stats->avgRefundClockTimeSec;
			$newJson->stats->avgRefundClockTime = $this->formatTime($newJson->stats->avgRefundClockTimeSec);
		}
		
		// Registrations by seconds
		$newJson->stats->numRegistrationsBySecond = array();
		foreach ($json1->stats->numRegistrationsBySecond as $sec=>$cnt)
			$newJson->stats->numRegistrationsBySecond[$sec] = $cnt;
		foreach ($json2->stats->numRegistrationsBySecond as $sec=>$cnt)
			$newJson->stats->numRegistrationsBySecond[$sec] = (isset($newJson->stats->numRegistrationsBySecond[$sec]) ? $newJson->stats->numRegistrationsBySecond[$sec] : 0) + $cnt;
		
		return $newJson;
	}
}

try {
	// Get load test id
	if ($argc < 2 || !Validator::validateInt($argv[1]))
		throw new LogicException('Load test id not set or invalid.');
	$loadTestId = (int)$argv[1];
	
	// Check if the server address was passed in
	$serverAddr = ($argc >= 3) ? $argv[2] : null;
	
	// Connect to database
	$dbh = &Utils::dbConnect($DB);
	if (!$dbh->setupMySQLi(false, false))
		throw new LogicException('Failed to connect to database.');
	
	try {
		// Get load test
		// Load load testing module
		require_once('Database/LoadTesting.class.php');
		$dbh_loadTesting = new DatabaseLoadTesting($dbh);
		if (!($loadTest = $dbh_loadTesting->getLoadTest($loadTestId)))
			throw new LogicException('Load test not found.');
	} catch (Exception $e) {
		throw new LoadTestControllerException(null, 0, $e);
	}
	
	// Set up controller
	$loadTestController = new RegistrationLoadTestController($dbh, $localTest ? null : $serverAddr, $loadTestId);
	$loadTestController->localTest = $localTest;
	$loadTestController->runLoadTest();
	
	// Close database
	$dbh->close();
	
} catch (Exception $e) {
	// Send admin e-mail
	// Construct message
	$emailMsg = "Load Testing Error.\n";
	$emailMsg .= "Exception (" . $e->getMessage() . "):\n";
	$emailMsg .= print_r($e, 1);
	
	// Send E-mail
	Utils::sendAdminEmail('Load Testing Error', $emailMsg, 'general_error');
	exit;
}

?>
