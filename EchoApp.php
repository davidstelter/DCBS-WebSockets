<?php
/**
 * @author David Stelter <david.stelter@gmail.com>
 * @copyright Copyright (c) 2011, David Stelter
 * @license http://www.opensource.org/licenses/MIT
 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 */
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
		$this->conn = $conn;
		echo "EchoApp instantiated for host {$conn->getHost()}\n";
	}

} // EchoApp{}

