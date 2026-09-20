<?php

namespace HexaPrWire\Core\Customer;

final class SubmissionMode {
	public const EDIT_EXISTING = 'edit_existing';
	public const CREATE_PENDING = 'create_pending';
	public const CREATE_PUBLISH = 'create_publish';
	public const FULL_ACCESS = 'full_access';

	/** @return array<string,string> */
	public static function choices(): array {
		return [
			self::EDIT_EXISTING => 'Edit existing releases only',
			self::CREATE_PENDING => 'Create releases — pending review',
			self::CREATE_PUBLISH => 'Create and publish',
			self::FULL_ACCESS => 'Full release access',
		];
	}

	public static function normalize( mixed $value ): string {
		$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		return array_key_exists( $value, self::choices() ) ? $value : self::EDIT_EXISTING;
	}

	public static function can_create( string $mode ): bool {
		return in_array( self::normalize( $mode ), [ self::CREATE_PENDING, self::CREATE_PUBLISH, self::FULL_ACCESS ], true );
	}

	public static function can_publish( string $mode ): bool {
		return in_array( self::normalize( $mode ), [ self::CREATE_PUBLISH, self::FULL_ACCESS ], true );
	}

	public static function is_full( string $mode ): bool {
		return self::FULL_ACCESS === self::normalize( $mode );
	}
}
