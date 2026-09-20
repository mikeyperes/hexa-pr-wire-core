<?php

namespace HexaPrWire\Core\Migration;

final class StatusReport {
	public const MIGRATION_SNIPPETS = [ 6, 8, 10, 17, 18, 20, 22, 25, 27, 29, 32, 35, 36, 37, 38, 47, 48, 49, 51 ];
	public const SUPERSEDED_SNIPPETS = [ 26, 30, 34, 43, 44, 52 ];

	/** @return array<string,mixed> */
	public function all(): array {
		$publication_posts = wp_count_posts( 'publication' );
		$publication_terms = wp_count_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false ] );
		return [
			'plugin_version' => HPRWC_VERSION,
			'core_package' => class_exists( '\\HexaPluginCorePackageRegistry' ) ? \HexaPluginCorePackageRegistry::report() : [],
			'publication_cpt' => post_type_exists( 'publication' ),
			'publication_taxonomy' => taxonomy_exists( 'publication' ),
			'publication_records' => is_object( $publication_posts ) ? (int) ( $publication_posts->publish ?? 0 ) : 0,
			'publication_terms' => is_wp_error( $publication_terms ) ? 0 : (int) $publication_terms,
			'acf_groups' => $this->acf_groups(),
			'snippets' => $this->snippets(),
			'migration_manifest' => get_option( LegacyMigration::MANIFEST_OPTION, [] ),
			'legacy_ui' => [
				'theme_options_group_trashed' => 'trash' === get_post_status( 308417 ),
				'website_settings_enabled' => '1' === (string) get_option( 'register_acf_website_settings', '' ),
			],
		];
	}

	/** @return array<string,bool> */
	private function acf_groups(): array {
		$groups = [];
		foreach ( [ 'group_6506a8003237a', 'group_69326f80f12ff', 'group_64a72abaeeff0', 'group_64a6e0e504f9e', 'group_63a0418e58839' ] as $key ) {
			$local = function_exists( 'acf_get_local_field_group' ) ? acf_get_local_field_group( $key ) : false;
			$groups[ $key ] = is_array( $local ) && ! empty( $local['active'] );
		}
		return $groups;
	}

	/** @return array<int,array<string,mixed>> */
	private function snippets(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'snippets';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return [];
		}
		$ids = array_merge( self::MIGRATION_SNIPPETS, self::SUPERSEDED_SNIPPETS );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$query = $wpdb->prepare( "SELECT id,name,active FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id", ...$ids );
		return array_map( static fn( object $row ): array => [ 'id' => (int) $row->id, 'name' => (string) $row->name, 'active' => (bool) $row->active ], (array) $wpdb->get_results( $query ) );
	}
}
