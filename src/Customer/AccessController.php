<?php

namespace HexaPrWire\Core\Customer;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressCustomerPolicyRepository;

final class AccessController implements Module {
	private bool $normalizing_terms = false;

	public function __construct(
		private AccessPolicy $policy,
		private WordPressCustomerPolicyRepository $policies
	) {}

	public function register(): void {
		add_filter( 'user_has_cap', [ $this, 'filter_primitive_capabilities' ], 20, 4 );
		add_filter( 'map_meta_cap', [ $this, 'map_post_capabilities' ], 20, 4 );
		add_filter( 'wp_insert_post_empty_content', [ $this, 'block_unauthorized_creation' ], 20, 2 );
		add_filter( 'wp_insert_post_data', [ $this, 'enforce_submission_status' ], 20, 4 );
		add_filter( 'rest_pre_insert_post', [ $this, 'enforce_rest_submission' ], 20, 2 );
		add_action( 'pre_get_posts', [ $this, 'scope_post_queries' ], 20 );
		add_filter( 'ajax_query_attachments_args', [ $this, 'scope_media_query' ], 20 );
		add_filter( 'get_terms_args', [ $this, 'scope_publication_terms' ], 20, 2 );
		add_action( 'set_object_terms', [ $this, 'enforce_term_assignment' ], 100, 6 );
		add_action( 'load-post-new.php', [ $this, 'redirect_disallowed_new_post' ] );
		add_action( 'admin_menu', [ $this, 'limit_customer_menus' ], 999 );
		add_filter( 'show_admin_bar', [ $this, 'filter_admin_bar' ] );
	}

	/** @param array<string,bool> $allcaps @param string[] $caps @param array<int,mixed> $args */
	public function filter_primitive_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		unset( $caps, $args );
		$user_id = (int) $user->ID;
		if ( ! $this->policy->is_customer( $user_id ) ) {
			return $allcaps;
		}
		$mode = $this->policy->mode( $user_id );
		$allcaps['publish_posts'] = SubmissionMode::can_publish( $mode );
		$allcaps['edit_others_posts'] = SubmissionMode::is_full( $mode );
		$allcaps['read_private_posts'] = SubmissionMode::is_full( $mode );
		$allcaps['delete_posts'] = SubmissionMode::is_full( $mode );
		$allcaps['delete_published_posts'] = SubmissionMode::is_full( $mode );
		return $allcaps;
	}

	/** @param string[] $caps @param array<int,mixed> $args @return string[] */
	public function map_post_capabilities( array $caps, string $cap, int $user_id, array $args ): array {
		if ( ! $this->policy->is_customer( $user_id ) || ! in_array( $cap, [ 'edit_post', 'delete_post', 'read_post' ], true ) ) {
			return $caps;
		}
		$post = isset( $args[0] ) ? get_post( absint( $args[0] ) ) : null;
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			return $caps;
		}
		if ( ! $this->policy->can_edit_post( $user_id, $post ) || ( 'delete_post' === $cap && ! SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ) ) {
			return [ 'do_not_allow' ];
		}
		return SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ? [ 'edit_others_posts' ] : [ 'edit_posts' ];
	}

	/** @param array<string,mixed> $postarr */
	public function block_unauthorized_creation( bool $maybe_empty, array $postarr ): bool {
		$user_id = get_current_user_id();
		if ( $maybe_empty || ! $this->policy->is_customer( $user_id ) || 'post' !== (string) ( $postarr['post_type'] ?? 'post' ) || absint( $postarr['ID'] ?? 0 ) > 0 ) {
			return $maybe_empty;
		}
		if ( $this->is_billing_fulfillment() ) {
			return false;
		}
		return ! $this->policy->can_create( $user_id );
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $postarr @param array<string,mixed> $unsanitized */
	public function enforce_submission_status( array $data, array $postarr, array $unsanitized, bool $update ): array {
		unset( $unsanitized );
		$user_id = get_current_user_id();
		if ( ! $this->policy->is_customer( $user_id ) || 'post' !== (string) ( $data['post_type'] ?? '' ) || $this->is_billing_fulfillment() ) {
			return $data;
		}
		$mode = $this->policy->mode( $user_id );
		if ( ! SubmissionMode::can_publish( $mode ) && in_array( (string) ( $data['post_status'] ?? '' ), [ 'publish', 'future' ], true ) ) {
			$existing = $update ? get_post( absint( $postarr['ID'] ?? 0 ) ) : null;
			$data['post_status'] = $existing instanceof \WP_Post && 'publish' === $existing->post_status ? 'publish' : 'pending';
		}
		return $data;
	}

	public function enforce_rest_submission( mixed $prepared_post, \WP_REST_Request $request ): mixed {
		$user_id = get_current_user_id();
		if ( ! $this->policy->is_customer( $user_id ) ) {
			return $prepared_post;
		}
		$is_new = absint( $request['id'] ?? 0 ) <= 0;
		if ( $is_new && ! $this->policy->can_create( $user_id ) ) {
			return new \WP_Error( 'hprwc_create_forbidden', 'Your account can edit purchased release drafts but cannot create new releases.', [ 'status' => 403 ] );
		}
		if ( is_object( $prepared_post ) && ! $this->policy->can_publish( $user_id ) && in_array( (string) ( $prepared_post->post_status ?? '' ), [ 'publish', 'future' ], true ) ) {
			$prepared_post->post_status = 'pending';
		}
		$requested_terms = $request->get_param( 'publication' );
		if ( is_array( $requested_terms ) ) {
			$existing_terms = $is_new ? [] : wp_get_object_terms( absint( $request['id'] ?? 0 ), 'publication', [ 'fields' => 'ids' ] );
			$existing_terms = is_wp_error( $existing_terms ) ? [] : array_map( 'absint', $existing_terms );
			$request->set_param( 'publication', $this->policy->filter_publications_for_existing_post( $user_id, $requested_terms, $existing_terms ) );
		}
		return $prepared_post;
	}

	public function scope_post_queries( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$user_id = get_current_user_id();
		if ( ! $this->policy->is_customer( $user_id ) || SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		if ( empty( $post_type ) || 'post' === $post_type || 'attachment' === $post_type ) {
			$query->set( 'author', $user_id );
		}
	}

	/** @param array<string,mixed> $query */
	public function scope_media_query( array $query ): array {
		$user_id = get_current_user_id();
		if ( $this->policy->is_customer( $user_id ) && ! SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ) {
			$query['author'] = $user_id;
		}
		return $query;
	}

	/** @param array<string,mixed> $args @param string[] $taxonomies @return array<string,mixed> */
	public function scope_publication_terms( array $args, array $taxonomies ): array {
		$user_id = get_current_user_id();
		if ( ! in_array( 'publication', $taxonomies, true ) || ! $this->policy->is_customer( $user_id ) || SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ) {
			return $args;
		}
		if ( ! $this->policies->publication_access_configured( $user_id ) ) {
			return $args;
		}
		$allowed = $this->policies->allowed_publications( $user_id );
		$current = array_values( array_filter( array_map( 'absint', (array) ( $args['include'] ?? [] ) ) ) );
		$args['include'] = $current ? array_values( array_intersect( $current, $allowed ) ) : ( $allowed ?: [ 0 ] );
		return $args;
	}

	/** @param string|int|array<int|string,mixed> $terms @param int[] $term_taxonomy_ids @param int[] $old_term_taxonomy_ids */
	public function enforce_term_assignment( int $object_id, mixed $terms, array $term_taxonomy_ids, string $taxonomy, bool $append, array $old_term_taxonomy_ids ): void {
		unset( $terms, $term_taxonomy_ids, $append );
		if ( $this->normalizing_terms || 'publication' !== $taxonomy || $this->is_billing_fulfillment() ) {
			return;
		}
		$post = get_post( $object_id );
		$user_id = get_current_user_id();
		if ( ! $post instanceof \WP_Post || ! $this->policy->is_customer( $user_id ) || ! $this->policy->can_edit_post( $user_id, $post ) || SubmissionMode::is_full( $this->policy->mode( $user_id ) ) ) {
			return;
		}
		$assigned = wp_get_object_terms( $object_id, 'publication', [ 'fields' => 'ids' ] );
		if ( is_wp_error( $assigned ) ) {
			return;
		}
		$assigned = array_values( array_map( 'absint', $assigned ) );
		$existing = $this->term_ids_from_taxonomy_ids( $old_term_taxonomy_ids );
		$allowed = $this->policy->filter_publications_for_existing_post( $user_id, $assigned, $existing );
		if ( $allowed !== $assigned ) {
			$this->normalizing_terms = true;
			wp_set_object_terms( $object_id, $allowed, 'publication', false );
			$this->normalizing_terms = false;
		}
	}

	/** @param int[] $term_taxonomy_ids @return int[] */
	private function term_ids_from_taxonomy_ids( array $term_taxonomy_ids ): array {
		global $wpdb;
		$term_taxonomy_ids = array_values( array_unique( array_filter( array_map( 'absint', $term_taxonomy_ids ) ) ) );
		if ( ! $term_taxonomy_ids ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
		$query = $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s AND term_taxonomy_id IN ({$placeholders})", 'publication', ...$term_taxonomy_ids );
		return array_values( array_map( 'absint', (array) $wpdb->get_col( $query ) ) );
	}

	public function redirect_disallowed_new_post(): void {
		$user_id = get_current_user_id();
		$post_type = sanitize_key( (string) ( $_GET['post_type'] ?? 'post' ) );
		if ( 'post' === $post_type && $this->policy->is_customer( $user_id ) && ! $this->policy->can_create( $user_id ) ) {
			wp_safe_redirect( add_query_arg( 'hprwc_notice', 'create_forbidden', admin_url( 'edit.php' ) ) );
			exit;
		}
	}

	public function limit_customer_menus(): void {
		$user_id = get_current_user_id();
		if ( ! $this->policy->is_customer( $user_id ) ) {
			return;
		}
		foreach ( [ 'edit.php?post_type=publication', 'edit.php?post_type=page', 'edit-comments.php', 'tools.php' ] as $slug ) {
			remove_menu_page( $slug );
		}
		if ( ! $this->policy->can_create( $user_id ) ) {
			remove_submenu_page( 'edit.php', 'post-new.php' );
		}
	}

	public function filter_admin_bar( bool $show ): bool {
		return $this->policy->is_customer( get_current_user_id() ) ? false : $show;
	}

	private function is_billing_fulfillment(): bool {
		return doing_action( 'woocommerce_order_status_processing' ) || doing_action( 'woocommerce_order_status_completed' );
	}
}
