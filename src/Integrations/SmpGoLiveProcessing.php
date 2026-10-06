<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Support\Activity;

/**
 * When SMP Publication Integration is active, a release that is submitted
 * (pending review, scheduled or published) gets SMP's Going Live steps run
 * automatically: missing excerpt, summary and FAQs are generated. SMP owns
 * the work and skips any post marked "Do not process this page".
 *
 * SMP only starts background jobs on Publish, which answers at once, so this
 * runs in the same save, after the editor's fields are stored. The editor that
 * loads next already shows the items being written.
 */
final class SmpGoLiveProcessing implements Module {
	private const SUBMITTED = [ 'pending', 'future', 'publish' ];

	/** @var array<int,true> Releases submitted in this request, processed once their save is complete. */
	private array $submitted = [];

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 30, 3 );
		add_action( 'wp_after_insert_post', [ $this, 'after_save' ], 20, 1 );
	}

	public static function available(): bool {
		return false !== has_action( 'smpi_go_live_process' );
	}

	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || ! in_array( $new_status, self::SUBMITTED, true ) || in_array( $old_status, self::SUBMITTED, true ) || ! self::available() ) {
			return;
		}
		$this->submitted[ $post->ID ] = true;
	}

	/** Runs after save_post, so the excerpt, summary and FAQ fields typed in the editor are already stored. */
	public function after_save( int $post_id ): void {
		if ( ! isset( $this->submitted[ $post_id ] ) ) {
			return;
		}
		unset( $this->submitted[ $post_id ] );
		$this->process( $post_id );
	}

	public function process( int $post_id ): void {
		if ( ! self::available() ) {
			return;
		}
		do_action( 'smpi_go_live_process', $post_id );
		Activity::add( 'SMP Going Live processing started for release ' . $post_id . '.', 'info', [ 'post_id' => $post_id ], 'smp' );
	}
}
