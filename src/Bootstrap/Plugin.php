<?php

namespace HexaPrWire\Core\Bootstrap;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CoreRuntime\PluginContext;
use Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater;
use Hexa\PluginCore\PluginUpdates\UpdaterConfig;
use HexaPrWire\Core\Admin\CustomerNavigation;
use HexaPrWire\Core\Admin\CustomerActions;
use HexaPrWire\Core\Admin\CustomerProfile;
use HexaPrWire\Core\Admin\Dashboard;
use HexaPrWire\Core\Admin\EditorChecklist;
use HexaPrWire\Core\Admin\EditorStyles;
use HexaPrWire\Core\Content\ContentModel;
use HexaPrWire\Core\Cli\Commands;
use HexaPrWire\Core\CredentialRepository;
use HexaPrWire\Core\Customer\AccessController;
use HexaPrWire\Core\Customer\AccessPolicy;
use HexaPrWire\Core\Customer\RoleLifecycle;
use HexaPrWire\Core\DestinationRegistry;
use HexaPrWire\Core\Domain\Publication\PublicationUrl;
use HexaPrWire\Core\Fields\FieldGroups;
use HexaPrWire\Core\Fields\ReleaseLocation;
use HexaPrWire\Core\ForceSyncService;
use HexaPrWire\Core\Frontend\PressReleaseContent;
use HexaPrWire\Core\Frontend\PublicationShortcodes;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressCustomerPolicyRepository;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressMailer;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressPublicationRepository;
use HexaPrWire\Core\Integrations\BillingPricingBridge;
use HexaPrWire\Core\Integrations\ElementorPublicationQuery;
use HexaPrWire\Core\Integrations\ForceSyncModule;
use HexaPrWire\Core\Migration\StatusReport;
use HexaPrWire\Core\Onboarding\OnboardingApi;
use HexaPrWire\Core\PublicationResolver;
use HexaPrWire\Core\Seo\CanonicalService;
use HexaPrWire\Core\Syndication\DeletionManifest;
use HexaPrWire\Core\Syndication\FeedRegistry;
use HexaPrWire\Core\Syndication\FeedRenderer;
use HexaPrWire\Core\Workflow\DeliveryLinkGenerator;
use HexaPrWire\Core\Workflow\DeliveryNotifications;
use HexaPrWire\Core\Workflow\NotificationSettings;
use HexaPrWire\Core\Workflow\OwnershipService;
use HexaPrWire\Core\Workflow\SubmissionNotifications;

final class Plugin {
	private bool $booted = false;
	private PublicationResolver $resolver;
	private CredentialRepository $credentials;
	private DestinationRegistry $destinations;
	private ForceSyncService $force_sync;

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$context = new PluginContext( [
			'slug' => 'hexa-pr-wire-core',
			'basename' => plugin_basename( HPRWC_FILE ),
			'version' => HPRWC_VERSION,
			'path' => HPRWC_DIR,
			'url' => HPRWC_URL,
			'github_repo' => 'mikeyperes/hexa-pr-wire-core',
			'admin_page' => 'hexa-pr-wire-core',
			'capability' => 'manage_options',
		] );
		$bootstrap = new CoreBootstrap( $context );
		$modules = new ModuleRegistry();

		$policies = new WordPressCustomerPolicyRepository();
		$access = new AccessPolicy( $policies );
		$publications = new WordPressPublicationRepository();
		$urls = new PublicationUrl();
		$notification_settings = new NotificationSettings();
		$mailer = new WordPressMailer();

		$this->resolver = new PublicationResolver();
		$this->credentials = new CredentialRepository();
		$this->destinations = new DestinationRegistry();
		$this->force_sync = new ForceSyncService( $this->resolver, $this->credentials, $this->destinations );

		$modules
				->add( new RoleLifecycle() )
				->add( new Commands() )
			->add( new ContentModel() )
			->add( new FieldGroups( new ReleaseLocation( $access ) ) )
			->add( new AccessController( $access, $policies ) )
				->add( new CustomerNavigation( $access ) )
				->add( new CustomerProfile( $policies, $notification_settings, $mailer ) )
				->add( new CustomerActions( $access ) )
				->add( new EditorChecklist() )
				->add( new EditorStyles() )
			->add( new Dashboard( $notification_settings, new StatusReport() ) )
			->add( new OwnershipService() )
			->add( new DeliveryLinkGenerator( $publications, $urls ) )
			->add( new CanonicalService( $publications, $urls ) )
			->add( new SubmissionNotifications( $notification_settings, $mailer ) )
			->add( new DeliveryNotifications( $notification_settings, $mailer ) )
			->add( new FeedRegistry( new FeedRenderer() ) )
			->add( new DeletionManifest() )
			->add( new PressReleaseContent() )
			->add( new PublicationShortcodes( $publications, $urls ) )
			->add( new ElementorPublicationQuery( $publications ) )
			->add( new BillingPricingBridge( $policies, $access ) )
			->add( new OnboardingApi( $this->destinations ) )
			->add( new ForceSyncModule( $this->resolver, $this->credentials, $this->destinations, $this->force_sync ) )
			->add( new GitHubPluginUpdater( UpdaterConfig::from_plugin_file(
				HPRWC_FILE,
				'mikeyperes/hexa-pr-wire-core',
				[ 'proper_folder_name' => 'hexa-pr-wire-core', 'tested' => '7.1', 'requires' => '6.5', 'requires_php' => '8.0' ]
			) ) );

		$modules->attach_to( $bootstrap );
		$bootstrap->boot();
		if ( HPRWC_VERSION !== (string) get_option( 'hprwc_version', '' ) ) {
			update_option( 'hprwc_version', HPRWC_VERSION, false );
		}
	}

	public function resolver(): PublicationResolver {
		return $this->resolver;
	}

	public function credentials(): CredentialRepository {
		return $this->credentials;
	}

	public function destinations(): DestinationRegistry {
		return $this->destinations;
	}

	public function force_sync(): ForceSyncService {
		return $this->force_sync;
	}
}
