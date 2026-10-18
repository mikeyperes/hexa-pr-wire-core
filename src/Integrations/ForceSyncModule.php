<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Admin\ForceSyncAdmin;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\CredentialRepository;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\PublicationResolver;
use HexaPrWire\Core\Syndication\OutletCategories;
use HexaPrWire\Core\Syndication\OutletClient;

final class ForceSyncModule implements Module {
	public function __construct(
		private PublicationResolver $resolver,
		private CredentialRepository $credentials,
		private DestinationRegistry $destinations,
		private ForceSyncService $service
	) {}

	public function register(): void {
		if ( is_admin() ) {
			add_action( 'admin_init', [ $this->credentials, 'migrate_legacy_credential' ], 20 );
			add_action( 'admin_init', [ $this, 'seed_destinations' ], 25 );
			$categories = new OutletCategories( new OutletClient( $this->credentials, $this->resolver, $this->destinations ) );
			( new ForceSyncAdmin( $this->resolver, $this->credentials, $this->destinations, $this->service, $categories ) )->register();
		}
	}

	public function seed_destinations(): void {
		if ( current_user_can( 'manage_options' ) && ! $this->destinations->is_initialized() ) {
			$this->destinations->seed_from_resolver( $this->resolver );
		}
	}
}
