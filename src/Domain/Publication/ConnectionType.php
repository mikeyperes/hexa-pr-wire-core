<?php

namespace HexaPrWire\Core\Domain\Publication;

/** How releases reach a publication, stored in its `connection_type` field. */
final class ConnectionType {
	public const LABELS = [
		'managed'  => 'Managed (Distributor)',
		'rss'      => 'RSS pull (Echo)',
		'external' => 'External',
		'premium'  => 'Premium',
	];

	/** Stored type, falling back to the premium tier; '' when not set. */
	public static function of( array $publication ): string {
		$type = sanitize_key( (string) get_post_meta( (int) $publication['id'], 'connection_type', true ) );
		if ( isset( self::LABELS[ $type ] ) ) {
			return $type;
		}
		return 'premium' === ( $publication['product_tier'] ?? '' ) ? 'premium' : '';
	}
}
