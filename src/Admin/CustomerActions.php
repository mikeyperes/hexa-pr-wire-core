<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Customer\AccessPolicy;
use HexaPrWire\Core\Support\Activity;

final class CustomerActions implements Module {
	private const ACTION = 'hprwc_create_customer_draft';

	public function __construct( private AccessPolicy $policy ) {}

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'profile_action' ], 50 );
		add_action( 'edit_user_profile', [ $this, 'profile_action' ], 50 );
		add_filter( 'user_row_actions', [ $this, 'row_actions' ], 20, 2 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'create_draft' ] );
		add_action( 'user_register', [ $this, 'remember_new_user' ], 20 );
		add_action( 'admin_init', [ $this, 'redirect_after_new_user' ], 20 );
	}

	public function profile_action( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) || ! $this->policy->is_customer( (int) $user->ID ) ) {
			return;
		}
		?>
		<h2>Release actions</h2>
		<table class="form-table" role="presentation"><tr><th>Create release</th><td><a class="button button-primary" href="<?php echo esc_url( $this->create_url( (int) $user->ID ) ); ?>">Create draft for this customer</a><p class="description">Creates one blank draft owned by this customer and opens it for editing.</p></td></tr></table>
		<?php
	}

	/** @param array<string,string> $actions @return array<string,string> */
	public function row_actions( array $actions, \WP_User $user ): array {
		if ( current_user_can( 'edit_user', $user->ID ) && $this->policy->is_customer( (int) $user->ID ) ) {
			$actions['hprwc_create_draft'] = '<a href="' . esc_url( $this->create_url( (int) $user->ID ) ) . '">Create release draft</a>';
		}
		return $actions;
	}

	public function create_draft(): void {
		$user_id = absint( $_GET['user_id'] ?? 0 );
		check_admin_referer( self::ACTION . '_' . $user_id );
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) || ! $this->policy->is_customer( $user_id ) ) {
			wp_die( 'You cannot create a release draft for this account.', 'Forbidden', [ 'response' => 403 ] );
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			wp_die( 'Customer account not found.', 'Not found', [ 'response' => 404 ] );
		}
		$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		$title = ( '' !== $name ? $name : $user->display_name ) . ' <' . $user->user_login . '>, Press Release Draft';
		$post_id = wp_insert_post( [ 'post_title' => $title, 'post_status' => 'draft', 'post_author' => $user_id, 'post_type' => 'post' ], true );
		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html( $post_id->get_error_message() ), 'Draft creation failed', [ 'response' => 500 ] );
		}
		update_post_meta( $post_id, 'submitted_by', $user_id );
		update_post_meta( $post_id, '_submitted_by', 'field_651368fa55448' );
		Activity::add( 'Administrator created a customer release draft.', 'success', [ 'post_id' => $post_id, 'user_id' => $user_id ], 'customers' );
		wp_safe_redirect( get_edit_post_link( $post_id, '' ) ?: admin_url( 'post.php?post=' . $post_id . '&action=edit' ) );
		exit;
	}

	public function remember_new_user( int $user_id ): void {
		$actor_id = get_current_user_id();
		if ( is_admin() && $actor_id > 0 && current_user_can( 'create_users' ) ) {
			set_transient( 'hprwc_new_user_' . $actor_id, $user_id, 5 * MINUTE_IN_SECONDS );
		}
	}

	public function redirect_after_new_user(): void {
		global $pagenow;
		if ( 'users.php' !== $pagenow || 'add' !== sanitize_key( (string) ( $_GET['update'] ?? '' ) ) ) {
			return;
		}
		$actor_id = get_current_user_id();
		$user_id = absint( get_transient( 'hprwc_new_user_' . $actor_id ) );
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		delete_transient( 'hprwc_new_user_' . $actor_id );
		wp_safe_redirect( admin_url( 'user-edit.php?user_id=' . $user_id . '#hprwc-customer-access' ) );
		exit;
	}

	private function create_url( int $user_id ): string {
		return wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'user_id' => $user_id ], admin_url( 'admin-post.php' ) ), self::ACTION . '_' . $user_id );
	}
}
