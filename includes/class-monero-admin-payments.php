<?php
/** WooCommerce admin list for Monero payments. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** List-table implementation for Monero orders. */
class Monero_Gateway_Admin_Payments_List extends WP_List_Table {
	private const VIEWS = array(
		'pending'   => array( 'watching', 'partial', 'underpaid', 'mempool', 'unconfirmed', 'confirming', 'locked' ),
		'paid'      => array( 'paid_unconfirmed' ),
		'confirmed' => array( 'paid' ),
		'expired'   => array( 'expired' ),
	);

	/** Orders displayed per page. */
	const PER_PAGE = 20;

	public static function status_values_for_view( $view ) {
		return self::VIEWS[ $view ] ?? array();
	}

	private function current_view() {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		return isset( self::VIEWS[ $view ] ) ? $view : 'all';
	}

	private function query_args_for_view( $view ) {
		$args = array( 'payment_method' => 'monero_gateway' );
		$statuses = self::status_values_for_view( $view );
		if ( $statuses ) {
			$hpos = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
			if ( $hpos ) {
				$args['meta_query'] = array( array( 'key' => '_monero_payment_status', 'value' => $statuses, 'compare' => 'IN' ) );
			} else {
				$args['meta_key']     = '_monero_payment_status';
				$args['meta_value']   = $statuses;
				$args['meta_compare'] = 'IN';
			}
		}
		return $args;
	}

	/** Configure the list table. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'monero_payment',
				'plural'   => 'monero_payments',
				'ajax'     => false,
			)
		);
	}

	/** Return table columns. */
	public function get_columns() {
		return array(
			'order'         => __( 'Order', 'monero_gateway' ),
			'date'          => __( 'Date', 'monero_gateway' ),
			'status'        => __( 'Status', 'monero_gateway' ),
			'address'       => __( 'Address', 'monero_gateway' ),
			'owed'          => __( 'Owed (XMR)', 'monero_gateway' ),
			'received'      => __( 'Received (XMR)', 'monero_gateway' ),
			'confirmations' => __( 'Confirmations', 'monero_gateway' ),
			'txids'         => __( 'Txid(s)', 'monero_gateway' ),
		);
	}

	/** Prepare the current page of orders. */
	public function prepare_items() {
		$current_page = max( 1, $this->get_pagenum() );
		$results      = wc_get_orders(
			array_merge( $this->query_args_for_view( $this->current_view() ), array(
				'payment_method' => 'monero_gateway',
				'orderby'        => 'date',
				'order'          => 'DESC',
				'limit'          => self::PER_PAGE,
				'page'           => $current_page,
				'paginate'       => true,
				'return'         => 'objects',
			) )
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'order' );
		$this->items           = $results->orders;

		$this->set_pagination_args(
			array(
				'total_items' => (int) $results->total,
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) $results->max_num_pages,
			)
		);
	}

	public function get_views() {
		$current = $this->current_view();
		$labels  = array( 'all' => __( 'All', 'monero_gateway' ), 'pending' => __( 'Pending', 'monero_gateway' ), 'paid' => __( 'Paid', 'monero_gateway' ), 'confirmed' => __( 'Confirmed', 'monero_gateway' ), 'expired' => __( 'Expired', 'monero_gateway' ) );
		$views   = array();
		foreach ( $labels as $view => $label ) {
			$count_args = array_merge( $this->query_args_for_view( $view ), array( 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
			$result = wc_get_orders( $count_args );
			$url = add_query_arg( array( 'page' => 'monero-gateway-payments', 'view' => $view ), admin_url( 'admin.php' ) );
			$views[ $view ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $current === $view ? ' class="current" aria-current="page"' : '', esc_html( $label ), (int) $result->total );
		}
		return $views;
	}

	/** Render a column value. */
	public function column_default( $order, $column_name ) {
		switch ( $column_name ) {
			case 'order':
				printf(
					'<a href="%1$s"><strong>%2$s</strong></a>',
					esc_url( $order->get_edit_order_url() ),
					esc_html( '#' . $order->get_order_number() )
				);
				break;

			case 'date':
				$date = $order->get_date_created();
				echo $date ? esc_html( wc_format_datetime( $date ) ) : esc_html( '—' );
				break;

			case 'status':
				$this->render_status( $order );
				break;

			case 'address':
				$this->render_address( $this->get_meta_string( $order, '_monero_address' ) );
				break;

			case 'owed':
				$this->render_value( $this->get_meta_string( $order, '_monero_amount' ) );
				break;

			case 'received':
				$this->render_value( $this->get_meta_string( $order, '_monero_received' ) );
				break;

			case 'confirmations':
				$this->render_value( $this->get_meta_string( $order, '_monero_confirmations' ) );
				break;

			case 'txids':
				$this->render_txids( $order->get_meta( '_monero_txids' ) );
				break;
		}
	}

	/** Render order and payment statuses. */
	private function render_status( $order ) {
		$order_status   = wc_get_order_status_name( $order->get_status() );
		$payment_status = $this->get_meta_string( $order, '_monero_payment_status' );

		echo esc_html( $order_status );
		if ( '' !== $payment_status ) {
			printf( '<br><small>%s</small>', esc_html( $payment_status ) );
		}
	}

	/** Render a shortened payment address. */
	private function render_address( $address ) {
		if ( '' === $address ) {
			echo esc_html( '—' );
			return;
		}

		$short_address = strlen( $address ) > 31
			? substr( $address, 0, 15 ) . '…' . substr( $address, -15 )
			: $address;

		printf(
			'<code title="%1$s">%2$s</code>',
			esc_attr( $address ),
			esc_html( $short_address )
		);
	}

	/** Render a scalar value or placeholder. */
	private function render_value( $value ) {
		echo '' !== $value ? esc_html( $value ) : esc_html( '—' );
	}

	/** Render stored transaction IDs. */
	private function render_txids( $stored_txids ) {
		$txids = $this->parse_txids( $stored_txids );

		if ( empty( $txids ) ) {
			echo esc_html( '—' );
			return;
		}

		foreach ( $txids as $index => $txid ) {
			if ( $index > 0 ) {
				echo '<br>';
			}
			printf( '<code class="monero-payment-txid">%s</code>', esc_html( $txid ) );
		}
	}

	/** Parse transaction IDs from order metadata. */
	private function parse_txids( $stored_txids ) {
		if ( is_array( $stored_txids ) ) {
			$txids = $stored_txids;
		} elseif ( is_string( $stored_txids ) && '' !== trim( $stored_txids ) ) {
			$decoded = json_decode( $stored_txids, true );
			$txids   = is_array( $decoded ) ? $decoded : explode( ',', $stored_txids );
		} else {
			return array();
		}

		$txids = array_filter( $txids, 'is_scalar' );
		$txids = array_map( 'strval', $txids );
		$txids = array_map( 'trim', $txids );

		return array_values( array_filter( $txids, 'strlen' ) );
	}

	/** Read scalar order metadata. */
	private function get_meta_string( $order, $key ) {
		$value = $order->get_meta( $key );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/** Render the empty-list message. */
	public function no_items() {
		esc_html_e( 'No Monero payments found.', 'monero_gateway' );
	}
}

/** Register and render the Monero payments page. */
class Monero_Gateway_Admin_Payments {

	/** Register the WooCommerce submenu. */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Monero payments', 'monero_gateway' ),
			__( 'Monero payments', 'monero_gateway' ),
			'manage_woocommerce',
			'monero-gateway-payments',
			array( __CLASS__, 'render_page' )
		);
	}

	/** Render the payments page. */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$list_table = new Monero_Gateway_Admin_Payments_List();
		$list_table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Monero payments', 'monero_gateway' ) . '</h1>';
		echo '<hr class="wp-header-end">';
		echo '<style>.monero-payment-txid{white-space:normal;word-break:break-all}</style>';
		$list_table->views();
		$list_table->display();
		echo '</div>';
	}
}
