<?php

namespace Hexa\PluginCore\PressReleaseSyndication;

/** The RSS shape published by the wire source and read by its outlets. */
final class FeedProtocol {
	public const VERSION = '1.0';
	public const OUTLET_FEED = 'rss_publication';
	public const OUTLET_PARAMETER = 'publication';
	private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
	private const CREATOR_NS = 'http://purl.org/dc/elements/1.1/';
	private const ATOM_NS = 'http://www.w3.org/2005/Atom';
	private const MEDIA_NS = 'http://search.yahoo.com/mrss/';
	private const RELEASE_NS = 'https://hexaprwire.com/ns/press-release/' . self::VERSION;

	/** Open the RSS document and preserve the WordPress namespace hook. */
	public static function preamble(): string {
		ob_start();
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>';
		echo '<rss version="2.0" xmlns:content="' . self::CONTENT_NS . '" xmlns:dc="' . self::CREATOR_NS . '" xmlns:atom="' . self::ATOM_NS . '"';
		$preamble_level = ob_get_level();
		ob_start();
		do_action( 'rss2_ns' );
		if ( ob_get_level() > $preamble_level ) {
			$hook_namespaces = (string) ob_get_clean();
			if ( '' !== trim( $hook_namespaces ) ) {
				echo ' ' . ltrim( $hook_namespaces );
			}
		}
		$preamble = (string) ob_get_clean();
		$preamble = (string) preg_replace( '/\s*xmlns:(?:media|hpr)=(?:"[^"]*"|\'[^\']*\')/i', '', $preamble );
		$preamble = (string) preg_replace( '/(?<!\s)xmlns:/', ' xmlns:', $preamble );

		return ltrim( rtrim( $preamble ) )
			. ' xmlns:media="' . self::MEDIA_NS . '"'
			. ' xmlns:hpr="' . self::RELEASE_NS . '">';
	}

	/**
	 * Write one release item using the source's content, image and release fields.
	 *
	 * @param array{image_url:string,image_alt:string,release_date:string,release_location:string} $fields
	 * @param null|callable():?array{slug:string,name:string} $outlet_category_reader
	 */
	public static function write_item( \WP_Post $post, \WP_User|false $author, string $url, string $content, array $fields, ?callable $outlet_category_reader ): void {
		$slug = (string) $post->post_name;
		[ 'image_url' => $image_url, 'image_alt' => $image_alt, 'release_date' => $release_date, 'release_location' => $release_location ] = $fields;
		echo '<item>';
		echo '<title>' . esc_html( get_the_title( $post ) ) . '</title>';
		echo '<link>' . esc_url( $url ) . '</link>';
		echo '<pubDate>' . esc_html( mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ) ) . '</pubDate>';
		echo '<dc:creator><![CDATA[' . self::cdata( $author ? $author->display_name : '' ) . ']]></dc:creator>';
		echo '<guid isPermaLink="false">' . esc_html( (string) $post->guid ) . '</guid>';
		echo '<author_id><![CDATA[' . (int) $post->post_author . ']]></author_id>';
		echo '<author_slug><![CDATA[' . self::cdata( $author ? $author->user_nicename : '' ) . ']]></author_slug>';
		echo '<author_url><![CDATA[' . self::cdata( get_author_posts_url( (int) $post->post_author ) ) . ']]></author_url>';
		echo '<post_slug><![CDATA[' . self::cdata( $slug ) . ']]></post_slug>';
		echo '<post_url><![CDATA[' . self::cdata( $url ) . ']]></post_url>';
		echo '<hpr:sourceId><![CDATA[' . Protocol::POST_ID_PREFIX . (int) $post->ID . ']]></hpr:sourceId>';
		echo '<hpr:canonicalUrl><![CDATA[' . self::cdata( $url ) . ']]></hpr:canonicalUrl>';
		echo '<hpr:deck><![CDATA[' . self::cdata( get_the_excerpt( $post ) ) . ']]></hpr:deck>';
		echo '<hpr:releaseDate><![CDATA[' . self::cdata( $release_date ) . ']]></hpr:releaseDate>';
		echo '<hpr:releaseLocation><![CDATA[' . self::cdata( $release_location ) . ']]></hpr:releaseLocation>';
		if ( '' !== $image_url ) {
			echo '<media:content url="' . esc_url( $image_url ) . '" medium="image">';
			echo '<media:title type="plain"><![CDATA[' . self::cdata( '' !== $image_alt ? $image_alt : get_the_title( $post ) ) . ']]></media:title>';
			echo '</media:content>';
		}
		$category_slugs = [];
		$terms = wp_get_post_terms( $post->ID, 'category' );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_slugs[] = $term->slug;
				echo '<category domain="category" nicename="' . esc_attr( $term->slug ) . '"><![CDATA[' . self::cdata( $term->name ) . ']]></category>';
			}
		}
		$outlet_category = null !== $outlet_category_reader ? $outlet_category_reader() : null;
		if ( null !== $outlet_category ) {
			echo '<hpr:outletCategory nicename="' . esc_attr( $outlet_category['slug'] ) . '"><![CDATA[' . self::cdata( $outlet_category['name'] ) . ']]></hpr:outletCategory>';
		}
		if ( $category_slugs ) {
			echo '<item_cat><![CDATA[' . self::cdata( implode( ',', $category_slugs ) ) . ']]></item_cat>';
			echo '<cat><![CDATA[' . self::cdata( implode( ',', $category_slugs ) ) . ']]></cat>';
		}
		echo '<description><![CDATA[' . self::cdata( get_the_excerpt( $post ) ) . ']]></description>';
		echo '<content:encoded><![CDATA[' . self::cdata( $content ) . ']]></content:encoded>';
		echo '</item>';
	}

	private static function cdata( string $value ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $value );
	}

	/** Parse the current and legacy feed wire fields without applying outlet policy. */
	public static function read( string $xml_body ): \Generator {
		if ( '' === trim( $xml_body ) || ! function_exists( 'simplexml_load_string' ) ) {
			throw new \RuntimeException( 'The feed response is empty or SimpleXML is unavailable.' );
		}

		// The historical feed can omit the whitespace between namespace declarations.
		// Repair only that deterministic XML defect before passing the body to SimpleXML.
		$xml_body = preg_replace( '/(["\x27])xmlns:/', '$1 xmlns:', $xml_body ) ?? $xml_body;
		$previous = libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $xml_body, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			libxml_clear_errors();
			throw new \RuntimeException( 'The feed XML could not be parsed.' );
		}

		foreach ( $xml->channel->item ?? [] as $node ) {
			$content = $node->children( self::CONTENT_NS );
			$release = $node->children( self::RELEASE_NS );
			$categories = [];
			foreach ( $node->category as $category ) {
				$attributes = $category->attributes();
				$slug = sanitize_title( (string) ( $attributes['nicename'] ?? $category ) );
				if ( '' !== $slug ) {
					$categories[ $slug ] = wp_strip_all_tags( (string) $category ) ?: $slug;
				}
			}
			$outlet_category = '';
			if ( isset( $release->outletCategory ) ) {
				$attributes = $release->outletCategory->attributes();
				$outlet_category = sanitize_title( (string) ( $attributes['nicename'] ?? $release->outletCategory ) );
			}

			yield [
				'title'           => (string) $node->title,
				'content'         => isset( $content->encoded ) ? (string) $content->encoded : '',
				'excerpt'         => (string) $node->description,
				'guid'            => trim( (string) $node->guid ),
				'post_url'        => (string) $node->post_url,
				'link'            => (string) $node->link,
				'post_slug'       => (string) $node->post_slug,
				'published_gmt'   => self::normalize_date( (string) $node->pubDate ),
				'categories'      => $categories,
				'outlet_category' => $outlet_category,
				'featured_image'  => self::extract_image_url( $node ),
			];
		}
	}

	private static function extract_image_url( \SimpleXMLElement $node ): string {
		$media = $node->children( self::MEDIA_NS );
		foreach ( $media->content ?? [] as $content ) {
			$attributes = $content->attributes();
			$url = esc_url_raw( (string) ( $attributes["url"] ?? "" ) );
			if ( self::is_image_url( $url ) ) {
				return $url;
			}
		}

		return "";
	}

	private static function is_image_url( string $url ): bool {
		return "" !== $url
			&& false !== filter_var( $url, FILTER_VALIDATE_URL )
			&& (bool) preg_match( "/\.(?:jpe?g|png|gif|webp|avif)$/i", (string) wp_parse_url( $url, PHP_URL_PATH ) );
	}

	private static function normalize_date( string $date ): string {
		$timestamp = strtotime( $date );
		return false === $timestamp ? "" : gmdate( "Y-m-d H:i:s", $timestamp );
	}
}
