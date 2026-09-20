<?php

namespace HexaPrWire\Core\Admin;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Contracts\Mailer;
use HexaPrWire\Core\Customer\SubmissionMode;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressCustomerPolicyRepository;
use HexaPrWire\Core\Support\Activity;
use HexaPrWire\Core\Workflow\NotificationSettings;

final class CustomerProfile implements Module {
	private const NONCE = 'hprwc_customer_policy';

	public function __construct(
		private WordPressCustomerPolicyRepository $policies,
		private NotificationSettings $notifications,
		private Mailer $mailer
	) {}

	public function register(): void {
		add_action( 'show_user_profile', [ $this, 'render' ], 5 );
		add_action( 'edit_user_profile', [ $this, 'render' ], 5 );
		add_action( 'personal_options_update', [ $this, 'save' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save' ] );
		add_filter( 'manage_users_columns', [ $this, 'columns' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'column_value' ], 10, 3 );
		add_filter( 'users_list_table_query_args', [ $this, 'default_order' ] );
	}

	public function render( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$is_customer = $this->policies->is_customer( (int) $user->ID );
		$allowed = $this->policies->allowed_publications( (int) $user->ID );
		$prices = $this->policies->publication_prices( (int) $user->ID );
		$terms = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'orderby' => 'name' ] );
		$terms = is_wp_error( $terms ) ? [] : $terms;
		?>
		<h2 id="hprwc-customer-access">Hexa PR Wire Customer Access</h2>
		<?php wp_nonce_field( self::NONCE, 'hprwc_customer_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Customer role</th>
				<td><strong><?php echo $is_customer ? 'Hexa PR Wire Customer' : 'Not assigned'; ?></strong><p class="description">Assign the “Hexa PR Wire Customer” role above to enforce these controls.</p></td>
			</tr>
			<tr>
				<th><label for="hprwc_submission_mode">Submission permission</label></th>
				<td><select name="hprwc_submission_mode" id="hprwc_submission_mode">
					<?php foreach ( SubmissionMode::choices() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->policies->mode( (int) $user->ID ), $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select><p class="description">Permissions are enforced on classic editor, REST, direct saves, post lists, media, and publication assignment.</p></td>
			</tr>
			<tr>
				<th>Allowed publications and customer pricing</th>
				<td><div class="hprwc-entitlements">
					<table class="widefat striped"><thead><tr><th>Allow</th><th>Publication</th><th>Customer price</th></tr></thead><tbody>
					<?php foreach ( $terms as $term ) : ?>
						<tr><td><input type="checkbox" name="hprwc_allowed_publications[]" value="<?php echo esc_attr( $term->term_id ); ?>" <?php checked( in_array( (int) $term->term_id, $allowed, true ) ); ?>></td>
						<td><?php echo esc_html( $term->name ); ?></td>
						<td><label class="screen-reader-text" for="hprwc_price_<?php echo esc_attr( $term->term_id ); ?>">Price for <?php echo esc_html( $term->name ); ?></label><input class="small-text" type="number" min="0" step="0.01" id="hprwc_price_<?php echo esc_attr( $term->term_id ); ?>" name="hprwc_publication_prices[<?php echo esc_attr( $term->term_id ); ?>]" value="<?php echo esc_attr( $prices[ $term->term_id ] ?? '' ); ?>"></td></tr>
					<?php endforeach; ?>
					</tbody></table>
					<p class="description">Blank means no publication-specific override. Billing can resolve these values through the <code>hprwc_customer_publication_price</code> API filter.</p>
				</div></td>
			</tr>
			<tr><th><label for="hprwc_notification_emails">Notification emails</label></th><td><textarea class="large-text" rows="4" id="hprwc_notification_emails" name="hprwc_notification_emails"><?php echo esc_textarea( implode( "\n", $this->notification_emails( (int) $user->ID ) ) ); ?></textarea><p class="description">One address per line. The account email is always included at send time.</p></td></tr>
			<tr><th><label for="hprwc_email_subject">Onboarding subject override</label></th><td><input class="regular-text" id="hprwc_email_subject" name="hprwc_email_subject" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'email_subject', true ) ); ?>"></td></tr>
			<tr><th><label for="hprwc_welcome_message">Onboarding message override</label></th><td><textarea class="large-text" rows="6" id="hprwc_welcome_message" name="hprwc_welcome_message"><?php echo esc_textarea( (string) get_user_meta( $user->ID, 'welcome_message', true ) ); ?></textarea></td></tr>
			<tr><th><label for="hprwc_private_notes">Private notes</label></th><td><textarea class="large-text" rows="5" id="hprwc_private_notes" name="hprwc_private_notes"><?php echo esc_textarea( (string) get_user_meta( $user->ID, 'private_notes', true ) ); ?></textarea></td></tr>
			<tr><th>Imported source</th><td><code><?php echo esc_html( (string) get_user_meta( $user->ID, 'imported_source', true ) ?: '—' ); ?></code></td></tr>
			<tr><th>Welcome email</th><td><label><input type="checkbox" name="hprwc_send_welcome" value="1"> Send the resolved onboarding email after saving this profile</label></td></tr>
		</table>
		<?php
	}

	public function save( int $user_id ): void {
		$nonce = isset( $_POST['hprwc_customer_nonce'] ) && is_scalar( $_POST['hprwc_customer_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['hprwc_customer_nonce'] ) ) : '';
		if ( ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'edit_users' ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		$mode = isset( $_POST['hprwc_submission_mode'] ) && is_scalar( $_POST['hprwc_submission_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['hprwc_submission_mode'] ) ) : SubmissionMode::EDIT_EXISTING;
		$allowed = isset( $_POST['hprwc_allowed_publications'] ) && is_array( $_POST['hprwc_allowed_publications'] ) ? wp_unslash( $_POST['hprwc_allowed_publications'] ) : [];
		$prices = isset( $_POST['hprwc_publication_prices'] ) && is_array( $_POST['hprwc_publication_prices'] ) ? wp_unslash( $_POST['hprwc_publication_prices'] ) : [];
		$this->policies->save( $user_id, $mode, $allowed, $prices );
		$this->save_emails( $user_id, $this->posted_string( 'hprwc_notification_emails' ) );
		update_user_meta( $user_id, 'email_subject', sanitize_text_field( $this->posted_string( 'hprwc_email_subject' ) ) );
		update_user_meta( $user_id, 'welcome_message', wp_kses_post( $this->posted_string( 'hprwc_welcome_message' ) ) );
		update_user_meta( $user_id, 'private_notes', wp_kses_post( $this->posted_string( 'hprwc_private_notes' ) ) );
		Activity::add( 'Customer access policy saved.', 'success', [ 'user_id' => $user_id, 'mode' => $mode ], 'customers' );
		if ( ! empty( $_POST['hprwc_send_welcome'] ) ) {
			$this->send_welcome( $user_id );
		}
	}

	/** @param array<string,string> $columns @return array<string,string> */
	public function columns( array $columns ): array {
		$columns['hprwc_mode'] = 'PR access';
		$columns['hprwc_publications'] = 'Publications';
		$columns['hprwc_drafts'] = 'Drafts';
		$columns['hprwc_releases'] = 'Releases';
		return $columns;
	}

	public function column_value( string $value, string $column, int $user_id ): string {
		if ( ! $this->policies->is_customer( $user_id ) ) {
			return $value;
		}
		if ( 'hprwc_mode' === $column ) {
			return esc_html( SubmissionMode::choices()[ $this->policies->mode( $user_id ) ] ?? 'Edit existing' );
		}
		if ( 'hprwc_publications' === $column ) {
			$mode = $this->policies->mode( $user_id );
			return SubmissionMode::is_full( $mode ) ? 'All' : (string) count( $this->policies->allowed_publications( $user_id ) );
		}
		if ( 'hprwc_drafts' === $column ) {
			$count = $this->release_counts()[ $user_id ]['draft'] ?? 0;
			return $count > 0 ? '<a href="' . esc_url( add_query_arg( [ 'post_status' => 'draft', 'post_type' => 'post', 'author' => $user_id ], admin_url( 'edit.php' ) ) ) . '">' . esc_html( (string) $count ) . '</a>' : '0';
		}
		if ( 'hprwc_releases' === $column ) {
			return (string) ( $this->release_counts()[ $user_id ]['total'] ?? 0 );
		}
		return $value;
	}

	/** @param array<string,mixed> $args @return array<string,mixed> */
	public function default_order( array $args ): array {
		if ( ! isset( $_GET['orderby'] ) ) {
			$args['orderby'] = 'registered';
			$args['order'] = 'DESC';
		}
		return $args;
	}

	/** @return string[] */
	private function notification_emails( int $user_id ): array {
		$count = min( 100, max( 0, (int) get_user_meta( $user_id, 'notification_emails', true ) ) );
		$emails = [];
		for ( $index = 0; $index < $count; $index++ ) {
			$email = sanitize_email( (string) get_user_meta( $user_id, "notification_emails_{$index}_email", true ) );
			if ( is_email( $email ) ) {
				$emails[] = $email;
			}
		}
		return array_values( array_unique( $emails ) );
	}

	private function save_emails( int $user_id, string $raw ): void {
		$emails = preg_split( '/[\s,;]+/', $raw ) ?: [];
		$emails = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ), 'is_email' ) ) );
		$old_count = min( 100, max( 0, (int) get_user_meta( $user_id, 'notification_emails', true ) ) );
		for ( $index = count( $emails ); $index < $old_count; $index++ ) {
			delete_user_meta( $user_id, "notification_emails_{$index}_email" );
		}
		update_user_meta( $user_id, 'notification_emails', count( $emails ) );
		foreach ( $emails as $index => $email ) {
			update_user_meta( $user_id, "notification_emails_{$index}_email", $email );
			update_user_meta( $user_id, "_notification_emails_{$index}_email", 'field_65144592492dd' );
		}
	}

	private function send_welcome( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}
		$config = $this->notifications->all();
		$subject = (string) get_user_meta( $user_id, 'email_subject', true ) ?: (string) $config['onboarding_subject'];
		$message = (string) get_user_meta( $user_id, 'welcome_message', true ) ?: (string) $config['onboarding_message'];
		$replace = [ '{first_name}' => (string) $user->first_name, '{last_name}' => (string) $user->last_name, '{full_name}' => (string) $user->display_name, '{dashboard_url}' => admin_url( 'edit.php' ) ];
		$sent = $this->mailer->send( array_merge( [ $user->user_email ], $this->notification_emails( $user_id ) ), strtr( $subject, $replace ), strtr( $message, $replace ) );
		Activity::add( 'Customer onboarding email ' . ( $sent ? 'sent.' : 'failed.' ), $sent ? 'success' : 'error', [ 'user_id' => $user_id ], 'customers' );
	}

	private function posted_string( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? (string) wp_unslash( (string) $_POST[ $key ] ) : '';
	}

	/** @return array<int,array{draft:int,total:int}> */
	private function release_counts(): array {
		static $counts = null;
		if ( is_array( $counts ) ) {
			return $counts;
		}
		global $wpdb;
		$counts = [];
		$rows = $wpdb->get_results( "SELECT post_author, SUM(CASE WHEN post_status='draft' THEN 1 ELSE 0 END) drafts, COUNT(*) total FROM {$wpdb->posts} WHERE post_type='post' AND post_status NOT IN ('trash','auto-draft') GROUP BY post_author" );
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->post_author ] = [ 'draft' => (int) $row->drafts, 'total' => (int) $row->total ];
		}
		return $counts;
	}
}
