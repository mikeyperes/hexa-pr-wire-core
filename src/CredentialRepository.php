<?php

namespace HexaPrWire\Core;

use Hexa\PluginCore\CredentialVault\CredentialStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CredentialRepository {
	private const SLUG = 'hexa-pr-wire-core';

	private const KEY = 'force_sync_token';

	private const MIGRATED_OPTION = 'hprwc_force_sync_credential_migrated';

	public function is_store_available(): bool {
		return class_exists( CredentialStore::class )
			&& function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' );
	}

	public function exists(): bool {
		return '' !== $this->get();
	}

	public function get(): string {
		if ( ! $this->is_store_available() ) {
			return '';
		}

		return trim( (string) ( new CredentialStore() )->get( self::SLUG, self::KEY ) );
	}

	public function store( string $credential ): bool {
		$credential = trim( $credential );
		if ( '' === $credential || ! $this->is_store_available() ) {
			return false;
		}

		$store = new CredentialStore();
		$store->store( self::SLUG, self::KEY, $credential );
		$readback = (string) $store->get( self::SLUG, self::KEY );

		return '' !== $readback && hash_equals( $credential, $readback );
	}

	/**
	 * One-time migration from the legacy source snippet/distributor option.
	 *
	 * The value is never printed, logged, placed in a URL, or stored unencrypted.
	 */
	public function migrate_legacy_credential(): bool {
		if ( $this->exists() ) {
			return true;
		}

		if ( ! $this->is_store_available() ) {
			return false;
		}

		$legacy_settings = get_option( 'hpr_force_sync_settings', [] );
		$candidate       = is_array( $legacy_settings ) ? trim( (string) ( $legacy_settings['secret_token'] ?? '' ) ) : '';

		if ( '' !== $candidate && $this->store( $candidate ) ) {
			update_option( self::MIGRATED_OPTION, gmdate( 'c' ), false );
			return true;
		}

		return false;
	}
}
