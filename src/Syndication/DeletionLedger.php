<?php

namespace HexaPrWire\Core\Syndication;

/**
 * Releases removed from hexaprwire.com, keyed by their source ID (`post:<ID>`),
 * the same ID every outlet stores in `_hpr_source_id`.
 */
final class DeletionLedger {
	public const OPTION = 'hprwc_deleted_releases';
	private const LIMIT = 2000;

	/** @param array<int,string> $hosts */
	public static function record( \WP_Post $post, array $hosts ): void {
		$ledger = self::all();
		$id = 'post:' . $post->ID;
		$ledger[ $id ] = [
			'id'          => $id,
			'slug'        => (string) $post->post_name,
			'title'       => get_the_title( $post ),
			'deleted_gmt' => gmdate( 'c' ),
			'hosts'       => array_values( array_unique( $hosts ) ),
			'results'     => [],
		];
		if ( count( $ledger ) > self::LIMIT ) {
			$ledger = array_slice( $ledger, -self::LIMIT, null, true );
		}
		update_option( self::OPTION, $ledger, false );
	}

	public static function forget( int $post_id ): void {
		$ledger = self::all();
		if ( isset( $ledger[ 'post:' . $post_id ] ) ) {
			unset( $ledger[ 'post:' . $post_id ] );
			update_option( self::OPTION, $ledger, false );
		}
	}

	/** @param array{ok:bool,status:int,message:string} $result */
	public static function result( int $post_id, string $host, array $result ): void {
		$ledger = self::all();
		if ( isset( $ledger[ 'post:' . $post_id ] ) ) {
			$ledger[ 'post:' . $post_id ]['results'][ $host ] = $result + [ 'time_gmt' => gmdate( 'c' ) ];
			update_option( self::OPTION, $ledger, false );
		}
	}

	/** @return array<string,mixed>|null */
	public static function get( int $post_id ): ?array {
		return self::all()[ 'post:' . $post_id ] ?? null;
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$ledger = get_option( self::OPTION, [] );
		return is_array( $ledger ) ? $ledger : [];
	}
}
