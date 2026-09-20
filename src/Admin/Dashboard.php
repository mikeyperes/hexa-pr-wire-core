<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Migration\StatusReport;
use HexaPrWire\Core\Support\Activity;
use HexaPrWire\Core\Workflow\NotificationSettings;

final class Dashboard implements Module {
	private const PAGE = 'hexa-pr-wire-core';
	private const AJAX_ACTION = 'hprwc_dashboard_tab';
	private const NONCE = 'hprwc_dashboard_tabs';
	private const TABS = [
		'overview' => 'Overview',
		'customers' => 'Customers',
		'publications' => 'Publications',
		'releases' => 'Releases',
		'notifications' => 'Notifications',
		'system' => 'System / Migration',
	];

	private TabRegistry $tabs;

	public function __construct( private NotificationSettings $notifications, private StatusReport $status ) {
		$this->tabs = new TabRegistry();
		foreach ( self::TABS as $id => $label ) {
			$this->tabs->add( new TabDefinition( $id, $label, [ $this, $id ], 'manage_options' ) );
		}
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 20 );
		add_action( 'admin_post_hprwc_save_notifications', [ $this, 'save_notifications' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_tab' ] );
	}

	public function menu(): void {
		add_menu_page( 'Hexa PR Wire', 'Hexa PR Wire', 'manage_options', self::PAGE, [ $this, 'render' ], 'dashicons-megaphone', 25 );
		foreach ( self::TABS as $tab => $label ) {
			add_submenu_page( self::PAGE, $label, $label, 'manage_options', self::PAGE . '-' . $tab, fn() => $this->render_tab( $tab ) );
		}
		remove_submenu_page( self::PAGE, self::PAGE );
	}

	public function assets( string $hook ): void {
		if ( str_contains( $hook, self::PAGE ) || in_array( $hook, [ 'profile.php', 'user-edit.php', 'user-new.php', 'users.php' ], true ) ) {
			wp_enqueue_style( 'hprwc-admin', HPRWC_URL . 'assets/admin/core.css', [], HPRWC_VERSION );
			wp_enqueue_script( 'hprwc-admin', HPRWC_URL . 'assets/admin/core.js', [ 'jquery' ], HPRWC_VERSION, true );
		}
	}

	public function render(): void {
		$this->render_tab( 'overview' );
	}

	public function render_tab( string $tab ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'You do not have permission to view this page.' );
		}
		$requested = sanitize_key( (string) ( $_GET['tab'] ?? '' ) );
		$tab = array_key_exists( $requested, self::TABS ) ? $requested : ( array_key_exists( $tab, self::TABS ) ? $tab : 'overview' );
		?>
		<div class="wrap hprwc-admin"><h1>Hexa PR Wire Core</h1><div class="hprwc-admin-content">
		<?php
		( new HostTabsRenderer() )->render( [
			'tabs' => $this->tabs->all(),
			'active' => $tab,
			'page_url' => admin_url( 'admin.php?page=' . self::PAGE ),
			'ajax_action' => self::AJAX_ACTION,
			'nonce' => wp_create_nonce( self::NONCE ),
			'root_id' => 'hprwc-dashboard-tabs',
			'panel_id' => 'hprwc-dashboard-panel',
			'label' => 'Hexa PR Wire Core sections',
			'render_callback' => [ $this, 'render_section' ],
		] );
		?></div></div>
		<?php
	}

	public function render_section( string $tab ): void {
		$definition = $this->tabs->get( $tab );
		if ( ! $definition instanceof TabDefinition || ! is_callable( $definition->renderer ) ) {
			echo '<div class="notice notice-error inline"><p>Unknown Core section.</p></div>';
			return;
		}
		call_user_func( $definition->renderer );
	}

	public function ajax_tab(): void {
		AjaxGuard::require_nonce_or_error( self::NONCE );
		AjaxGuard::require_capability_or_error( 'manage_options' );
		$tab = sanitize_key( (string) ( $_POST['tab'] ?? '' ) );
		$definition = $this->tabs->get( $tab );
		if ( ! $definition instanceof TabDefinition ) {
			wp_send_json_error( [ 'code' => 'invalid_tab', 'message' => 'Unknown Core section.' ], 400 );
		}
		ob_start();
		$this->render_section( $tab );
		wp_send_json_success( [ 'tab' => $tab, 'label' => $definition->label, 'html' => (string) ob_get_clean() ] );
	}

	public function save_notifications(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'hprwc_save_notifications' ) ) {
			wp_die( 'Invalid request.' );
		}
		$input = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];
		$this->notifications->save( $input );
		Activity::add( 'Notification templates saved.', 'success', [], 'settings' );
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE . '-notifications', 'updated' => 1 ], admin_url( 'admin.php' ) ) );
		exit;
	}

	private function overview(): void {
		$customers = count_users();
		$role_count = (int) ( $customers['avail_roles']['hexa_pr_wire_user'] ?? 0 );
		$post_counts = wp_count_posts( 'post' );
		$this->cards( [
			[ 'Customers', $role_count, 'users.php?role=hexa_pr_wire_user' ],
			[ 'Pending review', (int) $post_counts->pending, 'edit.php?post_status=pending&post_type=post' ],
			[ 'Published releases', (int) $post_counts->publish, 'edit.php?post_status=publish&post_type=post' ],
			[ 'Publication records', (int) wp_count_posts( 'publication' )->publish, 'edit.php?post_type=publication' ],
		] );
		echo '<div class="hprwc-panel"><h2>Core ownership</h2><p>Hexa PR Wire Core owns customer permissions, the publication registry/taxonomy, release fields, editor workflow, feeds, delivery links, notifications, canonical URLs, and migration status. Billing remains the owner of checkout and order fulfillment; Distributor remains the owner of destination imports.</p></div>';
	}

	private function customers(): void {
		echo '<div class="hprwc-panel"><h2>Customer controls</h2><p>Open a customer profile to set the submission mode, allowed publications, publication-specific prices, notification recipients, templates, and private notes.</p><p><a class="button button-primary" href="' . esc_url( admin_url( 'users.php?role=hexa_pr_wire_user' ) ) . '">Manage customers</a> <a class="button" href="' . esc_url( admin_url( 'user-new.php' ) ) . '">Add customer</a></p></div>';
	}

	private function publications(): void {
		$mapped = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'meta_key' => 'publication', 'fields' => 'ids' ] );
		$this->cards( [
			[ 'Registry records', (int) wp_count_posts( 'publication' )->publish, 'edit.php?post_type=publication' ],
			[ 'Taxonomy terms', (int) wp_count_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false ] ), 'edit-tags.php?taxonomy=publication&post_type=post' ],
			[ 'Mapped terms', is_wp_error( $mapped ) ? 0 : count( $mapped ), 'edit-tags.php?taxonomy=publication&post_type=post' ],
		] );
	}

	private function releases(): void {
		$counts = wp_count_posts( 'post' );
		$this->cards( [
			[ 'Drafts', (int) $counts->draft, 'edit.php?post_status=draft&post_type=post' ],
			[ 'Pending review', (int) $counts->pending, 'edit.php?post_status=pending&post_type=post' ],
			[ 'Published', (int) $counts->publish, 'edit.php?post_status=publish&post_type=post' ],
		] );
		echo '<div class="hprwc-panel"><p><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php' ) ) . '">Manage releases</a></p></div>';
	}

	private function notifications(): void {
		$config = $this->notifications->all();
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success inline"><p>Notification settings saved.</p></div>';
		}
		$fields = [
			'pending' => 'Pending-review alert',
			'draft' => 'Customer draft update',
			'live' => 'Customer live-links email',
			'onboarding' => 'Customer onboarding email',
		];
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hprwc-panel">
			<input type="hidden" name="action" value="hprwc_save_notifications"><?php wp_nonce_field( 'hprwc_save_notifications' ); ?>
			<h2>Notification templates</h2>
			<p>Placeholders: <code>{title}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{full_name}</code>, <code>{permalink}</code>, <code>{edit_url}</code>, <code>{dashboard_url}</code>, <code>{link_output}</code>.</p>
			<?php foreach ( $fields as $key => $label ) : ?>
				<fieldset class="hprwc-template"><legend><strong><?php echo esc_html( $label ); ?></strong></legend>
				<label>Subject<input class="large-text" name="settings[<?php echo esc_attr( $key ); ?>_subject]" value="<?php echo esc_attr( (string) $config[ $key . '_subject' ] ); ?>"></label>
				<label>Message<textarea class="large-text" rows="5" name="settings[<?php echo esc_attr( $key ); ?>_message]"><?php echo esc_textarea( (string) $config[ $key . '_message' ] ); ?></textarea></label>
				</fieldset>
			<?php endforeach; ?>
			<label><strong>Administrator recipients</strong><textarea class="large-text" rows="4" name="settings[admin_emails]"><?php echo esc_textarea( (string) $config['admin_emails'] ); ?></textarea></label>
			<?php submit_button( 'Save notification settings' ); ?>
		</form>
		<?php
	}

	private function system(): void {
		$report = $this->status->all();
		$core = $report['core_package'];
		echo '<div class="hprwc-panel"><h2>Runtime</h2><table class="widefat striped"><tbody>';
		foreach ( [
			'Plugin version' => $report['plugin_version'],
			'Hexa Plugin Core' => (string) ( $core['selected']['version'] ?? 'Unavailable' ),
			'Core package healthy' => ! empty( $core['healthy'] ) ? 'Yes' : 'No',
			'Publication CPT' => $report['publication_cpt'] ? 'Registered' : 'Missing',
			'Publication taxonomy' => $report['publication_taxonomy'] ? 'Registered' : 'Missing',
			'HWS Website Settings' => ! empty( $report['legacy_ui']['website_settings_enabled'] ) ? 'Enabled' : 'Disabled',
			'Legacy Theme Options group' => ! empty( $report['legacy_ui']['theme_options_group_trashed'] ) ? 'Trashed' : 'Active',
		] as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<div class="hprwc-panel"><h2>Legacy snippet handoff</h2><table class="widefat striped"><thead><tr><th>ID</th><th>Name</th><th>Status</th></tr></thead><tbody>';
		foreach ( $report['snippets'] as $snippet ) {
			echo '<tr><td>' . esc_html( (string) $snippet['id'] ) . '</td><td>' . esc_html( (string) $snippet['name'] ) . '</td><td>' . ( $snippet['active'] ? '<span class="hprwc-badge warning">Active</span>' : '<span class="hprwc-badge success">Disabled</span>' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Legacy snippets are disabled only by the migration command after deployed parity checks pass.</p></div>';
		$entries = array_reverse( Activity::logger()->all() );
		echo '<div class="hprwc-panel"><h2>Recent activity</h2><table class="widefat striped"><thead><tr><th>Time</th><th>Level</th><th>Message</th><th>Source</th></tr></thead><tbody>';
		foreach ( array_slice( $entries, 0, 30 ) as $entry ) {
			echo '<tr><td>' . esc_html( (string) $entry->timestamp ) . '</td><td>' . esc_html( $entry->level ) . '</td><td>' . esc_html( $entry->message ) . '</td><td>' . esc_html( (string) $entry->source ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/** @param array<int,array{0:string,1:int,2:string}> $cards */
	private function cards( array $cards ): void {
		echo '<div class="hprwc-cards">';
		foreach ( $cards as [ $label, $value, $url ] ) {
			echo '<a class="hprwc-card" href="' . esc_url( admin_url( $url ) ) . '"><span>' . esc_html( $label ) . '</span><strong>' . number_format_i18n( $value ) . '</strong></a>';
		}
		echo '</div>';
	}
}
