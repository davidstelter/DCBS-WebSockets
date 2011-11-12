<?php
/**
 * @refer http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-17
 * @author David Stelter
 */
class WebSocket {

	const MASTER_CONNECTION_BACKLOG = 15;
	const WS_MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	private $controlSock = null;
	private $bindAddress = null;
	private $bindPort = null;

	private $clients = array();
	private $socks = array();

	public function __construct($address, $port) {
		if (! is_numeric($port)) {
			throw new InvalidArgumentException('Port number must be numeric');
		}
		$this->bindAddress = $address;
		$this->bindPort = (int) $port;

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

		$this->log('Listening on ' . $this->getAddress());

		$this->socks[] = $this->controlSock;

		while(true) {
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
					$this->log('traffic on existing connection');
					if (($bytes = socket_recv($sock, $buffer, 2048, 0)) == 0) {
						$this->disconnect($sock);
					} else {
						$client = $this->getClientBySocket($sock);
						if (! $client->getHandshake()) {
							$client->doHandshake($buffer);
							//$this->doHandshake($client, $buffer);
						} else {
							$this->process($client, $buffer);
						}
					}
				} // create / continue
			}
		} // while (true)

	} // __construct()

	private function process(WebSocketConnection $conn, $data) {
		$frame = new WebSocketFrame($data, true);
		$this->log($frame);

		if ($frame->getOpcode() == WebSocketFrame::OPCODE_CLOSE_CONN) {
			$this->log('client-initiated close');
			$conn->close();
		}

		if ($frame->getData() == 'dofoobar') {
			$this->log("read command 'dofoobar'");
			$conn->send('FOOBAR');
		}
		return;
	} // process()

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
			$this->socks[] = $sock;
		}
	} // newClient()

	public function log($message) {
		error_log($message);
		//echo "$message\n";
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

	private function accept() {
	}

	public static function getHeaders($data) {
		$h = array();
		foreach (explode("\n", $data) as $line) {
			if (! isset($h['resource']) && sscanf($line, 'GET %s HTTP/1.1', $m) == 1) {
				$h['resource'] = $m;
				continue;
			}

			if (! isset($h['host']) && sscanf($line, 'Host: %s', $m) == 1) {
				$h['host'] = $m;
				continue;
			}

			if (! isset($h['origin']) && sscanf($line, 'Sec-WebSocket-Origin: %s', $m) == 1) {
				$h['origin'] = $m;
				continue;
			}

			if (! isset($h['key']) && sscanf($line, 'Sec-WebSocket-Key: %s', $m) == 1) {
				$h['key'] = $m;
				continue;
			}

			if (! isset($h['version']) && sscanf($line, 'Sec-WebSocket-Version: %s', $m) == 1) {
				$h['version'] = $m;
				continue;
			}
		}

		return $h;
	} // getHeaders()

}

$server = new WebSocket('localhost', 12345);

