<?php

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

		return pack('CC', $b[0], $b[1]) . $this->data;
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

	public function setOpcode($opcode) {
		if (! isset(self::$opcode_labels[$opcode])) {
			throw new UnexpectedValueException('Unrecognised opcode ' . sprintf('0x%x', $opcode));
		}
		$this->opcode = $opcode;
	} // setOpcode()

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

} // class WebSocketFrame

