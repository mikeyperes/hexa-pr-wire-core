<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;

/**
 * "Release Status" box (top of the editor sidebar): word count, images,
 * internal/external links, H2/H3 counts and the linked payment. Shown to
 * everyone who can edit the release, customers included. The editor script
 * (editor-checklist.js) keeps the content counts live with the same rules.
 */
final class ReleaseStatus implements Module {
	public function register(): void {
		add_action( 'add_meta_boxes_post', [ $this, 'meta_box' ], 10 );
	}

	public function meta_box(): void {
		add_meta_box( 'hprwc-release-status', 'Release Status', [ $this, 'render' ], 'post', 'side', 'high' );
	}

	/**
	 * Counts for one release body. Keep in step with releaseStats() in
	 * editor-checklist.js.
	 *
	 * @return array{words:int,images:int,links_internal:int,links_external:int,h2:int,h3:int}
	 */
	public static function stats( string $html, string $site_host ): array {
		$text = trim( preg_replace( '/\s+/u', ' ', str_replace( "\xC2\xA0", ' ', html_entity_decode( (string) preg_replace( '/<[^>]*>/', ' ', $html ), ENT_QUOTES, 'UTF-8' ) ) ) );
		$internal = 0;
		$external = 0;
		preg_match_all( '/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $links );
		foreach ( $links[1] as $href ) {
			$href = trim( $href );
			if ( '' === $href || '#' === $href[0] || preg_match( '/^(mailto|tel|javascript):/i', $href ) ) {
				continue;
			}
			$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );
			$host = preg_replace( '/^www\./', '', $host );
			if ( '' === $host || $host === $site_host ) {
				$internal++;
			} else {
				$external++;
			}
		}
		return [
			'words' => '' === $text ? 0 : count( preg_split( '/\s+/u', $text ) ),
			'images' => preg_match_all( '/<img\b/i', $html ),
			'links_internal' => $internal,
			'links_external' => $external,
			'h2' => preg_match_all( '/<h2\b/i', $html ),
			'h3' => preg_match_all( '/<h3\b/i', $html ),
		];
	}

	public static function site_host(): string {
		return (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
	}

	public function render( \WP_Post $post ): void {
		$host = self::site_host();
		$s = self::stats( (string) $post->post_content, $host );
		$rows = [
			'words' => [ 'Word count', $s['words'] ],
			'images' => [ 'Images in text', $s['images'] ],
			'links_internal' => [ 'Internal links', $s['links_internal'] ],
			'links_external' => [ 'External links', $s['links_external'] ],
			'h2' => [ 'H2 headings', $s['h2'] ],
			'h3' => [ 'H3 headings', $s['h3'] ],
		];
		?>
		<div class="hprwc-release-status" data-hprwc-release-status data-site-host="<?php echo esc_attr( $host ); ?>">
			<table class="widefat striped" style="border:0"><tbody>
				<?php foreach ( $rows as $key => [ $label, $value ] ) : ?>
					<tr><td><?php echo esc_html( $label ); ?></td><td style="text-align:right"><strong data-hprwc-stat="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( (string) $value ); ?></strong></td></tr>
				<?php endforeach; ?>
				<tr><td>Featured image</td><td style="text-align:right"><strong><?php echo has_post_thumbnail( $post ) ? 'Yes' : 'No'; ?></strong></td></tr>
			</tbody></table>
			<h4 style="margin:12px 0 6px">Payment</h4>
			<?php $this->render_payment( $post ); ?>
		</div>
		<?php
	}

	private function render_payment( \WP_Post $post ): void {
		$order_id = (int) get_post_meta( $post->ID, 'billing_invoice_id', true );
		$order = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			echo '<p class="description">No payment is linked to this release.</p>';
			return;
		}
		$paid = $order->get_date_paid();
		$rows = [
			'Order' => '#' . $order->get_order_number(),
			'Status' => wc_get_order_status_name( $order->get_status() ),
			'Total' => wp_strip_all_tags( $order->get_formatted_order_total() ),
			'Method' => $order->get_payment_method_title() ?: '—',
			'Transaction' => $order->get_transaction_id() ?: '—',
			'Paid' => $paid ? wp_date( 'M j, Y g:i a', $paid->getTimestamp() ) : 'Not paid',
		];
		echo '<table class="widefat striped" style="border:0"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><td>' . esc_html( $label ) . '</td><td style="text-align:right">' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( current_user_can( 'edit_shop_orders' ) ) {
			echo '<p><a href="' . esc_url( $order->get_edit_order_url() ) . '">Open order</a></p>';
		}
	}
}
