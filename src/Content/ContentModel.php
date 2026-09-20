<?php

namespace HexaPrWire\Core\Content;

use Hexa\PluginCore\ContentTypes\ContentTypeRegistry;
use Hexa\PluginCore\Taxonomies\TaxonomyRegistry;
use HexaPrWire\Core\Contracts\Module;

final class ContentModel implements Module {
	private ContentTypeRegistry $content_types;
	private TaxonomyRegistry $taxonomies;

	public function __construct() {
		$this->content_types = new ContentTypeRegistry(
			[
				'option_name' => 'hprwc_content_types',
				'ajax_action' => 'hprwc_save_content_types',
				'nonce_action' => 'hprwc_content_types',
				'hook_priority' => 4,
			]
		);
		$this->content_types->add(
			[
				'id' => 'publication',
				'owner' => 'Hexa PR Wire Core',
				'description' => 'Source publication registry used by release taxonomy mappings and delivery URLs.',
				'post_type' => [
					'key' => 'publication',
					'singular' => 'Publication',
					'plural' => 'Publications',
					'rewrite_slug' => 'publication',
					'args' => [
						'public' => true,
						'publicly_queryable' => true,
						'show_ui' => true,
						'show_in_menu' => true,
						'show_in_nav_menus' => true,
						'show_in_admin_bar' => true,
							'show_in_rest' => true,
							'has_archive' => false,
							'can_export' => false,
						'hierarchical' => false,
						'supports' => [ 'title', 'editor', 'thumbnail' ],
						'taxonomies' => [ 'category', 'post_tag' ],
					],
				],
			]
		);

		$this->taxonomies = new TaxonomyRegistry( [ 'hook_priority' => 4 ] );
		$this->taxonomies->add(
			[
				'id' => 'publication',
				'taxonomy' => 'publication',
				'label' => 'Publications',
				'owner' => 'Hexa PR Wire Core',
				'description' => 'Permitted publication destinations for source press releases.',
				'object_types' => [ 'post' ],
				'args' => [
					'public' => true,
					'publicly_queryable' => true,
					'hierarchical' => true,
					'show_ui' => true,
					'show_in_menu' => true,
					'show_in_nav_menus' => true,
					'show_tagcloud' => true,
					'show_in_quick_edit' => true,
					'show_admin_column' => false,
					'show_in_rest' => true,
					'query_var' => true,
					'rewrite' => [ 'slug' => 'publication', 'with_front' => true, 'hierarchical' => false ],
				],
			]
		);
	}

	public function register(): void {
		$this->content_types->register();
		$this->taxonomies->register();
	}

	public function content_types(): ContentTypeRegistry {
		return $this->content_types;
	}

	public function taxonomies(): TaxonomyRegistry {
		return $this->taxonomies;
	}
}
