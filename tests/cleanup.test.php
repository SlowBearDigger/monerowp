<?php
/** Source-level cleanup and licensing regression checks. */

function ok_cleanup( $name, $condition ) {
	static $passed = 0;
	static $failed = 0;
	if ( $condition ) {
		++$passed;
		echo "PASS  {$name}\n";
	} else {
		++$failed;
		echo "FAIL  {$name}\n";
	}
	$GLOBALS['cleanup_counts'] = array( $passed, $failed );
}

$root      = dirname( __DIR__ );
$root_license = file_get_contents( $root . '/LICENSE' );
$plugin    = file_get_contents( $root . '/monero-woocommerce-gateway.php' );
$gateway   = file_get_contents( $root . '/includes/class-wc-gateway-monero.php' );
$util      = file_get_contents( $root . '/includes/class-monero-util.php' );
$scanner   = file_get_contents( $root . '/includes/class-monero-scanner.php' );
$widget    = file_get_contents( $root . '/assets/js/monero-pay.js' );
$qr_notice = $root . '/assets/js/qrcode-generator.LICENSE';
$licenses  = $root . '/includes/vendor/monero/LICENSES.md';
$production_php = $plugin;
foreach ( glob( $root . '/includes/*.php' ) as $php_file ) {
	$production_php .= "\n" . file_get_contents( $php_file );
}

ok_cleanup( 'legacy gateway directory is absent', ! is_dir( $root . '/include' ) );
ok_cleanup( 'legacy template directory is absent', ! is_dir( $root . '/templates/monero-gateway' ) );

ok_cleanup( 'QR license is bundled', is_file( $qr_notice ) );
$qr_text = is_file( $qr_notice ) ? file_get_contents( $qr_notice ) : '';
ok_cleanup( 'QR license preserves copyright and permission',
	false !== strpos( $qr_text, 'Copyright (c) 2009 Kazuhiko Arase' )
	&& false !== strpos( $qr_text, 'Permission is hereby granted' )
);

ok_cleanup( 'vendored crypto notices are bundled', is_file( $licenses ) );
$license_text = is_file( $licenses ) ? file_get_contents( $licenses ) : '';
ok_cleanup( 'MoneroPHP and php-keccak notices are complete',
	false !== strpos( $license_text, '2017 SerHack and 2018 Monero Integrations team' )
	&& false !== strpos( $license_text, 'Copyright (c) 2018 Boris Momčilović' )
	&& substr_count( $license_text, 'Permission is hereby granted' ) >= 2
);
ok_cleanup( 'project license preserves historical and current notices',
	false !== strpos( $root_license, 'Copyright (c) 2017-2018, Monero Integrations' )
	&& false !== strpos( $root_license, 'Copyright (c) 2026, Monero Integrations' )
);
ok_cleanup( 'crypto readiness checks every required extension',
	false !== strpos( $util, "extension_loaded( 'gmp' )" )
	&& false !== strpos( $util, "extension_loaded( 'bcmath' )" )
	&& false !== strpos( $util, "extension_loaded( 'mbstring' )" )
);

ok_cleanup( 'official Monero PNG assets are present',
	is_file( $root . '/assets/images/monero-icon.png' )
	&& is_file( $root . '/assets/images/monero-accepted-here.png' )
);
ok_cleanup( 'official Monero PNG assets match pinned sources',
	'7eacf70c9348f787e59327f96df15b1fde0e0556d73bcb95d21a1fd533c3ecf2' === hash_file( 'sha256', $root . '/assets/images/monero-icon.png' )
	&& '33636d04f57dc99f888a7d079d1be3b2f068320a07b13b9024f0f8d2f698a5a1' === hash_file( 'sha256', $root . '/assets/images/monero-accepted-here.png' )
);
ok_cleanup( 'invented replacement assets are absent',
	! file_exists( $root . '/assets/images/monero-icon.svg' )
	&& ! file_exists( $root . '/assets/images/monero-accepted-here.svg' )
);
ok_cleanup( 'gateway and shortcode use official PNG assets',
	false !== strpos( $gateway, 'monero-icon.png' )
	&& false !== strpos( file_get_contents( $root . '/includes/class-monero-shortcodes.php' ), 'monero-accepted-here.png' )
);
ok_cleanup( 'official image provenance is bundled', is_file( $root . '/assets/images/LICENSE' ) );

$unused_util_methods = array(
	'to_invoice_state', 'is_address_like', 'normalize_agent_url', 'resolve_claim_window',
	'claim_window_from_days', 'claim_expires_at', 'claim_expired', 'nonce_amount',
	'from_total', 'classify_payment', 'verify_sig', 'event_fresh', 'test_amount_allowed',
);
foreach ( $unused_util_methods as $method ) {
	ok_cleanup( "unused Monero_Util::{$method} removed", false === strpos( $util, "function {$method}(" ) );
	ok_cleanup( "no production caller remains for Monero_Util::{$method}", false === strpos( $production_php, "Monero_Util::{$method}(" ) );
}
ok_cleanup( 'unused single-window scanner removed', false === strpos( $scanner, 'function scan(' ) );
ok_cleanup( 'no production caller remains for single-window scanner', false === strpos( $production_php, '->scan(' ) );

$unused_widget_features = array(
	'verify-url', 'status-url', 'stream-url', 'receipt-url', 'verify-page',
	'pubkey', 'fingerprint', 'xpVerifyConfig', 'EventSource',
);
foreach ( $unused_widget_features as $feature ) {
	ok_cleanup( "unused widget feature {$feature} removed", false === strpos( $widget, $feature ) );
	ok_cleanup( "no PHP emitter remains for widget feature {$feature}", false === strpos( $production_php, $feature ) );
}

ok_cleanup( 'obsolete Travis configuration removed', ! file_exists( $root . '/.travis.yml' ) );
ok_cleanup( 'GitHub Actions unit workflow present', is_file( $root . '/.github/workflows/tests.yml' ) );
ok_cleanup( 'current bootstrap still loads active gateway', false !== strpos( $plugin, "includes/class-wc-gateway-monero.php" ) );

list( $passed, $failed ) = $GLOBALS['cleanup_counts'];
echo "\n" . ( $failed ? 'FAIL' : 'ALL GREEN' ) . " — {$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
