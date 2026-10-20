<?php

namespace HexaPrWire\Core\Customer;

use HexaPrWire\Core\Contracts\Module;

/**
 * What a customer can reach in WP Admin. Customers work only on their own posts and
 * media, so every other content type is closed by permission (its menu, list, editor
 * and REST route all follow), and staff-only plugin screens are closed by slug.
 * New "hide this from customers" rules belong here.
 */
final class AdminScope implements Module {
	/** Content types a customer works with; every other admin-visible type is closed. */
	private const CONTENT_TYPES = [ 'post', 'attachment' ];

	/**
	 * Plugin screens that only check edit_posts and have no customer use, by page-slug
	 * prefix. Elementor's own Role Manager blocks its editor but not these menus.
	 */
	private const CLOSED_PAGE_PREFIXES = [ 'elementor' ];

	/** Core screens a customer has no use for. */
	private const CLOSED_MENUS = [ 'edit-comments.php', 'tools.php' ];

	public function __construct( private AccessPolicy $policy ) {}

	public function register(): void {
		add_action( 'wp_loaded', [ $this, 'close_content_types' ] );
		add_action( 'admin_menu', [ $this, 'remove_closed_pages' ], PHP_INT_MAX );
		add_action( 'admin_init', [ $this, 'block_closed_pages' ] );
	}

	private function applies(): bool {
		return $this->policy->is_customer( get_current_user_id() );
	}

	/** Runs after every plugin has registered its types, for customer requests only. */
	public function close_content_types(): void {
		if ( ! $this->applies() ) {
			return;
		}
		foreach ( get_post_types( [ 'show_ui' => true ], 'objects' ) as $type ) {
			if ( in_array( $type->name, self::CONTENT_TYPES, true ) ) {
				continue;
			}
			foreach ( array_keys( (array) $type->cap ) as $key ) {
				// Reading published content on the public site is unchanged.
				if ( ! in_array( $key, [ 'read', 'read_post' ], true ) ) {
					$type->cap->$key = 'do_not_allow';
				}
			}
		}
	}

	public function remove_closed_pages(): void {
		if ( ! $this->applies() ) {
			return;
		}
		global $menu;
		foreach ( (array) $menu as $item ) {
			$slug = (string) ( $item[2] ?? '' );
			if ( in_array( $slug, self::CLOSED_MENUS, true ) || $this->is_closed_page( $slug ) ) {
				remove_menu_page( $slug );
			}
		}
		if ( ! $this->policy->can_create( get_current_user_id() ) ) {
			remove_submenu_page( 'edit.php', 'post-new.php' );
		}
	}

	public function block_closed_pages(): void {
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		if ( '' !== $page && $this->is_closed_page( $page ) && $this->applies() && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'edit.php' ) );
			exit;
		}
	}

	private function is_closed_page( string $slug ): bool {
		foreach ( self::CLOSED_PAGE_PREFIXES as $prefix ) {
			if ( str_starts_with( $slug, $prefix ) ) {
				return true;
			}
		}
		return false;
	}
}
