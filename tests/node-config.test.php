<?php
define( 'ABSPATH', __DIR__ . '/' );

$pass = 0;
$fail = 0;
function ok_node( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return $url; }
function esc_html__( $text ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr__( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
	private $code;
	public function __construct( $code ) { $this->code = $code; }
	public function get_error_code() { return $this->code; }
}

require_once __DIR__ . '/../includes/class-monero-node-config.php';
require_once __DIR__ . '/../includes/class-monero-node-fields.php';

$legacy = Monero_Node_Config::normalize_list( 'https://one.test:18081, https://two.test:18081' );
ok_node( 'legacy nodes migrate in order', array( 'https://one.test:18081', 'https://two.test:18081' ) === array_column( $legacy, 'url' ) );
ok_node( 'legacy nodes have no auth', array( 'none', 'none' ) === array_column( $legacy, 'auth' ) );
ok_node( 'remote plaintext node is rejected', is_wp_error( Monero_Node_Config::sanitize_submission( array( array( 'url' => 'http://node.test:18081' ) ), array() ) ) );
ok_node( 'loopback plaintext node is allowed', 'http://127.0.0.1:18081' === Monero_Node_Config::normalize_list( 'http://127.0.0.1:18081' )[0]['url'] );
ok_node( 'localhost plaintext node is allowed', 'http://localhost:18081' === Monero_Node_Config::normalize_list( 'http://localhost:18081' )[0]['url'] );
ok_node( 'a hostname beginning with 127 is not loopback', is_wp_error( Monero_Node_Config::sanitize_submission( array( array( 'url' => 'http://127.evil.test:18081' ) ), array() ) ) );

$saved = array( array( 'url' => 'https://private.test:18081', 'auth' => 'digest', 'username' => 'merchant', 'password' => 'secret' ) );
$submitted = array( array( 'url' => 'https://private.test:18081', 'auth' => 'digest', 'username' => 'merchant', 'password' => '' ) );
$clean = Monero_Node_Config::sanitize_submission( $submitted, $saved );
ok_node( 'blank matching password is preserved', 'secret' === $clean[0]['password'] );
ok_node( 'embedded credentials are rejected', is_wp_error( Monero_Node_Config::sanitize_submission( array( array( 'url' => 'https://u:p@node.test', 'auth' => 'none' ) ), array() ) ) );
ok_node( 'incomplete authenticated node is rejected', is_wp_error( Monero_Node_Config::sanitize_submission( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => 'u', 'password' => '' ) ), array() ) ) );
ok_node( 'changed username cannot reuse password', is_wp_error( Monero_Node_Config::sanitize_submission( array( array( 'url' => 'https://private.test:18081', 'auth' => 'digest', 'username' => 'other', 'password' => '' ) ), $saved ) ) );
ok_node( 'legacy URL output is retained', 'https://private.test:18081' === Monero_Node_Config::legacy_urls( $clean ) );
$html = Monero_Node_Fields::render( $clean );
ok_node( 'node field renders URL', false !== strpos( $html, 'https://private.test:18081' ) );
ok_node( 'node field renders auth selector', false !== strpos( $html, 'value="digest" selected' ) );
ok_node( 'node field marks saved password', false !== strpos( $html, 'data-password-saved="true"' ) );
ok_node( 'node field never renders saved password', false === strpos( $html, 'secret' ) );
ok_node( 'node field includes row controls', false !== strpos( $html, 'monero-node-add' ) && false !== strpos( $html, 'monero-node-remove' ) );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . " — $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
