<?php

namespace HexaPrWire\Core\Cli;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Migration\LegacyMigration;
use HexaPrWire\Core\Migration\ParityReport;
use HexaPrWire\Core\Migration\StatusReport;

final class Commands implements Module {
	public function register(): void {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'hprwc', $this );
		}
	}

	/** Prints the current Core and legacy ownership report. */
	public function status(): void {
		$this->output( ( new StatusReport() )->all() );
	}

	/** Runs deterministic Core parity checks. Pass --post-migration after the legacy handoff. */
	public function parity( array $args, array $assoc_args ): void {
		unset( $args );
		$report = ( new ParityReport() )->run( isset( $assoc_args['post-migration'] ) );
		$this->output( $report );
		if ( empty( $report['passed'] ) ) {
			\WP_CLI::halt( 1 );
		}
	}

	/** Shows the migration plan. Pass --execute to disable replaced snippets and UI definitions. */
	public function migrate( array $args, array $assoc_args ): void {
		unset( $args );
		$migration = new LegacyMigration();
		if ( ! isset( $assoc_args['execute'] ) ) {
			$this->output( [ 'dry_run' => true, 'plan' => $migration->plan() ] );
			return;
		}
		try {
			$this->output( [ 'dry_run' => false, 'manifest' => $migration->migrate(), 'next' => 'Run hprwc parity --post-migration in a fresh process.' ] );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/** Restores the exact pre-migration states from the saved manifest. Requires --execute. */
	public function rollback( array $args, array $assoc_args ): void {
		unset( $args );
		if ( ! isset( $assoc_args['execute'] ) ) {
			\WP_CLI::error( 'Rollback is read-only unless --execute is supplied.' );
		}
		try {
			$this->output( [ 'manifest' => ( new LegacyMigration() )->rollback() ] );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
		}
	}

	/** @param array<string,mixed> $data */
	private function output( array $data ): void {
		\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
