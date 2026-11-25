<?php

namespace HexaPrWire\Core\Support;

/** Holds the normalized To and BCC addresses for a notification. */
final class MailRecipients {
	/** @var string[] */
	private array $to;

	/** @var string[] */
	private array $bcc;

	/** Resolves the addresses using the WordPress mailer's normalization rules. */
	public function __construct( array $to, array $bcc ) {
		$this->to = self::normalize( $to );
		$this->bcc = array_values( array_diff( self::normalize( $bcc ), $this->to ) );
	}

	/** Returns the primary recipients. @return string[] */
	public function to(): array {
		return $this->to;
	}

	/** Returns the hidden-copy recipients. @return string[] */
	public function bcc(): array {
		return $this->bcc;
	}

	/** @param string[] $emails @return string[] */
	private static function normalize( array $emails ): array {
		return array_values( array_unique( array_filter( array_map( static fn( string $email ): string => strtolower( sanitize_email( $email ) ), $emails ), 'is_email' ) ) );
	}
}
