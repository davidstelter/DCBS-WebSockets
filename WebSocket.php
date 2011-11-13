<?php
/**
 * @refer http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 * @author David Stelter
 */
class WebSocket {

	const MASTER_CONNECTION_BACKLOG = 15;
	const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	const APP_DEFAULT = 'default';

	private $controlSock = null;
	private $bindAddress = null;
	private $bindPort    = null;
	private $logfile     = null;

	private $clients = array();
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

		$this->socks[] = $this->controlSock;

		while (true) {
			$read = $this->socks;
			socket_select($read, $write=null, $except=null, null);
			foreach ($read as $sock) {
				if ($sock == $this->controlSock) { // client initiating new connection
					$this->log('new connection initiated by client');
					if (! is_resource($client = socket_accept($this->controlSock))) {
						//log error
						$this->log('error accepting connection');
					} else {
						$this->newClient($client);
					}
				} else { // traffic on existing connection
					//$this->log('traffic on existing connection');
					if (($bytes = socket_recv($sock, $buffer, 2048, 0)) == 0) {
						$this->disconnect($sock);
					} else {
						$client = $this->getClientBySocket($sock);
						if (! $client->getHandshake()) {
							$client->doHandshake($buffer);
							if (! $host = $client->getHost()) {
								$host = self::APP_DEFAULT;
							}

							if ($appClass = self::getApp($host)) {
								$this->log('App ' . $appClass . ' found for host ' . $host);
								$app = new $appClass($client);
								$client->setApp($app);
							} else {
								$this->log('No app registered for ' . $host);
							}
						} else {
							$client->process($buffer);
							//$this->process($client, $buffer);
						}
					}
				} // create / continue
			}
		} // while (true)
	} // run()

	/**
	 * @param resource $sock
	 */
	private function disconnect($sock) {
		unset($this->clients[$sock]);
		unset($this->socks[$sock]);

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

	/**
	 * @param resource $sock
	 * @return WebSocketClient
	 */
	private function getClientBySocket($sock) {
		if (isset($this->clients[$sock])) {
			return $this->clients[$sock];
		} else {
			throw new RuntimeException('failed to locate client by socket');
		}
	} // getClientBySocket()

	private function newClient($sock) {
		if (is_resource($sock)) {
			$this->clients[$sock] = new WebSocketConnection($this, $sock);
			$this->socks[$sock] = $sock;
			$this->log('Connecting client socket ' . self::getSocketNameString($sock));
		}
	} // newClient()

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
		foreach ($this->socks as $sock) {
			if (is_resource($sock)) {
				socket_close($sock);
			}
		}
	} // __destruct()

	public function getAddress() {
		return $this->bindAddress . ':' . $this->bindPort;
	} // getAddress()

} // WebSocket{}

//$server = new WebSocket('localhost', 12345);

