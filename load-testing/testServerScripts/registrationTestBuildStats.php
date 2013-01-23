<?php

define('STATS_SOCKET_PORT', 50000);

// Open a UDP socket
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
socket_bind($socket, '127.0.0.1', STATS_SOCKET_PORT);
socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, array('sec'=>2,'usec'=>0));

$running = $successes = $errors = 0;
$pages = array();

function processMessage($msg)
{
	global $successes, $errors, $pages;
	
	// Check message type
	if (isset($msg['type']))
	{
		if ($msg['type'] == 'success')
			$successes++;
		else if ($msg['type'] == 'error')
			$errors++;
		else if ($msg['type'] == 'page' && isset($msg['page']))
		{
			if (!isset($pages[$msg['page']]))
				$pages[$msg['page']] = 0;
			$pages[$msg['page']]++;
		}
	}
}

while (1)
{
	// Get number still running
	$running = (int)trim(exec('ps aux | grep registrationTest.php | grep -v grep | wc -l | tail -1'));
	
	$t1 = microtime(true);
	do
	{
		// Read from socket
		$t2 = microtime(true);
		$timeout = max(.001, (2 - ($t2 - $t1)));
		$from = '';
		$port = 0;
		$socketTimeout = array(
			'sec' => (int)$timeout,
			'usec' => (int)(($timeout  - (int)$timeout)*1000000)
		);
		
		socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, $socketTimeout);
		if (@socket_recvfrom($socket, $buf, 4096, 0, $from, $port))
		{
			@socket_sendto($socket, 'A', 1, 0, $from, $port);
			processMessage(unserialize($buf));
		}
		
		// While we can read more, do it for up to 10 seconds
		socket_set_nonblock($socket);
		if (microtime(true) -  $t1 < 10 && @socket_recvfrom($socket, $buf, 4096, 0, $from, $port))
		{
			@socket_sendto($socket, 'A', 1, 0, $from, $port);
			processMessage(unserialize($buf));
		}
		socket_set_block($socket);
	} while (microtime(true) - $t1 < 2);
	
	// Write stats
	$str = "{$running}\n{$errors}\n{$successes}\n";
	foreach ($pages as $page=>$count)
		$str .= "{$count}\t{$page}\n";
	file_put_contents('runningStats.dat', $str);
	
	// Check if we should loop
	if ($running == 0 && ($errors > 0 || $successes > 0))
		break;
	
	// Sleep so we have waited at least 2 seconds from last loop start
	$t2 = microtime(true);
	$usec = (int)((2 - ($t2 - $t1)) * 1000000);
	if ($usec > 0)
		usleep($usec);
}

socket_close($socket);

?>