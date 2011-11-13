<?php

class EchoApp implements iWebSocketApp {

	private $conn = null;

	public function onMessage($msg) {
		echo "EchoApp got message '$msg'\n";
		$this->conn->send(strtoupper($msg));
	}

	public function onError($error) {

	}

	public function onClose($data) {
		echo "EchoApp shutdown for client {$this->conn->getAddressString()}\n";
	} // onClose()

	public function __construct(WebSocketConnection $conn) {
		echo "EchoApp instantiated!\n";
		$this->conn = $conn;
	}

}

