<?php

$dir = dirname(__FILE__);
require_once ($dir . '/WebSocketFrame.php');
require_once ($dir . '/WebSocketConnection.php');
require_once ($dir . '/WebSocket.php');

$server = new WebSocket('localhost', 12345);

