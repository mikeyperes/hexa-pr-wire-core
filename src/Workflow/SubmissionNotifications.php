<?php

namespace HexaPrWire\Core\Workflow;

use HexaPrWire\Core\Contracts\CustomerPolicyRepository;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\Mailer;
use HexaPrWire\Core\Support\Activity;

/**
 * Alerts the administrator recipients about every customer submission:
 * a release entering review, or a release a customer published directly.
 */
final class SubmissionNotifications implements Module {
	private const LABELS = [
		'pending' => 'Pending-review',
		'published' => 'Customer-published',
	];

	public function __construct( private NotificationSettings $settings, private Mailer $mailer, private CustomerPolicyRepository $policies ) {}

	public function register(): void {
		add_action( 'transition_post_status', [ $this, 'notify_submission' ], 20, 3 );
	}

	public function notify_submission( string $new_status, string $old_status, \WP_Post $post ): void {
		$kind = $this->submission_kind( $new_status, $old_status, $post );
		if ( null === $kind ) {
			return;
		}
		$marker = '_hprwc_' . $kind . '_notice_' . md5( $post->post_modified_gmt . '|' . $new_status );
		if ( get_post_meta( $post->ID, $marker, true ) ) {
			return;
		}
		$config = $this->settings->all();
		$recipients = $this->settings->admin_emails();
		// A customer-published release also goes to that customer's own account email.
		$author = 'published' === $kind ? get_userdata( (int) $post->post_author ) : false;
		if ( $author instanceof \WP_User && is_email( $author->user_email ) ) {
			$recipients[] = $author->user_email;
		}
		$sent = $this->mailer->send(
			array_values( array_unique( $recipients ) ),
			$this->settings->interpolate( (string) $config[ $kind . '_subject' ], $post ),
			nl2br( esc_html( $this->settings->interpolate( (string) $config[ $kind . '_message' ], $post ) ) )
		);
		if ( $sent ) {
			update_post_meta( $post->ID, $marker, gmdate( 'c' ) );
		}
		Activity::add( self::LABELS[ $kind ] . ' notification ' . ( $sent ? 'sent.' : 'failed.' ), $sent ? 'success' : 'error', [ 'post_id' => $post->ID ], 'notifications' );
	}

	/** The notification a status change calls for, or null when none is due. */
	private function submission_kind( string $new_status, string $old_status, \WP_Post $post ): ?string {
		if ( 'post' !== $post->post_type || $new_status === $old_status ) {
			return null;
		}
		if ( 'pending' === $new_status ) {
			return 'pending';
		}
		$live = [ 'publish', 'future' ];
		if ( in_array( $new_status, $live, true ) && ! in_array( $old_status, $live, true ) && $this->policies->is_customer( get_current_user_id() ) ) {
			return 'published';
		}
		return null;
	}
}
