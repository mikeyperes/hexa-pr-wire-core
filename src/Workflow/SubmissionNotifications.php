<?php

namespace HexaPrWire\Core\Workflow;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\Mailer;
use HexaPrWire\Core\Support\Activity;

final class SubmissionNotifications implements Module {
	public function __construct( private NotificationSettings $settings, private Mailer $mailer ) {}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'notify_pending' ], 20, 3 );
	}

	public function notify_pending( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'post' !== $post->post_type || 'pending' !== $new_status || 'pending' === $old_status ) {
			return;
		}
		$marker = '_hprwc_pending_notice_' . md5( $post->post_modified_gmt . '|' . $new_status );
		if ( get_post_meta( $post->ID, $marker, true ) ) {
			return;
		}
		$config = $this->settings->all();
		$sent = $this->mailer->send(
			$this->settings->admin_emails(),
			$this->settings->interpolate( (string) $config['pending_subject'], $post ),
			nl2br( esc_html( $this->settings->interpolate( (string) $config['pending_message'], $post ) ) )
		);
		if ( $sent ) {
			update_post_meta( $post->ID, $marker, gmdate( 'c' ) );
		}
		Activity::add( $sent ? 'Pending-review notification sent.' : 'Pending-review notification failed.', $sent ? 'success' : 'error', [ 'post_id' => $post->ID ], 'notifications' );
	}
}
