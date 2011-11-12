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

class WebSocketFrame {
	private $fin    = null;
	private $opcode = null;
	private $len    = null;
	private $masked = null;

	private $data   = array();
	private $raw    = array();

	private $res    = array();
	private $key    = array();

	const OPCODE_CONTINUATION = 0x00;
	const OPCODE_TEXT_FRAME   = 0x01;
	const OPCODE_BINARY_FRAME = 0x02;
	const OPCODE_RSRV_3       = 0x03;
	const OPCODE_RSRV_4       = 0x04;
	const OPCODE_RSRV_5       = 0x05;
	const OPCODE_RSRV_6       = 0x06;
	const OPCODE_RSRV_7       = 0x07;
	const OPCODE_CLOSE_CONN   = 0x08;
	const OPCODE_PING         = 0x09;
	const OPCODE_PONG         = 0x0a;
	const OPCODE_RSRV_B       = 0x0b;
	const OPCODE_RSRV_C       = 0x0c;
	const OPCODE_RSRV_D       = 0x0d;
	const OPCODE_RSRV_E       = 0x0e;
	const OPCODE_RSRV_F       = 0x0f;

	static $opcode_labels = array (
		self::OPCODE_CONTINUATION => 'continuation',
		self::OPCODE_TEXT_FRAME   => 'text frame',
		self::OPCODE_BINARY_FRAME => 'binary frame',
		self::OPCODE_RSRV_3       => 'reserved (0x03)',
		self::OPCODE_RSRV_4       => 'reserved (0x04)',
		self::OPCODE_RSRV_5       => 'reserved (0x05)',
		self::OPCODE_RSRV_6       => 'reserved (0x06)',
		self::OPCODE_RSRV_7       => 'reserved (0x07)',
		self::OPCODE_CLOSE_CONN   => 'close',
		self::OPCODE_PING         => 'ping',
		self::OPCODE_PONG         => 'pong',
		self::OPCODE_RSRV_B       => 'reserved (0x0b)',
		self::OPCODE_RSRV_C       => 'reserved (0x0c)',
		self::OPCODE_RSRV_D       => 'reserved (0x0d)',
		self::OPCODE_RSRV_E       => 'reserved (0x0e)',
		self::OPCODE_RSRV_F       => 'reserved (0x0f)',
	);

	/**
	 * Constructs a WebSocketFrame from the raw data, handles data unmasking
	 * @param string $data
	 */
	public function readFrame($data) {
		// doing an unpack as unsigned char data saves much tomfoolery later...
		$u = unpack('C*', $data); // note: unpack returns 1-based array
		unset($data);

		$this->fin    = (bool) ($u[1] & 0x80);
		$this->res[1] = (bool) ($u[1] & 0x40);
		$this->res[2] = (bool) ($u[1] & 0x20);
		$this->res[3] = (bool) ($u[1] & 0x10);

		$this->opcode = $u[1] & 0x0f;
		$this->masked = (bool) ($u[2] & 0x80);

		// the length field is variable, either 1, 3, or 7 bytes in length (minus MSb, byte 1)
		$offset = null; // byte offset of data payload (or mask), 1-based thanks to unpack()

		$L = $u[2] & 0x7f;
		if ($L == 0x7f) { // L == 127, length field is 7 + 64 bits
			// TODO: fix!
			$this->len = ($u[3] << 24) + ($u[4] << 16) + ($u[5] << 8) + ($u[6]);
			$offset = 11;
		} elseif ($L == 0x7e) { // L == 126, length field is 7 + 16 bits
			$this->len = ($u[3] << 8) + $u[4];
			$offset = 5;
		} else { // <= 125, length field is 7 bits
			$this->len = $L;
			$offset = 3;
		}

		// masking key is optional, if it's present it's 4 bytes
		if ($this->masked) {
			for ($i = 0; $i < 4; ++$i) {
				$this->key[$i] = $u[$i + $offset];
			}
			$offset += 4;
		}

		$this->data = '';
		// payload
		for ($i = 0; $i < $this->len; ++$i) {
			$pos = $i + $offset;

			if ($this->masked) {
				$B = $u[$pos] ^ $this->key[$i % 4]; // XOR with key bytes
			} else { // no mask, data is in the clear
				$B = $u[$pos];
			}

			if (self::OPCODE_TEXT_FRAME === $this->opcode) {
				$this->data .= chr($B);
			} else {
				$this->data .= $B;
			}
		}
	} // readFrame()


	/**
	 * If $data is set to a raw WebSocket frame and $raw is true, this WebSocketFrame has its
	 * members populated from the raw frame, and $this->data will contain the decoded payload.
	 * If $data is not null but $raw is false (the default), $this->data is set to the $data and
	 * this WebSocketFrame can be used to encode a raw frame to send to the client.
	 * @param $data = null
	 * @param $raw = false
	 */
	public function __construct($data = null, $raw = false) {
		if ($data) {
			if ($raw) {
				$this->readFrame($data);
			} else {
				$this->data = $data;
			}
		}
	} // __construct()

	/**
	 * Get a WebSocket frame bytestream suitable for sending over an open socket to a client.
	 * @return string
	 */
	public function getFrame() {
		if (! $this->data) {
			return null;
		}

		$b = array();

		$this->fin = true;

		for ($i = 0; $i < 3; ++$i) {
			$this->res[$i] = false;
		}
		$this->len = strlen($this->data);
		if (null === $this->opcode) {
			$this->opcode = self::OPCODE_TEXT_FRAME;
		}

		//todo: support masking!
		$this->masked = false;

		//$b[0] = $this->opcode;
		$b[0] = 0x01;
		if ($this->fin) {
			$b[0] |= 0x80;
		} else {
			$b[0] &= 0x7f;
		}

		//todo: actually write out the RSV bits...
		//todo: support len > 125!
		$b[1] = $this->len;

		if ($this->masked) {
			$b[1] |= 0x80;
		} else {
			$b[1] &= 0x7f;
		}

		//return pack('CC', $b[0], $b[1]) . $this->data;
		return pack('CC', 0x81, 0x06) . $this->data;
	} // getFrame()

	/**
	 * Returns the data payload.
	 * @return array
	 */
	public function getData() {
		return $this->data;
	} // getData

	/**
	 * Length of the data payload
	 * @return int
	 */
	public function getLen() {
		return $this->len;
	} // getLen()

	/**
	 * @return bool
	 */
	public function getMasked() {
		return $this->masked;
	} // getMasked()

	/**
	 * @param bool $masked
	 */
	public function setMasked($masked) {
		$this->masked = (bool) $masked;
	} // setMasked()

	/**
	 * @return int
	 */
	public function getOpcode() {
		return $this->opcode;
	} // getOpcode()

	/**
	 * Human-readable opcode label
	 * @return string
	 */
	public function getOpcodeLabel() {
		return self::$opcode_labels[$this->opcode];
	} // getOpcodeLabel()

	public function __toString() {
		$s = "FIN:    {$this->fin}\n";

		foreach ($this->res as $k => $v) {
			$s.= "RSRV{$k}:  " . (int)$v . "\n";
		}

		$s.= 'OPCODE: ' . self::$opcode_labels[$this->opcode] . "\n";
		$s.= "MASK:   " . (int) $this->masked . "\n";
		$s.= "LEN:    {$this->len}\n";

		if ($this->masked) {
			$k = $this->key;
			$s.= sprintf("KEY:    0x%x%x%x%x\n", $k[0], $k[1], $k[2], $k[3]);
		}

		$s.= "DATA:    '{$this->data}'\n";

		return $s;
	} // __toString()
}

class WebSocketConnection {
	private $status = null;

	private $maskOutgoing = false;
	private $server = null;
	private $socket = null;
	private $headers = array();

	private $closePending = false;

	private $wsProtocol = 'ws';

	// fields from client-sent headers //
	private $host = null;
	private $origin = null;
	private $resource = null;
	private $key = null;
	private $version = null;
	private $protocol = array();

	// calculated session key //
	private $sessionKey = null;

	private $handshake = false;

	const STATUS_CONNECTING = 0;
	const STATUS_OPEN       = 1;
	const STATUS_CLOSING    = 2;
	const STATUS_CLOSED     = 3;

	static $status_labels = array(
		self::STATUS_CONNECTING => 'connecting',
		self::STATUS_OPEN       => 'open',
		self::STATUS_CLOSING    => 'closing',
		self::STATUS_CLOSED     => 'closed',
	);

	public function getStatus() {
		return $this->status;
	} // getStatus()

	public function __construct(WebSocket $server, $socket) {
		if (! is_resource($socket)) {
			throw new RuntimeException('invalid socket');
		}

		$this->server = $server;
		$this->socket = $socket;
	} // __construct()

	/**
	 * Initiates a WebSockets protocol close
	 */
	public function close() {
		if ($this->closePending) {
			return; // silently ignore repeat calls
		}

		$this->closePending = true;
		$frame = new WebSocketFrame;
		$frame->setOpcode(WebSocketFrame::OPCODE_CLOSE_CONN);
		$this->sendRaw($frame->getFrame());
		//$this->server->close($this->$socket);
	} // close()

	/**
	 * Acknowledge a WebSocket protocol close from the client
	 */
	private function ackClose() {

	} // ackClose()

	public function doHandshake($buffer) {
		$this->server->log('begin handshake...');

		$headers = WebSocket::getHeaders($buffer);
		$this->origin   = $headers['origin'];
		$this->host     = $headers['host'];
		$this->resource = $headers['resource'];
		$this->key      = $headers['key'];
		$this->version  = $headers['version'];

		$upgrade = array(
			"HTTP/1.1 101 Web Socket Protocol Handshake",
			"Upgrade: WebSocket",
			"Connection: Upgrade",
			"Sec-WebSocket-Accept: " . $this->getSessionKey(),
			'', // yes, we need these...
			'', // two sets of trailing \r\n required!
		);

		$this->sendRaw(implode("\r\n", $upgrade));
		$this->handshake = true;
		$this->server->log('handshake complete');

		//print_r($upgrade);
	} // doHandshake()

	/**
	 * Send raw data to the socket
	 * @param string $msg
	 * @return int
	 */
	private function sendRaw($message) {
		if (! is_resource($this->socket)) {
			throw new RuntimeException('socket not open');
		}
		$len = strlen($message);
		$this->server->log("len: $len");
		if (false === ($written = socket_write($this->socket, $message, $len) )) {
			throw new RuntimeException('socket_write failed');
		}
		$this->server->log("Wrote $written bytes");
		return $written;
	} // sendRaw()

	/**
	 * Send data through the WebSocket protocol
	 * @param $data
	 */
	public function send($data) {
		if (! $this->connectionOpen) {
			throw new RuntimeException('Connection is not open, cannot send data');
		}
		$frame = new WebSocketFrame($data);
		$frame->setMasked($this->maskOutgoing);
		$rawFrame = $frame->getFrame();
		$this->sendRaw($rawFrame);
	} // send()

	public function getURI() {
		return $this->wsProtocol . '://' . $this->host . $this->resource;
	} // getURI()

	public function getSessionKey() {
		if (! $this->sessionKey) {
			$raw = $this->key . WebSocket::WS_MAGIC_GUID;
			$this->sessionKey = base64_encode(sha1($raw, true));
		}
		return $this->sessionKey;
	} // getSessionKey()

	public function getHandshake() {
		return $this->handshake;
	} // getHandshake()

} // class WebSocketConnection

$server = new WebSocket('localhost', 12345);


