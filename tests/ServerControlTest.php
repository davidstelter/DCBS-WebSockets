<?php
/**
 * @author David Stelter <david.stelter@gmail.com>
 * @copyright Copyright (c) 2011, David Stelter
 * @license http://www.opensource.org/licenses/MIT
 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 */
class ServerControlTest extends PHPUnit_Framework_TestCase {

	private $masterPid = null;
	private $server = null;

	const SERVER_IP = '127.0.0.1';
	const SERVER_PORT = 12345;

	protected function setUp() {
		$this->server = new WebSocket(self::SERVER_IP, self::SERVER_PORT);
		$this->server->registerApp('TestApp', WebSocket::APP_DEFAULT);
	}


	protected function tearDown() {
		if (isset($this->server) && is_object($this->server)) {
			$this->server->shutdown();
		}
	}

	/**
	 * Crude check that a PID is running. SIGURG is usually ignored, so we kill the PID with SIGURG.
	 * If posix_kill() returns false, the PID likely doesn't exist.
	 * @param int $pid
	 * @return bool
	 */
	private static function checkPid($pid) {
		return posix_kill($pid, SIGURG);
	} // checkPid()

	/**
	 * Make sure the server creates a process on the indicated pid, and that it kills it on shutdown.
	 * Also checks for expected return codes of shutdown method.
	 */
	public function testMasterShutdown() {
		$this->masterPid = $this->server->run();


		$this->assertTrue(self::checkPid($this->masterPid),
			"Process not running on indicated PID {$this->masterPid}");
		$this->assertTrue($this->server->shutdown(),
			"Server shutdown failed to return true");
		$this->assertFalse(self::checkPid($this->masterPid),
			"Process appears to still be running on PID {$this->masterPid}");
		$this->assertFalse($this->server->shutdown(),
			"shutdown failed to return false after already stopped");
	} // testMasterShutdown()

	/**
	 * The master process should be automatically shut down when the creating object is destroyed
	 * in the initial thread.
	 */
	public function testMasterAutoShutdown() {
		$this->masterPid = $this->server->run();

		$this->assertTrue(self::checkPid($this->masterPid),
			"Process not running on indicated PID {$this->masterPid}");
		unset($this->server);
		$this->assertFalse(self::checkPid($this->masterPid),
			"Process appears to still be running on PID {$this->masterPid}");
	} // testMasterShutdown()

	/**
	 * If the server is started on an already listening port, it should throw an exception and exit.
	 */
	public function testStartupOnOccupiedPort() {
		$this->setExpectedException('RuntimeException');
		$this->server->run();

		$this->server2 = new WebSocket(self::SERVER_IP, self::SERVER_PORT);
		$this->server2->run();
		// this cleanup shouldn't be needed...
		$this->server2->shutdown();
	} // testStartupOnOccupiedPort()

}

