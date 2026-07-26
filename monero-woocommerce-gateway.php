<?php
/**
 * Plugin Name:       Monero WooCommerce Gateway
 * Plugin URI:        https://github.com/monero-integrations/monerowp
 * Description:       Extends WooCommerce by adding a Monero Gateway
 * Version:           4.0.0-dev.1
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            mosu-forge, SerHack
 * License:           MIT
 * Text Domain:       monero_gateway
 * WC requires at least: 7.0
 * WC tested up to:   10.9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MONERO_GATEWAY_WC_VERSION', '4.0.0-dev.1' );
define( 'MONERO_GATEWAY_WC_FILE', __FILE__ );

// HPOS + cart/checkout blocks compatibility.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );

// 5-minute cron interval for the reconcile safety net (WP ships hourly+ only).
add_filter( 'cron_schedules', function ( $s ) {
	if ( ! isset( $s['monero_gateway_5min'] ) ) {
		$s['monero_gateway_5min'] = array(
			'interval' => 300,
			'display'  => __( 'Every 5 minutes (Monero gateway)', 'monero_gateway' ),
		);
	}
	return $s;
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'monero_gateway_expire_orders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'monero_gateway_expire_orders' );
	}
	if ( ! wp_next_scheduled( 'monero_gateway_reconcile' ) ) {
		wp_schedule_event( time() + 300, 'monero_gateway_5min', 'monero_gateway_reconcile' );
	}
} );

add_action( 'monero_gateway_expire_orders', function () {
	if ( class_exists( 'WC_Gateway_Monero' ) ) {
		( new WC_Gateway_Monero() )->expire_orders();
	}
} );

add_action( 'monero_gateway_reconcile', function () {
	if ( class_exists( 'WC_Gateway_Monero' ) ) {
		( new WC_Gateway_Monero() )->reconcile_on_hold();
	}
} );

register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'monero_gateway_expire_orders' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'monero_gateway_expire_orders' );
	}
	if ( ! wp_next_scheduled( 'monero_gateway_reconcile' ) ) {
		wp_schedule_event( time() + 300, 'monero_gateway_5min', 'monero_gateway_reconcile' );
	}
} );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'monero_gateway_expire_orders' );
	wp_clear_scheduled_hook( 'monero_gateway_reconcile' );
} );

add_action( 'plugins_loaded', 'monero_gateway_wc_init' );

/**
 * Bootstrap: load gateway classes and wire WooCommerce hooks.
 */
function monero_gateway_wc_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Monero WooCommerce Gateway needs WooCommerce to be active.', 'monero_gateway' ) . '</p></div>';
		} );
		return;
	}

	require_once __DIR__ . '/includes/class-monero-util.php';
	require_once __DIR__ . '/includes/class-monero-node-config.php';
	require_once __DIR__ . '/includes/class-monero-node-fields.php';
	require_once __DIR__ . '/includes/class-monero-scanner.php';
	require_once __DIR__ . '/includes/class-wc-gateway-monero.php';
	require_once __DIR__ . '/includes/class-monero-shortcodes.php';
	add_action( 'init', function () {
		( new Monero_Gateway_Shortcodes( new WC_Gateway_Monero( false ) ) )->register();
	} );
	require_once __DIR__ . '/includes/class-monero-discount.php';
	$monero_settings = get_option( 'woocommerce_monero_gateway_settings', array() );
	$monero_discount = new Monero_Gateway_Discount( $monero_settings['discount'] ?? 0 );
	$monero_discount->register();
	$register_discount_update = function () use ( $monero_discount ) {
		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback( array( 'namespace' => 'monero-gateway', 'callback' => array( $monero_discount, 'store_api_update' ) ) );
		}
	};
	if ( did_action( 'woocommerce_blocks_loaded' ) ) {
		$register_discount_update();
	} else {
		add_action( 'woocommerce_blocks_loaded', $register_discount_update );
	}

	if ( is_admin() ) {
		require_once __DIR__ . '/includes/class-monero-admin-payments.php';
		add_action( 'admin_menu', array( 'Monero_Gateway_Admin_Payments', 'register_menu' ) );
	}

	// Native XMR store currency (cart total is the XMR amount — no price feed).
	add_filter( 'woocommerce_currencies', function ( $currencies ) {
		$currencies['XMR'] = __( 'Monero (XMR)', 'monero_gateway' );
		return $currencies;
	} );
	add_filter( 'woocommerce_currency_symbol', function ( $symbol, $currency ) {
		return 'XMR' === $currency ? 'ɱ' : $symbol;
	}, 10, 2 );
	add_filter( 'woocommerce_price_trim_zeros', '__return_false' );

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_Gateway_Monero';
		return $gateways;
	} );

	add_action( 'woocommerce_blocks_payment_method_type_registration', function ( $registry ) {
		require_once __DIR__ . '/includes/class-monero-blocks.php';
		$registry->register( new Monero_Blocks_Support() );
	} );

	// Buyer-facing scripts (enqueued from the gateway payment panel).
	add_action( 'wp_enqueue_scripts', function () {
		wp_register_script(
			'monero-gateway-qrcode',
			plugins_url( 'assets/js/qrcode-generator.min.js', MONERO_GATEWAY_WC_FILE ),
			array(),
			MONERO_GATEWAY_WC_VERSION,
			true
		);
		wp_register_script(
			'monero-gateway-widget',
			plugins_url( 'assets/js/monero-pay.js', MONERO_GATEWAY_WC_FILE ),
			array( 'monero-gateway-qrcode' ),
			MONERO_GATEWAY_WC_VERSION,
			true
		);
		wp_register_script(
			'monero-gateway-checkout',
			plugins_url( 'assets/js/monero-checkout.js', MONERO_GATEWAY_WC_FILE ),
			array(),
			MONERO_GATEWAY_WC_VERSION,
			true
		);
		wp_register_style(
			'monero-gateway-checkout',
			plugins_url( 'assets/css/monero-checkout.css', MONERO_GATEWAY_WC_FILE ),
			array(),
			MONERO_GATEWAY_WC_VERSION
		);
		wp_localize_script( 'monero-gateway-checkout', 'monero_gatewayL10n', array(
			'watching'    => __( 'Watching', 'monero_gateway' ),
			'detected'    => __( 'Detected', 'monero_gateway' ),
			'confirming'  => __( 'Confirming', 'monero_gateway' ),
			'confirmed'   => __( 'Confirmed', 'monero_gateway' ),
			'paid'        => __( 'Payment confirmed', 'monero_gateway' ),
			'mWatching'   => __( 'Watching the blockchain for your payment…', 'monero_gateway' ),
			'mMempool'    => __( 'Payment detected — waiting for the first confirmation.', 'monero_gateway' ),
			'mConfirming' => __( 'Confirming — {c}/{m} confirmations.', 'monero_gateway' ),
			'mPartial'    => __( 'Received {r} XMR — send {s} more (QR updated to the exact amount).', 'monero_gateway' ),
			'mLocked'     => __( 'Funds received — maturing on-chain…', 'monero_gateway' ),
			'mConnecting' => __( 'Connecting to the payment scanner…', 'monero_gateway' ),
			'mSyncing'    => __( 'Node catching up to the blockchain — your payment will appear here shortly.', 'monero_gateway' ),
			'mCancelled'  => __( 'This order was cancelled. Payment monitoring has stopped.', 'monero_gateway' ),
			'mFailed'     => __( 'This order failed. Payment monitoring has stopped.', 'monero_gateway' ),
			'mRefunded'   => __( 'This order was refunded. Payment monitoring has stopped.', 'monero_gateway' ),
			'mStopped'    => __( 'This page stopped refreshing — reload to check status', 'monero_gateway' ),
			'block'       => __( 'Latest block', 'monero_gateway' ),
		) );
	} );

	// Checkout style when the panel enqueues monero-gateway-checkout (often after wp_head).
	add_filter( 'print_scripts_array', function ( $handles ) {
		if ( in_array( 'monero-gateway-checkout', $handles, true ) ) {
			wp_enqueue_style( 'monero-gateway-checkout' );
		}
		return $handles;
	} );

	// ?wc-ajax=monero_gateway_status — buyer poll (proxied server-side).
	add_action( 'wc_ajax_monero_gateway_status', 'monero_gateway_wc_ajax_status' );
	add_action( 'wc_ajax_nopriv_monero_gateway_status', 'monero_gateway_wc_ajax_status' );

	// Settings "Check setup" (admin-ajax; not bound in the gateway constructor).
	add_action( 'wp_ajax_monero_gateway_test_node', 'monero_gateway_wc_ajax_test_node' );
}

/**
 * Buyer status poll endpoint.
 */
function monero_gateway_wc_ajax_status() {
	$gw = new WC_Gateway_Monero();
	$gw->ajax_status();
}

/**
 * Admin "Check setup" AJAX endpoint.
 */
function monero_gateway_wc_ajax_test_node() {
	( new WC_Gateway_Monero() )->ajax_test_node();
}
