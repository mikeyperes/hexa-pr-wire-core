<?php

namespace HexaPrWire\Core\Contracts;

interface Mailer {
	/** @param string[] $recipients */
	public function send( array $recipients, string $subject, string $message ): bool;
}
