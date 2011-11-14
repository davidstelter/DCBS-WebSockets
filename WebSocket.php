<?php
/**
 * @author David Stelter <david.stelter@gmail.com>
 * @copyright Copyright (c) 2011, David Stelter
 * @license http://www.opensource.org/licenses/MIT
 * @link http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 */
class WebSocket {

	const MASTER_CONNECTION_BACKLOG = 15;
	const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	const APP_DEFAULT = 'default';
	const SOCK_BUFFER_SIZE = 4096;

	private $controlSock = null;
	private $bindAddress = null;
	private $bindPort    = null;
	private $logfile     = null;

	private $children = array();
	private $socks   = array();
	private $apps    = array();

	/**
	 * @param $name
	 * @param $host = null
	 */
	public function registerApp($name, $host = null) {
		if (! $host) {
			$host = self::APP_DEFAULT;
		}
		$this->apps[$host] = $name;
		$this->log("Registered app '$name' for host '$host'");
	} // registerApp()

	public function getApp($host) {
		if (isset($this->apps[$host])) {
			return $this->apps[$host];
		} else {
			return $this->getDefaultApp();
		}
	} // getApp()

	public function getDefaultApp() {
		if (isset($this->apps[self::APP_DEFAULT])) {
			return $this->apps[self::APP_DEFAULT];
		} else {
			return null;
		}
	} // getDefaultApp()

	/**
	 * @param $address
	 * @param $port
	 */
	public function __construct($address, $port) {
		if (! is_numeric($port)) {
			throw new InvalidArgumentException('Port number must be numeric');
		}
		$this->bindAddress = $address;
		$this->bindPort = (int) $port;
	} // __construct()

	/**
	 * Run server main loop
	 */
	public function run() {
		if (! is_resource($this->controlSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP))) {
			throw new RuntimeException('master socket creation failed');
		}

		if (! socket_set_option($this->controlSock, SOL_SOCKET, SO_REUSEADDR, 1)) {
			throw new RuntimeException('master socket option SO_REUSEADDR failed');
		}

		if (! socket_bind($this->controlSock, $this->bindAddress, $this->bindPort)) {
			throw new RuntimeException('bind to ' . $this->getAddress() . ' failed');
		}

		if (! socket_listen($this->controlSock, self::MASTER_CONNECTION_BACKLOG)) {
			throw new RuntimeException('listen failed on master socket');
		}

		$this->log('Listening on ' . self::getSocketNameString($this->controlSock));

		while (true) {
			if (is_resource($client = socket_accept($this->controlSock))) {
				$this->log('new connection initiated by client ' . self::getPeerString($client));
				$this->newClient($client);
			} else {
				$this->log('error accepting connection');
			}
		} // while (true)
	} // run()

	public static function getPeerString($sock) {
		$addr;
		$port;
		if (! socket_getpeername($sock, $addr, $port)) {
			return null;
		} else {
			return "{$addr}:{$port}";
		}
	} // getPeerString()

	/**
	 * @param resource $sock
	 */
	private function disconnect($sock) {
		if (is_resource($sock)) {
			$this->log('disconnecting socket ' . self::getSocketNameString($sock));
			socket_close($sock);
		}
	} // disconnect()

	/**
	 * Returns the name in 'address':'port' format of the socket $sock. If $sock is not a resource,
	 * null is returned. On error, false is returned.
	 * @param resource $sock
	 * @return mixed
	 */
	public static function getSocketNameString($sock) {
		if (is_resource($sock)) {
			$addr;
			$port;
			if (socket_getsockname($sock, $addr, $port)) {
				return "{$addr}:{$port}";
			} else {
				return false;
			}
		} else {
			return null;
		}
	} // getSocketNameString()

	private function newClient($sock) {
		if (is_resource($sock)) {
			$this->socks[$sock] = $sock;
			if (($pid = pcntl_fork()) == 0) { // child
				$this->runChild($sock);
			} else { // parent
				$this->log('forked child ' . $pid);
				$this->children[$pid] = self::getPeerString($sock);
			}
		}
	} // newClient()

	private function runChild($childSock) {
		if (is_resource($this->controlSock)) {
			socket_close($this->controlSock);
		}

		$connection = new WebSocketConnection($this, $childSock);
		$this->log('Connecting client socket ' . self::getSocketNameString($childSock));

		while (true) {
			if (($bytes = socket_recv($childSock, $buf, self::SOCK_BUFFER_SIZE, 0)) == 0) {
				$this->disconnect($childSock);
				exit;
			} else {
				if ($connection->isEstablished()) {
					$connection->process($buf);
				} else { // do handshake and set up application
					$connection->doHandshake($buf);
					if (! $host = $connection->getHost()) {
						$host = self::APP_DEFAULT;
					}

					if ($appClass = self::getApp($host)) {
						$this->log('App ' . $appClass . ' found for host ' . $host);
						$app = new $appClass($connection);
						$connection->setApp($app);
					} else {
						$this->log('No app registered for ' . $host);
					}
				}
			}
		} // while (true)
	} // runChild()

	/**
	 * @param string $message
	 */
	public function log($message) {
		if ($this->logfile) {
			error_log($message, 3, $this->logfile);
		} else {
			error_log($message);
		}
	} // log()

	/**
	 * Ensures graceful socket closure.
	 */
	public function __destruct() {
		if (is_resource($this->controlSock)) {
			socket_close($sock);
		}
	} // __destruct()

	public function getAddress() {
		return $this->bindAddress . ':' . $this->bindPort;
	} // getAddress()

} // WebSocket{}

//$server = new WebSocket('localhost', 12345);

