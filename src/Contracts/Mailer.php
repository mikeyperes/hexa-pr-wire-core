<?php

namespace HexaPrWire\Core\Contracts;

interface Mailer {
	/**
	 * @param string[] $recipients
	 * @param string[] $bcc
	 */
	public function send( array $recipients, string $subject, string $message, array $bcc = [] ): bool;
}
