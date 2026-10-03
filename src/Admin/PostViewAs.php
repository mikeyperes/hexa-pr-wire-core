<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;
use HWS\BaseTools\UserImpersonation\UserImpersonationFeature;
use HWS\BaseTools\UserImpersonation\ViewAsController;
use HWS\BaseTools\UserImpersonation\VirtualRequestContext;

/** Release-specific UI over Base Tools' existing isolated sessions. */
final class PostViewAs implements Module {
	private const SEARCH_ACTION = 'hprwc_view_as_users';

	public function register(): void {
		add_action( 'admin_bar_menu', [ $this, 'toolbar' ], 90 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_' . self::SEARCH_ACTION, [ $this, 'search_users' ] );
	}

	public function toolbar( \WP_Admin_Bar $bar ): void {
		$post = $this->current_post();
		if ( ! $post || ! $this->available() ) {
			return;
		}
		$bar->add_node( [ 'id' => 'hprwc-view-as', 'title' => 'View as user', 'href' => false ] );
		$users = [];
		$author = get_userdata( (int) $post->post_author );
		$submitted = get_userdata( absint( get_post_meta( $post->ID, 'submitted_by', true ) ) );
		if ( $author instanceof \WP_User ) {
			$users[ $author->ID ] = [ 'user' => $author, 'source' => 'Author' ];
		}
		if ( $submitted instanceof \WP_User ) {
			if ( isset( $users[ $submitted->ID ] ) ) {
				$users[ $submitted->ID ]['source'] = 'Author / Submitted by';
			} else {
				$users[ $submitted->ID ] = [ 'user' => $submitted, 'source' => 'Submitted by' ];
			}
		}
		foreach ( $users as $id => $entry ) {
			$bar->add_node( [
				'id' => 'hprwc-view-as-' . $id,
				'parent' => 'hprwc-view-as',
				'title' => esc_html( $entry['source'] . ': ' . $this->user_label( $entry['user'] ) ),
				'href' => ViewAsController::start_url( (int) $id, $this->destination( $post, is_admin() ) ),
				'meta' => [ 'target' => '_blank', 'rel' => 'noopener noreferrer' ],
			] );
		}
		$bar->add_node( [
			'id' => 'hprwc-view-as-search',
			'parent' => 'hprwc-view-as',
			'title' => 'Choose another user',
			'href' => false,
			'meta' => [ 'html' => '<div class="hprwc-view-as-picker"><label for="hprwc-view-as-query">Search name, username or email</label><input id="hprwc-view-as-query" type="search" autocomplete="off" placeholder="Type at least 2 characters" aria-controls="hprwc-view-as-results"><p id="hprwc-view-as-status" role="status" aria-live="polite"></p><ul id="hprwc-view-as-results"></ul><p>Opens in a new tab. Your admin tab stays signed in.</p></div>' ],
		] );
	}

	public function assets(): void {
		$post = $this->current_post();
		if ( ! $post || ! $this->available() || ! is_admin_bar_showing() ) {
			return;
		}
		wp_enqueue_style( 'hprwc-post-view-as', HPRWC_URL . 'assets/admin/post-view-as.css', [], HPRWC_VERSION );
		wp_enqueue_script( 'hprwc-post-view-as', HPRWC_URL . 'assets/admin/post-view-as.js', [], HPRWC_VERSION, true );
		wp_localize_script( 'hprwc-post-view-as', 'hprwcPostViewAs', [
			'url' => admin_url( 'admin-ajax.php' ),
			'action' => self::SEARCH_ACTION,
			'nonce' => wp_create_nonce( self::SEARCH_ACTION . '_' . $post->ID ),
			'postId' => $post->ID,
			'view' => is_admin() ? 'editor' : 'frontend',
		] );
	}

	public function search_users(): void {
		if ( ! $this->available() ) {
			wp_send_json_error( [ 'message' => 'Only administrators can select a View As user.' ], 403 );
		}
		$post_id = absint( $_POST['post_id'] ?? 0 );
		check_ajax_referer( self::SEARCH_ACTION . '_' . $post_id, 'nonce' );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => 'Release not found or inaccessible.' ], 403 );
		}
		$query = isset( $_POST['query'] ) && is_string( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';
		if ( strlen( $query ) < 2 ) {
			wp_send_json_success( [ 'users' => [] ] );
		}
		$matches = new \WP_User_Query( [
			'number' => 20,
			'count_total' => false,
			'orderby' => 'display_name',
			'order' => 'ASC',
			'search' => '*' . trim( $query, '*' ) . '*',
			'search_columns' => [ 'display_name', 'user_login', 'user_email' ],
		] );
		$destination = $this->destination( $post, 'editor' === ( $_POST['view'] ?? '' ) );
		$users = [];
		foreach ( $matches->get_results() as $user ) {
			$users[] = [ 'label' => $this->user_label( $user ), 'url' => ViewAsController::start_url( (int) $user->ID, $destination ) ];
		}
		wp_send_json_success( [ 'users' => $users ] );
	}

	private function available(): bool {
		return current_user_can( 'manage_options' )
			&& is_callable( [ ViewAsController::class, 'start_url' ] )
			&& UserImpersonationFeature::enabled()
			&& '' === VirtualRequestContext::request_token_from_globals();
	}

	private function current_post(): ?\WP_Post {
		if ( is_admin() ) {
			$screen = get_current_screen();
			if ( ! $screen || 'post' !== $screen->base || 'post' !== $screen->post_type || 'edit' !== ( $_GET['action'] ?? '' ) ) {
				return null;
			}
			$post = get_post( absint( $_GET['post'] ?? 0 ) );
		} else {
			$post = is_singular( 'post' ) ? get_queried_object() : null;
		}
		return $post instanceof \WP_Post && 'post' === $post->post_type && current_user_can( 'edit_post', $post->ID ) ? $post : null;
	}

	private function destination( \WP_Post $post, bool $editor ): string {
		return $editor ? admin_url( 'post.php?post=' . $post->ID . '&action=edit' ) : (string) get_permalink( $post );
	}

	private function user_label( \WP_User $user ): string {
		return $user->display_name . ' (' . $user->user_login . ')';
	}
}
