<?php

namespace HexaPrWire\Core\Migration;

final class ParityReport {
	public const GROUP_KEYS = [
		'group_6506a8003237a',
		'group_69326f80f12ff',
		'group_64a72abaeeff0',
		'group_64a6e0e504f9e',
		'group_63a0418e58839',
	];

	public const FIELD_KEYS = [
		'field_6506a80059c58', 'field_6506a80959c59', 'field_6506a80c59c5a', 'field_6506a81059c5b',
		'field_6506a82059c5c', 'field_6506a83659c5d', 'field_6506a9496e720', 'field_6506c74c765d9',
		'field_6506ceb4af76d', 'field_650757ba0e871', 'field_6507dcf363142', 'field_6512f79098cbd',
		'field_69326f81d09e3', 'field_64a72abb01ec2', 'field_64a72abb01ef9', 'field_64a72abb01f2f',
		'field_64a72abb05373', 'field_64a72abb053df', 'field_64a72abb02037', 'field_64a6e0e61d1a5',
		'field_64a75c0ed49a2', 'field_64a75bc4d49a1', 'field_64a9c5d4cf5fb', 'field_64a75d2ceb7a1',
		'field_64a75d69eb7a2', 'field_64a772e55b5bb', 'field_64a81e947e59a', 'field_651368fa55448',
		'field_64b997104e05a', 'field_652cb84e99150', 'field_6931f38cfd6e6', 'field_6931f3d2fd6e7',
		'field_693201e4fd6e8', 'field_6933d4dc2dd6f',
	];

	/** @return array<string,mixed> */
	public function run( bool $post_migration = false ): array {
		$checks = [];
		$this->check( $checks, 'plugin_version', defined( 'HPRWC_VERSION' ) && '2.0.0' === HPRWC_VERSION, defined( 'HPRWC_VERSION' ) ? HPRWC_VERSION : 'missing' );
		$this->check( $checks, 'publication_cpt', post_type_exists( 'publication' ), post_type_exists( 'publication' ) ? 'registered' : 'missing' );
		$this->check( $checks, 'publication_taxonomy', taxonomy_exists( 'publication' ) && is_object_in_taxonomy( 'post', 'publication' ), taxonomy_exists( 'publication' ) ? 'registered' : 'missing' );
		$this->check( $checks, 'customer_role', (bool) get_role( 'hexa_pr_wire_user' ), get_role( 'hexa_pr_wire_user' ) ? 'registered' : 'missing' );

		$core = class_exists( '\\HexaPluginCorePackageRegistry' ) ? \HexaPluginCorePackageRegistry::report() : [];
		$selected_version = (string) ( $core['selected']['version'] ?? '' );
		$selected_hash = (string) ( $core['selected']['hash'] ?? '' );
		$expected_hash = is_readable( HPRWC_DIR . 'vendor/hexa/plugin-core/PACKAGE_HASH' ) ? trim( (string) file_get_contents( HPRWC_DIR . 'vendor/hexa/plugin-core/PACKAGE_HASH' ) ) : '';
		$actual_hash = class_exists( '\\HexaPluginCorePackageRegistry' ) ? \HexaPluginCorePackageRegistry::source_hash( HPRWC_DIR . 'vendor/hexa/plugin-core' ) : '';
		$this->check( $checks, 'shared_core_version', '3.0.6' === $selected_version, $selected_version ?: 'missing' );
		$this->check( $checks, 'shared_core_integrity', '' !== $expected_hash && hash_equals( $expected_hash, $actual_hash ) && hash_equals( $expected_hash, $selected_hash ), $actual_hash ?: 'missing' );
		$this->check( $checks, 'shared_core_health', ! empty( $core['healthy'] ), $core['issues'] ?? [] );

		foreach ( self::GROUP_KEYS as $key ) {
			$group = function_exists( 'acf_get_local_field_group' ) ? acf_get_local_field_group( $key ) : false;
			$this->check( $checks, 'acf_group_' . $key, is_array( $group ) && ! empty( $group['active'] ), is_array( $group ) ? 'local active' : 'missing' );
		}
		foreach ( self::FIELD_KEYS as $key ) {
			$field = function_exists( 'acf_get_local_field' ) ? acf_get_local_field( $key ) : false;
			$this->check( $checks, 'acf_field_' . $key, is_array( $field ), is_array( $field ) ? (string) ( $field['name'] ?? 'registered' ) : 'missing' );
		}

		foreach ( [ 'display_standard_releases_table', 'display_featured_standard_releases_links', 'display_new_sources', 'publication_press_links' ] as $shortcode ) {
			$this->check( $checks, 'shortcode_' . $shortcode, shortcode_exists( $shortcode ), shortcode_exists( $shortcode ) ? 'registered' : 'missing' );
		}
		global $wp_rewrite;
		$feeds = $wp_rewrite instanceof \WP_Rewrite ? (array) $wp_rewrite->feeds : [];
		foreach ( [ 'internal-rss', 'rss_scale_my_publication', 'rss_michael_peres', 'rss_publication' ] as $feed ) {
			$this->check( $checks, 'feed_' . $feed, in_array( $feed, $feeds, true ), in_array( $feed, $feeds, true ) ? 'registered' : 'missing' );
		}
		$this->check( $checks, 'secure_delivery_ajax', has_action( 'wp_ajax_hprwc_send_delivery_links' ) && has_action( 'wp_ajax_hprwc_notify_draft_update' ) && ! has_action( 'wp_ajax_nopriv_hprwc_send_delivery_links' ) && ! has_action( 'wp_ajax_nopriv_hprwc_notify_draft_update' ), 'authenticated actions only' );
		$this->check( $checks, 'publication_records', (int) ( wp_count_posts( 'publication' )->publish ?? 0 ) > 0, (int) ( wp_count_posts( 'publication' )->publish ?? 0 ) );
		$term_count = wp_count_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false ] );
		$this->check( $checks, 'publication_terms', ! is_wp_error( $term_count ) && (int) $term_count > 0, is_wp_error( $term_count ) ? $term_count->get_error_message() : (int) $term_count );

		if ( $post_migration ) {
			$legacy = ( new LegacyMigration() )->legacy_state();
			$this->check( $checks, 'legacy_snippets_disabled', 0 === count( array_filter( $legacy['snippets'], static fn( array $item ): bool => ! empty( $item['active'] ) ) ), $legacy['snippets'] );
			$this->check( $checks, 'cpt_ui_publication_disabled', empty( $legacy['cpt_ui_publication'] ), $legacy['cpt_ui_publication'] ? 'active' : 'disabled' );
			$this->check( $checks, 'acf_taxonomy_ui_disabled', empty( $legacy['acf_taxonomies_active'] ), $legacy['acf_taxonomies_active'] );
			$this->check( $checks, 'database_acf_groups_disabled', empty( $legacy['acf_groups_active'] ), $legacy['acf_groups_active'] );
		}

		$failed = array_values( array_filter( $checks, static fn( array $check ): bool => ! $check['passed'] ) );
		return [
			'passed' => [] === $failed,
			'mode' => $post_migration ? 'post-migration' : 'pre-migration',
			'checks' => $checks,
			'failed' => $failed,
		];
	}

	/** @param array<string,array{passed:bool,actual:mixed}> $checks */
	private function check( array &$checks, string $id, bool $passed, mixed $actual ): void {
		$checks[ $id ] = [ 'passed' => $passed, 'actual' => $actual ];
	}
}
