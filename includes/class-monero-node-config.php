<?php
/** Normalize and validate Monero daemon node settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monero_Node_Config {
	private function __construct() {}

	public static function normalize_list( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s*,\s*/', trim( $value ), -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}

		$nodes = array();
		foreach ( $value as $row ) {
			if ( is_string( $row ) ) {
				$row = array( 'url' => $row );
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = self::normalize_url( isset( $row['url'] ) ? $row['url'] : '' );
			if ( null === $url ) {
				continue;
			}
			$auth = strtolower( trim( (string) ( isset( $row['auth'] ) ? $row['auth'] : 'none' ) ) );
			if ( ! in_array( $auth, array( 'none', 'basic', 'digest' ), true ) ) {
				$auth = 'none';
			}
			$nodes[] = array(
				'url'      => $url,
				'auth'     => $auth,
				'username' => 'none' === $auth ? '' : trim( (string) ( isset( $row['username'] ) ? $row['username'] : '' ) ),
				'password' => 'none' === $auth ? '' : (string) ( isset( $row['password'] ) ? $row['password'] : '' ),
			);
		}
		return $nodes;
	}

	public static function sanitize_submission( $rows, $saved_rows ) {
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'monero_gateway_invalid_nodes' );
		}
		$saved = self::normalize_list( $saved_rows );
		$nodes = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return new WP_Error( 'monero_gateway_invalid_node' );
			}
			$url = self::normalize_url( isset( $row['url'] ) ? $row['url'] : '' );
			if ( null === $url ) {
				return new WP_Error( 'monero_gateway_invalid_node_url' );
			}
			$auth = strtolower( trim( (string) ( isset( $row['auth'] ) ? $row['auth'] : 'none' ) ) );
			if ( ! in_array( $auth, array( 'none', 'basic', 'digest' ), true ) ) {
				return new WP_Error( 'monero_gateway_invalid_node_auth' );
			}
			$username = 'none' === $auth ? '' : trim( (string) ( isset( $row['username'] ) ? $row['username'] : '' ) );
			$password = 'none' === $auth ? '' : (string) ( isset( $row['password'] ) ? $row['password'] : '' );
			if ( 'none' !== $auth && '' === $password ) {
				foreach ( $saved as $old ) {
					if ( self::same( $url, $old['url'] ) && self::same( $auth, $old['auth'] ) && self::same( $username, $old['username'] ) ) {
						$password = $old['password'];
						break;
					}
				}
			}
			if ( 'none' !== $auth && ( '' === $username || '' === $password ) ) {
				return new WP_Error( 'monero_gateway_missing_node_credentials' );
			}
			$nodes[] = compact( 'url', 'auth', 'username', 'password' );
		}
		return $nodes;
	}

	public static function legacy_urls( $rows ) {
		return implode( ', ', array_column( self::normalize_list( $rows ), 'url' ) );
	}

	private static function normalize_url( $value ) {
		$url   = trim( (string) $value );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return null;
		}
		if ( ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( 'http' === strtolower( $parts['scheme'] ) && ! self::is_loopback_host( $parts['host'] ) ) {
			return null;
		}
		$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $url ) : $url;
		return rtrim( $clean, '/' );
	}

	private static function is_loopback_host( $host ) {
		$host = strtolower( trim( (string) $host, '[]' ) );
		return 'localhost' === $host || '::1' === $host
			|| ( false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && 0 === strpos( $host, '127.' ) );
	}

	private static function same( $left, $right ) {
		return hash_equals( (string) $left, (string) $right );
	}
}
