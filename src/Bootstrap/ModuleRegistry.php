<?php

namespace HexaPrWire\Core\Bootstrap;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class ModuleRegistry {
	/** @var ModuleInterface[] */
	private array $modules = [];

	public function add( ModuleInterface $module ): self {
		$this->modules[] = $module;
		return $this;
	}

	/** @return ModuleInterface[] */
	public function all(): array {
		return $this->modules;
	}

	public function attach_to( CoreBootstrap $bootstrap ): void {
		foreach ( $this->modules as $module ) {
			$bootstrap->add_module( $module );
		}
	}
}
