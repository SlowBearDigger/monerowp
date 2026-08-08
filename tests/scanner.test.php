<?php
/** Live stagenet vectors for payment verification and block scanning. */

define( 'ABSPATH', __DIR__ . '/' );   // satisfy the includes' direct-access guard
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( (string) $url, $component ); } }
require_once __DIR__ . '/../includes/class-monero-util.php';
require_once __DIR__ . '/../includes/class-monero-scanner.php';

$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; if ( $c ) { $pass++; echo "PASS  $n\n"; } else { $fail++; echo "FAIL  $n" . ( $x !== '' ? "  — $x" : '' ) . "\n"; } }

$NODE = getenv( 'XMR_NODE' ) ?: 'http://node2.monerodevs.org:38089';
// Disposable stagenet view-only key; override with XMR_TEST_VIEW_KEY.
$VIEW = getenv( 'XMR_TEST_VIEW_KEY' ) ?: '3b6765f2072e11438aaa22ae9168adf304c414d8da5de504dcdb46e397a6f604';
$VECTORS = array(
	'primary'    => array(
		'txid'    => '8c3079a92e185d241752d1bee6131e7962ddcb54d1e176b6cf5372a51ad08a71',
		'address' => '5BEiTonHrFFgGSRAQTknCsEU9jRtGXEVBbv9bZSHCybmUT6aoA2V9M98rLFW2rfzyw5ayituBVETeG9Zkw3AAsyqE4T7N2n',
		'expect'  => '100000000000',
	),
	'subaddress' => array(
		'txid'    => 'c7622a43ea3e08bad10a7fd9219d5ede55ff7ad5b475d87fbdde4c9e2fb05d20',
		'address' => '74opWdZjQg1GuhPeGJWmgkBYs3kTX8k1vHuNuPDfLzKLF9TzYw3gjde765xp1o9yhpVFSoFfbfnPha2dXnBY8uLm9rqhQab',
		'expect'  => '100000000000',
	),
);

$s   = new Monero_Scanner( $NODE, 'stagenet' );

// Key/address setup checks are offline.
$BUYER_PRIMARY0 = '5BEiTonHrFFgGSRAQTknCsEU9jRtGXEVBbv9bZSHCybmUT6aoA2V9M98rLFW2rfzyw5ayituBVETeG9Zkw3AAsyqE4T7N2n';
ok( 'verify_keys: correct view key matches the address', ! empty( $s->verify_keys( $BUYER_PRIMARY0, $VIEW )['key_match'] ) );
ok( 'verify_keys: wrong view key does NOT match', empty( $s->verify_keys( $BUYER_PRIMARY0, str_repeat( '0', 64 ) )['key_match'] ) );
ok( 'verify_keys: garbage address → not valid', empty( $s->verify_keys( 'not-a-real-address', $VIEW )['address_valid'] ) );

// Truncated tx_extra must not read past the buffer.
$err = 0; set_error_handler( function () use ( &$err ) { $err++; return false; } );
$bad_tx = array( 'extra' => array( 1, 2, 3 ), 'vout' => array(), 'rct_signatures' => array() ); // tag 01 wants 32 bytes, only 2 follow
$rt = $s->detect_in_tx( $bad_tx, $BUYER_PRIMARY0, $VIEW );
restore_error_handler();
ok( 'detect_in_tx: truncated extra → null, no warnings', $rt === null && $err === 0, "err=$err" );

$tip = $s->tip_height();
ok( 'node reachable (tip height)', is_int( $tip ) && $tip > 0, "tip=" . var_export( $tip, true ) );
if ( ! $tip ) { echo "\nFAIL — node unreachable, cannot run the on-chain vectors ($NODE)\n"; exit( 1 ); }

foreach ( $VECTORS as $name => $v ) {
	$r = $s->verify_payment( $v['txid'], $v['address'], $VIEW, array( 'tip' => $tip, 'require_commitment' => true ) );
	ok( "$name: output detected as ours",            ! empty( $r['found'] ), $r['reason'] ?? '' );
	ok( "$name: amount decoded exactly",             ( $r['amount_atomic'] ?? null ) === $v['expect'], "got " . var_export( $r['amount_atomic'] ?? null, true ) . " want {$v['expect']}" );
	ok( "$name: commitment verifies (C = aH + mG)",  ! empty( $r['commitment_ok'] ) );
	ok( "$name: not locked (unlock_time elapsed)",   isset( $r['locked'] ) && false === $r['locked'] );
	ok( "$name: confirmations present + >= 0",       isset( $r['confirmations'] ) && $r['confirmations'] >= 0, "conf=" . var_export( $r['confirmations'] ?? null, true ) );
}

// Exercise the production verify-to-settlement pipeline.
$pipe = $s->verify_payment( $VECTORS['subaddress']['txid'], $VECTORS['subaddress']['address'], $VIEW, array( 'tip' => $tip, 'require_commitment' => true ) );
$recv = $pipe['amount_atomic'] ?? '0';
$row = array_merge( $pipe, array( 'txid' => $VECTORS['subaddress']['txid'] ) );
$v1 = Monero_Util::summarize_payments( array( $row ), $recv, '0', 1 );
ok( 'pipeline: exact committed amount → paid', $v1['status'] === 'paid' && $v1['paid'] === true );
$v2 = Monero_Util::summarize_payments( array( $row ), gmp_strval( gmp_add( gmp_init( $recv ), 1 ) ), '0', 1 );
ok( 'pipeline: 1 pico under the order amount → partial (no settle)', $v2['status'] === 'partial' && $v2['paid'] === false );
$v3 = Monero_Util::summarize_payments( array( $row ), $recv, '0', ( $pipe['confirmations'] ?? 0 ) + 1 );
ok( 'pipeline: amount ok but below required confirmations → not paid', $v3['paid'] === false && $v3['status'] === 'mempool' );

// Derived subaddresses must match monero-ts.
$BUYER_PRIMARY = '5BEiTonHrFFgGSRAQTknCsEU9jRtGXEVBbv9bZSHCybmUT6aoA2V9M98rLFW2rfzyw5ayituBVETeG9Zkw3AAsyqE4T7N2n';
$sub = $s->subaddress( 0, 7, $VIEW, $BUYER_PRIMARY );
ok( 'subaddress(0,7) derives the SAME address monero-ts made', is_array( $sub ) && $sub['address'] === $VECTORS['subaddress']['address'], $sub['address'] ?? 'null' );
ok( 'subaddress(0,0) === the primary address', $s->subaddress( 0, 0, $VIEW, $BUYER_PRIMARY )['address'] === $BUYER_PRIMARY );

// Discover a payment by scanning its block without a known txid.
$BLOCK = 2144642; // the block c7622a43… landed in
$hit = $s->scan_all( $VECTORS['subaddress']['address'], $VIEW, $BLOCK, $BLOCK, array( 'tip' => $tip, 'require_commitment' => true ) );
$hit_row = $hit['matches'][0] ?? array();
ok( 'scan_all: discovers the payment by block scan (no txid given)', $hit_row && $hit_row['txid'] === $VECTORS['subaddress']['txid'], $hit_row['txid'] ?? ( 'scanned_to ' . ( $hit['scanned_to'] ?? '?' ) ) );
ok( 'scan_all: decodes the right amount', ( $hit_row['amount_atomic'] ?? null ) === $VECTORS['subaddress']['expect'] );
// Empty scans still advance their checkpoint.
$miss = $s->scan_all( $VECTORS['subaddress']['address'], $VIEW, $BLOCK - 1, $BLOCK - 1, array( 'tip' => $tip ) );
ok( 'scan_all: empty range → no matches + scanned_to checkpoint', empty( $miss['matches'] ) && isset( $miss['scanned_to'] ) && $miss['scanned_to'] === $BLOCK - 1 );

// A wrong view key must not produce a false positive.
$bad = $s->verify_payment( $VECTORS['primary']['txid'], $VECTORS['primary']['address'], str_repeat( '0', 64 ), array( 'tip' => $tip, 'require_commitment' => true ) );
ok( 'wrong view key → NOT found (no false positive)', empty( $bad['found'] ) );

echo "\n" . ( $fail ? 'FAILED' : 'ALL GREEN' ) . " — $pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
