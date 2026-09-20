<?php

namespace HexaPrWire\Core\Workflow;

final class NotificationSettings {
	public const OPTION = 'hprwc_notification_settings';

	/** @return array<string,mixed> */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );
		$defaults = [
			'pending_subject' => 'Release pending review: {title}',
			'pending_message' => "A Hexa PR Wire release is ready for review.\n\nTitle: {title}\nCustomer: {full_name}\nEdit: {edit_url}",
			'draft_subject' => (string) get_option( 'options_notify_draft_update_subject', '' ) ?: 'Update to your press release: {title}',
			'draft_message' => (string) get_option( 'options_notify_draft_update_message', '' ) ?: "Hello {first_name},<br><br>Your Hexa PR Wire draft has been updated.<br><br><a href=\"{edit_url}\">Review your draft</a>.",
			'live_subject' => 'Your Press Release is Live: {title}',
			'live_message' => "Hello {first_name},<br><br>Your press release has been published on Hexa PR Wire.<br><br>{link_output}",
			'onboarding_subject' => (string) get_option( 'options_email_subject', '' ) ?: 'Hexa PR Wire: Your submission portal is active',
			'onboarding_message' => (string) get_option( 'options_welcome_message', '' ) ?: "Hello {first_name},<br><br>Your Hexa PR Wire customer account is ready.<br><br><a href=\"{dashboard_url}\">Open your dashboard</a>.",
			'admin_emails' => implode( "\n", $this->legacy_admin_emails() ),
		];
		return array_merge( $defaults, is_array( $stored ) ? $stored : [] );
	}

	public function save( array $input ): void {
		$current = $this->all();
		foreach ( [ 'pending_subject', 'draft_subject', 'live_subject', 'onboarding_subject' ] as $key ) {
			$current[ $key ] = sanitize_text_field( (string) ( $input[ $key ] ?? $current[ $key ] ) );
		}
		foreach ( [ 'pending_message', 'draft_message', 'live_message', 'onboarding_message' ] as $key ) {
			$current[ $key ] = wp_kses_post( (string) ( $input[ $key ] ?? $current[ $key ] ) );
		}
		$current['admin_emails'] = implode( "\n", $this->normalize_emails( (string) ( $input['admin_emails'] ?? '' ) ) );
		update_option( self::OPTION, $current, false );
	}

	/** @return string[] */
	public function admin_emails(): array {
		return $this->normalize_emails( (string) $this->all()['admin_emails'] );
	}

	public function interpolate( string $template, \WP_Post $post, ?\WP_User $user = null ): string {
		$user ??= get_userdata( (int) $post->post_author ) ?: null;
		$values = [
			'{title}' => get_the_title( $post ),
			'{first_name}' => $user ? (string) $user->first_name : '',
			'{last_name}' => $user ? (string) $user->last_name : '',
			'{full_name}' => $user ? (string) $user->display_name : '',
			'{permalink}' => get_permalink( $post ),
			'{edit_url}' => get_edit_post_link( $post->ID, '' ) ?: admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			'{dashboard_url}' => admin_url( 'edit.php' ),
			'{link_output}' => (string) get_post_meta( $post->ID, 'link_output', true ),
		];
		return strtr( $template, $values );
	}

	/** @return string[] */
	private function normalize_emails( string $raw ): array {
		$values = preg_split( '/[\s,;]+/', $raw ) ?: [];
		return array_values( array_unique( array_filter( array_map( 'sanitize_email', $values ), 'is_email' ) ) );
	}

	/** @return string[] */
	private function legacy_admin_emails(): array {
		$count = min( 100, max( 0, (int) get_option( 'options_notification_admin_emails', 0 ) ) );
		$emails = [];
		for ( $index = 0; $index < $count; $index++ ) {
			$email = sanitize_email( (string) get_option( "options_notification_admin_emails_{$index}_email", '' ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		if ( ! $emails ) {
			$emails[] = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		return array_values( array_filter( $emails, 'is_email' ) );
	}
}
