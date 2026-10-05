<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;

final class EditorChecklist implements Module {
	public function register(): void {
		add_action( 'add_meta_boxes_post', [ $this, 'meta_box' ], 20 );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function meta_box(): void {
		add_meta_box( 'hprwc-release-checklist', 'Release Requirements', [ $this, 'render' ], 'post', 'side', 'high' );
	}

	public function render( \WP_Post $post ): void {
		$state = $this->state( $post );
		?>
		<div class="hprwc-checklist" data-hprwc-checklist>
			<p>Complete these four release requirements before publication.</p>
			<ul>
					<?php foreach ( [ 'h2' => 'Headings use H2', 'featured' => 'Featured image', 'location' => 'Location', 'date' => 'Date' ] as $key => $label ) : ?>
					<li data-hprwc-check="<?php echo esc_attr( $key ); ?>" class="<?php echo $state[ $key ] ? 'is-complete' : 'is-missing'; ?>"><span aria-hidden="true"><?php echo $state[ $key ] ? '✓' : '○'; ?></span> <?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p class="description" data-hprwc-checklist-summary><?php echo esc_html( array_sum( $state ) . '/4 complete' ); ?></p>
		</div>
		<?php
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'hprwc-admin', HPRWC_URL . 'assets/admin/core.css', [], HPRWC_VERSION );
		wp_enqueue_script( 'hprwc-editor-checklist', HPRWC_URL . 'assets/admin/editor-checklist.js', [ 'jquery', 'wp-data' ], HPRWC_VERSION, true );
	}

	/**
	 * A release passes when it has at least one H2 heading and no heading of
	 * any other level. Keep in step with headingsUseH2() in editor-checklist.js.
	 */
	public static function headings_use_h2( string $content ): bool {
		return 1 === preg_match( '/<h2\b/i', $content ) && 0 === preg_match( '/<h[13456]\b/i', $content );
	}

	/** @return array<string,bool> */
	private function state( \WP_Post $post ): array {
		return [
			'h2' => self::headings_use_h2( (string) $post->post_content ),
			'featured' => has_post_thumbnail( $post ),
			'location' => '' !== trim( (string) get_post_meta( $post->ID, 'press_release_location', true ) ),
			'date' => '' !== trim( (string) get_post_meta( $post->ID, 'press_release_date', true ) ),
		];
	}
}
