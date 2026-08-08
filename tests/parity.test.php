<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'MONERO_GATEWAY_WC_FILE', __DIR__ . '/../monero-woocommerce-gateway.php' );

$pass = 0;
$fail = 0;
$shortcodes = array();
function ok_parity( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }
function __( $text ) { return $text; }
function esc_html__( $text ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_attr__( $text ) { return $text; }
function esc_url( $text ) { return esc_html( $text ); }
function plugins_url( $path ) { return 'https://shop.test/plugin/' . ltrim( $path, '/' ); }
function shortcode_atts( $defaults, $atts ) { return array_merge( $defaults, $atts ); }
function get_woocommerce_currency() { return 'USD'; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_shortcode( $name, $callback ) { global $shortcodes; $shortcodes[ $name ] = $callback; }
class WP_Error {}
class WP_List_Table {
	public function __construct( $args = array() ) {}
}

class Parity_Rate_Gateway {
	public $rate = 150.0;
	public function get_rate_for_currency( $currency ) { return 'XMR' === $currency ? 1.0 : $this->rate; }
}

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
