<?php

define('APPROOT', dirname(dirname(__FILE__)));
define('TESTROOT', dirname(__FILE__));

function require_files(array $files, $prefix = '') {
	foreach ($files as $file) {
		require_once("{$prefix}{$file}");
	}
}

$sourcefiles = array(
	'WebSocket.php',
	'WebSocketConnection.php',
	'WebSocketFrame.php',
	'iWebSocketApp.php',
);

$testfiles = array(
	'TestApp.php',
);

require_files($sourcefiles, APPROOT . '/');
require_files($testfiles, TESTROOT . '/');


