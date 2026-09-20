<?php

namespace HexaPrWire\Core;

use HexaPrWire\Core\Bootstrap\Plugin as BootstrapPlugin;

/** Stable facade retained for integrations that referenced the v1 plugin class. */
final class Plugin {
	private static ?self $instance = null;
	private ?BootstrapPlugin $plugin = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		if ( $this->plugin instanceof BootstrapPlugin ) {
			return;
		}
		$this->plugin = new BootstrapPlugin();
		$this->plugin->boot();
	}

	public function runtime(): ?BootstrapPlugin {
		return $this->plugin;
	}

	public function resolver(): ?PublicationResolver {
		return $this->plugin?->resolver();
	}

	public function credentials(): ?CredentialRepository {
		return $this->plugin?->credentials();
	}

	public function destinations(): ?DestinationRegistry {
		return $this->plugin?->destinations();
	}

	public function force_sync(): ?ForceSyncService {
		return $this->plugin?->force_sync();
	}
}
