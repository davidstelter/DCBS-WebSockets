<?php

interface iWebSocketApp {
	public function onMessage($data);
	public function onClose($data);
	public function onError($data);
}

