<?php
/** Exact amount, settlement, and URL helpers for the gateway. */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Monero_Util {

	/** One XMR has 12 piconero decimal places. */
	const XMR_DECIMALS = 12;

	/** Require all extensions used by the vendored cryptography. */
	public static function crypto_ready() {
		return extension_loaded( 'gmp' ) && extension_loaded( 'bcmath' ) && extension_loaded( 'mbstring' );
	}

	/** Format a canonical XMR amount with piconero precision. */
	public static function fmt( $xmr ) {
		return self::pico_to_string( self::xmr_to_pico( $xmr ) );
	}

	/** Convert XMR to exact piconero. */
	public static function xmr_to_pico( $xmr ) {
		$value = trim( (string) $xmr );
		if ( ! preg_match( '/^(\d+)(?:\.(\d*))?$/D', $value, $parts ) ) {
			$number = (float) $xmr;
			if ( ! is_finite( $number ) || $number <= 0 ) { return 0; }
			preg_match( '/^(\d+)\.(\d{12})$/D', sprintf( '%.12F', $number ), $parts );
		}
		$fraction = isset( $parts[2] ) ? $parts[2] : '';
		$pico     = gmp_init( ltrim( $parts[1] . str_pad( substr( $fraction, 0, self::XMR_DECIMALS ), self::XMR_DECIMALS, '0' ), '0' ) ?: '0', 10 );
		if ( isset( $fraction[ self::XMR_DECIMALS ] ) && $fraction[ self::XMR_DECIMALS ] >= '5' ) { $pico = gmp_add( $pico, 1 ); }
		return (int) gmp_strval( $pico );
	}

	/** Format piconero as XMR without integer truncation. */
	public static function pico_to_string( $pico ) {
		$p = gmp_init( (string) $pico, 10 );
		if ( gmp_cmp( $p, 0 ) <= 0 ) {
			return '0';
		}
		$denom = gmp_init( '1000000000000', 10 );
		$int   = gmp_strval( gmp_div_q( $p, $denom ) );
		$frac  = (int) gmp_strval( gmp_mod( $p, $denom ) );   // < 1e12, fits an int safely
		if ( 0 === $frac ) {
			return $int;
		}
		$fs = rtrim( str_pad( (string) $frac, self::XMR_DECIMALS, '0', STR_PAD_LEFT ), '0' );
		return $int . '.' . $fs;
	}

	/** Keep order keys out of third-party redirects. */
	public static function same_origin( $url, $home ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( empty( $host ) ) { return '' === (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ); }
		$scheme      = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_SCHEME ) );
		$home_scheme = strtolower( (string) wp_parse_url( (string) $home, PHP_URL_SCHEME ) );
		$port        = (int) wp_parse_url( (string) $url, PHP_URL_PORT ) ?: ( 'https' === $scheme ? 443 : 80 );
		$home_port   = (int) wp_parse_url( (string) $home, PHP_URL_PORT ) ?: ( 'https' === $home_scheme ? 443 : 80 );
		return in_array( $scheme, array( 'http', 'https' ), true )
			&& $scheme === $home_scheme
			&& $port === $home_port
			&& strtolower( (string) $host ) === strtolower( (string) wp_parse_url( (string) $home, PHP_URL_HOST ) );
	}

	/** Read a row amount as non-negative GMP. */
	private static function row_amt_pico( $row ) {
		$v = isset( $row['amount_atomic'] ) ? (string) $row['amount_atomic'] : '0';
		if ( '' === $v || ! preg_match( '/^-?\d+$/', $v ) ) { return gmp_init( 0 ); }
		$g = gmp_init( $v, 10 );
		return gmp_cmp( $g, 0 ) < 0 ? gmp_init( 0 ) : $g;
	}

	/** Select the most conservative creditable copy of a duplicated output. */
	private static function more_creditable( $a, $b ) {
		$ak = ! empty( $a['commitment_ok'] );
		$bk = ! empty( $b['commitment_ok'] );
		if ( $ak !== $bk ) { return $ak ? $a : $b; }
		$ap = ! empty( $a['in_pool'] );
		$bp = ! empty( $b['in_pool'] );
		if ( $ap !== $bp ) { return $ap ? $b : $a; }
		$ac = ( isset( $a['confirmations'] ) && null !== $a['confirmations'] ) ? (int) $a['confirmations'] : -1;
		$bc = ( isset( $b['confirmations'] ) && null !== $b['confirmations'] ) ? (int) $b['confirmations'] : -1;
		if ( $ac !== $bc ) { return $ac > $bc ? $a : $b; }
		$al = ! empty( $a['locked'] );
		$bl = ! empty( $b['locked'] );
		if ( $al !== $bl ) { return $al ? $a : $b; }   // contradictory lock status -> keep LOCKED (conservative)
		$cmp = gmp_cmp( self::row_amt_pico( $a ), self::row_amt_pico( $b ) );
		if ( 0 !== $cmp ) { return $cmp < 0 ? $a : $b; }
		return $a;
	}

	/**
	 * Deduplicate by one-time output key, falling back to txid. The output key prevents
	 * Monero's burning-bug pattern from crediting two transactions for one spendable output.
	 */
	public static function dedup_outputs( $rows ) {
		if ( ! is_array( $rows ) ) { return array(); }
		$pos = array();
		$out = array();
		foreach ( $rows as $t ) {
			if ( ! is_array( $t ) ) { $out[] = $t; continue; }
			$k = ( isset( $t['out_key'] ) && '' !== (string) $t['out_key'] ) ? 'k:' . (string) $t['out_key']
				: ( ( isset( $t['txid'] ) && '' !== (string) $t['txid'] ) ? 't:' . (string) $t['txid'] : '' );
			if ( '' === $k ) { $out[] = $t; continue; }
			if ( ! isset( $pos[ $k ] ) ) { $pos[ $k ] = count( $out ); $out[] = $t; }
			else { $out[ $pos[ $k ] ] = self::more_creditable( $out[ $pos[ $k ] ], $t ); }
		}
		return $out;
	}

	/** Sum committed outputs and return the order settlement verdict. */
	public static function summarize_payments( $rows, $exp_pico, $tol_pico, $min_conf ) {
		$min_conf = max( 0, (int) $min_conf );
		$rows     = self::dedup_outputs( is_array( $rows ) ? $rows : array() );
		$confirmed = gmp_init( 0 );
		$pending   = gmp_init( 0 );
		$locked    = gmp_init( 0 );
		$min_confs = null;
		$txids     = array();
		foreach ( $rows as $t ) {
			// Never credit an amount without a valid on-chain commitment.
			if ( ! is_array( $t ) || empty( $t['commitment_ok'] ) ) { continue; }
			$amt = self::row_amt_pico( $t );
			if ( isset( $t['txid'] ) && '' !== (string) $t['txid'] ) { $txids[] = (string) $t['txid']; }
			if ( ! empty( $t['locked'] ) ) { $locked = gmp_add( $locked, $amt ); continue; }
			// Hold mempool conflicts as pending until they land in a block.
			if ( ! empty( $t['double_spend_seen'] ) ) { $pending = gmp_add( $pending, $amt ); continue; }
			$confs   = ( isset( $t['confirmations'] ) && null !== $t['confirmations'] ) ? (int) $t['confirmations'] : null;
			$in_pool = ! empty( $t['in_pool'] );
			if ( ( $in_pool && 0 === $min_conf ) || ( ! $in_pool && null !== $confs && $confs >= $min_conf ) ) {
				$confirmed = gmp_add( $confirmed, $amt );
				$min_confs = ( null === $min_confs ) ? $confs : min( $min_confs, $confs );
			} else {
				$pending = gmp_add( $pending, $amt );
			}
		}

		$exp = gmp_init( (string) $exp_pico, 10 );
		$tol = gmp_init( (string) $tol_pico, 10 );
		if ( gmp_cmp( $tol, 0 ) < 0 ) { $tol = gmp_init( 0 ); }
		$max_tol = gmp_cmp( $exp, 0 ) <= 0 ? gmp_init( 0 ) : gmp_sub( $exp, gmp_init( 1 ) );
		if ( gmp_cmp( $tol, $max_tol ) > 0 ) { $tol = $max_tol; }
		$threshold = gmp_sub( $exp, $tol );
		$seen      = gmp_add( gmp_add( $confirmed, $pending ), $locked );

		$base = array(
			'received_pico'  => gmp_strval( $confirmed ),
			'confirmed_pico' => gmp_strval( $confirmed ),
			'pending_pico'   => gmp_strval( $pending ),
			'locked_pico'    => gmp_strval( $locked ),
			'seen_pico'      => gmp_strval( $seen ),
			'confirmations'  => ( null === $min_confs ) ? 0 : (int) $min_confs,
			'txids'          => $txids,
			'overpaid_pico'  => '0',
			'shortfall_pico' => gmp_cmp( $threshold, $seen ) > 0 ? gmp_strval( gmp_sub( $threshold, $seen ) ) : '0',
		);

		if ( gmp_cmp( $exp, 0 ) <= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'invalid' ) ); }
		if ( gmp_cmp( $confirmed, $threshold ) >= 0 ) {
			$over = gmp_cmp( $confirmed, $exp ) > 0 ? gmp_strval( gmp_sub( $confirmed, $exp ) ) : '0';
			return array_merge( $base, array( 'paid' => true, 'status' => 'paid', 'overpaid_pico' => $over, 'shortfall_pico' => '0' ) );
		}
		if ( gmp_cmp( gmp_add( $locked, $confirmed ), $threshold ) >= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'locked' ) ); }
		if ( gmp_cmp( gmp_add( $confirmed, $pending ), $threshold ) >= 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'mempool' ) ); }
		if ( gmp_cmp( $confirmed, 0 ) > 0 || gmp_cmp( $pending, 0 ) > 0 ) { return array_merge( $base, array( 'paid' => false, 'status' => 'partial' ) ); }
		return array_merge( $base, array( 'paid' => false, 'status' => 'pending' ) );
	}
}
