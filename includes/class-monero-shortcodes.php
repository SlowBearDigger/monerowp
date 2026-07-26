<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monero_Gateway_Shortcodes {
	private $gateway;

	public function __construct( $gateway ) {
		$this->gateway = $gateway;
	}

	public function register() {
		add_shortcode( 'monero-price', array( $this, 'price' ) );
		add_shortcode( 'monero-accepted-here', array( $this, 'accepted_here' ) );
	}

	public function price( $atts = array() ) {
		$atts     = shortcode_atts( array( 'currency' => get_woocommerce_currency() ), $atts, 'monero-price' );
		$currency = strtoupper( preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) $atts['currency'] ) ) );
		if ( '' === $currency ) {
			return '';
		}
		$rate = $this->gateway->get_rate_for_currency( $currency );
		if ( is_wp_error( $rate ) || ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return '';
		}
		if ( 'XMR' === $currency ) {
			return '1 XMR = 1 XMR';
		}
		$decimals = 'BTC' === $currency ? 8 : 5;
		return esc_html( '1 XMR = ' . number_format( (float) $rate, $decimals, '.', '' ) . ' ' . $currency );
	}

	public function accepted_here( $atts = array() ) {
		return '<img src="' . esc_url( plugins_url( 'assets/images/monero-accepted-here.png', MONERO_GATEWAY_WC_FILE ) ) . '" alt="' . esc_attr__( 'Monero accepted here', 'monero_gateway' ) . '">';
	}
}
