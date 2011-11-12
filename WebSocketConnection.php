<?php

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

