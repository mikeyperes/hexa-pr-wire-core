<?php

namespace HexaPrWire\Core\Fields;

use Hexa\PluginCore\AcfFieldFactory\AcfFieldFactory as Field;
use Hexa\PluginCore\FieldStructures\AcfFieldGroupRegistry;
use HexaPrWire\Core\Contracts\Module;

final class FieldGroups implements Module {
	private AcfFieldGroupRegistry $registry;

	public function __construct( private ReleaseLocation $release_location ) {
		$this->registry = new AcfFieldGroupRegistry(
			[
				'option_name' => 'hprwc_acf_field_groups',
				'ajax_action' => 'hprwc_save_acf_field_groups',
				'nonce_action' => 'hprwc_acf_field_groups',
				'hook_priority' => 5,
			]
		);
		foreach ( $this->definitions() as $definition ) {
			$this->registry->add( $definition );
		}
	}

	public function register(): void {
		$this->release_location->register();
		$this->registry->register();
	}

	public function registry(): AcfFieldGroupRegistry {
		return $this->registry;
	}

	/** @return array<int,array<string,mixed>> */
	private function definitions(): array {
		return [
			$this->definition( 'publication-registry', 'Publication Registry', 'group_6506a8003237a', [ $this, 'publication_group' ], [ 'dr', 'da', 'tf', 'status', 'url_nice', 'url_press_release_prefix', 'product_tier', 'featured', 'icon', 'new_source', 'url', 'email_delivery' ], 'Publication records' ),
			$this->definition( 'publication-taxonomy-map', 'Publication Taxonomy Mapping', 'group_69326f80f12ff', [ $this, 'taxonomy_group' ], [ 'publication' ], 'Publication taxonomy terms' ),
			$this->definition( 'release-public', 'Release Public Fields', 'group_64a72abaeeff0', [ $this, 'public_release_group' ], [ 'press_release_date', 'press_release_location', 'contact_information', 'link_output' ], 'Hexa PR Wire release posts' ),
			$this->definition( 'release-checklist', 'Release Editorial Checklist', 'group_64a6e0e504f9e', [ $this, 'checklist_group' ], [ 'checklist', 'notes_for_editorial_team' ], 'Hexa PR Wire release posts' ),
			$this->definition( 'release-private', 'Release Private Workflow', 'group_63a0418e58839', [ $this, 'private_release_group' ], [ 'submitted_by', 'disclaimer', 'link_output_html', 'canonical' ], 'Administrator release workflow' ),
		];
	}

	/** @param string[] $fields @return array<string,mixed> */
	private function definition( string $id, string $label, string $key, callable $provider, array $fields, string $location ): array {
		return [
			'id' => $id,
			'label' => $label,
			'description' => 'Registered in versioned Hexa PR Wire Core code with stable legacy keys.',
			'group_key' => $key,
			'enabled_default' => true,
			'definition' => $provider,
			'fields' => $fields,
			'location' => $location,
			'dependencies' => [ 'Advanced Custom Fields Pro' ],
		];
	}

	/** @return array<string,mixed> */
	public function publication_group(): array {
		return $this->group( 'group_6506a8003237a', 'Publication', [
			Field::text( [ 'key' => 'field_6506a80059c58', 'label' => 'DR', 'name' => 'dr' ] ),
			Field::text( [ 'key' => 'field_6506a80959c59', 'label' => 'DA', 'name' => 'da' ] ),
			Field::text( [ 'key' => 'field_6506a80c59c5a', 'label' => 'TF', 'name' => 'tf' ] ),
			Field::toggle( [ 'key' => 'field_6506a81059c5b', 'label' => 'Status', 'name' => 'status', 'message' => 'Active publication', 'default_value' => 1 ] ),
			Field::text( [ 'key' => 'field_6506a82059c5c', 'label' => 'URL Nice', 'name' => 'url_nice' ] ),
			Field::text( [ 'key' => 'field_6506a83659c5d', 'label' => 'Press Release URL Prefix', 'name' => 'url_press_release_prefix' ] ),
			Field::select( [ 'key' => 'field_6506a9496e720', 'label' => 'Product Tier', 'name' => 'product_tier', 'choices' => [ 'standard' => 'Standard Release', 'premium' => 'Premium Release' ], 'default_value' => 'standard' ] ),
			Field::toggle( [ 'key' => 'field_6506c74c765d9', 'label' => 'Featured', 'name' => 'featured', 'default_value' => 1 ] ),
			Field::image( [ 'key' => 'field_6506ceb4af76d', 'label' => 'Icon', 'name' => 'icon', 'return_format' => 'array' ] ),
			Field::toggle( [ 'key' => 'field_650757ba0e871', 'label' => 'New Source', 'name' => 'new_source', 'default_value' => 1 ] ),
			Field::text( [ 'key' => 'field_6507dcf363142', 'label' => 'URL', 'name' => 'url' ] ),
			Field::field( 'button_group', [ 'key' => 'field_6512f79098cbd', 'label' => 'Email Delivery', 'name' => 'email_delivery', 'choices' => [ 'Test' => 'Test' ] ] ),
		], [ [ [ 'param' => 'post_type', 'operator' => '==', 'value' => 'publication' ] ] ] );
	}

	/** @return array<string,mixed> */
	public function taxonomy_group(): array {
		return $this->group( 'group_69326f80f12ff', 'Taxonomy - Publication', [
			Field::field( 'post_object', [ 'key' => 'field_69326f81d09e3', 'label' => 'Publication Post', 'name' => 'publication', 'post_type' => [ 'publication' ], 'return_format' => 'id', 'ui' => 1 ] ),
		], [ [ [ 'param' => 'taxonomy', 'operator' => '==', 'value' => 'publication' ] ] ] );
	}

	/** @return array<string,mixed> */
	public function public_release_group(): array {
		return $this->group( 'group_64a72abaeeff0', 'Press Release — Public', [
			Field::text( [ 'key' => 'field_64a72abb01ec2', 'label' => 'Press Release Date', 'name' => 'press_release_date', 'instructions' => 'Example: January 1, 2026' ] ),
			Field::text( [ 'key' => 'field_64a72abb01ef9', 'label' => 'Press Release Location', 'name' => 'press_release_location', 'instructions' => 'Example: Miami, Florida' ] ),
			Field::repeater( [ 'key' => 'field_64a72abb01f2f', 'label' => 'Contact Information', 'name' => 'contact_information', 'layout' => 'table', 'sub_fields' => [
				Field::text( [ 'key' => 'field_64a72abb05373', 'label' => 'Name', 'name' => 'name' ] ),
				Field::text( [ 'key' => 'field_64a72abb053df', 'label' => 'URL', 'name' => 'url', 'placeholder' => 'https://, mailto:, or tel:' ] ),
			] ] ),
			Field::wysiwyg( [ 'key' => 'field_64a72abb02037', 'label' => 'Press Release Links', 'name' => 'link_output', 'instructions' => 'Generated delivery links appear here after approval.' ] ),
		], $this->release_location_rules() );
	}

	/** @return array<string,mixed> */
	public function checklist_group(): array {
		$toggle = static fn( string $key, string $label, string $name ): array => Field::toggle( [ 'key' => $key, 'label' => $label, 'name' => $name, 'message' => 'Completed' ] );
		return $this->group( 'group_64a6e0e504f9e', 'Press Release — Editorial Checklist', [
			Field::group( [ 'key' => 'field_64a6e0e61d1a5', 'label' => 'Going Live Checklist', 'name' => 'checklist', 'sub_fields' => [
				$toggle( 'field_64a75c0ed49a2', 'Title Requirements', 'title_requirements' ),
				$toggle( 'field_64a75bc4d49a1', 'Tone and Content', 'focus_on_announcement' ),
				$toggle( 'field_64a9c5d4cf5fb', 'Internal Linking', 'internal_linking' ),
				$toggle( 'field_64a75d2ceb7a1', 'Word Count', 'word_count_is_just_right' ),
				$toggle( 'field_64a75d69eb7a2', 'Subtitles Use H2 Tags', 'sub-titles_are_header_tags' ),
				$toggle( 'field_64a772e55b5bb', 'Photo Guidelines', 'photo_guidelines' ),
			] ] ),
			Field::textarea( [ 'key' => 'field_64a81e947e59a', 'label' => 'Notes for Editorial Team', 'name' => 'notes_for_editorial_team' ] ),
		], $this->release_location_rules() );
	}

	/** @return array<string,mixed> */
	public function private_release_group(): array {
		return $this->group( 'group_63a0418e58839', 'Press Release — Private Workflow', [
			Field::user( [ 'key' => 'field_651368fa55448', 'label' => 'Submitted By', 'name' => 'submitted_by', 'return_format' => 'id' ] ),
			Field::select( [ 'key' => 'field_64b997104e05a', 'label' => 'Disclaimer', 'name' => 'disclaimer', 'choices' => [
				'null' => 'None',
				'None of the information on this website is investment or financial advice. Hexa PR Wire, affiliates and syndication partners are not responsible for any financial losses sustained by acting on information provided on this website.' => 'Cryptocurrency / Financial',
				'Neither Hexa PR Wire nor any other syndicating outlets are responsible for the accuracy of the content contained in this press release. Readers are advised to exercise due diligence and cross-verify any information provided.' => 'Betting',
			] ] ),
			Field::wysiwyg( [ 'key' => 'field_652cb84e99150', 'label' => 'Link Output (HTML)', 'name' => 'link_output_html' ] ),
			Field::group( [ 'key' => 'field_6931f38cfd6e6', 'label' => 'Canonical', 'name' => 'canonical', 'sub_fields' => [
				Field::toggle( [ 'key' => 'field_6931f3d2fd6e7', 'label' => 'Enable Override', 'name' => 'enable' ] ),
				Field::text( [ 'key' => 'field_693201e4fd6e8', 'label' => 'Custom URL', 'name' => 'custom_url' ] ),
				Field::text( [ 'key' => 'field_6933d4dc2dd6f', 'label' => 'Live URL', 'name' => 'live_url' ] ),
			] ] ),
		], [ [ [ 'param' => 'hprwc_release', 'operator' => '==', 'value' => 'yes' ], [ 'param' => 'current_user_role', 'operator' => '==', 'value' => 'administrator' ] ] ] );
	}

	/** @return array<int,array<int,array<string,string>>> */
	private function release_location_rules(): array {
		return [ [ [ 'param' => 'hprwc_release', 'operator' => '==', 'value' => 'yes' ] ] ];
	}

	/** @param array<int,array<string,mixed>> $fields @param array<int,mixed> $location @return array<string,mixed> */
	private function group( string $key, string $title, array $fields, array $location ): array {
		return [
			'key' => $key,
			'title' => $title,
			'fields' => $fields,
			'location' => $location,
			'menu_order' => 0,
			'position' => 'normal',
			'style' => 'default',
			'label_placement' => 'top',
			'instruction_placement' => 'label',
			'active' => true,
			'show_in_rest' => 0,
		];
	}
}
