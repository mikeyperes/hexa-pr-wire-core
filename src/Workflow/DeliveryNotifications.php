<?php

namespace HexaPrWire\Core\Workflow;

use Hexa\PluginCore\WpAdminAjax\AjaxGuard;
use HexaPrWire\Core\Contracts\Mailer;
use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Support\Activity;

final class DeliveryNotifications implements Module {
	private const NONCE = 'hprwc_delivery_notifications';

	public function __construct( private NotificationSettings $settings, private Mailer $mailer ) {}

	public function register(): void {
		add_action( 'add_meta_boxes_post', [ $this, 'meta_box' ], 40 );
		add_action( 'wp_ajax_hprwc_send_delivery_links', [ $this, 'ajax_live' ] );
		add_action( 'wp_ajax_hprwc_notify_draft_update', [ $this, 'ajax_draft' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function assets( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->post_type || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}
		wp_enqueue_script( 'hprwc-delivery-notifications', HPRWC_URL . 'assets/admin/delivery-notifications.js', [ 'jquery' ], HPRWC_VERSION, true );
	}

	public function meta_box(): void {
		if ( current_user_can( 'edit_others_posts' ) ) {
			add_meta_box( 'hprwc-delivery-notifications', 'Customer Notifications', [ $this, 'render' ], 'post', 'side', 'high' );
		}
	}

	public function render( \WP_Post $post ): void {
		$user_id = (int) ( get_post_meta( $post->ID, 'submitted_by', true ) ?: $post->post_author );
		$recipients = $this->user_emails( $user_id );
		?>
		<div class="hprwc-notification-box" data-hprwc-notifications data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p><strong>Recipients:</strong><br><?php echo esc_html( $recipients ? implode( ', ', $recipients ) : 'No valid customer recipients' ); ?></p>
			<p><button type="button" class="button button-primary" data-hprwc-notify="live" <?php disabled( ! $recipients ); ?>>Email live links</button></p>
			<p><button type="button" class="button" data-hprwc-notify="draft" <?php disabled( ! $recipients ); ?>>Notify draft update</button></p>
			<p class="description" data-hprwc-notify-status aria-live="polite"></p>
		</div>
		<?php
	}

	public function ajax_live(): void {
		$this->handle( 'live' );
	}

	public function ajax_draft(): void {
		$this->handle( 'draft' );
	}

	private function handle( string $type ): void {
		AjaxGuard::require_nonce_or_error( self::NONCE );
		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( [ 'code' => 'unauthorized', 'message' => 'You cannot send this notification.' ], 403 );
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			wp_send_json_error( [ 'code' => 'invalid_post', 'message' => 'Release not found.' ], 404 );
		}
		$user_id = (int) ( get_post_meta( $post_id, 'submitted_by', true ) ?: $post->post_author );
		$user = get_userdata( $user_id );
		$recipients = $this->user_emails( $user_id );
		$config = $this->settings->all();
		$subject_key = 'live' === $type ? 'live_subject' : 'draft_subject';
		$message_key = 'live' === $type ? 'live_message' : 'draft_message';
		$subject = $this->settings->interpolate( (string) $config[ $subject_key ], $post, $user ?: null );
		$message = $this->settings->interpolate( (string) $config[ $message_key ], $post, $user ?: null );
		$sent = $this->mailer->send( $recipients, $subject, $message );
		Activity::add( ucfirst( $type ) . '-release notification ' . ( $sent ? 'sent.' : 'failed.' ), $sent ? 'success' : 'error', [ 'post_id' => $post_id, 'recipient_count' => count( $recipients ) ], 'notifications' );
		if ( ! $sent ) {
			wp_send_json_error( [ 'code' => 'mail_failed', 'message' => 'WordPress could not send the message.' ], 502 );
		}
		wp_send_json_success( [ 'message' => 'Notification sent to ' . count( $recipients ) . ' recipient(s).' ] );
	}

	/** @return string[] */
	private function user_emails( int $user_id ): array {
		$user = get_userdata( $user_id );
		$emails = $user instanceof \WP_User ? [ $user->user_email ] : [];
		$count = min( 100, max( 0, (int) get_user_meta( $user_id, 'notification_emails', true ) ) );
		for ( $index = 0; $index < $count; $index++ ) {
			$emails[] = (string) get_user_meta( $user_id, "notification_emails_{$index}_email", true );
		}
		return array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) ) );
	}
}
