<?php

namespace HexaPrWire\Core\Syndication;

final class FeedRenderer {
	/** @param array<string,mixed> $query_args */
	public function render( array $query_args ): void {
		$this->render_multiple( [ $query_args ] );
	}

	/** @param array<int,array<string,mixed>> $query_sets */
	public function render_multiple( array $query_sets ): void {
		$queries = [];
		foreach ( $query_sets as $query_args ) {
			$queries[] = new \WP_Query( array_merge( [
				'post_type' => 'post',
				'post_status' => 'publish',
				'orderby' => 'date',
				'order' => 'DESC',
				'posts_per_page' => -1,
				'ignore_sticky_posts' => true,
			], $query_args ) );
		}
		status_header( 200 );
		header( 'Content-Type: ' . feed_content_type( 'rss-http' ) . '; charset=' . get_option( 'blog_charset' ), true );
		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?>';
		echo '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:atom="http://www.w3.org/2005/Atom"';
		do_action( 'rss2_ns' );
		echo '><channel>';
		echo '<title>' . esc_html( get_bloginfo_rss( 'name' ) ) . ' - Feed</title>';
		echo '<atom:link href="' . esc_url( get_self_link() ) . '" rel="self" type="application/rss+xml" />';
		echo '<link>' . esc_url( home_url( '/' ) ) . '</link>';
		echo '<lastBuildDate>' . esc_html( mysql2date( 'D, d M Y H:i:s +0000', get_lastpostmodified( 'GMT' ), false ) ) . '</lastBuildDate>';
		do_action( 'rss2_head' );
		$rendered = [];
		foreach ( $queries as $query ) {
			foreach ( $query->posts as $post ) {
				if ( isset( $rendered[ $post->ID ] ) ) {
					continue;
				}
				$rendered[ $post->ID ] = true;
				$this->item( $post );
			}
		}
		echo '</channel></rss>';
		wp_reset_postdata();
		exit;
	}

	private function item( \WP_Post $post ): void {
		setup_postdata( $post );
		$author = get_userdata( (int) $post->post_author );
		$slug = (string) $post->post_name;
		$url = get_permalink( $post );
		$content = apply_filters( 'the_content', $post->post_content );
		$content .= '<br><br><small><em>This article was originally published at: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></em></small>';
		echo '<item>';
		echo '<title>' . esc_html( get_the_title( $post ) ) . '</title>';
		echo '<link>' . esc_url( $url ) . '</link>';
		echo '<pubDate>' . esc_html( mysql2date( 'D, d M Y H:i:s +0000', $post->post_date_gmt, false ) ) . '</pubDate>';
		echo '<dc:creator><![CDATA[' . $this->cdata( $author ? $author->display_name : '' ) . ']]></dc:creator>';
		echo '<guid isPermaLink="false">' . esc_html( (string) $post->guid ) . '</guid>';
		echo '<author_id><![CDATA[' . (int) $post->post_author . ']]></author_id>';
		echo '<author_slug><![CDATA[' . $this->cdata( $author ? $author->user_nicename : '' ) . ']]></author_slug>';
		echo '<author_url><![CDATA[' . $this->cdata( get_author_posts_url( (int) $post->post_author ) ) . ']]></author_url>';
		echo '<post_slug><![CDATA[' . $this->cdata( $slug ) . ']]></post_slug>';
		echo '<post_url><![CDATA[' . $this->cdata( $url ) . ']]></post_url>';
		$category_slugs = [];
		$terms = wp_get_post_terms( $post->ID, 'category' );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$category_slugs[] = $term->slug;
				echo '<category domain="category" nicename="' . esc_attr( $term->slug ) . '"><![CDATA[' . $this->cdata( $term->name ) . ']]></category>';
			}
		}
		if ( $category_slugs ) {
			echo '<item_cat><![CDATA[' . $this->cdata( implode( ',', $category_slugs ) ) . ']]></item_cat>';
			echo '<cat><![CDATA[' . $this->cdata( implode( ',', $category_slugs ) ) . ']]></cat>';
		}
		echo '<description><![CDATA[' . $this->cdata( get_the_excerpt( $post ) ) . ']]></description>';
		echo '<content:encoded><![CDATA[' . $this->cdata( $content ) . ']]></content:encoded>';
		do_action( 'rss2_item' );
		echo '</item>';
	}

	private function cdata( string $value ): string {
		return str_replace( ']]>', ']]]]><![CDATA[>', $value );
	}
}
