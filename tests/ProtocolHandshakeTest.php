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

	protected function setUp() {
		$this->server = new WebSocket('127.0.0.1', 12345);
		$this->server->registerApp('TestApp', WebSocket::APP_DEFAULT);
		$this->masterPid = $this->server->run();
	}

	protected function tearDown() {
		// not defined, crap... posix_kill($this->masterPid);
	}

	public function testHello() {
		$this->assertTrue(false, 'true is not false');
	}

}

