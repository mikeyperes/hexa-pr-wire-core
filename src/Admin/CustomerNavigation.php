<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Customer\AccessPolicy;

final class CustomerNavigation implements Module {
	public function __construct( private AccessPolicy $policy ) {}

	public function register(): void {
		add_filter( 'login_redirect', [ $this, 'login_redirect' ], 20, 3 );
		add_action( 'admin_init', [ $this, 'dashboard_redirect' ] );
		add_filter( 'admin_body_class', [ $this, 'body_class' ] );
		add_action( 'admin_head', [ $this, 'remove_help' ] );
	}

	public function login_redirect( string $redirect_to, string $requested, \WP_User|\WP_Error $user ): string {
		unset( $requested );
		return $user instanceof \WP_User && $this->policy->is_customer( (int) $user->ID ) ? admin_url( 'edit.php' ) : $redirect_to;
	}

	public function dashboard_redirect(): void {
		global $pagenow;
		if ( 'index.php' === $pagenow && $this->policy->is_customer( get_current_user_id() ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'edit.php' ) );
			exit;
		}
	}

	public function body_class( string $classes ): string {
		return $this->policy->is_customer( get_current_user_id() ) ? $classes . ' hprwc-customer-admin' : $classes;
	}

	public function remove_help(): void {
		if ( $this->policy->is_customer( get_current_user_id() ) ) {
			$screen = get_current_screen();
			if ( $screen ) {
				$screen->remove_help_tabs();
			}
		}
	}
}
