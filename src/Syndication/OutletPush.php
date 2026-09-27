<?php

namespace HexaPrWire\Core\Syndication;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\PublicationResolver;
use HexaPrWire\Core\Support\Activity;

/**
 * Real-time syndication: publishing or updating a release makes each selected
 * outlet pull it now; trashing or deleting one makes each outlet apply
 * hexaprwire.com's deletion list now. Runs in the background (WP-Cron) so the
 * editor never waits on outlets. Outlets still pull every 4 hours as a safety net.
 */
final class OutletPush implements Module {
	public const PUSH_HOOK = 'hprwc_push_release';
	public const DELETE_HOOK = 'hprwc_push_deletion';

	public function __construct(
		private PublicationResolver $resolver,
		private DestinationRegistry $destinations,
		private ForceSyncService $force_sync,
		private OutletClient $client
	) {}

	public function register(): void {
		add_action( 'wp_after_insert_post', [ $this, 'after_save' ], 20, 4 );
		add_action( self::PUSH_HOOK, [ $this, 'push' ] );
		add_action( 'wp_trash_post', [ $this, 'before_removal' ] );
		add_action( 'before_delete_post', [ $this, 'before_removal' ] );
		add_action( 'untrashed_post', [ DeletionLedger::class, 'forget' ] );
		add_action( self::DELETE_HOOK, [ $this, 'push_deletion' ] );
	}

	public function after_save( int $post_id, \WP_Post $post, bool $update, ?\WP_Post $before = null ): void {
		unset( $update, $before );
		if ( 'post' !== $post->post_type || 'publish' !== $post->post_status || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( apply_filters( 'hprwc_push_on_publish', true, $post ) ) {
			self::schedule( self::PUSH_HOOK, $post_id, 10 );
		}
	}

	public function push( int $post_id ): void {
		$resolved = $this->destinations->enforce( $this->resolver->resolve_post( $post_id ) );
		$ok = 0;
		$failed = [];
		foreach ( $resolved['targets'] as $target ) {
			$result = $this->force_sync->run( $post_id, (int) $target['publication_id'], 'force' );
			if ( ! empty( $result['ok'] ) ) {
				$ok++;
			} else {
				$failed[] = (string) $target['domain'] . ': ' . (string) ( $result['message'] ?? 'failed' );
			}
		}
		if ( [] !== $resolved['targets'] ) {
			Activity::add( sprintf( 'Release pushed to %d of %d outlets.', $ok, count( $resolved['targets'] ) ), [] === $failed ? 'success' : 'warning', [ 'post_id' => $post_id, 'failed' => $failed ], 'syndication' );
		}
	}

	public function before_removal( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		DeletionLedger::record( $post, $this->client->hosts( $post_id ) );
		self::schedule( self::DELETE_HOOK, $post_id, 5 );
	}

	public function push_deletion( int $post_id ): void {
		$entry = DeletionLedger::get( $post_id );
		if ( null === $entry ) {
			return;
		}
		$failed = [];
		foreach ( (array) $entry['hosts'] as $host ) {
			$result = $this->client->command( (string) $host, 'deletions/sync' );
			DeletionLedger::result( $post_id, (string) $host, $result );
			if ( ! $result['ok'] ) {
				$failed[] = $host . ': ' . $result['message'];
			}
		}
		Activity::add( sprintf( 'Deletion pushed to %d outlets.', count( (array) $entry['hosts'] ) ), [] === $failed ? 'success' : 'warning', [ 'source_id' => $entry['id'], 'failed' => $failed ], 'syndication' );
	}

	private static function schedule( string $hook, int $post_id, int $delay ): void {
		if ( ! wp_next_scheduled( $hook, [ $post_id ] ) ) {
			wp_schedule_single_event( time() + $delay, $hook, [ $post_id ] );
		}
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
