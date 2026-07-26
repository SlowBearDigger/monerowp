<?php

$product_id = absint( getenv( 'MONEROWP_E2E_PRODUCT_ID' ) );
$total      = trim( (string) ( getenv( 'MONEROWP_E2E_TOTAL' ) ?: '0.10' ) );

if ( $product_id <= 0 ) {
	throw new RuntimeException( 'MONEROWP_E2E_PRODUCT_ID must be a positive integer.' );
}

if ( ! preg_match( '/^\d+(?:\.\d{1,2})?$/', $total ) || (float) $total <= 0 ) {
	throw new RuntimeException( 'MONEROWP_E2E_TOTAL must be a positive amount with at most two decimals.' );
}

$product = wc_get_product( $product_id );
if ( ! $product ) {
	throw new RuntimeException( 'Test product not found.' );
}

$gateways = WC()->payment_gateways()->payment_gateways();
if ( empty( $gateways['monero_gateway'] ) ) {
	throw new RuntimeException( 'Monero gateway not registered.' );
}

$order = wc_create_order();
$item  = new WC_Order_Item_Product();
$item->set_product( $product );
$item->set_quantity( 1 );
$item->set_subtotal( $total );
$item->set_total( $total );
$order->add_item( $item );
$order->set_address(
	array(
		'first_name' => 'MoneroWP',
		'last_name'  => 'E2E',
		'email'      => 'monerowp-e2e@example.invalid',
		'country'    => 'US',
	),
	'billing'
);
$order->set_payment_method( $gateways['monero_gateway'] );
$order->calculate_totals( false );
$order->save();

$result = $gateways['monero_gateway']->process_payment( $order->get_id() );
$order  = wc_get_order( $order->get_id() );

echo wp_json_encode(
	array(
		'order_id'    => $order->get_id(),
		'status'      => $order->get_status(),
		'result'      => isset( $result['result'] ) ? $result['result'] : '',
		'address'     => (string) $order->get_meta( '_monero_address' ),
		'amount_xmr'  => (string) $order->get_meta( '_monero_amount' ),
		'birthday'    => (int) $order->get_meta( '_monero_birthday' ),
		'scan_height' => (int) $order->get_meta( '_monero_scan_height' ),
	)
);
