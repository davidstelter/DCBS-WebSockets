<?php

class StatusApp implements iWebSocketApp {

	private $conn = null;

	public function onMessage($msg) {
		switch ($msg) {
			case 'pid':
				$this->conn->send('pid: ' . getmypid());
				break;

			case 'mem':
				$mem = memory_get_usage(true);
				$mb = $mem / pow(2,20);
				$this->conn->send("{$mb}MB");
				break;

			case 'tail':
				$this->doTail();
				break;

			default:
				$this->conn->send("unknown command '$msg'");
				break;
		}
		echo "StatusApp got message '$msg'\n";
	}

	public function doTail() {
		if (! $f = fopen(dirname(__FILE__).'/foo.txt', 'r')) {
			$this->conn->send("Failed to open file");
			return false;
		}

		while (true) {
			if (false !== ($string = fread($f, 125))) {
				if ($string) {
					$this->conn->send($string);
				} else {
					sleep(1);
				}
			}
		}

	}

	public function onError($error) {
		echo "error!\n";
	}

	public function onClose($data) {
		echo "StatusApp shutdown for client {$this->conn->getAddressString()}\n";
	} // onClose()

	public function __construct(WebSocketConnection $conn) {
		$this->conn = $conn;
		echo "StatusApp instantiated for host {$conn->getHost()}\n";
	}

} // StatusApp{}

