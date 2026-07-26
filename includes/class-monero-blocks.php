<?php
/** WooCommerce Blocks checkout integration for Monero. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/** Register Monero as a Blocks payment method. */
final class Monero_Blocks_Support extends AbstractPaymentMethodType {

	/** Payment method name. */
	protected $name = 'monero_gateway';

	/** Load gateway settings. */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_monero_gateway_settings', array() );
	}

	/** Mirror classic-checkout availability. */
	public function is_active() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways[ $this->name ] ) && $gateways[ $this->name ]->can_accept_payments();
	}

	/** Register and return the Blocks script handle. */
	public function get_payment_method_script_handles() {
		wp_register_script(
			'monero-gateway-blocks',
			plugins_url( 'assets/js/monero-blocks.js', MONERO_GATEWAY_WC_FILE ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			MONERO_GATEWAY_WC_VERSION,
			true
		);
		return array( 'monero-gateway-blocks' );
	}

	/** Return settings exposed to the Blocks script. */
	public function get_payment_method_data() {
		return array(
			'title'       => $this->settings['title'] ?? __( 'Monero (XMR)', 'monero_gateway' ),
			'description' => $this->settings['description'] ?? '',
			'discount'    => Monero_Gateway_Discount::normalize_percentage( $this->settings['discount'] ?? 0 ),
			'supports'    => array( 'products' ),
		);
	}
}
