<?php

namespace HexaPrWire\Core\Domain\Publication;

/** How a publication's site is reached, stored in its `connection_type` field. */
final class ConnectionType {
	public const LABELS = [
		'internal' => 'Internal',
		'rest'     => 'REST',
		'plugin'   => 'Plugin',
		'premium'  => 'Premium',
	];

	public const DESCRIPTIONS = [
		'internal' => 'Our own server: Distributor plugin, plus full server access.',
		'rest'     => 'Partner site reached over the WordPress REST API with an administrator Application Password and the Distributor plugin.',
		'plugin'   => 'Reached only through the Distributor plugin and the Hexa PR Wire token.',
		'premium'  => 'Premium placement delivered by hand; no plugin connection.',
	];

	/** Stored type, falling back to the premium tier; '' when not set. */
	public static function of( array $publication ): string {
		$type = sanitize_key( (string) get_post_meta( (int) $publication['id'], 'connection_type', true ) );
		if ( isset( self::LABELS[ $type ] ) ) {
			return $type;
		}
		return 'premium' === ( $publication['product_tier'] ?? '' ) ? 'premium' : '';
	}

	/** Whether the site runs Distributor and can be checked and updated remotely. */
	public static function is_connected( string $type ): bool {
		return in_array( $type, [ 'internal', 'rest', 'plugin' ], true );
	}
}
