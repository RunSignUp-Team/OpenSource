<?php

/** Copyright: Bickel Advisory Services, LLC. */

/** Sessions using replicated memcached */
class SessionReplicatedMemcached
{
	/** Default Lock TTL */
	const DEFAULT_LOCK_TTL = 30;
	
	/** Lock Wait Time in Microseconds */
	private $lockWait = 0;
	
	/** First Memcached Pool */
  private $memcached1 = null;
	
	/** Second Memcached Pool */
  private $memcached2 = null;
	
	/** Servers for pool 1 */
	private $servers1 = null;
	
	/** Servers for pool 2 */
	private $servers2 = null;
	
	/** Open Locks */
	private $openLocks = array();
	
	/** Session id md5s */
	private $sessionIdMd5s = array();
	
	/**
	 * Constructor
	 * @param array $servers1 Server pool 1 (See Memcached::addServers for format)
	 * @param array $servers2 Server pool 2 (See Memcached::addServers for format)
	 * @param int $lockWait Time, in microseconds, between session lock attempts.
	 * 	Must be at least 100
	 */
	public function __construct($servers1, $servers2, $lockWait = 150000)
	{
		$this->servers1 = $servers1;
		$this->servers2 = $servers2;
		$this->lockWait = max($lockWait, 100);
	}
	
	/** Set up session handler */
	public function setupSessionHandler()
	{
		// Set save handler
		session_set_save_handler(
			array($this, 'open'),
			array($this, 'close'),
			array($this, 'read'),
			array($this, 'write'),
			array($this, 'destroy'),
			array($this, 'gc')
		);
		
		// Close session on shutdown
		register_shutdown_function('session_write_close');
		register_shutdown_function(array($this, 'release_all_locks'));
	}
	
	/** Open Callback.  Sets up memcached */
	public function open($savePath, $sessionName)
	{
		// Set up memcached
		$this->memcached1 = new Memcached();
		$this->memcached2 = new Memcached();
		$this->memcached1->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
		$this->memcached2->setOption(Memcached::OPT_DISTRIBUTION, Memcached::DISTRIBUTION_CONSISTENT);
		$this->memcached1->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
		$this->memcached2->setOption(Memcached::OPT_SERIALIZER, Memcached::SERIALIZER_IGBINARY);
		$this->memcached1->setOption(Memcached::OPT_PREFIX_KEY, 'session.key.');
		$this->memcached2->setOption(Memcached::OPT_PREFIX_KEY, 'session.key.');
		$this->memcached1->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
		$this->memcached2->setOption(Memcached::OPT_BINARY_PROTOCOL, true);
		
		// Fail after .5 seconds
		$this->memcached1->setOption(Memcached::OPT_SERVER_FAILURE_LIMIT, 1);
		$this->memcached2->setOption(Memcached::OPT_SERVER_FAILURE_LIMIT, 1);
		$this->memcached1->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);
		$this->memcached2->setOption(Memcached::OPT_CONNECT_TIMEOUT, 1000);
		
		// Add servers
		$rtn = $this->memcached1->addServers($this->servers1);
		$rtn = $this->memcached2->addServers($this->servers2) || $rtn;
		
		return $rtn;
	}
	
	/** Close Callback */
	public function close()
  {
		return true;
  }
	
	/**
	 * Read a session
	 * @param string $id Session id
	 * @return string Session encoded (serialized) string, or an empty string if there is no data to read.
	 */
  public function read($id)
  {
		// Get lock TTL
		$lockTtl = (int)ini_get('max_execution_time');
		if (!$lockTtl)
			$lockTtl = self::DEFAULT_LOCK_TTL;
		
		// Maximum lock wait attempts
		$attempts = (int)($lockTtl*1000000 / $this->lockWait);
		
		// Convert TTL to timestamp
		$lockTtl = time() + $lockTtl;
		
		// Wait for locks
		$lockKey = $id.'.lock';
		for ($numLocks = 0; $numLocks < 2 && $attempts > 0; $attempts--)
		{
			// Need lock 1
			if ($numLocks == 0)
			{
				if ($this->memcached1->add($lockKey, 1, $lockTtl))
					$numLocks = 1;
				else if ($this->memcached1->getResultCode() == Memcached::RES_DATA_EXISTS)
					usleep($this->lockWait);
				// Write error... assume we got the key
				else if ($this->memcached1->getResultCode() == Memcached::RES_WRITE_FAILURE)
					$numLocks = 1;
			}
			
			// Need lock 2
			if ($numLocks == 1)
			{
				if ($this->memcached2->add($lockKey, 1, $lockTtl))
					$numLocks = 2;
				else if ($this->memcached2->getResultCode() == Memcached::RES_DATA_EXISTS)
					usleep($this->lockWait);
				// Write error... assume we got the key
				else if ($this->memcached2->getResultCode() == Memcached::RES_WRITE_FAILURE)
					$numLocks = 2;
			}
		}
		
		// Check if we got the locks
		if ($numLocks == 1)
			$this->memcached1->delete($lockKey);
		if ($numLocks != 2)
			return '';
		
		// Add to open locks
		$this->openLocks[] = $lockKey;
		
		// Get session data from both servers
		$data1 = $this->memcached1->get($id);
		$data2 = $this->memcached2->get($id);
		
		// Check which ones newer
		$rtn = '';
		if ($data1 === false && $data2 === false)
		{}
		else if ($data1 === false)
			$rtn = $data2[1];
		else if ($data2 === false)
			$rtn = $data1[1];
		else if ($data1[0] >= $data2[0])
			$rtn = $data1[1];
		else
			$rtn = $data2[1];
		
		// Store MD5
		if ($rtn)
			$this->sessionIdMd5s[$id] = md5(serialize($rtn));
		
		// Return data
		return $rtn;
	}
	
	/**
	 * Write to a session
	 * @param string $id Session id
	 * @param string $data Serialized session data
	 * @return string Session encoded (serialized) string, or an empty string if there is no data to read.
	 */
  public function write($id, $data)
  {
		// Check if anything changed
		if (!isset($this->sessionIdMd5s[$id]) || $this->sessionIdMd5s[$id] != md5(serialize($data)))
		{
			// Get expiration
			$expiration = (int)ini_get('session.gc_maxlifetime');
			if ($expiration)
				$expiration = time()+$expiration;
			else
				$expiration = 0;
			
			// Write to both sessions with a timestamp
			$ts = time();
			$rtn = $this->memcached1->set($id, array($ts, $data), $expiration);
			$rtn = $this->memcached2->set($id, array($ts, $data), $expiration) || $rtn;
		}
		// Nothing changed
		else
			$rtn = true;
		
		// Delete locks
		$lockKey = $id.'.lock';
		$this->memcached1->delete($lockKey);
		$this->memcached2->delete($lockKey);
		
		// Remove from open locks
		$this->openLocks = array_filter($this->openLocks, function ($a) use ($lockKey) {
			return $a != $lockKey;
		});
		
		return $rtn;
	}

	/**
	 * Destory a session
	 * @param string $id Session id
	 */
  public function destroy($id)
  {
		// Delete session data
		$this->memcached1->delete($id);
		$this->memcached2->delete($id);
		
		// Delete locks
		$lockKey = $id.'.lock';
		$this->memcached1->delete($lockKey);
		$this->memcached2->delete($lockKey);
		
		// Remove from open locks
		$this->openLocks = array_filter($this->openLocks, function ($a) use ($lockKey) {
			return $a != $lockKey;
		});
		
		return true;
	}
	
	/**
	 * Release all locks.  Should not be called directly by user.  This is
	 * registered as a shutdown function.
	 */
	public function release_all_locks()
	{
		foreach ($this->openLocks as $lockKey)
		{
			$this->memcached1->delete($lockKey);
			$this->memcached2->delete($lockKey);
		}
	}

	/**
	 * Garbage Collection.
	 * Doesn't need to do anything since memcached handles expirations.
	 */
  public function gc($maxlifetime)
	{
		return true;
  }
}

?>