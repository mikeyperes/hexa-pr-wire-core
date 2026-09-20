<?php

namespace HexaPrWire\Core\Migration;

use HexaPrWire\Core\Support\Activity;
use RuntimeException;

final class LegacyMigration {
	public const MANIFEST_OPTION = 'hprwc_legacy_migration_manifest';
	public const DATABASE_GROUP_KEYS = [
		'group_6506a8003237a',
		'group_69326f80f12ff',
		'group_64a72abaeeff0',
		'group_64a6e0e504f9e',
		'group_63a0418e58839',
		'group_64a74dfaa21da',
		'group_65a0884cd33ba',
	];

	/** @return array<string,mixed> */
	public function plan(): array {
		$state = $this->legacy_state();
		return [
			'site' => home_url( '/' ),
			'plugin' => 'hexa-pr-wire-core',
			'data_owner' => 'Code Snippets, CPT UI, and ACF UI',
			'snippet_ids' => array_keys( $state['snippets'] ),
			'active_snippet_ids' => array_values( array_map( 'intval', array_keys( array_filter( $state['snippets'], static fn( array $item ): bool => ! empty( $item['active'] ) ) ) ) ),
			'cpt_ui_publication' => $state['cpt_ui_publication'],
			'acf_taxonomies_active' => $state['acf_taxonomies_active'],
			'acf_groups_active' => $state['acf_groups_active'],
			'record_selector_sha256' => hash( 'sha256', wp_json_encode( [ StatusReport::MIGRATION_SNIPPETS, StatusReport::SUPERSEDED_SNIPPETS, self::DATABASE_GROUP_KEYS, 'publication' ] ) ),
			'before_sha256' => hash( 'sha256', wp_json_encode( $state ) ),
			'after_sha256' => hash( 'sha256', wp_json_encode( $this->expected_after_state( $state ) ) ),
		];
	}

	/** @return array<string,mixed> */
	public function migrate(): array {
		$parity = ( new ParityReport() )->run( false );
		if ( empty( $parity['passed'] ) ) {
			throw new RuntimeException( 'Pre-migration parity failed; no legacy records were changed.' );
		}
		$existing = get_option( self::MANIFEST_OPTION, [] );
		if ( is_array( $existing ) && 'applied' === ( $existing['status'] ?? '' ) ) {
			return $existing;
		}

		$state = $this->legacy_state();
		if ( is_array( $existing ) && 'prepared' === ( $existing['status'] ?? '' ) && is_array( $existing['before'] ?? null ) ) {
			$manifest = $existing;
		} else {
			$manifest = [
				'version' => HPRWC_VERSION,
				'site' => home_url( '/' ),
				'created_gmt' => gmdate( 'c' ),
				'status' => 'prepared',
				'before' => $state,
				'before_sha256' => hash( 'sha256', wp_json_encode( $state ) ),
			];
			update_option( self::MANIFEST_OPTION, $manifest, false );
		}

		try {
			foreach ( array_keys( $state['snippets'] ) as $snippet_id ) {
				$this->set_snippet_active( (int) $snippet_id, false );
			}
			$cptui = get_option( 'cptui_post_types', [] );
			if ( is_array( $cptui ) && array_key_exists( 'publication', $cptui ) ) {
				unset( $cptui['publication'] );
				update_option( 'cptui_post_types', $cptui, false );
			}
			foreach ( $state['acf_taxonomies'] as $item ) {
				if ( 'publish' === $item['status'] ) {
					$this->disable_acf_post( (int) $item['ID'], 'acf-taxonomy' );
				}
			}
			foreach ( $state['acf_groups'] as $item ) {
				if ( 'publish' === $item['status'] ) {
					$this->disable_acf_post( (int) $item['ID'], 'acf-field-group' );
				}
			}
			flush_rewrite_rules( false );
			$after = $this->legacy_state();
			$expected = $this->expected_after_state( $state );
			if ( hash( 'sha256', wp_json_encode( $expected ) ) !== hash( 'sha256', wp_json_encode( $after ) ) ) {
				throw new RuntimeException( 'Legacy state did not match the expected disabled state.' );
			}
			$manifest['status'] = 'applied';
			$manifest['applied_version'] = HPRWC_VERSION;
			$manifest['applied_gmt'] = gmdate( 'c' );
			$manifest['after'] = $after;
			$manifest['after_sha256'] = hash( 'sha256', wp_json_encode( $after ) );
			update_option( self::MANIFEST_OPTION, $manifest, false );
			Activity::add( 'Legacy snippets and UI definitions disabled after Core parity.', 'success', [ 'manifest' => $manifest['after_sha256'] ], 'migration' );
			return $manifest;
		} catch ( \Throwable $throwable ) {
			try {
				$this->restore( $manifest );
				$manifest['status'] = 'rolled_back_after_failure';
				$manifest['error'] = $throwable->getMessage();
				update_option( self::MANIFEST_OPTION, $manifest, false );
				throw $throwable;
			} catch ( \Throwable $rollback_error ) {
				if ( $rollback_error === $throwable ) {
					throw $throwable;
				}
				$manifest['status'] = 'rollback_failed';
				$manifest['error'] = $throwable->getMessage();
				$manifest['rollback_error'] = $rollback_error->getMessage();
				update_option( self::MANIFEST_OPTION, $manifest, false );
				throw new RuntimeException( $throwable->getMessage() . ' Rollback also failed: ' . $rollback_error->getMessage(), 0, $throwable );
			}
		}
	}

	/** @return array<string,mixed> */
	public function rollback(): array {
		$manifest = get_option( self::MANIFEST_OPTION, [] );
		if ( ! is_array( $manifest ) || empty( $manifest['before'] ) ) {
			throw new RuntimeException( 'No migration rollback manifest exists.' );
		}
		$this->restore( $manifest );
		$manifest['status'] = 'rolled_back';
		$manifest['rolled_back_gmt'] = gmdate( 'c' );
		update_option( self::MANIFEST_OPTION, $manifest, false );
		Activity::add( 'Legacy migration rolled back from its stored manifest.', 'warning', [], 'migration' );
		return $manifest;
	}

	/** @return array<string,mixed> */
	public function legacy_state(): array {
		global $wpdb;
		$ids = array_values( array_unique( array_merge( StatusReport::MIGRATION_SNIPPETS, StatusReport::SUPERSEDED_SNIPPETS ) ) );
		$table = $wpdb->prefix . 'snippets';
		$snippets = [];
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,name,active FROM {$table} WHERE id IN ({$placeholders}) ORDER BY id", ...$ids ) );
			foreach ( (array) $rows as $row ) {
				$snippets[ (int) $row->id ] = [ 'name' => (string) $row->name, 'active' => (bool) $row->active ];
			}
		}
		$cptui = get_option( 'cptui_post_types', [] );
		$cptui_publication = is_array( $cptui ) && isset( $cptui['publication'] ) ? $cptui['publication'] : [];
		$acf_taxonomies = $this->acf_taxonomies();
		$acf_groups = $this->acf_groups();
		return [
			'snippets' => $snippets,
			'cpt_ui_publication' => $cptui_publication,
			'acf_taxonomies' => $acf_taxonomies,
			'acf_taxonomies_active' => array_values( array_filter( $acf_taxonomies, static fn( array $item ): bool => 'publish' === $item['status'] ) ),
			'acf_groups' => $acf_groups,
			'acf_groups_active' => array_values( array_filter( $acf_groups, static fn( array $item ): bool => 'publish' === $item['status'] ) ),
		];
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function expected_after_state( array $state ): array {
		$after = $state;
		foreach ( $after['snippets'] as &$snippet ) {
			$snippet['active'] = false;
		}
		unset( $snippet );
		$after['cpt_ui_publication'] = [];
		foreach ( $after['acf_taxonomies'] as &$taxonomy ) {
			if ( 'publish' === $taxonomy['status'] ) {
				$taxonomy['status'] = 'acf-disabled';
			}
		}
		unset( $taxonomy );
		foreach ( $after['acf_groups'] as &$group ) {
			if ( 'publish' === $group['status'] ) {
				$group['status'] = 'acf-disabled';
			}
		}
		unset( $group );
		$after['acf_taxonomies_active'] = [];
		$after['acf_groups_active'] = [];
		return $after;
	}

	/** @param array<string,mixed> $manifest */
	private function restore( array $manifest ): void {
		$before = $manifest['before'] ?? [];
		foreach ( (array) ( $before['snippets'] ?? [] ) as $id => $snippet ) {
			$this->set_snippet_active( (int) $id, ! empty( $snippet['active'] ) );
		}
		$cptui = get_option( 'cptui_post_types', [] );
		$cptui = is_array( $cptui ) ? $cptui : [];
		if ( ! empty( $before['cpt_ui_publication'] ) ) {
			$cptui['publication'] = $before['cpt_ui_publication'];
		} else {
			unset( $cptui['publication'] );
		}
		update_option( 'cptui_post_types', $cptui, false );
		foreach ( [ 'acf_taxonomies', 'acf_groups' ] as $key ) {
			foreach ( (array) ( $before[ $key ] ?? [] ) as $item ) {
				$post_type = 'acf_taxonomies' === $key ? 'acf-taxonomy' : 'acf-field-group';
				$this->set_acf_status( (int) $item['ID'], $post_type, (string) $item['status'] );
			}
		}
		flush_rewrite_rules( false );
	}

	private function set_snippet_active( int $id, bool $active ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'snippets';
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$table} WHERE id=%d", $id ) );
		if ( null === $current || (bool) $current === $active ) {
			return;
		}
		if ( ! $active && function_exists( 'Code_Snippets\\deactivate_snippet' ) ) {
			\Code_Snippets\deactivate_snippet( $id );
		} else {
			$updated = $wpdb->update( $table, [ 'active' => $active ? 1 : 0 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
			if ( false === $updated ) {
				throw new RuntimeException( 'Could not update Code Snippet ' . $id . ' during rollback.' );
			}
			if ( function_exists( 'Code_Snippets\\clean_snippets_cache' ) ) {
				\Code_Snippets\clean_snippets_cache( $table );
			}
		}
		$verified = $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$table} WHERE id=%d", $id ) );
		if ( (bool) $verified !== $active ) {
			throw new RuntimeException( 'Could not change Code Snippet ' . $id . ' to the requested state.' );
		}
	}

	private function set_acf_status( int $post_id, string $post_type, string $status ): void {
		if ( in_array( $status, [ 'publish', 'acf-disabled' ], true ) ) {
			$activate = 'publish' === $status;
			if ( 'acf-taxonomy' === $post_type && function_exists( 'acf_update_taxonomy_active_status' ) ) {
				$result = acf_update_taxonomy_active_status( $post_id, $activate );
			} elseif ( 'acf-field-group' === $post_type && function_exists( 'acf_update_field_group_active_status' ) ) {
				$result = acf_update_field_group_active_status( $post_id, $activate );
			} else {
				throw new RuntimeException( 'The supported ACF status interface is unavailable for ' . $post_type . '.' );
			}
		} else {
			$result = wp_update_post( [ 'ID' => $post_id, 'post_status' => sanitize_key( $status ) ], true );
		}
		if ( ! $result || is_wp_error( $result ) || $status !== get_post_status( $post_id ) ) {
			throw new RuntimeException( 'Could not set legacy UI record ' . $post_id . ' to ' . $status . '.' );
		}
	}

	private function disable_acf_post( int $post_id, string $post_type ): void {
		if ( 'acf-taxonomy' === $post_type && function_exists( 'acf_update_taxonomy_active_status' ) ) {
			$result = acf_update_taxonomy_active_status( $post_id, false );
		} elseif ( 'acf-field-group' === $post_type && function_exists( 'acf_update_field_group_active_status' ) ) {
			$result = acf_update_field_group_active_status( $post_id, false );
		} else {
			throw new RuntimeException( 'The supported ACF deactivation interface is unavailable for ' . $post_type . '.' );
		}
		if ( ! $result || 'acf-disabled' !== get_post_status( $post_id ) ) {
			throw new RuntimeException( 'Could not disable legacy ACF record ' . $post_id . '.' );
		}
	}

	/** @return array<int,array{ID:int,status:string,title:string,key:string}> */
	private function acf_groups(): array {
		$posts = get_posts( [ 'post_type' => 'acf-field-group', 'post_status' => [ 'publish', 'acf-disabled', 'draft', 'trash', 'private' ], 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
		$items = [];
		foreach ( $posts as $post ) {
			$key = preg_replace( '/__trashed$/', '', (string) $post->post_name );
			if ( ! in_array( $key, self::DATABASE_GROUP_KEYS, true ) ) {
				continue;
			}
			$items[] = [ 'ID' => (int) $post->ID, 'status' => (string) $post->post_status, 'title' => (string) $post->post_title, 'key' => $key ];
		}
		return $items;
	}

	/** @return array<int,array{ID:int,status:string,title:string,key:string}> */
	private function acf_taxonomies(): array {
		$posts = get_posts( [ 'post_type' => 'acf-taxonomy', 'post_status' => [ 'publish', 'acf-disabled', 'draft', 'trash', 'private' ], 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ] );
		$items = [];
		foreach ( $posts as $post ) {
			$content = maybe_unserialize( $post->post_content );
			if ( ! is_array( $content ) || 'publication' !== (string) ( $content['taxonomy'] ?? '' ) ) {
				continue;
			}
			$items[] = [ 'ID' => (int) $post->ID, 'status' => (string) $post->post_status, 'title' => (string) $post->post_title, 'key' => (string) $post->post_name ];
		}
		return $items;
	}
}
