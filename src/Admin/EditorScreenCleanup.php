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
