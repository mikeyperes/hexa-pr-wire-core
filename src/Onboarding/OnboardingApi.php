<?php

namespace HexaPrWire\Core\Onboarding;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\PublicationResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Administrator-only source contract for one immutable outlet onboarding run. */
final class OnboardingApi implements Module {
	public const CONTRACT_VERSION = '1.0';

	private const ROUTE_NAMESPACE = 'hprwc/v1';
	private const OPERATIONS_OPTION = 'hprwc_onboarding_operations';
	private const MAX_OPERATIONS = 100;
	private const PUBLICATION_FIELDS = [
		'status',
		'url_nice',
		'url_press_release_prefix',
		'product_tier',
		'featured',
		'icon',
		'new_source',
		'url',
		'_thumbnail_id',
	];

	public function __construct( private DestinationRegistry $destinations ) {}

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/onboarding',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'inspect' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/onboarding/outlet',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'apply' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
		register_rest_route(
			self::ROUTE_NAMESPACE,
			'/onboarding/rollback',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rollback' ],
				'permission_callback' => [ $this, 'authorize' ],
			]
		);
	}

	public function authorize(): bool {
		return current_user_can( 'manage_options' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function inspect( \WP_REST_Request $request ) {
		$operation_id = sanitize_text_field( (string) $request->get_param( 'operation_id' ) );
		if ( '' !== $operation_id ) {
			$valid = $this->validate_operation_id( $operation_id );
			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
			$operations = $this->operations();
			if ( ! isset( $operations[ $operation_id ] ) ) {
				return new \WP_Error( 'hprwc_operation_not_found', 'The onboarding operation was not found.', [ 'status' => 404 ] );
			}

			return $this->contract() + [ 'operation' => $this->public_operation( $operations[ $operation_id ] ) ];
		}

		$origin = $this->normalize_origin( (string) $request->get_param( 'canonical_origin' ) );
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( is_wp_error( $origin ) ) {
			return $origin;
		}

		$hierarchy = $this->hierarchy();
		if ( is_wp_error( $hierarchy ) ) {
			return $hierarchy;
		}
		$matches = $this->find_matches( $slug, $origin );

		return $this->contract() + [
			'hierarchy_revision' => $this->hierarchy_revision( $hierarchy ),
			'hierarchy'          => $hierarchy,
			'top_level_available'=> true,
			'match_count'        => count( $matches ),
			'matches'            => $matches,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function apply( \WP_REST_Request $request ) {
		$payload = $this->payload( $request );
		$safe = $this->reject_sensitive_payload( $payload );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$operation_id = sanitize_text_field( (string) ( $payload['operation_id'] ?? '' ) );
		$valid = $this->validate_operation_id( $operation_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$plan_fingerprint = strtolower( sanitize_text_field( (string) ( $payload['plan_fingerprint'] ?? '' ) ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $plan_fingerprint ) ) {
			return new \WP_Error( 'hprwc_invalid_plan_fingerprint', 'A SHA-256 plan_fingerprint is required.', [ 'status' => 422 ] );
		}

		$operations = $this->operations();
		if ( isset( $operations[ $operation_id ] ) ) {
			$operation = $operations[ $operation_id ];
			if ( ! hash_equals( (string) ( $operation['plan_fingerprint'] ?? '' ), $plan_fingerprint ) ) {
				return new \WP_Error( 'hprwc_operation_conflict', 'The operation ID is already bound to another plan.', [ 'status' => 409 ] );
			}
			if ( 'applied' === (string) ( $operation['status'] ?? '' ) ) {
				return [ 'success' => true, 'idempotent' => true, 'operation' => $this->public_operation( $operation ), 'readback' => $operation['result'] ];
			}

			return new \WP_Error( 'hprwc_operation_requires_reconciliation', 'The operation is not safely replayable. Inspect or roll back the recorded operation.', [ 'status' => 409, 'operation' => $this->public_operation( $operation ) ] );
		}

		$display_name = sanitize_text_field( (string) ( $payload['display_name'] ?? '' ) );
		$slug = sanitize_title( (string) ( $payload['slug'] ?? '' ) );
		$origin = $this->normalize_origin( (string) ( $payload['canonical_origin'] ?? '' ) );
		if ( '' === $display_name || '' === $slug || is_wp_error( $origin ) ) {
			return is_wp_error( $origin ) ? $origin : new \WP_Error( 'hprwc_invalid_outlet', 'Display name, lowercase outlet slug and canonical HTTPS origin are required.', [ 'status' => 422 ] );
		}
		if ( $slug !== (string) ( $payload['slug'] ?? '' ) ) {
			return new \WP_Error( 'hprwc_invalid_slug', 'The outlet slug must already be normalized lowercase hyphen-case.', [ 'status' => 422 ] );
		}

		$hierarchy = $this->hierarchy();
		if ( is_wp_error( $hierarchy ) ) {
			return $hierarchy;
		}
		$reviewed_revision = sanitize_text_field( (string) ( $payload['hierarchy_revision'] ?? '' ) );
		$current_revision = $this->hierarchy_revision( $hierarchy );
		if ( '' === $reviewed_revision || ! hash_equals( $current_revision, $reviewed_revision ) ) {
			return new \WP_Error( 'hprwc_hierarchy_changed', 'The publication hierarchy changed after review. Build a new plan from the current hierarchy.', [ 'status' => 409, 'hierarchy_revision' => $current_revision ] );
		}

		$placement = $this->placement( $payload, $hierarchy );
		if ( is_wp_error( $placement ) ) {
			return $placement;
		}
		$logo = $this->attachment( absint( $payload['logo_attachment_id'] ?? 0 ), 'logo' );
		$icon = $this->attachment( absint( $payload['icon_attachment_id'] ?? 0 ), 'icon' );
		if ( is_wp_error( $logo ) || is_wp_error( $icon ) ) {
			return is_wp_error( $logo ) ? $logo : $icon;
		}

		$prefix = $this->normalize_prefix( (string) ( $payload['press_release_url_prefix'] ?? trailingslashit( $origin ) . 'press-release/' ), $origin );
		if ( is_wp_error( $prefix ) ) {
			return $prefix;
		}
		$contract_version = sanitize_text_field( (string) ( $payload['press_release_contract_version'] ?? '' ) );
		if ( '1.0' !== $contract_version ) {
			return new \WP_Error( 'hprwc_press_contract_mismatch', 'The supported press-release contract version is 1.0.', [ 'status' => 409 ] );
		}

		$matches = $this->find_matches( $slug, $origin );
		if ( count( $matches ) > 1 ) {
			return new \WP_Error( 'hprwc_outlet_conflict', 'Multiple source publication records match this outlet.', [ 'status' => 409, 'matches' => $matches ] );
		}
		$publication_id = (int) ( $matches[0]['publication_id'] ?? 0 );
		$term = $this->resolve_term( $slug, $publication_id );
		if ( is_wp_error( $term ) ) {
			return $term;
		}

		$before = [
			'publication' => $this->snapshot_publication( $publication_id ),
			'term'        => $this->snapshot_term( $term instanceof \WP_Term ? (int) $term->term_id : 0 ),
			'destination' => $publication_id > 0 ? $this->destinations->entry( $publication_id ) : null,
		];
		$operation = [
			'operation_id'       => $operation_id,
			'plan_fingerprint'   => $plan_fingerprint,
			'status'             => 'applying',
			'reviewed_hierarchy_revision' => $reviewed_revision,
			'parent_path'        => $placement['parent_path'],
			'before'             => $before,
			'resources'          => [ 'publication_id' => $publication_id, 'term_id' => $term instanceof \WP_Term ? (int) $term->term_id : 0 ],
			'applied_by'         => get_current_user_id(),
			'updated_gmt'        => gmdate( 'c' ),
		];
		$operations[ $operation_id ] = $operation;
		$this->save_operations( $operations );

		if ( $publication_id <= 0 ) {
			$inserted = wp_insert_post(
				[
					'post_type'    => PublicationResolver::POST_TYPE,
					'post_status'  => 'publish',
					'post_title'   => $display_name,
					'post_name'    => $slug,
					'post_content' => '',
				],
				true
			);
			if ( is_wp_error( $inserted ) ) {
				return $this->fail_operation( $operation_id, $inserted );
			}
			$publication_id = (int) $inserted;
			$operation['resources']['publication_id'] = $publication_id;
			$operation['resources']['publication_created'] = true;
			$this->store_operation( $operation_id, $operation );
		} else {
			$updated = wp_update_post(
				[
					'ID'          => $publication_id,
					'post_title'  => $display_name,
					'post_name'   => $slug,
					'post_status' => 'publish',
				],
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $this->fail_operation( $operation_id, $updated );
			}
		}

		if ( ! $term instanceof \WP_Term ) {
			$inserted = wp_insert_term( $display_name, PublicationResolver::TAXONOMY, [ 'slug' => $slug, 'parent' => $placement['parent_term_id'] ] );
			if ( is_wp_error( $inserted ) ) {
				return $this->fail_operation( $operation_id, $inserted );
			}
			$term_id = (int) $inserted['term_id'];
			$operation['resources']['term_id'] = $term_id;
			$operation['resources']['term_created'] = true;
			$this->store_operation( $operation_id, $operation );
		} else {
			$term_id = (int) $term->term_id;
			$updated = wp_update_term( $term_id, PublicationResolver::TAXONOMY, [ 'name' => $display_name, 'slug' => $slug, 'parent' => $placement['parent_term_id'] ] );
			if ( is_wp_error( $updated ) ) {
				return $this->fail_operation( $operation_id, $updated );
			}
		}

		$fields = [
			'status'                   => 1,
			'url_nice'                 => $display_name,
			'url_press_release_prefix' => $prefix,
			'product_tier'             => 'standard',
			'featured'                 => 1,
			'icon'                     => $icon['attachment_id'],
			'new_source'               => 1,
			'url'                      => $origin,
			'_thumbnail_id'            => $logo['attachment_id'],
		];
		foreach ( $fields as $key => $value ) {
			update_post_meta( $publication_id, $key, $value );
			if ( function_exists( 'update_field' ) && ! str_starts_with( $key, '_' ) ) {
				update_field( $key, $value, $publication_id );
			}
		}
		update_term_meta( $term_id, 'publication', $publication_id );
		if ( function_exists( 'update_field' ) ) {
			update_field( 'publication', $publication_id, PublicationResolver::TAXONOMY . '_' . $term_id );
		}

		$approved = $this->destinations->approve( $publication_id, (string) wp_parse_url( $origin, PHP_URL_HOST ) );
		if ( false === $approved ) {
			return $this->fail_operation( $operation_id, new \WP_Error( 'hprwc_destination_approval_failed', 'The exact destination host could not be approved.', [ 'status' => 500 ] ) );
		}

		$result = $this->readback(
			$publication_id,
			$term_id,
			$placement['parent_path'],
			$reviewed_revision,
			$contract_version,
			$logo,
			$icon
		);
		$operation['status'] = 'applied';
		$operation['receipt_id'] = 'hprwc-source-' . substr( hash( 'sha256', $operation_id . ':' . $plan_fingerprint ), 0, 24 );
		$operation['result'] = $result;
		$operation['updated_gmt'] = gmdate( 'c' );
		$this->store_operation( $operation_id, $operation );

		return [
			'success'    => true,
			'idempotent' => false,
			'operation'  => $this->public_operation( $operation ),
			'readback'   => $result,
		];
	}

	/** @return array<string,mixed>|\WP_Error */
	public function rollback( \WP_REST_Request $request ) {
		$payload = $this->payload( $request );
		$safe = $this->reject_sensitive_payload( $payload );
		if ( is_wp_error( $safe ) ) {
			return $safe;
		}
		$operation_id = sanitize_text_field( (string) ( $payload['operation_id'] ?? '' ) );
		$valid = $this->validate_operation_id( $operation_id );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$operations = $this->operations();
		if ( ! isset( $operations[ $operation_id ] ) ) {
			return new \WP_Error( 'hprwc_operation_not_found', 'The onboarding operation was not found.', [ 'status' => 404 ] );
		}
		$operation = $operations[ $operation_id ];
		if ( 'rolled_back' === (string) ( $operation['status'] ?? '' ) ) {
			return [ 'success' => true, 'idempotent' => true, 'operation' => $this->public_operation( $operation ) ];
		}

		$plan_fingerprint = strtolower( sanitize_text_field( (string) ( $payload['plan_fingerprint'] ?? '' ) ) );
		$receipt_id = sanitize_text_field( (string) ( $payload['receipt_id'] ?? '' ) );
		if ( ! hash_equals( (string) ( $operation['plan_fingerprint'] ?? '' ), $plan_fingerprint )
			|| ! hash_equals( (string) ( $operation['receipt_id'] ?? '' ), $receipt_id ) ) {
			return new \WP_Error( 'hprwc_rollback_binding_mismatch', 'The rollback request does not match the recorded plan and source receipt.', [ 'status' => 409 ] );
		}

		$before = is_array( $operation['before'] ?? null ) ? $operation['before'] : [];
		$resources = is_array( $operation['resources'] ?? null ) ? $operation['resources'] : [];
		$publication_id = absint( $resources['publication_id'] ?? 0 );
		$term_id = absint( $resources['term_id'] ?? 0 );

		if ( ! empty( $resources['term_created'] ) ) {
			$deleted = wp_delete_term( $term_id, PublicationResolver::TAXONOMY );
			if ( is_wp_error( $deleted ) || false === $deleted ) {
				return $this->rollback_failure( $operation_id, 'The new publication hierarchy term could not be removed.' );
			}
		} elseif ( ! $this->restore_term( is_array( $before['term'] ?? null ) ? $before['term'] : [] ) ) {
			return $this->rollback_failure( $operation_id, 'The previous publication hierarchy term could not be restored.' );
		}

		if ( ! empty( $resources['publication_created'] ) ) {
			if ( ! wp_delete_post( $publication_id, true ) ) {
				return $this->rollback_failure( $operation_id, 'The new publication record could not be removed.' );
			}
		} elseif ( ! $this->restore_publication( is_array( $before['publication'] ?? null ) ? $before['publication'] : [] ) ) {
			return $this->rollback_failure( $operation_id, 'The previous publication record could not be restored.' );
		}

		$destination_entry = is_array( $before['destination'] ?? null ) ? $before['destination'] : null;
		if ( ! $this->destinations->restore( $publication_id, $destination_entry ) ) {
			return $this->rollback_failure( $operation_id, 'The previous Force Sync destination approval could not be restored.' );
		}

		$operation['status'] = 'rolled_back';
		$operation['rolled_back_gmt'] = gmdate( 'c' );
		$operation['updated_gmt'] = gmdate( 'c' );
		$this->store_operation( $operation_id, $operation );

		return [
			'success' => true,
			'idempotent' => false,
			'operation' => $this->public_operation( $operation ),
			'media_retained_for_review' => [ absint( $operation['result']['logo_attachment_id'] ?? 0 ), absint( $operation['result']['icon_attachment_id'] ?? 0 ) ],
		];
	}

	/** @return array<string,mixed> */
	private function contract(): array {
		return [
			'contract'              => 'hexa-pr-wire-core-onboarding',
			'contract_version'      => self::CONTRACT_VERSION,
			'plugin_version'        => HPRWC_VERSION,
			'authentication'        => 'WordPress authenticated administrator with manage_options',
			'accepts_login_secrets' => false,
			'source_origin'         => untrailingslashit( home_url( '/' ) ),
			'press_release_contract_version' => '1.0',
			'routes'                => [
				'inspect'  => rest_url( self::ROUTE_NAMESPACE . '/onboarding' ),
				'apply'    => rest_url( self::ROUTE_NAMESPACE . '/onboarding/outlet' ),
				'rollback' => rest_url( self::ROUTE_NAMESPACE . '/onboarding/rollback' ),
			],
		];
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function hierarchy() {
		$terms = get_terms( [ 'taxonomy' => PublicationResolver::TAXONOMY, 'hide_empty' => false ] );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$by_id = [];
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_id[ (int) $term->term_id ] = $term;
			}
		}
		$rows = [];
		foreach ( $by_id as $term_id => $term ) {
			$parts = [ $term->name ];
			$parent = (int) $term->parent;
			$seen = [ $term_id => true ];
			while ( $parent > 0 && isset( $by_id[ $parent ] ) && ! isset( $seen[ $parent ] ) ) {
				$seen[ $parent ] = true;
				array_unshift( $parts, $by_id[ $parent ]->name );
				$parent = (int) $by_id[ $parent ]->parent;
			}
			$rows[] = [
				'term_id'        => $term_id,
				'name'           => $term->name,
				'slug'           => $term->slug,
				'path'           => implode( ' > ', $parts ),
				'parent_term_id' => (int) $term->parent > 0 ? (int) $term->parent : null,
			];
		}
		usort( $rows, static fn ( array $left, array $right ): int => strnatcasecmp( (string) $left['path'], (string) $right['path'] ) );

		return $rows;
	}

	/** @param array<int,array<string,mixed>> $hierarchy */
	private function hierarchy_revision( array $hierarchy ): string {
		$revision = array_map(
			static fn ( array $row ): array => [
				'term_id' => (int) $row['term_id'],
				'parent_term_id' => $row['parent_term_id'],
				'slug' => (string) $row['slug'],
				'name' => (string) $row['name'],
			],
			$hierarchy
		);
		usort( $revision, static fn ( array $left, array $right ): int => $left['term_id'] <=> $right['term_id'] );

		return hash( 'sha256', (string) wp_json_encode( $revision ) );
	}

	/** @param array<string,mixed> $payload @param array<int,array<string,mixed>> $hierarchy @return array{parent_term_id:int,parent_path:string}|\WP_Error */
	private function placement( array $payload, array $hierarchy ) {
		$top_level = rest_sanitize_boolean( $payload['top_level'] ?? false );
		$parent_id = absint( $payload['parent_term_id'] ?? 0 );
		$parent_path = sanitize_text_field( (string) ( $payload['parent_path'] ?? '' ) );
		if ( $top_level ) {
			if ( $parent_id > 0 || 'Top level' !== $parent_path ) {
				return new \WP_Error( 'hprwc_invalid_top_level', 'Top-level placement must use parent_term_id 0 and the exact Top level path.', [ 'status' => 422 ] );
			}

			return [ 'parent_term_id' => 0, 'parent_path' => 'Top level' ];
		}
		foreach ( $hierarchy as $row ) {
			if ( (int) $row['term_id'] === $parent_id && hash_equals( (string) $row['path'], $parent_path ) ) {
				return [ 'parent_term_id' => $parent_id, 'parent_path' => $parent_path ];
			}
		}

		return new \WP_Error( 'hprwc_invalid_parent', 'The selected hierarchy parent ID and full path do not match the reviewed hierarchy.', [ 'status' => 409 ] );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function attachment( int $attachment_id, string $role ) {
		$attachment = get_post( $attachment_id );
		$url = $attachment instanceof \WP_Post ? (string) wp_get_attachment_url( $attachment_id ) : '';
		$mime = $attachment instanceof \WP_Post ? (string) get_post_mime_type( $attachment ) : '';
		if ( ! $attachment instanceof \WP_Post || 'attachment' !== $attachment->post_type || ! str_starts_with( $mime, 'image/' ) || '' === $url ) {
			return new \WP_Error( 'hprwc_invalid_' . $role, 'The ' . $role . ' must be an existing source image attachment.', [ 'status' => 422 ] );
		}
		$source_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$image_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) || ! hash_equals( $source_host, $image_host ) ) {
			return new \WP_Error( 'hprwc_' . $role . '_not_source_hosted', 'The ' . $role . ' URL must use HTTPS on the Hexa PR Wire source host.', [ 'status' => 422 ] );
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );

		return [
			'attachment_id' => $attachment_id,
			'url'           => esc_url_raw( $url ),
			'mime_type'     => $mime,
			'width'         => is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0,
			'height'        => is_array( $metadata ) ? absint( $metadata['height'] ?? 0 ) : 0,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function find_matches( string $slug, string $origin ): array {
		$posts = get_posts(
			[
				'post_type'      => PublicationResolver::POST_TYPE,
				'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);
		$matches = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$post_origin = $this->normalize_origin( (string) get_post_meta( $post->ID, 'url', true ) );
			$origin_match = ! is_wp_error( $post_origin ) && hash_equals( $post_origin, $origin );
			$slug_match = '' !== $slug && hash_equals( (string) $post->post_name, $slug );
			if ( ! $origin_match && ! $slug_match ) {
				continue;
			}
			$matches[] = [
				'publication_id' => (int) $post->ID,
				'display_name'   => get_the_title( $post ),
				'slug'           => (string) $post->post_name,
				'canonical_origin'=> is_wp_error( $post_origin ) ? '' : $post_origin,
				'matched_by'     => array_values( array_filter( [ $slug_match ? 'slug' : '', $origin_match ? 'canonical_origin' : '' ] ) ),
			];
		}

		return $matches;
	}

	/** @return \WP_Term|null|\WP_Error */
	private function resolve_term( string $slug, int $publication_id ) {
		$terms = get_terms( [ 'taxonomy' => PublicationResolver::TAXONOMY, 'hide_empty' => false, 'slug' => $slug ] );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		$matching = [];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$mapped = absint( get_term_meta( $term->term_id, 'publication', true ) );
			if ( $publication_id > 0 && $mapped === $publication_id ) {
				return $term;
			}
			if ( 0 === $mapped ) {
				$matching[] = $term;
			}
		}
		if ( count( $matching ) > 1 || ( count( $terms ) > 0 && [] === $matching ) ) {
			return new \WP_Error( 'hprwc_term_conflict', 'The requested publication slug is already bound to another hierarchy term or record.', [ 'status' => 409 ] );
		}

		return $matching[0] ?? null;
	}

	/** @return array<string,mixed> */
	private function snapshot_publication( int $publication_id ): array {
		$post = $publication_id > 0 ? get_post( $publication_id ) : null;
		if ( ! $post instanceof \WP_Post ) {
			return [ 'exists' => false ];
		}
		$meta = [];
		foreach ( self::PUBLICATION_FIELDS as $key ) {
			$meta[ $key ] = [
				'exists' => metadata_exists( 'post', $publication_id, $key ),
				'value'  => get_post_meta( $publication_id, $key, true ),
			];
		}

		return [
			'exists'       => true,
			'ID'           => $publication_id,
			'post_title'   => (string) $post->post_title,
			'post_name'    => (string) $post->post_name,
			'post_status'  => (string) $post->post_status,
			'post_content' => (string) $post->post_content,
			'post_excerpt' => (string) $post->post_excerpt,
			'meta'         => $meta,
		];
	}

	/** @return array<string,mixed> */
	private function snapshot_term( int $term_id ): array {
		$term = $term_id > 0 ? get_term( $term_id, PublicationResolver::TAXONOMY ) : null;
		if ( ! $term instanceof \WP_Term ) {
			return [ 'exists' => false ];
		}

		return [
			'exists'      => true,
			'term_id'     => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'parent'      => (int) $term->parent,
			'description' => (string) $term->description,
			'mapping'     => [
				'exists' => metadata_exists( 'term', $term->term_id, 'publication' ),
				'value'  => get_term_meta( $term->term_id, 'publication', true ),
			],
		];
	}

	private function restore_publication( array $snapshot ): bool {
		if ( empty( $snapshot['exists'] ) || empty( $snapshot['ID'] ) ) {
			return true;
		}
		$updated = wp_update_post(
			[
				'ID'           => absint( $snapshot['ID'] ),
				'post_title'   => (string) ( $snapshot['post_title'] ?? '' ),
				'post_name'    => (string) ( $snapshot['post_name'] ?? '' ),
				'post_status'  => (string) ( $snapshot['post_status'] ?? 'draft' ),
				'post_content' => (string) ( $snapshot['post_content'] ?? '' ),
				'post_excerpt' => (string) ( $snapshot['post_excerpt'] ?? '' ),
			],
			true
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}
		foreach ( is_array( $snapshot['meta'] ?? null ) ? $snapshot['meta'] : [] as $key => $state ) {
			if ( ! empty( $state['exists'] ) ) {
				update_post_meta( absint( $snapshot['ID'] ), (string) $key, $state['value'] ?? '' );
			} else {
				delete_post_meta( absint( $snapshot['ID'] ), (string) $key );
			}
		}

		return true;
	}

	private function restore_term( array $snapshot ): bool {
		if ( empty( $snapshot['exists'] ) || empty( $snapshot['term_id'] ) ) {
			return true;
		}
		$term_id = absint( $snapshot['term_id'] );
		$updated = wp_update_term(
			$term_id,
			PublicationResolver::TAXONOMY,
			[
				'name'        => (string) ( $snapshot['name'] ?? '' ),
				'slug'        => (string) ( $snapshot['slug'] ?? '' ),
				'parent'      => absint( $snapshot['parent'] ?? 0 ),
				'description' => (string) ( $snapshot['description'] ?? '' ),
			]
		);
		if ( is_wp_error( $updated ) ) {
			return false;
		}
		if ( ! empty( $snapshot['mapping']['exists'] ) ) {
			update_term_meta( $term_id, 'publication', $snapshot['mapping']['value'] ?? '' );
		} else {
			delete_term_meta( $term_id, 'publication' );
		}

		return true;
	}

	/** @return array<string,mixed> */
	private function readback( int $publication_id, int $term_id, string $parent_path, string $reviewed_revision, string $press_contract, array $logo, array $icon ): array {
		$post = get_post( $publication_id );
		$term = get_term( $term_id, PublicationResolver::TAXONOMY );

		return [
			'outlet_id'                      => $publication_id,
			'outlet_slug'                    => $post instanceof \WP_Post ? (string) $post->post_name : '',
			'enabled'                        => 1 === (int) get_post_meta( $publication_id, 'status', true ),
			'hierarchy_term_id'              => $term_id,
			'hierarchy_path'                 => $parent_path,
			'outlet_hierarchy_path'          => 'Top level' === $parent_path ? ( $term instanceof \WP_Term ? $term->name : '' ) : $parent_path . ' > ' . ( $term instanceof \WP_Term ? $term->name : '' ),
			'hierarchy_revision'             => $reviewed_revision,
			'current_hierarchy_revision'     => $this->current_hierarchy_revision(),
			'source_contract_version'        => self::CONTRACT_VERSION,
			'press_release_contract_version' => $press_contract,
			'feed_endpoint'                  => add_query_arg( [ 'feed' => 'rss_publication', 'publication' => $term instanceof \WP_Term ? $term->slug : '' ], home_url( '/' ) ),
			'logo_attachment_id'             => $logo['attachment_id'],
			'logo_url'                       => $logo['url'],
			'logo_mime_type'                 => $logo['mime_type'],
			'logo_width'                     => $logo['width'],
			'logo_height'                    => $logo['height'],
			'icon_attachment_id'             => $icon['attachment_id'],
			'icon_url'                       => $icon['url'],
			'icon_mime_type'                 => $icon['mime_type'],
			'icon_width'                     => $icon['width'],
			'icon_height'                    => $icon['height'],
			'destination_host_approved'      => $this->destinations->is_approved( $publication_id, (string) wp_parse_url( (string) get_post_meta( $publication_id, 'url', true ), PHP_URL_HOST ) ),
		];
	}

	private function current_hierarchy_revision(): string {
		$hierarchy = $this->hierarchy();
		return is_wp_error( $hierarchy ) ? '' : $this->hierarchy_revision( $hierarchy );
	}

	/** @return string|\WP_Error */
	private function normalize_origin( string $url ) {
		$url = trim( $url );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) || empty( $parts['host'] )
			|| ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) || ! empty( $parts['query'] ) || ! empty( $parts['fragment'] )
			|| ( ! empty( $parts['path'] ) && '/' !== $parts['path'] ) || filter_var( $parts['host'], FILTER_VALIDATE_IP ) ) {
			return new \WP_Error( 'hprwc_invalid_origin', 'A canonical public HTTPS origin without credentials, path, query or fragment is required.', [ 'status' => 422 ] );
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9.\-]*[a-z0-9])?$/', $host ) || false === strpos( $host, '.' ) ) {
			return new \WP_Error( 'hprwc_invalid_origin_host', 'The canonical origin host is invalid.', [ 'status' => 422 ] );
		}
		$port = isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';

		return 'https://' . $host . $port;
	}

	/** @return string|\WP_Error */
	private function normalize_prefix( string $url, string $origin ) {
		$url = esc_url_raw( trim( $url ) );
		if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return new \WP_Error( 'hprwc_invalid_prefix', 'The press-release URL prefix must be HTTPS.', [ 'status' => 422 ] );
		}
		$prefix_origin = $this->normalize_origin( 'https://' . (string) wp_parse_url( $url, PHP_URL_HOST ) . ( wp_parse_url( $url, PHP_URL_PORT ) ? ':' . (int) wp_parse_url( $url, PHP_URL_PORT ) : '' ) );
		if ( is_wp_error( $prefix_origin ) || ! hash_equals( $origin, $prefix_origin ) ) {
			return new \WP_Error( 'hprwc_prefix_origin_mismatch', 'The press-release URL prefix must use the destination origin.', [ 'status' => 422 ] );
		}

		return trailingslashit( $url );
	}

	/** @return true|\WP_Error */
	private function validate_operation_id( string $operation_id ) {
		return 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $operation_id )
			? true
			: new \WP_Error( 'hprwc_invalid_operation_id', 'A stable 8-128 character operation_id is required.', [ 'status' => 422 ] );
	}

	/** @return array<string,mixed> */
	private function payload( \WP_REST_Request $request ): array {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}

		return is_array( $payload ) ? $payload : [];
	}

	/** @return true|\WP_Error */
	private function reject_sensitive_payload( array $payload ) {
		$blocked = [ 'password', 'passwd', 'secret', 'token', 'cookie', 'authorization', 'application_password', 'credential' ];
		$walk = static function ( array $values ) use ( &$walk, $blocked ): array {
			$found = [];
			foreach ( $values as $key => $value ) {
				$normalized = strtolower( str_replace( [ '-', ' ' ], '_', (string) $key ) );
				foreach ( $blocked as $needle ) {
					if ( str_contains( $normalized, $needle ) ) {
						$found[] = (string) $key;
						break;
					}
				}
				if ( is_array( $value ) ) {
					$found = array_merge( $found, $walk( $value ) );
				}
			}

			return $found;
		};
		$found = array_values( array_unique( $walk( $payload ) ) );

		return [] === $found ? true : new \WP_Error( 'hprwc_secrets_not_accepted', 'Login credentials and secrets must not be sent to or stored by Core onboarding.', [ 'status' => 422, 'blocked_fields' => $found ] );
	}

	/** @return array<string,array<string,mixed>> */
	private function operations(): array {
		$operations = get_option( self::OPERATIONS_OPTION, [] );
		return is_array( $operations ) ? $operations : [];
	}

	private function save_operations( array $operations ): void {
		if ( count( $operations ) > self::MAX_OPERATIONS ) {
			$operations = array_slice( $operations, -self::MAX_OPERATIONS, null, true );
		}
		update_option( self::OPERATIONS_OPTION, $operations, false );
	}

	private function store_operation( string $operation_id, array $operation ): void {
		$operations = $this->operations();
		$operations[ $operation_id ] = $operation;
		$this->save_operations( $operations );
	}

	/** @return array<string,mixed> */
	private function public_operation( array $operation ): array {
		return array_intersect_key(
			$operation,
			array_flip( [ 'operation_id', 'plan_fingerprint', 'status', 'receipt_id', 'reviewed_hierarchy_revision', 'parent_path', 'resources', 'result', 'error', 'updated_gmt', 'rolled_back_gmt' ] )
		);
	}

	/** @return \WP_Error */
	private function fail_operation( string $operation_id, \WP_Error $error ): \WP_Error {
		$operations = $this->operations();
		$operation = $operations[ $operation_id ] ?? [ 'operation_id' => $operation_id ];
		$operation['status'] = 'reconciliation_required';
		$operation['error'] = sanitize_text_field( $error->get_error_message() );
		$operation['updated_gmt'] = gmdate( 'c' );
		$this->store_operation( $operation_id, $operation );

		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), [ 'status' => 500, 'operation' => $this->public_operation( $operation ) ] );
	}

	/** @return \WP_Error */
	private function rollback_failure( string $operation_id, string $message ): \WP_Error {
		$operations = $this->operations();
		$operation = $operations[ $operation_id ] ?? [ 'operation_id' => $operation_id ];
		$operation['status'] = 'rollback_failed';
		$operation['error'] = sanitize_text_field( $message );
		$operation['updated_gmt'] = gmdate( 'c' );
		$this->store_operation( $operation_id, $operation );

		return new \WP_Error( 'hprwc_rollback_failed', $message, [ 'status' => 500, 'operation' => $this->public_operation( $operation ) ] );
	}
}
