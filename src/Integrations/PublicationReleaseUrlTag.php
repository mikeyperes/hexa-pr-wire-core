<?php

namespace HexaPrWire\Core\Integrations;

/**
 * Elementor URL tag: the current loop publication's copy of the queried release.
 * Loaded only from `elementor/dynamic_tags/register`, after Elementor defines Data_Tag.
 */
final class PublicationReleaseUrlTag extends \Elementor\Core\DynamicTags\Data_Tag {
	/** @var callable|null Set by ElementorPublicationQuery; Elementor instantiates tags itself. */
	public static $resolve = null;

	public function get_name(): string {
		return 'hpr-publication-release-url';
	}

	public function get_title(): string {
		return 'Publication Release URL';
	}

	public function get_group(): string {
		return 'post';
	}

	public function get_categories(): array {
		return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
	}

	protected function get_value( array $options = [] ): string {
		return is_callable( self::$resolve ) ? (string) call_user_func( self::$resolve ) : '';
	}
}
