<?php
/**
 * @author David Stelter <david.stelter@gmail.com>
 * @copyright Copyright (c) 2011, David Stelter
 * @license http://www.opensource.org/licenses/MIT
 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 */
class ProtocolHandshakeTest extends PHPUnit_Framework_TestCase {

	private $masterPid = null;
	private $server = null;

	const SERVER_IP = '127.0.0.1';
	const SERVER_PORT = 12345;

	protected function setUp() {
		$this->server = new WebSocket(self::SERVER_IP, self::SERVER_PORT);
		$this->server->registerApp('TestApp', WebSocket::APP_DEFAULT);
		$this->masterPid = $this->server->run();
	} // setUp()

	/**
	 * Taken right from the protocol docs:
	 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17#page-7
	 * @return string
	 */
	private function getClientHandshakeString() {
		$hs = array(
			'GET /chat HTTP/1.1',
			'Host: server.example.com',
			'Upgrade: websocket',
			'Connection: Upgrade',
			'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
			'Sec-WebSocket-Protocol: chat, superchat',
			'Sec-WebSocket-Version: 13',
			'',
			'',
		);

		return implode("\r\n", $hs);
	} // getClientHandshakeString()

	/**
	 * Checks that the server responds exactly as detailed in the spec for the handshake.
	 */
	public function testHandshakeCompliance_Very_Literal() {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

		if (! is_resource($sock)) {
			throw new Exception("failed to create client socket");
		}

		if (! socket_connect($sock, self::SERVER_IP, self::SERVER_PORT)) {
			throw new Exception("Failed to connect to server");
		}

		socket_write($sock, $this->getClientHandshakeString());

		$read = socket_read($sock, 2048);
		$lines = explode("\r\n", $read);

		$expected = array(
			'HTTP/1.1 101 Switching Protocols',
			'Upgrade: websocket',
			'Connection: Upgrade',
			'Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
		);

		foreach ($expected as $k => $v) {
			$num = $k + 1;
			$this->assertEquals($v, $lines[$k], "line {$num} of response is not conformant");
		}
	} // testHandshakeCompliance_Very_Literal()

}

