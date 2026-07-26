<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monero_Gateway_Discount {
	const COUPON_CODE = 'monero-payment-discount';
	private $percentage;

	public function __construct( $percentage ) {
		$this->percentage = self::normalize_percentage( $percentage );
	}

	public static function normalize_percentage( $value ) {
		return is_numeric( $value ) ? min( 100.0, max( 0.0, (float) $value ) ) : 0.0;
	}

	public static function coupon_data( $method, $percentage ) {
		$percentage = self::normalize_percentage( $percentage );
		if ( 'monero_gateway' !== $method || $percentage <= 0 ) {
			return false;
		}
		return array(
			'discount_type'      => 'percent',
			'amount'             => $percentage,
			'individual_use'     => false,
			'exclude_sale_items' => false,
			'free_shipping'      => false,
			'virtual'            => true,
		);
	}

	public function register() {
		add_filter( 'woocommerce_get_shop_coupon_data', array( $this, 'virtual_coupon' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'classic_checkout_update' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'sync_cart_coupon' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'sync_cart_coupon' ) );
		add_filter( 'woocommerce_cart_totals_coupon_label', array( $this, 'coupon_label' ), 10, 2 );
	}

	public function coupon_label( $label, $coupon ) {
		return self::COUPON_CODE === $coupon->get_code() ? __( 'Monero payment discount', 'monero_gateway' ) : $label;
	}

	public function virtual_coupon( $data, $code ) {
		if ( self::COUPON_CODE !== wc_format_coupon_code( $code ) ) {
			return $data;
		}
		$generated = self::coupon_data( $this->selected_method(), $this->percentage );
		return false === $generated ? $data : $generated;
	}

	public function classic_checkout_update( $posted ) {
		$data = array();
		parse_str( (string) $posted, $data );
		$this->set_selected_method( isset( $data['payment_method'] ) ? $data['payment_method'] : '' );
		$this->sync_cart_coupon();
	}

	public function store_api_update( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['payment_method'] ) || ! is_string( $data['payment_method'] ) ) {
			return new WP_Error( 'monero_gateway_discount', __( 'Invalid payment method.', 'monero_gateway' ) );
		}
		$this->set_selected_method( $data['payment_method'] );
		$this->sync_cart_coupon();
		return array();
	}

	public function set_selected_method( $method ) {
		if ( WC()->session ) {
			WC()->session->set( 'chosen_payment_method', sanitize_key( (string) $method ) );
		}
	}

	private function selected_method() {
		return WC()->session ? (string) WC()->session->get( 'chosen_payment_method', '' ) : '';
	}

	public function sync_cart_coupon() {
		if ( ! WC()->cart || ! WC()->session ) {
			return;
		}
		$should_apply = false !== self::coupon_data( $this->selected_method(), $this->percentage );
		$applied      = WC()->cart->has_discount( self::COUPON_CODE );
		if ( $should_apply && ! $applied ) {
			WC()->cart->apply_coupon( self::COUPON_CODE );
		} elseif ( ! $should_apply && $applied ) {
			WC()->cart->remove_coupon( self::COUPON_CODE );
		}
	}
}
