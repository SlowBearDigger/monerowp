<?php
/**
 * Uninstall Monero WooCommerce Gateway.
 *
 * Deletes gateway settings, plugin options, transients/locks, and cron hooks.
 * Does not delete order meta (merchant payment history).
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clear plugin data for the current site.
 */
function monero_gateway_uninstall_site() {
	delete_option( 'woocommerce_monero_gateway_settings' );
	delete_option( 'monero_gateway_reconcile_cursor' );
	delete_option( 'monero_gateway_rate_ok_at' );
	delete_option( 'monero_gateway_keys_ok' );

	global $wpdb;

	// Per-order scan/payment locks and saved price-feed state.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$options = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( 'monero_gateway_lock_' ) . '%',
			$wpdb->esc_like( 'monero_gateway_last_rate_' ) . '%'
		)
	);
	foreach ( (array) $options as $name ) {
		delete_option( $name );
	}

	// Rate caches, node nettype, rate-limits, and scan cooldowns.
	$transient_prefixes = array(
		'monero_gateway_rate_',
		'monero_gateway_node_nettype_',
		'monero_gateway_rl_s_',
	);
	$transient_patterns = array();
	foreach ( $transient_prefixes as $prefix ) {
		$transient_patterns[] = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
		$transient_patterns[] = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
	}
	$where = implode( ' OR ', array_fill( 0, count( $transient_patterns ), 'option_name LIKE %s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$transients = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE {$where}", $transient_patterns ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	foreach ( (array) $transients as $name ) {
		if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
			delete_option( $name );
			continue;
		}
		if ( 0 === strpos( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}

	wp_clear_scheduled_hook( 'monero_gateway_expire_orders' );
	wp_clear_scheduled_hook( 'monero_gateway_reconcile' );
	wp_clear_scheduled_hook( 'monero_update_event' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		monero_gateway_uninstall_site();
		restore_current_blog();
	}
} else {
	monero_gateway_uninstall_site();
}
