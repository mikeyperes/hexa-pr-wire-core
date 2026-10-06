<?php

namespace HexaPrWire\Core\Frontend;

use Hexa\PluginCore\SearchQuery\ElementorPublicTextIndex;
use Hexa\PluginCore\SearchQuery\ElementorSearchAdapter;
use Hexa\PluginCore\SearchQuery\ResultTypeLabels;
use Hexa\PluginCore\SearchQuery\SearchQueryConfiguration;
use HexaPrWire\Core\Contracts\Module;

/**
 * Full-site live search for Elementor Pro's Search widget with Query ID
 * `hprw_site_search`. Core owns matching, the live request, and the Result
 * Type tag; this module declares only Hexa PR Wire's sources and labels.
 */
final class SiteSearch implements Module {
	public const QUERY_ID = 'hprw_site_search';

	public const LABELS = [
		'post'          => 'Press Release',
		'press-release' => 'External PR',
	];

	public const DEFAULT_LABEL = 'Site Content';

	public function register(): void {
		if ( ! class_exists( ElementorSearchAdapter::class ) || ! class_exists( ResultTypeLabels::class ) ) {
			return;
		}

		( new ElementorPublicTextIndex() )->register();
		ResultTypeLabels::register( self::LABELS, self::DEFAULT_LABEL );
		( new ElementorSearchAdapter( [ $this, 'settings' ], self::QUERY_ID, null, 24 ) )->register();
	}

	/** @return array<string,mixed> */
	public function settings(): array {
		return [
			'enabled'          => true,
			'scope'            => 'all',
			'term_logic'       => 'all',
			'word_matching'    => 'prefix',
			'post_types'       => SearchQueryConfiguration::searchable_post_types(),
			'fields'           => [ 'title', 'content', 'excerpt', 'slug' ],
			'taxonomies'       => SearchQueryConfiguration::searchable_taxonomies(),
			'authors'          => true,
			'custom_fields'    => [ 'press_release_location', 'post_summary', ElementorPublicTextIndex::META_KEY ],
			'results_per_page' => 12,
			'orderby'          => 'relevance',
		];
	}
}
