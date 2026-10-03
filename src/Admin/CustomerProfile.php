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
		$user_id = (int) $user->ID;
		$is_customer = $this->policies->is_customer( $user_id );
		$prices = $this->policies->publication_prices( $user_id );
		$terms = get_terms( [ 'taxonomy' => 'publication', 'hide_empty' => false, 'orderby' => 'name' ] );
		$terms = is_wp_error( $terms ) ? [] : $terms;
		$access_mode = $this->policies->publication_access_mode( $user_id );
		$allowed = $this->policies->allowed_publications( $user_id );
		$excluded = $this->policies->excluded_publications( $user_id, false );
		$modes = [
			'unrestricted' => [ 'Every publication', 'Can publish to all current and future publications. The list below only sets prices.' ],
			'restricted' => [ 'Only the ones you pick', 'Can publish only to publications switched on under “Can publish”. New publications stay off until you add them.' ],
			'excluded' => [ 'Everything except blocked', 'Can publish everywhere except publications switched on under “Blocked”. Blocking a group blocks all of its outlets, including future ones.' ],
		];
		?>
		<h2 id="hprwc-customer-access">Hexa PR Wire Customer Access</h2>
		<?php wp_nonce_field( self::NONCE, 'hprwc_customer_nonce' ); ?>
		<div class="hprwc-customer">
			<section class="hprwc-card">
				<header><h3>Account</h3><p>What this person is allowed to do with press releases.</p></header>
				<div class="hprwc-field">
					<span class="hprwc-label">Customer role</span>
					<div><span class="hprwc-badge <?php echo $is_customer ? 'success' : 'warning'; ?>"><?php echo $is_customer ? 'Hexa PR Wire Customer' : 'Not a customer'; ?></span>
					<?php if ( ! $is_customer ) : ?><p class="description">Set the Role above to “Hexa PR Wire Customer” for these settings to take effect.</p><?php endif; ?></div>
				</div>
				<div class="hprwc-field">
					<label class="hprwc-label" for="hprwc_submission_mode">Release permission</label>
					<div><select name="hprwc_submission_mode" id="hprwc_submission_mode">
						<?php foreach ( SubmissionMode::choices() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $this->policies->mode( $user_id ), $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select><p class="description">Applies everywhere the customer can touch a release: editor, post lists, media and the API.</p></div>
				</div>
			</section>

			<section class="hprwc-card hprwc-entitlements" data-mode="<?php echo esc_attr( $access_mode ); ?>">
				<header><h3>Publications &amp; pricing</h3><p>First choose the rule for where this customer can publish, then fine-tune individual publications and prices in the list.</p></header>
				<div class="hprwc-step"><span class="hprwc-step-number">1</span><div><strong>Where can they publish?</strong></div></div>
				<div class="hprwc-modes" role="radiogroup" aria-label="Publication access">
					<?php foreach ( $modes as $value => [ $title, $help ] ) : ?>
						<label class="hprwc-mode"><input type="radio" name="hprwc_publication_access_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $value, $access_mode ); ?>><span><strong><?php echo esc_html( $title ); ?></strong><small><?php echo esc_html( $help ); ?></small></span></label>
					<?php endforeach; ?>
				</div>
				<div class="hprwc-step"><span class="hprwc-step-number">2</span><div><strong>Publications</strong>
					<p class="hprwc-hint" data-for="unrestricted">Every publication is open to this customer. Enter a price only where they should pay something other than the standard store price.</p>
					<p class="hprwc-hint" data-for="restricted">Switch on each publication they can publish to. Anything left off is unavailable.</p>
					<p class="hprwc-hint" data-for="excluded">Switch on each publication to block. Everything left off stays available.</p>
				</div></div>
				<div class="hprwc-toolbar">
					<input type="search" class="hprwc-search" placeholder="Search publications…" aria-label="Search publications">
					<span class="hprwc-summary" aria-live="polite"></span>
					<span class="hprwc-bulk"><button type="button" class="button" data-bulk="1">Switch all on</button> <button type="button" class="button" data-bulk="0">Switch all off</button></span>
				</div>
				<div class="hprwc-table-scroll">
					<table class="hprwc-publications">
						<thead><tr><th>Publication</th><th class="hprwc-col-allow">Can publish</th><th class="hprwc-col-exclude">Blocked</th><th class="hprwc-col-price">Customer price</th></tr></thead>
						<tbody>
						<?php foreach ( $this->publication_rows( $terms ) as [ $term, $depth, $outlets ] ) :
							$id = (int) $term->term_id; ?>
							<tr class="hprwc-pub<?php echo $depth ? ' is-outlet' : ''; ?>" data-term="<?php echo esc_attr( (string) $id ); ?>" data-parent="<?php echo esc_attr( (string) $term->parent ); ?>" data-name="<?php echo esc_attr( strtolower( $term->name ) ); ?>">
								<td class="hprwc-pub-name" style="--hprwc-depth:<?php echo esc_attr( (string) $depth ); ?>"><?php echo esc_html( $term->name ); ?><?php if ( $outlets ) : ?> <span class="hprwc-tag">Group · <?php echo esc_html( sprintf( _n( '%d outlet', '%d outlets', $outlets ), $outlets ) ); ?></span><?php endif; ?></td>
								<td class="hprwc-col-allow"><?php $this->toggle( 'hprwc_allowed_publications[]', $id, in_array( $id, $allowed, true ), 'Allow ' . $term->name ); ?></td>
								<td class="hprwc-col-exclude"><?php $this->toggle( 'hprwc_excluded_publications[]', $id, in_array( $id, $excluded, true ), 'Block ' . $term->name ); ?><span class="hprwc-inherited">Blocked by group</span></td>
								<td class="hprwc-col-price"><span class="hprwc-price"><span aria-hidden="true">$</span><input type="number" min="0" step="0.01" id="hprwc_price_<?php echo esc_attr( (string) $id ); ?>" name="hprwc_publication_prices[<?php echo esc_attr( (string) $id ); ?>]" value="<?php echo esc_attr( (string) ( $prices[ $id ] ?? '' ) ); ?>" placeholder="Standard" aria-label="Price for <?php echo esc_attr( $term->name ); ?>"></span></td>
							</tr>
						<?php endforeach; ?>
						<tr class="hprwc-empty" hidden><td colspan="4">No publications match your search.</td></tr>
						</tbody>
					</table>
				</div>
				<p class="description">Leave a price empty to charge the standard store price. Prices save on their own and never change access.</p>
			</section>

			<section class="hprwc-card">
				<header><h3>Notifications</h3><p>Who gets emailed about this customer's releases.</p></header>
				<div class="hprwc-field">
					<label class="hprwc-label" for="hprwc_notification_emails">Extra recipients</label>
					<div><textarea rows="3" id="hprwc_notification_emails" name="hprwc_notification_emails" placeholder="name@example.com"><?php echo esc_textarea( implode( "\n", $this->notification_emails( $user_id ) ) ); ?></textarea><p class="description">One address per line. The account email always gets a copy.</p></div>
				</div>
			</section>

			<section class="hprwc-card">
				<header><h3>Welcome email</h3><p>The onboarding email this customer receives. Leave the fields empty to use the standard text from Hexa PR Wire settings.</p></header>
				<div class="hprwc-field">
					<label class="hprwc-label" for="hprwc_email_subject">Subject</label>
					<div><input id="hprwc_email_subject" name="hprwc_email_subject" value="<?php echo esc_attr( (string) get_user_meta( $user_id, 'email_subject', true ) ); ?>" placeholder="Standard subject"></div>
				</div>
				<div class="hprwc-field">
					<label class="hprwc-label" for="hprwc_welcome_message">Message</label>
					<div><textarea rows="6" id="hprwc_welcome_message" name="hprwc_welcome_message" placeholder="Standard message"><?php echo esc_textarea( (string) get_user_meta( $user_id, 'welcome_message', true ) ); ?></textarea><p class="description">Placeholders: {first_name}, {last_name}, {full_name}, {dashboard_url}.</p></div>
				</div>
				<div class="hprwc-field">
					<span class="hprwc-label">Send now</span>
					<div class="hprwc-inline"><?php $this->toggle( 'hprwc_send_welcome', 1, false, 'Send the welcome email' ); ?><span>Send the welcome email when this profile is saved</span></div>
				</div>
			</section>

			<section class="hprwc-card">
				<header><h3>Internal</h3><p>Only administrators see this.</p></header>
				<div class="hprwc-field">
					<label class="hprwc-label" for="hprwc_private_notes">Private notes</label>
					<div><textarea rows="4" id="hprwc_private_notes" name="hprwc_private_notes"><?php echo esc_textarea( (string) get_user_meta( $user_id, 'private_notes', true ) ); ?></textarea></div>
				</div>
				<div class="hprwc-field">
					<span class="hprwc-label">Imported from</span>
					<div><code><?php echo esc_html( (string) get_user_meta( $user_id, 'imported_source', true ) ?: '—' ); ?></code></div>
				</div>
			</section>
		</div>
		<?php
	}

	private function toggle( string $name, int $value, bool $checked, string $label ): void {
		printf(
			'<label class="hprwc-switch"><input type="checkbox" name="%1$s" value="%2$s" %3$s><span class="hprwc-switch-track" aria-hidden="true"></span><span class="screen-reader-text">%4$s</span></label>',
			esc_attr( $name ),
			esc_attr( (string) $value ),
			checked( $checked, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Groups first with their outlets nested beneath them.
	 *
	 * @param \WP_Term[] $terms
	 * @return array<int,array{0:\WP_Term,1:int,2:int}>
	 */
	private function publication_rows( array $terms ): array {
		$ids = array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, $terms );
		$children = [];
		foreach ( $terms as $term ) {
			$parent = in_array( (int) $term->parent, $ids, true ) ? (int) $term->parent : 0;
			$children[ $parent ][] = $term;
		}
		$rows = [];
		$walk = static function ( int $parent, int $depth ) use ( &$walk, &$rows, $children ): void {
			foreach ( $children[ $parent ] ?? [] as $term ) {
				$rows[] = [ $term, $depth, count( $children[ (int) $term->term_id ] ?? [] ) ];
				$walk( (int) $term->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $rows;
	}

	public function save( int $user_id ): void {
		$nonce = isset( $_POST['hprwc_customer_nonce'] ) && is_scalar( $_POST['hprwc_customer_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['hprwc_customer_nonce'] ) ) : '';
		if ( ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'edit_users' ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		$mode = isset( $_POST['hprwc_submission_mode'] ) && is_scalar( $_POST['hprwc_submission_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['hprwc_submission_mode'] ) ) : SubmissionMode::EDIT_EXISTING;
		$access_mode = isset( $_POST['hprwc_publication_access_mode'] ) && is_scalar( $_POST['hprwc_publication_access_mode'] )
			? sanitize_key( wp_unslash( (string) $_POST['hprwc_publication_access_mode'] ) )
			: $this->policies->publication_access_mode( $user_id );
		$allowed = isset( $_POST['hprwc_allowed_publications'] ) && is_array( $_POST['hprwc_allowed_publications'] ) ? wp_unslash( $_POST['hprwc_allowed_publications'] ) : [];
		$excluded = isset( $_POST['hprwc_excluded_publications'] ) && is_array( $_POST['hprwc_excluded_publications'] ) ? wp_unslash( $_POST['hprwc_excluded_publications'] ) : [];
		$prices = isset( $_POST['hprwc_publication_prices'] ) && is_array( $_POST['hprwc_publication_prices'] ) ? wp_unslash( $_POST['hprwc_publication_prices'] ) : [];
		$this->policies->save( $user_id, $mode, $allowed, $prices, $access_mode, $excluded );
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
			if ( SubmissionMode::is_full( $mode ) || ! $this->policies->publication_access_configured( $user_id ) ) {
				return 'All';
			}
			return WordPressCustomerPolicyRepository::ACCESS_EXCLUDED === $this->policies->publication_access_mode( $user_id )
				? 'All except ' . count( $this->policies->excluded_publications( $user_id ) )
				: (string) count( $this->policies->allowed_publications( $user_id ) );
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
