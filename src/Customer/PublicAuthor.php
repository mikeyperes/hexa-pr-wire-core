<?php

namespace HexaPrWire\Core\Customer;

use HexaPrWire\Core\Contracts\Module;

/**
 * The public author of a customer's releases. Each customer defaults to themselves;
 * they can switch their default to Hexa PR Wire, and an administrator can set any
 * user. Each release can also be switched to Hexa PR Wire in its editor.
 * "Submitted by" always stays the customer, so ownership is unchanged (ReleaseOwner).
 */
final class PublicAuthor implements Module {
	public const META = 'hprwc_public_author';
	public const HOUSE = 'house';
	private const NONCE = 'hprwc_public_author';

	private bool $applying = false;

	public function __construct( private AccessPolicy $policy ) {}

	public function register(): void {
		add_action( 'hprwc_release_owner_captured', [ $this, 'apply_default' ], 10, 2 );
		add_action( 'add_meta_boxes_post', [ $this, 'add_box' ] );
		add_action( 'admin_action_editpost', [ $this, 'allow_customer_save' ] );
		add_filter( 'wp_insert_post_data', [ $this, 'resolve_author' ], 30, 2 );
		add_action( 'show_user_profile', [ $this, 'render_customer_choice' ], 20 );
		add_action( 'personal_options_update', [ $this, 'save_customer_choice' ] );
	}

	public static function house_id(): int {
		$user = get_user_by( 'login', 'hexaprwire' );
		return (int) apply_filters( 'hprwc_house_author_id', $user instanceof \WP_User ? (int) $user->ID : 0 );
	}

	/** '' (the customer), 'house' (Hexa PR Wire) or another user's ID. */
	public static function setting( int $customer_id ): string {
		return (string) get_user_meta( $customer_id, self::META, true );
	}

	public static function default_author( int $customer_id ): int {
		$value = self::setting( $customer_id );
		if ( self::HOUSE === $value && self::house_id() > 0 ) {
			return self::house_id();
		}
		return ctype_digit( $value ) && get_userdata( (int) $value ) ? (int) $value : $customer_id;
	}

	public static function save_setting( int $customer_id, string $value ): void {
		$value = sanitize_key( $value );
		if ( ctype_digit( $value ) && ( (int) $value === $customer_id || ! get_userdata( (int) $value ) ) ) {
			$value = '';
		}
		if ( ctype_digit( $value ) && (int) $value === self::house_id() ) {
			$value = self::HOUSE;
		}
		if ( '' === $value || ( self::HOUSE !== $value && ! ctype_digit( $value ) ) ) {
			delete_user_meta( $customer_id, self::META );
			return;
		}
		update_user_meta( $customer_id, self::META, $value );
	}

	/** A new release is created under its customer, then moved to their public author. */
	public function apply_default( int $post_id, int $owner_id ): void {
		if ( ! $this->policy->is_customer( $owner_id ) ) {
			return;
		}
		$author = self::default_author( $owner_id );
		if ( $author === $owner_id || $author <= 0 ) {
			return;
		}
		$this->applying = true;
		wp_update_post( [ 'ID' => $post_id, 'post_author' => $author ] );
		$this->applying = false;
	}

	public function add_box( \WP_Post $post ): void {
		if ( self::house_id() > 0 && $this->can_choose( $post ) ) {
			add_meta_box( 'hprwc-public-author', 'Public author', [ $this, 'render_box' ], 'post', 'side', 'high' );
		}
	}

	public function render_box( \WP_Post $post ): void {
		$is_house = (int) $post->post_author === self::house_id();
		$owner = get_userdata( ReleaseOwner::id( $post ) );
		wp_nonce_field( self::NONCE . '_' . $post->ID, 'hprwc_public_author_nonce' );
		?>
		<input type="hidden" name="hprwc_house_author_was" value="<?php echo $is_house ? '1' : '0'; ?>">
		<label><input type="checkbox" name="hprwc_house_author" value="1" <?php checked( $is_house ); ?>> Publish under Hexa PR Wire</label>
		<p class="description">Off: published under <?php echo esc_html( $owner ? $owner->display_name : 'the customer' ); ?>. "Submitted by" stays <?php echo esc_html( $owner ? $owner->display_name : 'the customer' ); ?> either way.</p>
		<?php
	}

	/**
	 * WordPress refuses a save by someone who is not the post's author and cannot edit
	 * others' posts. A customer owns their release through "Submitted by", so their
	 * save passes as themselves; resolve_author() then keeps the real author.
	 */
	public function allow_customer_save(): void {
		$user_id = get_current_user_id();
		$post = get_post( absint( $_POST['post_ID'] ?? 0 ) );
		if ( $post instanceof \WP_Post && $this->policy->is_customer( $user_id ) && ReleaseOwner::id( $post ) === $user_id ) {
			$_POST['post_author'] = $user_id;
			unset( $_POST['post_author_override'] );
		}
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $postarr @return array<string,mixed> */
	public function resolve_author( array $data, array $postarr ): array {
		$post_id = absint( $postarr['ID'] ?? 0 );
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $this->applying || ! $post instanceof \WP_Post || 'post' !== ( $data['post_type'] ?? '' ) ) {
			return $data;
		}
		$toggle = $this->requested_toggle( $post );
		if ( null !== $toggle ) {
			$data['post_author'] = $toggle ? self::house_id() : ReleaseOwner::id( $post );
		} elseif ( $this->policy->is_customer( get_current_user_id() ) ) {
			// Customers change the author only through the toggle.
			$data['post_author'] = (int) $post->post_author;
		}
		return $data;
	}

	public function render_customer_choice( \WP_User $user ): void {
		if ( ! $this->policy->is_customer( (int) $user->ID ) || self::house_id() <= 0 ) {
			return;
		}
		$value = self::setting( (int) $user->ID );
		$custom = '' !== $value && self::HOUSE !== $value ? get_userdata( (int) $value ) : false;
		?>
		<h2>Public author</h2>
		<table class="form-table" role="presentation"><tr>
			<th>New releases</th>
			<td>
				<input type="hidden" name="hprwc_default_house_was" value="<?php echo esc_attr( $value ); ?>">
				<label><input type="checkbox" name="hprwc_default_house" value="1" <?php checked( self::HOUSE, $value ); ?>> Publish my new releases under Hexa PR Wire</label>
				<p class="description">
					<?php if ( $custom ) : ?>Your new releases are currently published under <?php echo esc_html( $custom->display_name ); ?>. <?php endif; ?>
					Off: your own name. "Submitted by" always stays you, and each release can still be switched in its editor.
				</p>
			</td>
		</tr></table>
		<?php
	}

	public function save_customer_choice( int $user_id ): void {
		if ( $user_id !== get_current_user_id() || ! $this->policy->is_customer( $user_id ) || ! isset( $_POST['hprwc_default_house_was'] ) ) {
			return;
		}
		$was = sanitize_key( wp_unslash( (string) $_POST['hprwc_default_house_was'] ) );
		$house = ! empty( $_POST['hprwc_default_house'] );
		if ( $house && self::HOUSE !== $was ) {
			self::save_setting( $user_id, self::HOUSE );
		} elseif ( ! $house && self::HOUSE === $was ) {
			self::save_setting( $user_id, '' );
		}
	}

	private function can_choose( \WP_Post $post ): bool {
		$user_id = get_current_user_id();
		return current_user_can( 'edit_post', $post->ID )
			&& ( current_user_can( 'manage_options' ) || ReleaseOwner::id( $post ) === $user_id );
	}

	/** True or false when the editor toggle changed; null when untouched or not submitted. */
	private function requested_toggle( \WP_Post $post ): ?bool {
		$nonce = isset( $_POST['hprwc_public_author_nonce'] ) && is_string( $_POST['hprwc_public_author_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['hprwc_public_author_nonce'] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE . '_' . $post->ID ) || self::house_id() <= 0 || ! $this->can_choose( $post ) ) {
			return null;
		}
		$now = ! empty( $_POST['hprwc_house_author'] );
		$was = '1' === (string) ( $_POST['hprwc_house_author_was'] ?? '' );
		return $now === $was ? null : $now;
	}
}
