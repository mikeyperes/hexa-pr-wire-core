<?php

namespace HexaPrWire\Core\Contracts;

use HexaPrWire\Core\Support\MailRecipients;

interface Mailer {
	/**
	 * Sends a notification to raw addresses or an already resolved recipient list.
	 *
	 * @param string[]|MailRecipients $recipients
	 * @param string[] $bcc Raw BCC addresses when the recipients are passed as an array.
	 */
	public function send( array|MailRecipients $recipients, string $subject, string $message, array $bcc = [] ): bool;
}
