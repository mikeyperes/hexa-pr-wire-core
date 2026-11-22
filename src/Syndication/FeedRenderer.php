<?php

namespace HexaPrWire\Core\Syndication;

use Hexa\PluginCore\PressReleaseSyndication\FeedProtocol;

final class FeedRenderer {
	/** @param array<string,mixed> $query_args */
	public function render( array $query_args ): void {
		$this->render_multiple( [ $query_args ] );
	}

	/** @param array<int,array<string,mixed>> $query_sets */
	public function render_multiple( array $query_sets ): void {
		$this->send( $this->build( $query_sets ) );
	}

	public function send( string $xml ): void {
		status_header( 200 );
		header( 'Content-Type: ' . feed_content_type( 'rss-http' ) . '; charset=' . get_option( 'blog_charset' ), true );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * The feed XML for the given queries. Items carry exactly the release's own
	 * image: third-party `rss2_item` output (e.g. FIFU adding the outlet logo)
	 * is not part of the Hexa PR Wire feed contract.
	 *
	 * With $outlet (one outlet's feed) each item also carries the release's
	 * chosen category on that outlet as `<hpr:outletCategory>`.
	 *
	 * @param array<int,array<string,mixed>> $query_sets
	 */
	public function build( array $query_sets, string $outlet = '' ): string {
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
		global $post;
		$original_post = $post;
		ob_start();
		echo FeedProtocol::preamble();
		echo '<channel>';
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
				$this->item( $post, $outlet );
			}
		}
		echo '</channel></rss>';
		$post = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_reset_postdata();
		return (string) ob_get_clean();
	}

	private function item( \WP_Post $post, string $outlet = '' ): void {
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		$author = get_userdata( (int) $post->post_author );
		$url = get_permalink( $post );
		$content = apply_filters( 'the_content', $post->post_content );
		$content .= '<br><br><small><em>This article was originally published at: <a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a></em></small>';
		$image_id = get_post_thumbnail_id( $post );
		$image_url = $image_id > 0 ? (string) wp_get_attachment_url( $image_id ) : '';
		$image_alt = $image_id > 0 ? trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) : '';
		$release_date = trim( (string) get_post_meta( $post->ID, 'press_release_date', true ) );
		$release_location = trim( (string) get_post_meta( $post->ID, 'press_release_location', true ) );
		FeedProtocol::write_item(
			$post,
			$author,
			$url,
			$content,
			[
				'image_url'        => $image_url,
				'image_alt'        => $image_alt,
				'release_date'     => $release_date,
				'release_location' => $release_location,
			],
			'' !== $outlet ? static fn() => OutletCategories::for_outlet( $post->ID, $outlet ) : null
		);
	}
}
