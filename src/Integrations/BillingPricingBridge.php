<?php

namespace HexaPrWire\Core\Integrations;

use HexaPrWire\Core\Contracts\Module;
use HexaPrWire\Core\Customer\AccessPolicy;
use HexaPrWire\Core\Infrastructure\WordPress\WordPressCustomerPolicyRepository;
use HexaPrWire\Core\Support\Activity;

final class BillingPricingBridge implements Module {
	public function __construct( private WordPressCustomerPolicyRepository $policies, private AccessPolicy $policy ) {}

	public function register(): void {
		add_filter( 'hprwc_customer_publication_price', [ $this, 'filtered_price' ], 10, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate' ], 30, 5 );
		add_filter( 'woocommerce_add_cart_item_data', [ $this, 'cart_data' ], 30, 3 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_price' ], 40 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'display_item' ], 30, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_item' ], 30, 4 );
		add_action( 'woocommerce_order_status_processing', [ $this, 'assign_fulfillment_publication' ], 30 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'assign_fulfillment_publication' ], 30 );
	}

	public function filtered_price( mixed $fallback, int $user_id, int $term_id ): mixed {
		$prices = $this->policies->publication_prices( $user_id );
		return $prices[ $term_id ] ?? $fallback;
	}

	/** @param array<string,mixed> $variations */
	public function validate( bool $passed, int $product_id, int $quantity, int $variation_id, array $variations ): bool {
		unset( $quantity, $variation_id, $variations );
		if ( ! $passed || ! $this->is_standard_product( $product_id ) ) {
			return $passed;
		}
		$term_id = $this->requested_term_id();
		if ( $term_id <= 0 ) {
			return true;
		}
		$user_id = get_current_user_id();
		if ( ! $this->policy->can_use_publication( $user_id, $term_id ) ) {
			wc_add_notice( 'That publication is not available for your account.', 'error' );
			return false;
		}
		return true;
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	public function cart_data( array $data, int $product_id, int $variation_id ): array {
		unset( $variation_id );
		if ( ! $this->is_standard_product( $product_id ) ) {
			return $data;
		}
		$term_id = $this->requested_term_id();
		if ( $term_id > 0 && $this->policy->can_use_publication( get_current_user_id(), $term_id ) ) {
			$data['_hprwc_publication_term_id'] = $term_id;
			$price = $this->filtered_price( null, get_current_user_id(), $term_id );
			if ( null !== $price ) {
				$data['_hpr_billing_price'] = $price;
			}
		}
		return $data;
	}

	public function apply_price( \WC_Cart $cart ): void {
		foreach ( $cart->get_cart() as $key => $item ) {
			$term_id = absint( $item['_hprwc_publication_term_id'] ?? 0 );
			$user_id = absint( $item['_hpr_billing_user_id'] ?? get_current_user_id() );
			$price = $term_id > 0 ? $this->filtered_price( null, $user_id, $term_id ) : null;
			if ( null === $price || ! is_numeric( $price ) ) {
				continue;
			}
			$price = number_format( (float) $price, 2, '.', '' );
			$cart->cart_contents[ $key ]['_hpr_billing_price'] = $price;
			if ( isset( $item['data'] ) && $item['data'] instanceof \WC_Product ) {
				$item['data']->set_price( $price );
			}
		}
	}

	/** @param array<int,array<string,string>> $data @param array<string,mixed> $item */
	public function display_item( array $data, array $item ): array {
		$term = get_term( absint( $item['_hprwc_publication_term_id'] ?? 0 ), 'publication' );
		if ( $term instanceof \WP_Term ) {
			$data[] = [ 'key' => 'Publication', 'value' => $term->name ];
		}
		return $data;
	}

	/** @param array<string,mixed> $values */
	public function save_item( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		unset( $cart_item_key, $order );
		$term_id = absint( $values['_hprwc_publication_term_id'] ?? 0 );
		$term = get_term( $term_id, 'publication' );
		if ( $term instanceof \WP_Term ) {
			$item->add_meta_data( 'Publication', $term->name, true );
			$item->add_meta_data( '_hprwc_publication_term_id', $term_id, true );
		}
	}

	public function assign_fulfillment_publication( int $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$post_id = absint( $order->get_meta( '_hpr_billing_fulfillment_post_id' ) );
		if ( $post_id <= 0 ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$term_id = absint( $item->get_meta( '_hprwc_publication_term_id' ) );
			if ( $term_id > 0 ) {
				wp_set_object_terms( $post_id, [ $term_id ], 'publication', false );
				Activity::add( 'Payment-created draft assigned to its purchased publication.', 'success', [ 'order_id' => $order_id, 'post_id' => $post_id, 'term_id' => $term_id ], 'billing' );
				break;
			}
		}
	}

	private function requested_term_id(): int {
		$requested = isset( $_REQUEST['publication'] ) && is_scalar( $_REQUEST['publication'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['publication'] ) ) : '';
		if ( '' === $requested ) {
			return 0;
		}
		if ( ctype_digit( $requested ) ) {
			$term = get_term( absint( $requested ), 'publication' );
		} else {
			$term = get_term_by( 'slug', sanitize_title( $requested ), 'publication' );
		}
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	private function is_standard_product( int $product_id ): bool {
		return class_exists( '\\HexaPrWire\\Billing\\Commerce\\ProductCatalog' )
			&& \HexaPrWire\Billing\Commerce\ProductCatalog::STANDARD === \HexaPrWire\Billing\Commerce\ProductCatalog::kind_for_product( $product_id );
	}
}
