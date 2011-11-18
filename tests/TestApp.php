<?php
/**
 * @author David Stelter <david.stelter@gmail.com>
 * @copyright Copyright (c) 2011, David Stelter
 * @license http://www.opensource.org/licenses/MIT
 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 */
class TestApp implements iWebSocketApp {

	private $conn = null;

	public function onMessage($msg) {

	} // onMessage()

	public function onError($error) {

	} // onError()

	public function onClose($data) {

	} // onClose()

	public function __construct(WebSocketConnection $conn) {
		$this->conn = $conn;
	}

} // EchoApp{}

