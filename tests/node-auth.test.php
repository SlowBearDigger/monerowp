<?php
define( 'ABSPATH', __DIR__ . '/' );

$pass = 0;
$fail = 0;
$requests = array();
$responses = array();
$filters = array();
function ok_auth( $name, $condition ) { global $pass, $fail; $condition ? $pass++ : $fail++; echo ( $condition ? 'PASS  ' : 'FAIL  ' ) . $name . "\n"; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return $url; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function __( $text ) { return $text; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function add_filter( $tag, $callback ) { global $filters; $filters[ $tag ][] = $callback; }
function add_action( $tag, $callback ) { add_filter( $tag, $callback ); }
function remove_filter( $tag, $callback ) {
	global $filters;
	if ( ! isset( $filters[ $tag ] ) ) { return; }
	$filters[ $tag ] = array_values( array_filter( $filters[ $tag ], function ( $registered ) use ( $callback ) { return $registered !== $callback; } ) );
}
function remove_action( $tag, $callback ) { remove_filter( $tag, $callback ); }
function apply_filters( $tag, $value ) {
	global $filters;
	$args = func_get_args();
	array_shift( $args );
	foreach ( isset( $filters[ $tag ] ) ? $filters[ $tag ] : array() as $callback ) {
		$args[0] = call_user_func_array( $callback, $args );
	}
	return $args[0];
}
function wp_safe_remote_get( $url, $args ) { return node_auth_request( 'GET', $url, $args ); }
function wp_safe_remote_post( $url, $args ) { return node_auth_request( 'POST', $url, $args ); }
function node_auth_request( $method, $url, $args ) {
	global $requests, $responses;
	$requests[] = compact( 'method', 'url', 'args' );
	$host = (string) parse_url( $url, PHP_URL_HOST );
	if ( in_array( $host, array( '127.0.0.1', 'localhost' ), true ) && ! apply_filters( 'http_request_host_is_external', false, $host, $url ) ) {
		return new WP_Error();
	}
	$port = (int) parse_url( $url, PHP_URL_PORT );
	if ( $port && ! in_array( $port, apply_filters( 'http_allowed_safe_ports', array( 80, 443 ) ), true ) ) {
		return new WP_Error();
	}
	return array_shift( $responses );
}
function wp_remote_retrieve_response_code( $response ) { return isset( $response['code'] ) ? $response['code'] : 0; }
function wp_remote_retrieve_body( $response ) { return isset( $response['body'] ) ? $response['body'] : ''; }
class WP_Error {}
class WC_Payment_Gateway {}

require_once __DIR__ . '/../includes/class-monero-node-config.php';
require_once __DIR__ . '/../includes/class-monero-scanner.php';
require_once __DIR__ . '/../includes/class-wc-gateway-monero.php';

$diagnostic = WC_Gateway_Monero::node_setup_diagnostic( array( 'code' => 'unauthorized' ) );
ok_auth( '401 diagnostic is specific', 'unauthorized' === $diagnostic['code'] && false !== strpos( $diagnostic['msg'], 'credentials' ) );
$diagnostic = WC_Gateway_Monero::node_setup_diagnostic( array( 'code' => 'digest_unavailable' ) );
ok_auth( 'Digest diagnostic requests cURL', 'digest_unavailable' === $diagnostic['code'] && false !== strpos( $diagnostic['msg'], 'cURL' ) );
ok_auth( 'one healthy node tolerates an unreachable fallback', WC_Gateway_Monero::node_health_allows_payments( array(
	array( 'ok' => true, 'nettype' => 'stagenet' ),
	array( 'ok' => false, 'nettype' => 'unknown' ),
), 'stagenet' ) );
ok_auth( 'reachable wrong-network node is rejected', ! WC_Gateway_Monero::node_health_allows_payments( array(
	array( 'ok' => true, 'nettype' => 'stagenet' ),
	array( 'ok' => true, 'nettype' => 'mainnet' ),
), 'stagenet' ) );
ok_auth( 'no healthy node fails closed', ! WC_Gateway_Monero::node_health_allows_payments( array(
	array( 'ok' => false, 'nettype' => 'unknown' ),
), 'stagenet' ) );

$responses[] = array( 'code' => 200, 'body' => json_encode( array( 'height' => 123, 'nettype' => 'mainnet' ) ) );
$scanner = new Monero_Scanner( array( array( 'url' => 'https://node.test', 'auth' => 'basic', 'username' => 'u', 'password' => 'p' ) ) );
$scanner->node_info();
$args = $requests[0]['args'];
ok_auth( 'Basic auth header is sent', 'Basic ' . base64_encode( 'u:p' ) === $args['headers']['Authorization'] );
ok_auth( 'redirects are disabled', 0 === $args['redirection'] );
ok_auth( 'response is bounded', 4 * 1024 * 1024 === $args['limit_response_size'] );

class Digest_Unavailable_Monero_Scanner extends Monero_Scanner {
	protected function digest_auth_available() { return false; }
}
$scanner = new Digest_Unavailable_Monero_Scanner( array( array( 'url' => 'https://digest.test', 'auth' => 'digest', 'username' => 'u', 'password' => 'p' ) ) );
$scanner->node_info();
$error = $scanner->last_node_error();
ok_auth( 'Digest never downgrades', is_array( $error ) && 'digest_unavailable' === $error['code'] );

$requests = array();
$responses = array(
	array( 'code' => 401, 'body' => '' ),
	array( 'code' => 200, 'body' => json_encode( array( 'height' => 456, 'nettype' => 'mainnet' ) ) ),
);
$scanner = new Monero_Scanner( array(
	array( 'url' => 'https://first.test', 'auth' => 'basic', 'username' => 'first', 'password' => 'secret' ),
	array( 'url' => 'https://second.test', 'auth' => 'none', 'username' => '', 'password' => '' ),
) );
$info = $scanner->node_info();
ok_auth( '401 fails over to the next node', 456 === $info['height'] );
ok_auth( 'first credentials do not leak to fallback', ! isset( $requests[1]['args']['headers']['Authorization'] ) );
ok_auth( 'successful fallback clears node error', null === $scanner->last_node_error() );

$responses = array(
	array( 'code' => 200, 'body' => json_encode( array( 'height' => 111, 'nettype' => 'mainnet' ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'height' => 222, 'nettype' => 'mainnet' ) ) ),
);
$first_local = ( new Monero_Scanner( 'http://127.0.0.1:18081' ) )->node_info();
$second_local = ( new Monero_Scanner( 'http://127.0.0.1:18082' ) )->node_info();
ok_auth( 'private nodes and every configured RPC port pass the safe HTTP guard', 111 === $first_local['height'] && 222 === $second_local['height'] );
ok_auth( 'private-node HTTP exceptions are request-scoped', empty( $filters['http_request_host_is_external'] ) && empty( $filters['http_allowed_safe_ports'] ) );

$responses = array( new WP_Error() );
$pool = ( new Monero_Scanner( 'https://pool.test' ) )->scan_pool( 'unused', 'unused' );
ok_auth( 'pool transport failure is distinct from an empty pool', null === $pool );

$requests = array();
$responses = array( array( 'code' => 200, 'body' => json_encode( array( 'transactions' => array() ) ) ) );
$pool = ( new Monero_Scanner( 'https://pool.test' ) )->scan_pool( 'unused', 'unused' );
ok_auth( 'pool request sends the empty JSON object required by monerod', array() === $pool && '{}' === $requests[0]['args']['body'] );

$requests = array();
$responses = array( array( 'code' => 200, 'body' => json_encode( array( 'height' => 333, 'nettype' => 'mainnet' ) ) ) );
$budgeted = new Monero_Scanner( 'https://budget.test', 'mainnet', 20 );
$budgeted->set_time_budget( 0.5 );
$budgeted->node_info();
ok_auth( 'the overall scan budget clamps each RPC timeout', $requests[0]['args']['timeout'] > 0 && $requests[0]['args']['timeout'] <= 0.5 );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . " — $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
