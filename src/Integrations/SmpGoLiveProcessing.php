<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Support\Activity;

/**
 * When SMP Publication Integration is active, a release that is submitted
 * (pending review, scheduled or published) gets SMP's Going Live steps run
 * automatically: missing excerpt, summary and FAQs are generated. SMP owns
 * the work and skips any post marked "Do not process this page". It runs in
 * the background so the editor's save is not slowed down.
 */
final class SmpGoLiveProcessing implements Module {
	private const EVENT = 'hprwc_smp_go_live_process';
	private const SUBMITTED = [ 'pending', 'future', 'publish' ];

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 30, 3 );
		add_action( self::EVENT, [ $this, 'process' ] );
	}

	public static function available(): bool {
		return false !== has_action( 'smpi_go_live_process' );
	}

	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || ! in_array( $new_status, self::SUBMITTED, true ) || in_array( $old_status, self::SUBMITTED, true ) || ! self::available() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EVENT, [ $post->ID ] ) ) {
			wp_schedule_single_event( time(), self::EVENT, [ $post->ID ] );
		}
	}

	public function process( int $post_id ): void {
		if ( ! self::available() ) {
			return;
		}
		do_action( 'smpi_go_live_process', $post_id );
		Activity::add( 'SMP Going Live processing ran for release ' . $post_id . '.', 'info', [ 'post_id' => $post_id ], 'smp' );
	}
}
