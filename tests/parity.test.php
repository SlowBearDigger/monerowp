<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MONERO_GATEWAY_WC_FILE', __DIR__ . '/../monero-woocommerce-gateway.php' );

$pass = 0;
$fail = 0;
$shortcodes = array();
$options = array();
$transients = array();
$remote_response = null;
function ok_parity( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }
function parity_method( $class, $method ) { if ( ! method_exists( $class, $method ) ) { return null; } $reflection = new ReflectionMethod( $class, $method ); if ( PHP_VERSION_ID < 80100 ) {
    $reflection->setAccessible( true );
} return $reflection; }
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_attr__( $text ) { return $text; }
function esc_url( $text ) { return esc_html( $text ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function plugins_url( $path ) { return 'https://shop.test/plugin/' . ltrim( $path, '/' ); }
function shortcode_atts( $defaults, $atts ) { return array_merge( $defaults, $atts ); }
function get_woocommerce_currency() { return 'USD'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_option( $key, $default = false ) { global $options; return array_key_exists( $key, $options ) ? $options[ $key ] : $default; }
function update_option( $key, $value ) { global $options; $options[ $key ] = $value; return true; }
function get_transient( $key ) { global $transients; return array_key_exists( $key, $transients ) ? $transients[ $key ] : false; }
function set_transient( $key, $value ) { global $transients; $transients[ $key ] = $value; return true; }
function wp_remote_get() { global $remote_response; return $remote_response; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
function add_shortcode( $name, $callback ) { global $shortcodes; $shortcodes[ $name ] = $callback; }
class WP_Error {}
class WC_Payment_Gateway {
	public $settings = array();
	public function get_option( $key, $default = '' ) { return array_key_exists( $key, $this->settings ) ? $this->settings[ $key ] : $default; }
}
class WP_List_Table {
	public function __construct( $args = array() ) {}
}

class Parity_Rate_Gateway {
	public $rate = 150.0;
	public function get_rate_for_currency( $currency ) { return 'XMR' === $currency ? 1.0 : $this->rate; }
}

require_once __DIR__ . '/../includes/class-monero-util.php';
ok_parity( 'XMR decimal conversion preserves every piconero above the float boundary', 9007199254740993 === Monero_Util::xmr_to_pico( '9007.199254740993' ) );
ok_parity( 'same-origin requires the same scheme and effective port', Monero_Util::same_origin( '/thank-you', 'https://shop.test' ) && Monero_Util::same_origin( 'https://shop.test/order', 'https://shop.test' ) && ! Monero_Util::same_origin( 'http://shop.test/order', 'https://shop.test' ) && ! Monero_Util::same_origin( 'https://shop.test:444/order', 'https://shop.test' ) && ! Monero_Util::same_origin( 'javascript:alert(1)', 'https://shop.test' ) );
$mempool = array( 'txid' => 'mempool-tx', 'amount_atomic' => '100', 'confirmations' => 0, 'in_pool' => true, 'locked' => false, 'double_spend_seen' => false, 'out_key' => 'out', 'commitment_ok' => true );
$zero_conf = Monero_Util::summarize_payments( array( $mempool ), '100', '0', 0 );
ok_parity( 'zero-conf settles a committed conflict-free mempool payment', true === $zero_conf['paid'] && 'paid' === $zero_conf['status'] );
$mempool['double_spend_seen'] = true;
ok_parity( 'zero-conf still rejects a reported mempool conflict', false === Monero_Util::summarize_payments( array( $mempool ), '100', '0', 0 )['paid'] );

require_once __DIR__ . '/../includes/class-wc-gateway-monero.php';
$legacy = WC_Gateway_Monero::migrate_legacy_settings( array(
	'monero_address' => 'legacy-address',
	'viewkey'        => 'legacy-view-key',
	'confirms'       => '5',
	'valid_time'     => '3600',
) );
ok_parity( '3.x settings migrate without deleting rollback data', 'legacy-address' === $legacy['xmr_address'] && 'legacy-view-key' === $legacy['view_key'] && '5' === $legacy['min_confirmations'] && '1' === $legacy['expiry_hours'] && isset( $legacy['monero_address'] ) );

$rate_window = parity_method( 'WC_Gateway_Monero', 'rate_feed_is_stale' );
$has_rate_window = null !== $rate_window;
ok_parity( 'rate outage uses the configured grace period', $has_rate_window
	&& false === $rate_window->invoke( null, 1000, 15, 1899 )
	&& true === $rate_window->invoke( null, 1000, 15, 1900 ) );
$last_rate = parity_method( 'WC_Gateway_Monero', 'last_rate_within_grace' );
$has_last_rate = null !== $last_rate;
ok_parity( 'last successful rate is usable only inside the grace period', $has_last_rate
	&& 150.0 === $last_rate->invoke( null, array( 'rate' => '150', 'at' => 1000 ), 15, 1899 )
	&& null === $last_rate->invoke( null, array( 'rate' => '150', 'at' => 1000 ), 15, 1900 ) );

$rate_gateway = ( new ReflectionClass( 'WC_Gateway_Monero' ) )->newInstanceWithoutConstructor();
$rate_gateway->settings = array( 'price_source' => 'coingecko', 'fixed_rate' => '0', 'rate_stale_minutes' => '15', 'debug_log' => 'no' );
$rate_option = 'monero_gateway_last_rate_' . md5( "coingecko\0USD\0" );
$options[ $rate_option ] = array( 'rate' => '150', 'at' => time() - 14 * 60 );
$remote_response = new WP_Error();
$resolve_rate = parity_method( 'WC_Gateway_Monero', 'resolve_rate' );
$inside_grace = $resolve_rate->invoke( $rate_gateway, 'USD' );
$options[ $rate_option ]['at'] = time() - 16 * 60;
$outside_grace = $resolve_rate->invoke( $rate_gateway, 'USD' );
ok_parity( 'checkout reuses the saved rate only during the configured outage window', 150.0 === $inside_grace && is_wp_error( $outside_grace ) );

$rate_message = parity_method( 'WC_Gateway_Monero', 'rate_unavailable_message' );
$has_rate_message = null !== $rate_message;
ok_parity( 'rate outage exposes the merchant message with a safe default', $has_rate_message
	&& 'Back soon.' === $rate_message->invoke( null, ' Back soon. ' )
	&& false !== strpos( $rate_message->invoke( null, '' ), 'exchange rate' ) );

$no_js = parity_method( 'WC_Gateway_Monero', 'no_script_fallback_html' );
$has_no_js = null !== $no_js;
$no_js_html = $has_no_js ? $no_js->invoke( null, 'https://shop.test/order/7/?key=wc_test' ) : '';
ok_parity( 'no-JavaScript fallback refreshes and offers a manual status check', $has_no_js
	&& false !== strpos( $no_js_html, 'http-equiv="refresh"' )
	&& false !== strpos( $no_js_html, 'https://shop.test/order/7/?key=wc_test' )
	&& false !== strpos( $no_js_html, 'Check payment status' ) );

$has_currency_filter = method_exists( 'WC_Gateway_Monero', 'add_xmr_currency' );
$currencies = $has_currency_filter ? WC_Gateway_Monero::add_xmr_currency( array( 'USD' => 'United States dollar', 'GBP' => 'Pound sterling' ) ) : array();
ok_parity( 'adding XMR preserves WooCommerce currencies', $has_currency_filter
	&& 'United States dollar' === $currencies['USD']
	&& 'Pound sterling' === $currencies['GBP']
	&& isset( $currencies['XMR'] ) );

require_once __DIR__ . '/../includes/vendor/monero/load.php';
$payment_cn = new \MoneroIntegrations\MoneroPhp\Cryptonote( 'stagenet' );
$payment_keys = $payment_cn->decode_address( '5BEiTonHrFFgGSRAQTknCsEU9jRtGXEVBbv9bZSHCybmUT6aoA2V9M98rLFW2rfzyw5ayituBVETeG9Zkw3AAsyqE4T7N2n' );
$payment_plain = '0123456789abcdef';
$payment_cipher = $payment_cn->stealth_payment_id( $payment_plain, $payment_keys['spendKey'], '3b6765f2072e11438aaa22ae9168adf304c414d8da5de504dcdb46e397a6f604' );
ok_parity( 'encrypted payment ID roundtrip terminates and restores the ID', $payment_plain === $payment_cn->stealth_payment_id( $payment_cipher, $payment_keys['spendKey'], '3b6765f2072e11438aaa22ae9168adf304c414d8da5de504dcdb46e397a6f604' ) );

require_once __DIR__ . '/../includes/class-monero-shortcodes.php';
$shortcode_service = new Monero_Gateway_Shortcodes( new Parity_Rate_Gateway() );
$shortcode_service->register();
ok_parity( 'registers monero-price', isset( $shortcodes['monero-price'] ) );
ok_parity( 'registers monero-accepted-here', isset( $shortcodes['monero-accepted-here'] ) );
ok_parity( 'renders fixed USD rate', false !== strpos( $shortcode_service->price( array( 'currency' => 'USD' ) ), '150.00000 USD' ) );
ok_parity( 'renders native XMR rate', '1 XMR = 1 XMR' === $shortcode_service->price( array( 'currency' => 'XMR' ) ) );
ok_parity( 'renders accepted image', false !== strpos( $shortcode_service->accepted_here(), 'monero-accepted-here.png' ) );

require_once __DIR__ . '/../includes/class-monero-admin-payments.php';
ok_parity( 'pending view maps active payment states', array( 'watching', 'partial', 'underpaid', 'mempool', 'unconfirmed', 'confirming', 'locked' ) === Monero_Gateway_Admin_Payments_List::status_values_for_view( 'pending' ) );
ok_parity( 'paid view maps sufficient unconfirmed funds', array( 'paid_unconfirmed' ) === Monero_Gateway_Admin_Payments_List::status_values_for_view( 'paid' ) );
ok_parity( 'confirmed view maps settled payment', array( 'paid' ) === Monero_Gateway_Admin_Payments_List::status_values_for_view( 'confirmed' ) );
ok_parity( 'expired view does not guess cancelled orders', array( 'expired' ) === Monero_Gateway_Admin_Payments_List::status_values_for_view( 'expired' ) );
ok_parity( 'invalid view falls back to all', array() === Monero_Gateway_Admin_Payments_List::status_values_for_view( 'garbage' ) );
$gateway_source = file_get_contents( __DIR__ . '/../includes/class-wc-gateway-monero.php' );
$widget_source  = file_get_contents( __DIR__ . '/../assets/js/monero-pay.js' );
$checkout_source = file_get_contents( __DIR__ . '/../assets/js/monero-checkout.js' );
$plugin_source  = file_get_contents( __DIR__ . '/../monero-woocommerce-gateway.php' );
ok_parity( 'legacy show_qr option restored', false !== strpos( $gateway_source, "'show_qr'" ) && false !== strpos( $gateway_source, "'default' => 'yes'" ) );
ok_parity( 'QR disabled without hiding payment panel', false !== strpos( $widget_source, "getAttribute('show-qr') !== 'no'" ) && false !== strpos( $widget_source, "showQr ? '<div class=\"qrwrap\"" ) );
ok_parity( 'QR dependency loads before the widget', false !== strpos( $plugin_source, "array( 'monero-gateway-qr' )" ) && file_exists( __DIR__ . '/../assets/js/qrcode-generator.min.js' ) );
ok_parity( 'top-up widget preserves show-qr preference', false !== strpos( $checkout_source, "['theme', 'lang', 'show-qr']" ) );

require_once __DIR__ . '/../includes/class-monero-discount.php';
ok_parity( 'discount clamps negative to zero', 0.0 === Monero_Gateway_Discount::normalize_percentage( '-1' ) );
ok_parity( 'discount keeps decimal percentage', 2.5 === Monero_Gateway_Discount::normalize_percentage( '2.5' ) );
ok_parity( 'discount clamps above 100', 100.0 === Monero_Gateway_Discount::normalize_percentage( '150' ) );
$coupon_data = Monero_Gateway_Discount::coupon_data( 'monero_gateway', 2.5 );
ok_parity( 'Monero selection creates virtual percent coupon', 'percent' === $coupon_data['discount_type'] && 2.5 === $coupon_data['amount'] && true === $coupon_data['virtual'] );
ok_parity( 'other method creates no Monero coupon', false === Monero_Gateway_Discount::coupon_data( 'cod', 2.5 ) );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . " — $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
