<?php

class WebSocketConnection {
	private $app = null;
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

	public function setApp(iWebSocketApp $app) {
		$this->app = $app;
	} // setApp()

	public function getStatus() {
		return $this->status;
	} // getStatus()

	public function getHost() {
		return $this->host;
	} // getHost()

	public function __construct(WebSocket $server, $socket) {
		if (! is_resource($socket)) {
			throw new RuntimeException('invalid socket');
		}

		$this->server = $server;
		$this->socket = $socket;
		$this->status = self::STATUS_CLOSED;
	} // __construct()

	/**
	 * @return string
	 */
	public function getAddressString() {
		return WebSocket::getSocketNameString($this->socket);
	} // getSocketAddressString()

	public function process($data) {
		$frame = new WebSocketFrame($data, true);
		$this->server->log($frame);

		switch ($frame->getOpcode()) {
			case WebSocketFrame::OPCODE_TEXT_FRAME:
			case WebSocketFrame::OPCODE_BINARY_FRAME:
				if ($this->app) {
					$this->app->onMessage($frame->getData());
				}
				break;

			case WebSocketFrame::OPCODE_CLOSE_CONN:
				if ($this->app) {
					$this->app->onClose($frame->getData());
				}
				$this->ackClose();
				break;

			case WebSocketFrame::OPCODE_PING:
			case WebSocketFrame::OPCODE_PONG:
				$this->server->log('Got ping or pong, should do something!');
				break;

			default:
				$this->server->log('Got unexpected opcode ' . $frame->getOpcodeLabel());
				break;
		}

		return;
	} // process()

	/**
	 * Initiates a WebSockets protocol close
	 */
	public function close() {
		if (self::STATUS_CLOSING === $this->status) {
			return; // silently ignore repeat calls
		}
		$this->server->log('client initiated close');

		$this->status = self::STATUS_CLOSING;
		$frame = new WebSocketFrame;
		$frame->setOpcode(WebSocketFrame::OPCODE_CLOSE_CONN);
		$this->sendRaw($frame->getFrame());
		//$this->server->close($this->$socket);
	} // close()

	/**
	 * Acknowledge a WebSocket protocol close from the client
	 */
	private function ackClose() {
		$this->server->log('received close from client');

		$frame = new WebSocketFrame;
		$frame->setOpcode(WebSocketFrame::OPCODE_CLOSE_CONN);
		$this->sendRaw($frame->getFrame());
		$this->status = self::STATUS_CLOSED;
	} // ackClose()

	public static function getHeaders($data) {
		$h = array(
			'resource' => null,
			'host'     => null,
			'origin'   => null,
			'key'      => null,
			'version'  => null,
			'protocol' => null,
		);

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

	public function doHandshake($buffer) {
		$this->status = self::STATUS_CONNECTING;
		$this->server->log('begin handshake...');

		$headers = self::getHeaders($buffer);

		$this->origin   = $headers['origin'];
		$this->host     = $headers['host'];
		$this->resource = $headers['resource'];
		$this->key      = $headers['key'];
		$this->version  = $headers['version'];
		$this->protocol = $headers['protocol'];

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
		$this->status = self::STATUS_OPEN;
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
		if (self::STATUS_OPEN !== $this->status) {
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

	public function isEstablished() {
		return $this->handshake;
	} // getHandshake()

} // class WebSocketConnection

