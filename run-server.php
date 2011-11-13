<?php

$dir = dirname(__FILE__);
require_once ($dir . '/WebSocketFrame.php');
require_once ($dir . '/WebSocketConnection.php');
require_once ($dir . '/WebSocket.php');
require_once ($dir . '/iWebSocketApp.php');
require_once ($dir . '/EchoApp.php');
require_once ($dir . '/StatusApp.php');

$server = new WebSocket('localhost', 12345);
//$server->registerApp('EchoApp', WebSocket::APP_DEFAULT);
$server->registerApp('StatusApp', WebSocket::APP_DEFAULT);
$server->run();

