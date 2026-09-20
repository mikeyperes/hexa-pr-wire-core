<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;

final class EditorStyles implements Module {
	public function register(): void {
		add_filter( 'mce_buttons_2', [ $this, 'buttons' ] );
		add_filter( 'tiny_mce_before_init', [ $this, 'formats' ] );
	}

	/** @param string[] $buttons @return string[] */
	public function buttons( array $buttons ): array {
		if ( ! in_array( 'styleselect', $buttons, true ) ) {
			array_unshift( $buttons, 'styleselect' );
		}
		return $buttons;
	}

	/** @param array<string,mixed> $settings @return array<string,mixed> */
	public function formats( array $settings ): array {
		$formats = [
			[ 'title' => 'Callout', 'block' => 'div', 'classes' => 'call-out', 'wrapper' => true ],
			[ 'title' => 'Disclaimer', 'block' => 'div', 'classes' => 'disclaimer', 'wrapper' => true ],
			[ 'title' => 'Warning', 'block' => 'div', 'classes' => 'warning', 'wrapper' => true ],
			[ 'title' => 'Highlighter', 'inline' => 'span', 'classes' => 'highlighter' ],
			[ 'title' => 'Smaller Text', 'inline' => 'span', 'classes' => 'small' ],
		];
		$existing = [];
		if ( ! empty( $settings['style_formats'] ) ) {
			$decoded = json_decode( (string) $settings['style_formats'], true );
			$existing = is_array( $decoded ) ? $decoded : [];
		}
		$settings['style_formats'] = wp_json_encode( array_merge( $existing, $formats ) );
		return $settings;
	}
}
