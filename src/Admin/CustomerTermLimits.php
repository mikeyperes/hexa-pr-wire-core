<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\Taxonomies\TermChoiceLimits;
use HexaPrWire\Core\Contracts\Module;

/**
 * Customer limits on release categories and tags (Hexa PR Wire → Editor
 * Screens), enforced by Core's TermChoiceLimits. Staff (edit_others_posts)
 * are never limited.
 */
final class CustomerTermLimits implements Module {
	public const SINGLE_CATEGORY = 'hprwc_customer_single_category';
	public const MAX_TAGS = 'hprwc_customer_max_tags';
	private const STAFF = 'edit_others_posts';
	private const SAVE_ACTION = 'hprwc_save_customer_term_limits';

	public function register(): void {
		( new TermChoiceLimits( [
			[ 'taxonomy' => 'category', 'max' => self::single_category() ? 1 : 0, 'capability' => self::STAFF, 'post_types' => [ 'post' ] ],
			[ 'taxonomy' => 'post_tag', 'max' => self::max_tags(), 'capability' => self::STAFF, 'post_types' => [ 'post' ] ],
		] ) )->register();
		add_action( 'admin_post_' . self::SAVE_ACTION, [ $this, 'save' ] );
	}

	public static function single_category(): bool {
		return '1' === (string) get_option( self::SINGLE_CATEGORY, '1' );
	}

	/** 0 means no limit. */
	public static function max_tags(): int {
		return max( 0, (int) get_option( self::MAX_TAGS, 3 ) );
	}

	public function render(): void {
		?>
		<div class="hprwc-panel">
			<h2>Customer categories and tags</h2>
			<p>Limits for customers on the release editor. Staff are never limited.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>
				<p><label><input type="checkbox" name="single_category" value="1" <?php checked( self::single_category() ); ?>> Customers can pick only one category (shown as radio buttons)</label></p>
				<p><label>Most tags a customer can add <input type="number" min="0" max="50" name="max_tags" value="<?php echo esc_attr( (string) self::max_tags() ); ?>" class="small-text"></label> <span class="description">0 means no limit.</span></p>
				<?php submit_button( 'Save customer limits', 'primary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::SAVE_ACTION ) ) {
			wp_die( 'Invalid request.' );
		}
		update_option( self::SINGLE_CATEGORY, empty( $_POST['single_category'] ) ? '0' : '1' );
		update_option( self::MAX_TAGS, min( 50, max( 0, (int) ( $_POST['max_tags'] ?? 3 ) ) ) );
		wp_safe_redirect( add_query_arg( [ 'page' => 'hexa-pr-wire-core-editor', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}
}
