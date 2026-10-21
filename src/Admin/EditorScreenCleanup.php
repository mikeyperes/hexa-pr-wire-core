<?php

namespace HexaPrWire\Core\Admin;

use Hexa\PluginCore\WpAdminUiCleanup\CleanupPresets;
use Hexa\PluginCore\WpAdminUiCleanup\CleanupRegistry;
use HexaPrWire\Core\Contracts\Module;

/**
 * Release editor clean-up toggles (Hexa PR Wire → Editor Screens), built on
 * Core's shared cleanup registry and presets.
 */
final class EditorScreenCleanup implements Module {
	private CleanupRegistry $registry;

	public function __construct() {
		$this->registry = new CleanupRegistry( [
			'option_prefix' => 'hprwc_ui_cleanup_',
			'ajax_action' => 'hprwc_ui_cleanup_toggle',
			'root_id' => 'hprwc-ui-cleanup',
			'sections' => [ 'editor' => [ 'title' => 'Release editor', 'description' => 'Boxes removed from the post editor screen.' ] ],
			'options' => [
				'hide_fifu_box' => CleanupPresets::fifu_meta_box( [ 'default' => true ] ),
				'hide_post_visibility_box' => [
					'label' => 'Post visibility box (customers)',
					'description' => "Removes SMP's Post visibility box (hide from home and archives) from the release editor for customers. Staff keep it.",
					'mode' => 'meta_box_remove',
					'meta_boxes' => [ 'smpi_visibility' ],
					'audience' => 'non_admins',
					'audience_capability' => 'edit_others_posts',
					'section' => 'editor',
					'default' => true,
				],
				'hide_echo_box' => [
					'label' => 'Echo post information box (customers)',
					'description' => "Removes the Echo RSS feed plugin's Auto Generated Post Information box from the release editor for customers. Staff keep it.",
					'mode' => 'meta_box_remove',
					'meta_boxes' => [ 'echo_meta_box_function_add' ],
					'audience' => 'non_admins',
					'audience_capability' => 'edit_others_posts',
					'section' => 'editor',
					'default' => true,
				],
				'hide_rankmath_footer' => [
					'label' => 'Rank Math footer credit (customers)',
					'description' => "Removes Rank Math's \"Thank you for using Rank Math\" line from the admin footer for customers. Staff keep it.",
					'mode' => 'footer_filter',
					'footer_patterns' => [ 'Rank Math' ],
					'audience' => 'non_admins',
					'audience_capability' => 'edit_others_posts',
					'section' => 'editor',
					'default' => true,
				],
				'hide_lock_modified_date' => CleanupPresets::rankmath_lock_modified_date( [
					'label' => 'Lock Modified Date (customers)',
					'description' => "Removes Rank Math's Lock Modified Date switch from the Publish box for customers. Staff keep it.",
					'audience' => 'non_admins',
					'audience_capability' => 'edit_others_posts',
					'default' => true,
				] ),
			],
		] );
	}

	public function register(): void {
		$this->registry->register();
	}

	public function render(): void {
		$this->registry->render();
	}
}
