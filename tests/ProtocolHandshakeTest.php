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

	protected function tearDown() {
		if (isset($this->server) && is_object($this->server)) {
			$this->server->shutdown();
		}
	}

	/**
	 * Taken right from the protocol docs:
	 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17#page-7
	 * @return string
	 */
	private static function getClientHandshakeString($key = 'dGhlIHNhbXBsZSBub25jZQ==') {
		$hs = array(
			'GET /chat HTTP/1.1',
			'Host: server.example.com',
			'Upgrade: websocket',
			'Connection: Upgrade',
			'Sec-WebSocket-Key: ' . $key,
			'Sec-WebSocket-Protocol: chat, superchat',
			'Sec-WebSocket-Version: 13',
			'',
			'',
		);

		return implode("\r\n", $hs);
	} // getClientHandshakeString()

	private function connectAndRead($sock, $handshake=null) {
		if (! is_resource($sock)) {
			throw new Exception("failed to create client socket");
		}

		if (! socket_connect($sock, self::SERVER_IP, self::SERVER_PORT)) {
			throw new Exception("Failed to connect to server");
		}

		if ($handshake) {
			socket_write($sock, $handshake);
		} else {
			socket_write($sock, self::getClientHandshakeString());
		}

		$read = socket_read($sock, 2048);

		return $read;
	}


	/**
	 * Checks that the server responds exactly as detailed in the spec for the handshake.
	 */
	public function testHandshakeCompliance_Very_Literal() {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		$read = self::connectAndRead($sock);
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

	/**
	 * Returns a base64 encoded random-ish string which when decoded is 16 bytes long, as per spec.
	 * @return string
	 */
	private static function getRandomKey() {
		$raw = substr(md5(rand()), 0, 16);
		return base64_encode($raw);
	}

	/**
	 * Checks that the server calculates response keys correctly with random client keys.
	 */
	public function testHandshakeKey() {
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		$key = self::getRandomKey();
		$read = self::connectAndRead($sock, self::getClientHandshakeString($key));
		$lines = explode("\r\n", $read);

		$serverResponse = null;
		foreach ($lines as $line) {
			if (strpos($line, 'Sec-WebSocket-Accept:') === 0) {
				$chunks = explode(':', $line);
				$serverResponse = trim($chunks[1]);
				break;
			}
		}

		$guid = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

		$calcResponse = base64_encode(sha1("{$key}{$guid}", true));

		echo "key: $key\n";

		$this->assertEquals($calcResponse, $serverResponse);
	} // testHandshakeCompliance_Very_Literal()


}

