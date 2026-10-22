<?php

namespace HexaPrWire\Core\Frontend;

use Hexa\PluginCore\SearchQuery\ElementorPublicTextIndex;
use Hexa\PluginCore\Search\SearchModule;
use Hexa\PluginCore\Search\SearchRegistry;
use Hexa\PluginCore\SearchQuery\ResultTypeLabels;
use Hexa\PluginCore\SearchQuery\SearchIndex;
use Hexa\PluginCore\SearchQuery\SearchQueryConfiguration;
use Hexa\PluginCore\SearchQuery\SearchQueryEngine;
use HexaPrWire\Core\Contracts\Module;

/**
 * Full-site search on Hexa Core's one search: `[hexa_search id="hprw_site"]`
 * above the Elementor loop it narrows (the homepage's latest press releases,
 * the Search page) and on the ordinary `?s=` results page. Give the loop the
 * CSS class `hexa-listing-results`; on the results page the loop showing the
 * page's own results is found by itself. Core owns matching, the refresh,
 * the loader and the Result Type tag; this module declares only Hexa PR
 * Wire's sources, filters and labels.
 */
final class SiteSearch implements Module {
	public const PROFILE = 'hprw_site';

	public const LABELS = [
		'post'          => 'Press Release',
		'press-release' => 'External PR',
	];

	public const DEFAULT_LABEL = 'Site Content';

	public function register(): void {
		if ( ! class_exists( SearchRegistry::class ) || ! class_exists( ResultTypeLabels::class ) || ! class_exists( SearchIndex::class ) ) {
			return;
		}

		( new ElementorPublicTextIndex() )->register();
		( new SearchIndex( [ $this, 'settings' ] ) )->register();
		ResultTypeLabels::register( self::LABELS, self::DEFAULT_LABEL );
		( new SearchModule() )->register();
		SearchRegistry::register( self::PROFILE, [
			'search'     => [ $this, 'settings' ],
			'post_types' => [ 'post' ],
			'filters'    => [
				'category' => [ 'type' => 'taxonomy', 'taxonomy' => 'category', 'options' => 'terms', 'control' => 'checkboxes', 'label' => 'Category', 'hide_empty' => true, 'limit' => 20 ],
			],
			'labels'     => [
				'placeholder'  => 'Search press releases…',
				'results_one'  => '%d press release',
				'results_many' => '%d press releases',
				'empty'        => 'No press releases match. Try fewer words or clear a filter.',
			],
			'class'      => 'hprw-site-search',
		] );
		( new SearchQueryEngine( [ $this, 'settings' ] ) )->register();
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
			'index'            => true,
		];
	}
}
