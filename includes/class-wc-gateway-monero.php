<?php
/**
 * Monero WooCommerce payment gateway.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WC_Gateway_* is the WooCommerce gateway naming convention.
class WC_Gateway_Monero extends WC_Payment_Gateway {
	const REORG_LOOKBACK = 3;
	private $scanner_instance;

	public function __construct( $register_hooks = true ) {
		$this->id                 = 'monero_gateway';
		$this->method_title       = __( 'Monero', 'monero_gateway' );
		$this->method_description = __( 'Accept Monero payments verified by WordPress through a configured Monero node.', 'monero_gateway' );
		$this->has_fields         = false;
		$this->icon               = apply_filters( 'woocommerce_monero_gateway_icon', plugins_url( 'assets/images/monero-icon.png', MONERO_GATEWAY_WC_FILE ) );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Monero (XMR)', 'monero_gateway' ) );
		$this->description = $this->get_option( 'description' );

		if ( ! $register_hooks ) {
			return;
		}

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'render_payment_panel' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'render_payment_panel' ) );
		add_action( 'woocommerce_view_order', array( $this, 'render_payment_panel' ) );
		add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'strip_pii' ), 20, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'strip_pii' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'maybe_warn_gmp' ) );
		add_action( 'admin_notices', array( $this, 'maybe_warn_network' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_details' ) );
	}

	/** Remove checkout network identifiers from Monero orders. */
	public function strip_pii( $order, $data = null ) {
		$method = ( is_array( $data ) && ! empty( $data['payment_method'] ) ) ? $data['payment_method'] : $order->get_payment_method();
		if ( $this->id === $method ) {
			$order->set_customer_ip_address( '' );
			$order->set_customer_user_agent( '' );
		}
	}

	/** Write to the WooCommerce log when enabled. */
	private function log( $message, $level = 'info' ) {
		if ( 'yes' !== $this->get_option( 'debug_log' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'monero_gateway' ) );
	}

	public function process_admin_options() {
		$old_settings = get_option( $this->get_option_key(), array() );
		$raw_nodes    = isset( $_POST['node_configs'] ) ? wp_unslash( $_POST['node_configs'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce.
		$nodes        = null === $raw_nodes ? null : Monero_Node_Config::sanitize_submission( $raw_nodes, isset( $old_settings['node_configs'] ) ? $old_settings['node_configs'] : ( isset( $old_settings['nodes'] ) ? $old_settings['nodes'] : array() ) );
		if ( is_wp_error( $nodes ) ) {
			WC_Admin_Settings::add_error( __( 'Monero node settings were not saved. Check every URL, authentication type, username, and password.', 'monero_gateway' ) );
			return false;
		}
		$address = $this->posted_setting( 'xmr_address', $this->get_option( 'xmr_address' ) );
		$view    = $this->posted_setting( 'view_key', $this->get_option( 'view_key' ) );
		$nodes_for_check = null !== $nodes ? $nodes : Monero_Node_Config::normalize_list( isset( $old_settings['node_configs'] ) ? $old_settings['node_configs'] : ( isset( $old_settings['nodes'] ) ? $old_settings['nodes'] : array() ) );
		$check   = ( '' === $view && defined( 'MONERO_GATEWAY_VIEW_KEY' ) ) ? trim( (string) MONERO_GATEWAY_VIEW_KEY ) : $view;
		$disabling = ! isset( $_POST[ $this->get_field_key( 'enabled' ) ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce.

		$address_valid = Monero_Util::crypto_ready()
			&& 95 === strlen( $address )
			&& in_array( substr( $address, 0, 1 ), array( '4', '5', '9' ), true )
			&& $this->scanner_for_address( $address )->address_valid( $address );
		$keys_valid = false;
		if ( $address_valid && preg_match( '/^[0-9a-fA-F]{64}$/', $check ) ) {
			$keys       = $this->scanner_for_address( $address )->verify_keys( $address, $check );
			$keys_valid = ! empty( $keys['address_valid'] ) && ! empty( $keys['key_match'] );
		}
		$nodes_valid = ! empty( $nodes_for_check );
		if ( ! $disabling && $address_valid && $nodes_valid ) {
			$nodes_valid = $this->nodes_match_network( $nodes_for_check, $this->detect_network_from_address( $address ) );
		}
		if ( ! $disabling && ( ! $address_valid || ! $keys_valid || ! $nodes_valid ) ) {
			WC_Admin_Settings::add_error( __( 'Monero settings were not saved. Check that the address, private view key, and node URLs form a valid set.', 'monero_gateway' ) );
			return false;
		}
		$saved = parent::process_admin_options();
		if ( $saved && null !== $nodes ) {
			$settings                 = get_option( $this->get_option_key(), array() );
			$settings['node_configs'] = $nodes;
			$settings['nodes']        = Monero_Node_Config::legacy_urls( $nodes );
			update_option( $this->get_option_key(), $settings );
			$this->settings = $settings;
		}
		if ( $saved && $keys_valid ) {
			update_option( 'monero_gateway_keys_ok', $this->keys_hash( $address, $check ), false );
		} elseif ( $saved ) {
			delete_option( 'monero_gateway_keys_ok' );
		}
		return $saved;
	}

	public function validate_xmr_address_field( $key, $value ) {
		$address = trim( sanitize_text_field( (string) $value ) );
		if ( ! Monero_Util::crypto_ready() ) {
			WC_Admin_Settings::add_error( __( 'The Monero address could not be validated because GMP or BCMath is unavailable.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		$standard_prefix = in_array( substr( $address, 0, 1 ), array( '4', '5', '9' ), true );
		if ( 95 !== strlen( $address ) || ! $standard_prefix || ! $this->scanner_for_address( $address )->address_valid( $address ) ) {
			WC_Admin_Settings::add_error( __( 'Enter a valid standard (primary) Monero address. Subaddresses and integrated addresses are not supported here.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		return $address;
	}

	public function validate_view_key_field( $key, $value ) {
		$view    = trim( sanitize_text_field( (string) $value ) );
		$address = $this->posted_setting( 'xmr_address', $this->get_option( 'xmr_address' ) );
		$check   = ( '' === $view && defined( 'MONERO_GATEWAY_VIEW_KEY' ) ) ? trim( (string) MONERO_GATEWAY_VIEW_KEY ) : $view;
		if ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $check ) || ! Monero_Util::crypto_ready() ) {
			WC_Admin_Settings::add_error( __( 'The private view key must contain exactly 64 hexadecimal characters.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		$keys = $this->scanner_for_address( $address )->verify_keys( $address, $check );
		if ( empty( $keys['address_valid'] ) || empty( $keys['key_match'] ) ) {
			WC_Admin_Settings::add_error( __( 'The private view key does not match the configured Monero address.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		return $view;
	}

	public function validate_tolerance_xmr_field( $key, $value ) {
		$tolerance = trim( sanitize_text_field( (string) $value ) );
		if ( ! is_numeric( $tolerance ) || (float) $tolerance < 0 || (float) $tolerance >= 0.01 ) {
			WC_Admin_Settings::add_error( __( 'The underpayment tolerance must be a number from 0 up to, but not including, 0.01 XMR.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		return $tolerance;
	}

	public function validate_discount_field( $key, $value ) {
		if ( ! is_numeric( $value ) ) {
			WC_Admin_Settings::add_error( __( 'The Monero discount must be a number from 0 to 100.', 'monero_gateway' ) );
			return $this->get_option( $key, '0' );
		}
		return (string) Monero_Gateway_Discount::normalize_percentage( $value );
	}

	public function validate_nodes_field( $key, $value ) {
		$nodes = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $value ) ) ) ) );
		foreach ( $nodes as $node ) {
			$scheme = strtolower( (string) wp_parse_url( $node, PHP_URL_SCHEME ) );
			$host   = (string) wp_parse_url( $node, PHP_URL_HOST );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
				WC_Admin_Settings::add_error( __( 'Each Monero node must be a valid HTTP(S) URL with a host.', 'monero_gateway' ) );
				return $this->get_option( $key );
			}
		}
		if ( ! $nodes ) {
			WC_Admin_Settings::add_error( __( 'Enter at least one Monero node URL.', 'monero_gateway' ) );
			return $this->get_option( $key );
		}
		return implode( ', ', $nodes );
	}

	public function generate_node_list_html( $key, $data ) {
		$rows = $this->get_option( 'node_configs', $this->get_option( 'nodes', isset( $data['default'] ) ? $data['default'] : '' ) );
		return '<tr><th scope="row" class="titledesc">' . esc_html( $data['title'] ) . '</th><td class="forminp">' . Monero_Node_Fields::render( $rows ) . '<p class="description">' . wp_kses_post( $data['description'] ) . '</p></td></tr>';
	}

	private function posted_setting( $key, $default = '' ) {
		$field = $this->get_field_key( $key );
		return isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : trim( (string) $default ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the settings nonce.
	}

	private function scanner_for_address( $address ) {
		require_once __DIR__ . '/class-monero-scanner.php';
		return new Monero_Scanner( '', $this->detect_network_from_address( $address ), 12 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => __( 'Enable', 'monero_gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Monero payments', 'monero_gateway' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Title', 'monero_gateway' ),
				'type'        => 'text',
				'default'     => __( 'Monero (XMR)', 'monero_gateway' ),
				'desc_tip'    => true,
				'description' => __( 'What the buyer sees at checkout.', 'monero_gateway' ),
			),
			'description' => array(
				'title'   => __( 'Description', 'monero_gateway' ),
				'type'    => 'textarea',
				'default' => __( 'Pay with Monero by scanning the QR code.', 'monero_gateway' ),
			),
			'checkout_theme' => array(
				'title'   => __( 'Payment box theme', 'monero_gateway' ),
				'type'    => 'select',
				'default' => 'light',
				'options' => array(
					'light' => __( 'Light', 'monero_gateway' ),
					'dark'  => __( 'Dark', 'monero_gateway' ),
				),
				'description' => __( 'Choose the payment box theme.', 'monero_gateway' ),
			),
			'show_qr' => array(
				'title'   => __( 'QR code', 'monero_gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Show the Monero payment QR code', 'monero_gateway' ),
				'default' => 'yes',
			),
			'discount' => array(
				'title'             => __( 'Monero payment discount (%)', 'monero_gateway' ),
				'type'              => 'number',
				'default'           => '0',
				'description'       => __( 'Applied to eligible items when Monero is selected.', 'monero_gateway' ),
				'custom_attributes' => array( 'min' => '0', 'max' => '100', 'step' => '0.01' ),
			),
			'success_redirect' => array(
				'title'       => __( 'Redirect after payment (URL)', 'monero_gateway' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'https://example.com/thank-you',
				'description' => __( 'Optional. {order_id} and {order_key} are replaced; {order_key} is included only for URLs on this site.', 'monero_gateway' ),
			),
			'network_status' => array(
				'title' => __( 'Network', 'monero_gateway' ),
				'type'  => 'network_status',
			),
			'xmr_address' => array(
				'title'       => __( 'Monero address', 'monero_gateway' ),
				'type'        => 'text',
				'placeholder' => '4... (mainnet) or 5.../7... (stagenet)',
				'description' => __( 'The address determines the network and is used to derive a subaddress for each order.', 'monero_gateway' ),
			),
			'view_key' => array(
				'title'       => __( 'Private view key', 'monero_gateway' ),
				'type'        => 'password',
				'description' => __( 'The private view key for the configured address. MONERO_GATEWAY_VIEW_KEY in wp-config.php takes precedence.', 'monero_gateway' ),
			),
			'nodes' => array(
				'title'       => __( 'Monero node(s)', 'monero_gateway' ),
				'type'        => 'node_list',
				'default'     => '',
				'description' => __( 'Add public or private nodes in priority order. Requests fail over to the next node; all nodes must match the address network.', 'monero_gateway' ),
			),
			'min_confirmations' => array(
				'title'             => __( 'Confirmations required', 'monero_gateway' ),
				'type'              => 'number',
				'default'           => '1',
				'description'       => __( 'Set 0 for mempool detection, 1 for the first block, or a higher value for more confirmations.', 'monero_gateway' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'tolerance_xmr' => array(
				'title'       => __( 'Underpayment tolerance (XMR)', 'monero_gateway' ),
				'type'        => 'text',
				'default'     => '0',
				'description' => __( 'Accept a payment that falls short by up to this amount.', 'monero_gateway' ),
			),
			'test_node' => array(
				'title' => __( 'Check setup', 'monero_gateway' ),
				'type'  => 'test_node',
			),
			'pricing_section' => array(
				'title'       => __( 'Pricing', 'monero_gateway' ),
				'type'        => 'title',
				'description' => __( 'Choose how the order total is converted to XMR.', 'monero_gateway' ),
			),
			'price_source' => array(
				'title'   => __( 'Price source', 'monero_gateway' ),
				'type'    => 'select',
				'default' => 'coingecko',
				'options' => array(
					'coingecko' => __( 'CoinGecko', 'monero_gateway' ),
					'custom'    => __( 'Custom URL', 'monero_gateway' ),
					'fixed'     => __( 'Fixed rate', 'monero_gateway' ),
				),
				'description' => __( 'Ignored when the store currency is XMR.', 'monero_gateway' ),
			),
			'coingecko_api_key' => array(
				'title'       => __( 'CoinGecko API key', 'monero_gateway' ),
				'type'        => 'password',
				'description' => __( 'Optional CoinGecko Demo or Pro API key.', 'monero_gateway' ),
			),
			'custom_rate_url' => array(
				'title'       => __( 'Custom price URL', 'monero_gateway' ),
				'type'        => 'text',
				'placeholder' => 'https://example.com/xmr?vs={currency}',
				'description' => __( 'URL returning the price of 1 XMR in the store currency.', 'monero_gateway' ),
			),
			'custom_rate_path' => array(
				'title'       => __( 'Rate JSON path', 'monero_gateway' ),
				'type'        => 'text',
				'placeholder' => 'data.rate',
				'description' => __( 'Dot path to the numeric rate, or empty for a bare number.', 'monero_gateway' ),
			),
			'fixed_rate' => array(
				'title'       => __( 'Fixed rate / fallback', 'monero_gateway' ),
				'type'        => 'text',
				'placeholder' => '150',
				'description' => __( 'Price of 1 XMR in the store currency.', 'monero_gateway' ),
			),
			'expiry_hours' => array(
				'title'             => __( 'Auto-cancel after (hours)', 'monero_gateway' ),
				'type'              => 'number',
				'default'           => '0',
				'description'       => __( 'Cancel unpaid orders after this period. Set 0 to disable expiry.', 'monero_gateway' ),
				'custom_attributes' => array( 'min' => '0', 'step' => '1' ),
			),
			'debug_log' => array(
				'title'   => __( 'Debug log', 'monero_gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Log payment scanning to WooCommerce logs.', 'monero_gateway' ),
				'default' => 'no',
			),
		);
	}

	/** Render the detected network in the settings table. */
	public function generate_network_status_html( $key, $data ) {
		$net    = $this->detect_network();
		$colors = array( 'mainnet' => '#15803d', 'stagenet' => '#b45309', 'testnet' => '#6d28d9' );
		$color  = isset( $colors[ $net ] ) ? $colors[ $net ] : '#374151';
		$saved  = '' !== trim( (string) $this->get_option( 'xmr_address' ) );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<span style="display:inline-block;font-family:ui-monospace,Menlo,monospace;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;background:<?php echo esc_attr( $color ); ?>;padding:5px 13px;border-radius:4px"><?php echo esc_html( $saved ? $net : __( 'not set', 'monero_gateway' ) ); ?></span>
				<p class="description"><?php esc_html_e( 'Detected from the configured address.', 'monero_gateway' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Render the settings setup check. */
	public function generate_test_node_html( $key, $data ) {
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
			<td class="forminp">
				<button type="button" class="button" id="monero-gateway-test-node"><?php esc_html_e( 'Check setup', 'monero_gateway' ); ?></button>
				<div id="monero-gateway-node-result" style="margin-top:10px"></div>
				<p class="description"><?php esc_html_e( 'Checks node access, network compatibility, and the address view key.', 'monero_gateway' ); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/** Show recorded payment data on the order screen. */
	public function admin_order_details( $order ) {
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$addr = (string) $order->get_meta( '_monero_address' );
		if ( '' === $addr ) {
			return;
		}
		$rows = array(
			__( 'Owed', 'monero_gateway' )         => $order->get_meta( '_monero_amount' ) . ' XMR',
			__( 'Received', 'monero_gateway' )     => ( $order->get_meta( '_monero_received' ) ?: '—' ) . ' XMR',
			__( 'Confirmations', 'monero_gateway' ) => $order->get_meta( '_monero_confirmations' ) ?: '—',
		);
		echo '<div class="monero-gateway-order-detail" style="clear:both;margin-top:12px"><h4 style="margin:0 0 6px">' . esc_html__( 'Monero payment', 'monero_gateway' ) . '</h4><p style="margin:0 0 4px"><strong>' . esc_html__( 'Address', 'monero_gateway' ) . ':</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $addr ) . '</code></p>';
		foreach ( $rows as $label => $val ) {
			echo '<p style="margin:0"><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '</p>';
		}
		$txids = (string) $order->get_meta( '_monero_txids' );
		if ( '' !== $txids ) {
			echo '<p style="margin:4px 0 0"><strong>tx:</strong><br><code style="font-size:11px;word-break:break-all">' . esc_html( $txids ) . '</code></p>';
		}
		if ( 'yes' === $order->get_meta( '_monero_overpaid' ) ) {
			echo '<p style="margin:6px 0 0;padding:6px 8px;background:#fffbeb;border:1px solid #f59e0b;border-radius:4px;color:#92400e"><strong>' . esc_html__( 'Overpaid', 'monero_gateway' ) . ':</strong> ' . esc_html( (string) $order->get_meta( '_monero_overpaid_xmr' ) ) . ' XMR — ' . esc_html__( 'refund the difference to the buyer.', 'monero_gateway' ) . '</p>';
		}
		echo '</div>';
	}

	public function is_available() {
		return $this->can_accept_payments();
	}

	/** Whether the current settings are safe and complete enough to accept payments. */
	public function can_accept_payments() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}
		if ( '' === trim( (string) $this->get_option( 'xmr_address' ) ) || '' === $this->view_key() || ! Monero_Util::crypto_ready() ) {
			return false;
		}
		if ( ! $this->configured_nodes() ) {
			return false;
		}
		if ( $this->network_mismatch() ) {
			return false;
		}
		if ( $this->live_rate_stale() ) {
			return false;
		}
		return $this->keys_verified();
	}

	/** Warn when the PHP cryptography extensions are unavailable. */
	public function maybe_warn_gmp() {
		if ( Monero_Util::crypto_ready() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Monero:</strong> '
			. esc_html__( 'Payment verification needs the GMP and BCMath extensions. The gateway is hidden until ext-gmp and ext-bcmath are enabled.', 'monero_gateway' )
			. '</p></div>';
	}

	/** Warn administrators when the configured node and address use different networks. */
	public function maybe_warn_network() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->network_mismatch() ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>Monero:</strong> '
			. esc_html__( 'The configured node is on a different network than the merchant address. The gateway is hidden until they match.', 'monero_gateway' )
			. '</p></div>';
	}

	/** Add the setup-check behavior to the gateway settings page. */
	public function admin_assets( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'monero_gateway' !== $section ) {
			return;
		}
		wp_register_script( 'monero-gateway-admin', false, array(), MONERO_GATEWAY_WC_VERSION, true );
		wp_enqueue_script( 'monero-gateway-admin' );
		wp_enqueue_script( 'monero-gateway-node-fields', plugins_url( 'assets/js/monero-node-fields.js', MONERO_GATEWAY_WC_FILE ), array(), MONERO_GATEWAY_WC_VERSION, true );
		wp_enqueue_style( 'monero-gateway-node-fields', plugins_url( 'assets/css/monero-node-fields.css', MONERO_GATEWAY_WC_FILE ), array(), MONERO_GATEWAY_WC_VERSION );
		wp_localize_script(
			'monero-gateway-admin',
			'moneroGatewayAdmin',
			array(
				'ajaxurl'     => admin_url( 'admin-ajax.php' ),
				'nodeNonce'   => wp_create_nonce( 'monero_gateway_test_node' ),
				'checking'    => __( 'checking…', 'monero_gateway' ),
				'reqfail'     => __( 'request failed', 'monero_gateway' ),
				'unreachable' => __( 'unreachable', 'monero_gateway' ),
			)
		);
		wp_add_inline_script(
			'monero-gateway-admin',
			"(function(){var A=window.moneroGatewayAdmin||{};function v(id){var e=document.getElementById(id);return e?(e.value||'').trim():'';}function nodes(){return Array.from(document.querySelectorAll('.monero-node-row')).map(function(r){return {url:(r.querySelector('[name$=\"[url]\"]')||{}).value||'',auth:(r.querySelector('[name$=\"[auth]\"]')||{}).value||'none',username:(r.querySelector('[name$=\"[username]\"]')||{}).value||'',password:(r.querySelector('[name$=\"[password]\"]')||{}).value||''};});}var b=document.getElementById('monero-gateway-test-node');if(!b){return;}var o=document.getElementById('monero-gateway-node-result');b.addEventListener('click',function(){o.textContent=A.checking||'checking…';var p=new URLSearchParams({action:'monero_gateway_test_node',_wpnonce:A.nodeNonce,address:v('woocommerce_monero_gateway_xmr_address'),view_key:v('woocommerce_monero_gateway_view_key'),node_configs:JSON.stringify(nodes())});fetch(A.ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:p}).then(function(r){return r.json();}).then(function(d){if(!d||!d.success){o.textContent='✗ '+((d&&d.data&&d.data.msg)||A.unreachable||'unreachable');o.style.color='#b91c1c';return;}o.innerHTML=(d.data.checks||[]).map(function(c){return '<div style=\"color:'+(c.ok?'#15803d':'#b91c1c')+'\">'+(c.ok?'✓':'✗')+' '+String(c.msg).replace(/[<>]/g,'')+'</div>';}).join('');}).catch(function(){o.textContent='✗ '+(A.reqfail||'request failed');o.style.color='#b91c1c';});});})();"
		);
	}

	/** Validate the configured node, address, and view key. */
	public function ajax_test_node() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'monero_gateway_test_node', '_wpnonce', false ) ) {
			wp_send_json_error( array( 'msg' => __( 'not allowed', 'monero_gateway' ) ) );
		}
		if ( ! Monero_Util::crypto_ready() ) {
			wp_send_json_error( array( 'msg' => __( 'PHP is missing the GMP or BCMath extension.', 'monero_gateway' ) ) );
		}
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		$raw_nodes  = isset( $_POST['node_configs'] ) ? json_decode( wp_unslash( $_POST['node_configs'] ), true ) : array();
		$node_count = is_array( $raw_nodes ) ? count( $raw_nodes ) : 0;
		$nodes      = Monero_Node_Config::sanitize_submission( is_array( $raw_nodes ) ? array_slice( $raw_nodes, 0, 10 ) : array(), $this->configured_nodes() );
		$view    = isset( $_POST['view_key'] ) ? sanitize_text_field( wp_unslash( $_POST['view_key'] ) ) : '';
		if ( defined( 'MONERO_GATEWAY_VIEW_KEY' ) && '' !== trim( (string) MONERO_GATEWAY_VIEW_KEY ) ) {
			$view = trim( (string) MONERO_GATEWAY_VIEW_KEY );
		}

		if ( is_wp_error( $nodes ) || ! $nodes ) {
			wp_send_json_error( array( 'msg' => __( 'Enter at least one Monero node URL.', 'monero_gateway' ) ) );
		}
		$c        = '' !== $address ? $address[0] : '4';
		$addr_net = '5' === $c ? 'stagenet' : ( in_array( $c, array( '9', 'A', 'B' ), true ) ? 'testnet' : 'mainnet' );

		require_once __DIR__ . '/class-monero-scanner.php';
		$checks  = array();
		$healthy = 0;
		$scanner = null;
		$timeout = max( 1, min( 5, (int) floor( 25 / ( 2 * max( 1, count( $nodes ) ) ) ) ) );
		foreach ( $nodes as $index => $node ) {
			$candidate = new Monero_Scanner( array( $node ), $addr_net, $timeout );
			$info      = $candidate->node_info();
			$number    = $index + 1;
			$net       = isset( $info['nettype'] ) ? (string) $info['nettype'] : 'unknown';
			if ( ! empty( $info['ok'] ) && 'unknown' !== $net && $net !== $addr_net ) {
				/* translators: 1: node number, 2: node network, 3: address network */
				$checks[] = array( 'ok' => false, 'warning' => true, 'code' => 'network_mismatch', 'msg' => sprintf( __( 'Node %1$d uses %2$s, but the address is %3$s.', 'monero_gateway' ), $number, $net, $addr_net ) );
				continue;
			}
			if ( ! empty( $info['ok'] ) ) {
				$healthy++;
				if ( null === $scanner ) { $scanner = $candidate; }
				/* translators: 1: node number, 2: network name, 3: block height */
				$checks[] = array( 'ok' => true, 'msg' => sprintf( __( 'Node %1$d is reachable — %2$s, block %3$s.', 'monero_gateway' ), $number, 'unknown' === $net ? $addr_net : $net, isset( $info['height'] ) ? $info['height'] : '?' ) );
				continue;
			}
			$diagnostic        = self::node_setup_diagnostic( $candidate->last_node_error() );
			$diagnostic['ok']  = false;
			$diagnostic['warning'] = true;
			/* translators: 1: node number, 2: diagnostic message */
			$diagnostic['msg'] = sprintf( __( 'Node %1$d: %2$s', 'monero_gateway' ), $number, $diagnostic['msg'] );
			$checks[]          = $diagnostic;
		}
		array_unshift( $checks, array( 'ok' => $healthy > 0, 'msg' => sprintf( __( '%1$d of %2$d nodes are healthy.', 'monero_gateway' ), $healthy, count( $nodes ) ) ) );
		if ( $node_count > count( $nodes ) ) {
			$checks[] = array( 'ok' => false, 'warning' => true, 'code' => 'node_limit', 'msg' => __( 'Only the first 10 nodes were checked.', 'monero_gateway' ) );
		}

		$ok   = $healthy > 0;
		$keys = ( '' !== $address && '' !== $view && $scanner ) ? $scanner->verify_keys( $address, $view ) : null;
		if ( '' !== $address ) {
			$valid    = $keys && ! empty( $keys['address_valid'] );
			$ok       = $ok && $valid;
			/* translators: %s: address network */
			$checks[] = array( 'ok' => $valid, 'msg' => $valid ? sprintf( __( 'Address is valid (%s).', 'monero_gateway' ), $addr_net ) : __( 'Address could not be decoded.', 'monero_gateway' ) );
		}
		if ( '' !== $address && '' !== $view ) {
			$match    = $keys && ! empty( $keys['key_match'] );
			$ok       = $ok && $match;
			$checks[] = array( 'ok' => $match, 'msg' => $match ? __( 'View key belongs to this address.', 'monero_gateway' ) : __( 'View key does not match this address.', 'monero_gateway' ) );
		} elseif ( '' === $view ) {
			$ok       = false;
			$checks[] = array( 'ok' => false, 'msg' => __( 'No view key set yet.', 'monero_gateway' ) );
		}
		if ( $ok ) {
			update_option( 'monero_gateway_keys_ok', $this->keys_hash( $address, $view ), false );
		}

		wp_send_json_success( array( 'ok' => $ok, 'checks' => $checks ) );
	}

	public static function node_setup_diagnostic( $error ) {
		$code = is_array( $error ) && isset( $error['code'] ) ? (string) $error['code'] : 'transport';
		$messages = array(
			'unauthorized'       => __( 'Node rejected the credentials. Check the username, password, and authentication type.', 'monero_gateway' ),
			'transport'          => __( 'Node could not be reached. Check the URL, port, TLS, and firewall.', 'monero_gateway' ),
			'digest_unavailable' => __( 'Digest authentication is unavailable. Enable PHP cURL or choose Basic/None.', 'monero_gateway' ),
			'http'               => __( 'Node returned an HTTP error. Check the endpoint and node service.', 'monero_gateway' ),
		);
		if ( ! isset( $messages[ $code ] ) ) { $code = 'transport'; }
		return array( 'code' => $code, 'msg' => $messages[ $code ] );
	}

	private function view_key() {
		if ( defined( 'MONERO_GATEWAY_VIEW_KEY' ) && '' !== trim( (string) MONERO_GATEWAY_VIEW_KEY ) ) {
			return trim( (string) MONERO_GATEWAY_VIEW_KEY );
		}
		return trim( (string) $this->get_option( 'view_key' ) );
	}

	private function keys_hash( $address, $view_key ) {
		return hash( 'sha256', trim( (string) $address ) . "\0" . trim( (string) $view_key ) );
	}

	private function keys_verified() {
		$address = trim( (string) $this->get_option( 'xmr_address' ) );
		$view    = $this->view_key();
		$hash    = $this->keys_hash( $address, $view );
		if ( hash_equals( $hash, (string) get_option( 'monero_gateway_keys_ok', '' ) ) ) {
			return true;
		}
		$keys = $this->scanner_for_address( $address )->verify_keys( $address, $view );
		if ( empty( $keys['address_valid'] ) || empty( $keys['key_match'] ) ) {
			delete_option( 'monero_gateway_keys_ok' );
			return false;
		}
		update_option( 'monero_gateway_keys_ok', $hash, false );
		return true;
	}

	private function scanner() {
		require_once __DIR__ . '/class-monero-scanner.php';
		if ( ! $this->scanner_instance ) {
			$this->scanner_instance = new Monero_Scanner( $this->configured_nodes(), $this->detect_network(), 12 );
		}
		return $this->scanner_instance;
	}

	private function configured_nodes() {
		return Monero_Node_Config::normalize_list( $this->get_option( 'node_configs', $this->get_option( 'nodes' ) ) );
	}

	private function network_mismatch() {
		if ( ! Monero_Util::crypto_ready() || '' === trim( (string) $this->get_option( 'xmr_address' ) ) ) {
			return false;
		}
		$nodes = $this->configured_nodes();
		if ( ! $nodes ) {
			return false;
		}
		return ! $this->nodes_match_network( $nodes, $this->detect_network() );
	}

	private function nodes_match_network( $nodes, $network ) {
		$key    = 'monero_gateway_node_nettype_' . md5( $network . "\0" . Monero_Node_Config::legacy_urls( $nodes ) );
		$health = get_transient( $key );
		if ( false !== $health ) {
			return 'ok' === $health;
		}
		require_once __DIR__ . '/class-monero-scanner.php';
		$infos = array();
		foreach ( $nodes as $node ) {
			$infos[] = ( new Monero_Scanner( $node, $network, 12 ) )->node_info();
		}
		$health = self::node_health_allows_payments( $infos, $network ) ? 'ok' : 'invalid';
		set_transient( $key, $health, 'ok' === $health ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return 'ok' === $health;
	}

	public static function node_health_allows_payments( $infos, $network ) {
		$healthy = 0;
		foreach ( (array) $infos as $info ) {
			if ( empty( $info['ok'] ) ) { continue; }
			$nettype = isset( $info['nettype'] ) ? (string) $info['nettype'] : 'unknown';
			if ( 'unknown' !== $nettype && $network !== $nettype ) { return false; }
			$healthy++;
		}
		return $healthy > 0;
	}

	private function live_rate_stale() {
		$source = $this->get_option( 'price_source', 'coingecko' );
		if ( 'fixed' === $source || (float) $this->get_option( 'fixed_rate' ) > 0 ) {
			return false;
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? strtoupper( get_woocommerce_currency() ) : '';
		if ( '' === $currency || 'XMR' === $currency ) {
			return false;
		}
		$vs  = strtolower( $currency );
		$key = 'custom' === $source ? 'monero_gateway_rate_custom_' . $vs : 'monero_gateway_rate_' . $vs;
		if ( false !== get_transient( $key ) ) {
			return false;
		}
		$health_key = 'monero_gateway_rate_health_' . md5( $source . "\0" . $currency . "\0" . (string) $this->get_option( 'custom_rate_url' ) );
		$health     = get_transient( $health_key );
		if ( false !== $health ) {
			return 'ok' !== $health;
		}
		$rate   = 'custom' === $source ? $this->custom_rate( $currency ) : $this->xmr_rate( $currency );
		$health = ( ! is_wp_error( $rate ) && (float) $rate > 0 ) || (int) get_option( 'monero_gateway_rate_ok_at', 0 ) >= time() - 30 * MINUTE_IN_SECONDS ? 'ok' : 'invalid';
		set_transient( $health_key, $health, 'ok' === $health ? 5 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
		return 'ok' !== $health;
	}

	private function detect_network() {
		$address = trim( (string) $this->get_option( 'xmr_address' ) );
		return $this->detect_network_from_address( $address );
	}

	private function detect_network_from_address( $address ) {
		$prefix  = '' !== $address ? $address[0] : '4';
		if ( '5' === $prefix ) {
			return 'stagenet';
		}
		if ( in_array( $prefix, array( '9', 'A', 'B' ), true ) ) {
			return 'testnet';
		}
		return 'mainnet';
	}

	/** Calculate the order amount in XMR. */
	public function get_xmr_amount( $order ) {
		$currency = strtoupper( $order->get_currency() );
		$total    = (float) $order->get_total();
		if ( 'XMR' === $currency ) {
			return Monero_Util::fmt( $total );
		}
		$rate = $this->resolve_rate( $currency );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}
		if ( $rate <= 0 ) {
			return new WP_Error( 'monero_gateway_rate', __( 'Could not get an XMR price. Check the pricing settings.', 'monero_gateway' ) );
		}
		return Monero_Util::fmt( $total / $rate );
	}

	/** Return the configured price of one XMR in a currency. */
	public function get_rate_for_currency( $currency ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		return 'XMR' === $currency ? 1.0 : $this->resolve_rate( $currency );
	}

	private function resolve_rate( $currency ) {
		$source = $this->get_option( 'price_source', 'coingecko' );
		$fixed  = (float) $this->get_option( 'fixed_rate' );
		if ( 'fixed' === $source ) {
			return $fixed > 0 ? $fixed : new WP_Error( 'monero_gateway_rate', __( 'Set a fixed XMR rate in the payment settings.', 'monero_gateway' ) );
		}
		$live = 'custom' === $source ? $this->custom_rate( $currency ) : $this->xmr_rate( $currency );
		if ( ! is_wp_error( $live ) && (float) $live > 0 ) {
			$live_value = (float) $live;
			if ( $fixed > 0 && ( $live_value < $fixed * 0.02 || $live_value > $fixed * 50 ) ) {
				$this->log( 'live rate (' . $live_value . ') is implausible against fixed fallback (' . $fixed . ') — discarding', 'warning' );
			} else {
				return $live_value;
			}
		}
		if ( $fixed > 0 ) {
			$this->log( 'price feed (' . $source . ') unavailable — using fixed fallback ' . $fixed, 'warning' );
			return $fixed;
		}
		return is_wp_error( $live ) ? $live : new WP_Error( 'monero_gateway_rate', __( 'Could not get an XMR price and no fixed fallback is set.', 'monero_gateway' ) );
	}

	private function custom_rate( $currency ) {
		$vs     = strtolower( $currency );
		$cached = get_transient( 'monero_gateway_rate_custom_' . $vs );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		$url = trim( (string) $this->get_option( 'custom_rate_url' ) );
		if ( '' === $url ) {
			return new WP_Error( 'monero_gateway_rate', __( 'No custom price URL is set.', 'monero_gateway' ) );
		}
		$url = str_replace( array( '{currency}', '{CURRENCY}' ), array( $vs, strtoupper( $vs ) ), $url );
		$res = wp_safe_remote_get( esc_url_raw( $url ), array( 'timeout' => 12 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$rate = $this->dig_path( $body, trim( (string) $this->get_option( 'custom_rate_path' ) ) );
		if ( ! is_numeric( $rate ) || (float) $rate <= 0 ) {
			return new WP_Error( 'monero_gateway_rate', __( 'The custom price source did not return a valid rate at that path.', 'monero_gateway' ) );
		}
		$rate = (float) $rate;
		set_transient( 'monero_gateway_rate_custom_' . $vs, $rate, 180 );
		update_option( 'monero_gateway_rate_ok_at', time(), false );
		return $rate;
	}

	private function dig_path( $data, $path ) {
		if ( '' === $path ) {
			return is_numeric( $data ) ? $data : null;
		}
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $data ) && array_key_exists( $segment, $data ) ) {
				$data = $data[ $segment ];
			} else {
				return null;
			}
		}
		return $data;
	}

	private function xmr_rate( $currency ) {
		$vs     = strtolower( $currency );
		$key    = 'monero_gateway_rate_' . $vs;
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (float) $cached;
		}
		$url     = 'https://api.coingecko.com/api/v3/simple/price?ids=monero&vs_currencies=' . rawurlencode( $vs );
		$api_key = trim( (string) $this->get_option( 'coingecko_api_key' ) );
		if ( '' !== $api_key ) {
			$url .= '&x_cg_demo_api_key=' . rawurlencode( $api_key );
		}
		$res = wp_remote_get( $url, array( 'timeout' => 12 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! isset( $body['monero'][ $vs ] ) ) {
			/* translators: %s: currency code */
			return new WP_Error( 'monero_gateway_rate', sprintf( __( 'No XMR price for %s.', 'monero_gateway' ), $currency ) );
		}
		$rate = (float) $body['monero'][ $vs ];
		set_transient( $key, $rate, 180 );
		update_option( 'monero_gateway_rate_ok_at', time(), false );
		return $rate;
	}

	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}
		try {
			if ( $order->is_paid() ) {
				return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			}
			$this->record_discount( $order );
			$existing_address = (string) $order->get_meta( '_monero_address' );
			$existing_amount  = (string) $order->get_meta( '_monero_amount' );
			if ( '' !== $existing_address && '' !== $existing_amount && ! $this->address_used_elsewhere( $existing_address, $order_id ) ) {
				if ( 'on-hold' !== $order->get_status() ) {
					$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'monero_gateway' ) );
				}
				return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			}
			$reset_order = false;
			if ( '' !== $existing_address || '' !== $existing_amount ) {
				$stale_meta = array(
					'_monero_address',
					'_monero_amount',
					'_monero_watch_txids',
					'_monero_watch_txid',
					'_monero_received',
					'_monero_seen',
					'_monero_shortfall',
					'_monero_confirmations',
					'_monero_scan_height',
					'_monero_tip_height',
					'_monero_partial_flagged',
					'_monero_overpaid',
					'_monero_overpaid_xmr',
					'_monero_payment_status',
					'_monero_txids',
				);
				foreach ( $stale_meta as $meta_key ) {
					$order->delete_meta_data( $meta_key );
				}
				$reset_order = true;
			}
			if ( 'on-hold' === $order->get_status() ) {
				$order->set_status( 'pending' );
				$reset_order = true;
			}
			if ( $reset_order ) {
				$order->save();
			}

			$amount = $this->get_xmr_amount( $order );
			if ( is_wp_error( $amount ) ) {
				wc_add_notice( $amount->get_error_message(), 'error' );
				return array( 'result' => 'failure' );
			}
			if ( (float) $amount <= 0 ) {
				if ( (float) $order->get_total() > 0 ) {
					wc_add_notice( __( 'Could not compute a valid XMR amount. Check the rate settings.', 'monero_gateway' ), 'error' );
					return array( 'result' => 'failure' );
				}
				$order->payment_complete();
				$order->add_order_note( __( 'Order total is 0 — no Monero payment required.', 'monero_gateway' ) );
				if ( WC()->cart ) {
					WC()->cart->empty_cart();
				}
				return array( 'result' => 'success', 'redirect' => $this->get_return_url( $order ) );
			}

			$primary = trim( (string) $this->get_option( 'xmr_address' ) );
			if ( '' === $primary || '' === $this->view_key() ) {
				wc_add_notice( __( 'Monero is not fully configured. Please contact us.', 'monero_gateway' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$scanner    = $this->scanner();
			$subaddress = null;
			for ( $attempt = 0; $attempt < 5; $attempt++ ) {
				$minor      = (int) $order_id + $attempt;
				$subaddress = $scanner->subaddress( 0, $minor, $this->view_key(), $primary );
				if ( $subaddress && ! empty( $subaddress['address'] ) && ! $this->address_used_elsewhere( $subaddress['address'], $order_id ) ) {
					break;
				}
				$subaddress = null;
			}
			if ( ! $subaddress || empty( $subaddress['address'] ) ) {
				$this->log( 'could not derive a unique subaddress for #' . $order_id, 'error' );
				wc_add_notice( __( 'Could not start the Monero payment. Please contact us.', 'monero_gateway' ), 'error' );
				return array( 'result' => 'failure' );
			}
			$birthday = $scanner->tip_height();
			if ( null === $birthday || (int) $birthday <= 0 ) {
				$this->log( 'checkout #' . $order_id . ' aborted — node unreachable, no tip height', 'error' );
				wc_add_notice( __( 'Could not reach the Monero network. Please try again in a moment.', 'monero_gateway' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$birthday = (int) $birthday;
			$order->update_meta_data( '_monero_address', $subaddress['address'] );
			$order->update_meta_data( '_monero_amount', $amount );
			$order->update_meta_data( '_monero_mode', 'watch' );
			$order->update_meta_data( '_monero_minor', $minor );
			$order->update_meta_data( '_monero_birthday', $birthday );
			$order->update_meta_data( '_monero_scan_height', $birthday );
			$order->save();
			$this->log( 'order #' . $order_id . ' → ' . $amount . ' XMR · ' . $subaddress['address'] . ' · from ' . $birthday );

			$order->update_status( 'on-hold', __( 'Awaiting Monero payment.', 'monero_gateway' ) );
		} catch ( \Throwable $exception ) {
			$this->log( 'payment setup failed for order #' . $order_id . ': ' . $exception->getMessage(), 'error' );
			wc_add_notice( __( 'Could not start the Monero payment. Please try again or contact us.', 'monero_gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	private function record_discount( $order ) {
		if ( '' !== (string) $order->get_meta( '_monero_discount_percent' ) ) {
			return;
		}
		$codes = array_map( 'wc_format_coupon_code', $order->get_coupon_codes() );
		if ( ! in_array( Monero_Gateway_Discount::COUPON_CODE, $codes, true ) ) {
			return;
		}
		$settings = get_option( 'woocommerce_monero_gateway_settings', array() );
		$percent  = Monero_Gateway_Discount::normalize_percentage( $settings['discount'] ?? 0 );
		$amount   = (float) $order->get_discount_total() + (float) $order->get_discount_tax();
		$order->update_meta_data( '_monero_discount_percent', (string) $percent );
		$order->update_meta_data( '_monero_discount_amount', wc_format_decimal( $amount ) );
		$order->update_meta_data( '_monero_pre_discount_total', wc_format_decimal( (float) $order->get_total() + $amount ) );
		$order->save();
	}

	/** Render the payment address, amount, and live status. */
	public function render_payment_panel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_payment_method() !== $this->id ) {
			return;
		}
		$address = (string) $order->get_meta( '_monero_address' );
		$amounts = $this->payment_amounts( $order );
		$amount  = $amounts['pay'];
		if ( '' === $address ) {
			return;
		}
		$paid       = $order->is_paid();
		$status_url = add_query_arg(
			array(
				'wc-ajax'  => 'monero_gateway_status',
				'order_id' => $order_id,
				'key'      => $order->get_order_key(),
			),
			home_url( '/' )
		);

		$redirect = trim( (string) $this->get_option( 'success_redirect' ) );
		if ( '' !== $redirect ) {
			$key_sub  = Monero_Util::same_origin( $redirect, home_url() ) ? rawurlencode( $order->get_order_key() ) : '';
			$redirect = str_replace(
				array( '{order_id}', '{order_key}' ),
				array( rawurlencode( (string) $order_id ), $key_sub ),
				$redirect
			);
		}

		$overpaid     = $paid && 'yes' === $order->get_meta( '_monero_overpaid' );
		$overpaid_xmr = (string) $order->get_meta( '_monero_overpaid_xmr' );
		$terminal     = ! $paid ? $this->terminal_status( $order ) : '';

		wp_enqueue_script( 'monero-gateway-widget' );
		wp_enqueue_script( 'monero-gateway-checkout' );
		wp_enqueue_style( 'monero-gateway-checkout' );
		?>
		<section class="monero-gateway-panel" style="margin:24px 0;max-width:420px">
			<h2><?php esc_html_e( 'Pay with Monero', 'monero_gateway' ); ?></h2>
			<?php if ( $terminal ) : ?>
				<div style="margin:8px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.55">
					<?php echo esc_html( $this->terminal_status_message( $terminal, $order_id ) ); ?>
				</div>
			<?php else : ?>
				<div id="monero-gateway-status" data-poll="<?php echo esc_url( $status_url ); ?>" data-paid="<?php echo $paid ? '1' : '0'; ?>"<?php echo '' !== $redirect ? ' data-redirect="' . esc_url( $redirect ) . '"' : ''; ?>
					style="font-weight:600;margin:8px 0;<?php echo $paid ? 'color:#15803d' : 'color:#b45309'; ?>">
					<?php echo $paid ? esc_html__( '✓ Payment received', 'monero_gateway' ) : esc_html__( '● Awaiting payment…', 'monero_gateway' ); ?>
				</div>
				<?php if ( $overpaid ) : ?>
					<div class="monero-gateway-overpaid" style="margin:10px 0;padding:11px 13px;border:1px solid #f59e0b;border-radius:6px;color:#92400e;background:#fffbeb;font-size:13px;line-height:1.5">
						<?php /* translators: %s: amount overpaid in XMR */ echo esc_html( sprintf( __( 'You overpaid %s XMR. Contact the store to arrange a refund of the difference.', 'monero_gateway' ), $overpaid_xmr ) ); ?>
					</div>
				<?php endif; ?>
				<?php if ( ! $paid ) : ?>
					<?php
					// Light DOM fallback: monero-pay uses shadow DOM without a slot, so this is hidden when JS runs.
					$pay_uri = 'monero:' . $address . ( '' !== $amount ? '?tx_amount=' . rawurlencode( $amount ) : '' );
					?>
					<monero-pay address="<?php echo esc_attr( $address ); ?>" amount="<?php echo esc_attr( $amount ); ?>" show-qr="<?php echo 'no' === $this->get_option( 'show_qr', 'yes' ) ? 'no' : 'yes'; ?>"
						label="<?php echo esc_attr( get_bloginfo( 'name' ) . ' #' . $order_id ); ?>"
						theme="<?php echo esc_attr( $this->get_option( 'checkout_theme', 'light' ) ); ?>"
						lang="<?php echo esc_attr( 'es' === substr( get_locale(), 0, 2 ) ? 'es' : 'en' ); ?>">
						<div class="monero-gateway-fallback">
							<?php if ( $amounts['partial'] ) : ?>
								<p><?php echo esc_html( sprintf( /* translators: 1: amount received in XMR, 2: remaining amount in XMR */ __( 'Already received %1$s XMR — send remaining %2$s XMR to:', 'monero_gateway' ), $amounts['received'], $amount ) ); ?></p>
							<?php else : ?>
								<p><?php echo esc_html( sprintf( /* translators: %s: amount in XMR */ __( 'Send exactly %s XMR to:', 'monero_gateway' ), $amount ) ); ?></p>
							<?php endif; ?>
							<p><code style="word-break:break-all;user-select:all"><?php echo esc_html( $address ); ?></code></p>
							<p><a href="<?php echo esc_attr( $pay_uri ); ?>"><?php esc_html_e( 'Open in Monero wallet', 'monero_gateway' ); ?></a></p>
							<noscript><p><?php esc_html_e( 'JavaScript is off — refresh this page to update the payment status.', 'monero_gateway' ); ?></p></noscript>
						</div>
					</monero-pay>
				<?php endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}

	/** Return the latest payment state to the buyer. */
	public function ajax_status() {
		$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rl_key = 'monero_gateway_rl_s_' . get_current_blog_id() . '_' . substr( md5( $ip ), 0, 16 );
		if ( (int) get_transient( $rl_key ) > 30 ) {
			wp_send_json( array( 'error' => 'too many requests' ), 429 );
		}
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order || ! hash_equals( $order->get_order_key(), $key ) || $order->get_payment_method() !== $this->id ) {
			set_transient( $rl_key, (int) get_transient( $rl_key ) + 1, 60 );
			wp_send_json( array( 'error' => 'not found' ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( $this->payment_status_response( $order, true ) );
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			$response             = $this->payment_status_response( $order, true );
			$response['status']   = $this->terminal_status( $order );
			$response['terminal'] = true;
			$response['message']  = $this->terminal_status_message( $response['status'], $order_id );
			wp_send_json( $response );
		}

		$outcome = $this->scan_order( $order );
		$order   = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json( array( 'error' => 'order unavailable' ), 404 );
		}
		if ( $order->is_paid() ) {
			wp_send_json( $this->payment_status_response( $order, true ) );
		}
		wp_send_json( $this->payment_status_response( $order, 'unreachable' !== $outcome ) );
	}

	/** Build the checkout status payload from the last persisted settlement summary. */
	private function payment_status_response( $order, $reachable ) {
		$paid       = $order->is_paid();
		$status     = $paid ? 'paid' : (string) $order->get_meta( '_monero_payment_status' );
		if ( 'paid_unconfirmed' === $status ) {
			$status = 'unconfirmed';
		}
		$tip        = (int) $order->get_meta( '_monero_tip_height' );
		$checkpoint = (int) $order->get_meta( '_monero_scan_height' );
		return array(
			'paid'             => $paid,
			'terminal'         => false,
			'status'           => '' !== $status ? $status : 'pending',
			'receivedXmr'      => (string) $order->get_meta( '_monero_seen' ),
			'shortfallXmr'     => (string) $order->get_meta( '_monero_shortfall' ),
			'confirmations'    => (int) $order->get_meta( '_monero_confirmations' ),
			'minConfirmations' => (int) $this->get_option( 'min_confirmations', '1' ),
			'tipHeight'        => $tip,
			'reachable'        => (bool) $reachable,
			'syncing'          => $tip > 0 && $checkpoint < $tip - 1,
		);
	}

	private function terminal_status( $order ) {
		$status = $order->get_status();
		if ( 'cancelled' === $status && 'expired' === (string) $order->get_meta( '_monero_payment_status' ) ) {
			return 'expired';
		}
		return in_array( $status, array( 'cancelled', 'failed', 'refunded' ), true ) ? $status : '';
	}

	private function terminal_status_message( $status, $order_id ) {
		switch ( $status ) {
			case 'expired':
				/* translators: %s: order number */
				return sprintf( __( 'This order (#%s) has expired. If you already sent a Monero payment, contact us with the order number.', 'monero_gateway' ), $order_id );
			case 'refunded':
				return __( 'This order was refunded. Payment monitoring has stopped.', 'monero_gateway' );
			case 'failed':
				return __( 'This order failed. Payment monitoring has stopped.', 'monero_gateway' );
			default:
				return __( 'This order was cancelled. Payment monitoring has stopped.', 'monero_gateway' );
		}
	}

	private function payment_amounts( $order ) {
		$expected  = (string) $order->get_meta( '_monero_amount' );
		$received  = (string) $order->get_meta( '_monero_seen' );
		$received  = '' !== $received ? $received : (string) $order->get_meta( '_monero_received' );
		if ( ! Monero_Util::crypto_ready() ) {
			return array(
				'expected'  => $expected,
				'received'  => $received,
				'shortfall' => $expected,
				'partial'   => false,
				'pay'       => $expected,
			);
		}
		$expected_pico = gmp_init( (string) Monero_Util::xmr_to_pico( $expected ), 10 );
		$received_pico  = gmp_init( (string) Monero_Util::xmr_to_pico( $received ), 10 );
		$shortfall      = Monero_Util::pico_to_string( gmp_cmp( $expected_pico, $received_pico ) > 0 ? gmp_sub( $expected_pico, $received_pico ) : 0 );
		$shortfall_pico = gmp_init( (string) Monero_Util::xmr_to_pico( $shortfall ), 10 );
		$partial        = ! $order->is_paid() && gmp_cmp( $received_pico, 0 ) > 0 && gmp_cmp( $shortfall_pico, 0 ) > 0;
		return array(
			'expected'  => $expected,
			'received'  => $received,
			'shortfall' => $shortfall,
			'partial'   => $partial,
			'pay'       => $partial ? $shortfall : $expected,
		);
	}

	/** Cancel expired unpaid orders after one final scan. */
	public function expire_orders() {
		$hours = (int) $this->get_option( 'expiry_hours' );
		if ( $hours <= 0 ) {
			return;
		}
		$ids = wc_get_orders(
			array(
				'status'         => 'on-hold',
				'payment_method' => $this->id,
				'date_created'   => '<' . ( time() - $hours * HOUR_IN_SECONDS - 15 * MINUTE_IN_SECONDS ),
				'limit'          => 100,
				'return'         => 'ids',
			)
		);
		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->is_paid() || '' === (string) $order->get_meta( '_monero_address' ) ) {
				continue;
			}
			if ( 'watch' !== (string) $order->get_meta( '_monero_mode' ) ) {
				continue;
			}
			$outcome = $this->scan_order( $order );
			if ( 'none' !== $outcome ) {
				if ( 'unreachable' === $outcome ) {
					$this->log( 'expiry deferred for order #' . $order_id . ' — node RPC unavailable or scan incomplete', 'warning' );
				}
				continue;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order || $order->is_paid() ) {
				continue;
			}
			if ( 'yes' === $order->get_meta( '_monero_partial_flagged' ) ) {
				continue;
			}
			$checkpoint = (int) $order->get_meta( '_monero_scan_height' );
			$tip        = (int) $order->get_meta( '_monero_tip_height' );
			if ( $tip <= 0 || $checkpoint < $tip - 1 || $checkpoint > $tip ) {
				$this->log( 'expiry deferred for order #' . $order_id . ' — scan checkpoint ' . $checkpoint . ' does not cover current tip ' . $tip, 'debug' );
				continue;
			}
			$pool_rows = $this->scanner()->scan_pool(
				(string) $order->get_meta( '_monero_address' ),
				$this->view_key(),
				array( 'require_commitment' => true )
			);
			if ( ! empty( $pool_rows ) ) {
				$this->log( 'expiry deferred for order #' . $order_id . ' — committed Monero payment detected in the transaction pool' );
				continue;
			}
			$order->update_meta_data( '_monero_payment_status', 'expired' );
			$order->update_status( 'cancelled', __( 'Auto-cancelled: no Monero payment within the expiry window.', 'monero_gateway' ) );
			$this->log( 'expired unpaid order #' . $order_id );
		}
	}

	/** Scan a bounded set of open orders. */
	public function reconcile_on_hold() {
		$cursor = (int) get_option( 'monero_gateway_reconcile_cursor', 0 );
		$ids    = wc_get_orders(
			array(
				'status'         => 'on-hold',
				'payment_method' => $this->id,
				'limit'          => -1,
				'return'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$ids = array_values( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			update_option( 'monero_gateway_reconcile_cursor', 0, false );
			return;
		}
		$after_cursor = array_filter( $ids, function ( $order_id ) use ( $cursor ) { return $order_id > $cursor; } );
		$at_cursor    = array_filter( $ids, function ( $order_id ) use ( $cursor ) { return $order_id <= $cursor; } );
		$ids          = array_merge( $after_cursor, $at_cursor );
		$scanned      = 0;
		foreach ( $ids as $order_id ) {
			$cursor = $order_id;
			$order  = wc_get_order( $order_id );
			if ( ! $order || $order->is_paid() || '' === (string) $order->get_meta( '_monero_address' ) ) {
				continue;
			}
			if ( 'watch' !== (string) $order->get_meta( '_monero_mode' ) ) {
				continue;
			}
			++$scanned;
			$this->scan_order( $order, 25.0, 90 );
			if ( $scanned >= 8 ) {
				break;
			}
		}
		update_option( 'monero_gateway_reconcile_cursor', $cursor, false );
	}

	/** Scan the chain for payments. Returns paid, none, busy, unreachable, or skip. */
	private function scan_order( $order, $time_budget = 8.0, $max_blocks = 30 ) {
		if ( ! $order || 'watch' !== $order->get_meta( '_monero_mode' ) || $order->is_paid() ) {
			return 'skip';
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'failed', 'refunded' ), true ) ) {
			return 'skip';
		}

		$order_id = $order->get_id();
		$cooldown = 'monero_gateway_scancd_' . get_current_blog_id() . '_' . $order_id;
		if ( false !== get_transient( $cooldown ) ) {
			return 'busy';
		}
		set_transient( $cooldown, 1, 20 );

		$address = (string) $order->get_meta( '_monero_address' );
		$view    = $this->view_key();
		if ( '' === $address || '' === $view ) {
			return 'skip';
		}
		$scanner = $this->scanner();
		$tip     = $scanner->tip_height();
		if ( null === $tip ) {
			$this->log( 'scan failed for order #' . $order_id . ' — no Monero node returned a tip height', 'warning' );
			delete_transient( $cooldown );
			return 'unreachable';
		}

		$min_conf = (int) $this->get_option( 'min_confirmations', '1' );
		$tol_pico = Monero_Util::xmr_to_pico( $this->get_option( 'tolerance_xmr', '0' ) );
		$exp_pico = Monero_Util::xmr_to_pico( (string) $order->get_meta( '_monero_amount' ) );

		$txids = json_decode( (string) $order->get_meta( '_monero_watch_txids' ), true );
		if ( ! is_array( $txids ) ) {
			$txids = array();
		}
		$legacy = (string) $order->get_meta( '_monero_watch_txid' );
		if ( '' !== $legacy && ! in_array( $legacy, $txids, true ) ) {
			$txids[] = $legacy;
		}
		$txids       = array_values( array_unique( $txids ) );
		$verify_txids = array_slice( array_reverse( $txids ), 0, 50 );
		if ( '' !== $legacy && ! in_array( $legacy, $verify_txids, true ) ) {
			$verify_txids[] = $legacy;
		}

		$rows = array();
		foreach ( $verify_txids as $txid ) {
			$result = $scanner->verify_payment( $txid, $address, $view, array( 'tip' => $tip, 'require_commitment' => true ) );
			if ( empty( $result['found'] ) ) {
				continue;
			}
			$rows[] = array(
				'txid'          => $txid,
				'amount_atomic' => isset( $result['amount_atomic'] ) ? $result['amount_atomic'] : '0',
				'confirmations' => array_key_exists( 'confirmations', $result ) ? $result['confirmations'] : null,
				'in_pool'       => ! empty( $result['in_pool'] ),
				'locked'        => ! empty( $result['locked'] ),
				'double_spend_seen' => ! empty( $result['double_spend_seen'] ),
				'out_key'       => isset( $result['out_key'] ) ? $result['out_key'] : '',
				'commitment_ok' => ! empty( $result['commitment_ok'] ),
			);
		}

		$birthday   = (int) $order->get_meta( '_monero_birthday' );
		$checkpoint = (int) $order->get_meta( '_monero_scan_height' );
		$from       = max( $birthday, $checkpoint - self::REORG_LOOKBACK );
		if ( $from > $tip ) {
			$this->log( 'scan deferred for order #' . $order_id . ' — start block ' . $from . ' is ahead of current tip ' . $tip, 'warning' );
			delete_transient( $cooldown );
			return 'unreachable';
		}
		$scan_target = min( max( 0, (int) $tip - 1 ), $checkpoint + max( 1, (int) $max_blocks ) );
		$scan        = $scanner->scan_all(
			$address,
			$view,
			$from,
			$scan_target,
			array( 'tip' => $tip, 'max_blocks' => max( 1, $scan_target - $from + 1 ), 'time_budget' => max( 0.1, (float) $time_budget ), 'require_commitment' => true )
		);
		$scanned_to    = isset( $scan['scanned_to'] ) ? min( $scan_target, (int) $scan['scanned_to'] ) : $from - 1;
		$scan_complete = $scanned_to >= $scan_target;
		$scan_reachable = $scan_complete || $scanned_to >= $from;
		if ( ! $scan_complete ) {
			$this->log( 'scan stopped early for order #' . $order_id . ' at block ' . $scanned_to . ' before ' . $scan_target . ' — node RPC unavailable or time budget exhausted', 'warning' );
			delete_transient( $cooldown );
		}
		$matches = isset( $scan['matches'] ) && is_array( $scan['matches'] ) ? $scan['matches'] : array();
		foreach ( $matches as $match ) {
			$rows[] = $match;
			if ( '' !== (string) $match['txid'] && ! in_array( $match['txid'], $txids, true ) ) {
				$txids[] = (string) $match['txid'];
			}
		}

		$txids = array_values( array_unique( $txids ) );
		$order->update_meta_data( '_monero_watch_txids', wp_json_encode( $txids ) );
		if ( ! empty( $txids ) ) {
			$order->update_meta_data( '_monero_watch_txid', $txids[0] );
		}
		$order->update_meta_data( '_monero_scan_height', max( $checkpoint, $scanned_to ) );
		$order->update_meta_data( '_monero_tip_height', (int) $tip );
		$order->save();

		$summary = Monero_Util::summarize_payments( $rows, $exp_pico, $tol_pico, $min_conf );
		$status        = (string) $summary['status'];
		$confirmations = (int) $summary['confirmations'];
		foreach ( $rows as $row ) {
			if ( ! empty( $row['commitment_ok'] ) && isset( $row['confirmations'] ) && null !== $row['confirmations'] ) {
				$confirmations = max( $confirmations, (int) $row['confirmations'] );
			}
		}
		if ( 'mempool' === $status ) {
			foreach ( $rows as $row ) {
				if ( ! empty( $row['commitment_ok'] ) && empty( $row['in_pool'] ) && isset( $row['confirmations'] ) && (int) $row['confirmations'] < $min_conf ) {
					$status = 'unconfirmed';
					break;
				}
			}
		}
		$seen_pico     = gmp_init( (string) $summary['seen_pico'], 10 );
		$expected_pico = gmp_init( (string) $exp_pico, 10 );
		$shortfall     = gmp_cmp( $expected_pico, $seen_pico ) > 0 ? gmp_sub( $expected_pico, $seen_pico ) : 0;
		if ( ! $summary['paid'] && gmp_cmp( $seen_pico, $expected_pico ) >= 0 && in_array( $status, array( 'mempool', 'unconfirmed' ), true ) ) {
			$status = 'paid_unconfirmed';
		}
		$order->update_meta_data( '_monero_payment_status', $status );
		$order->update_meta_data( '_monero_seen', Monero_Util::pico_to_string( $summary['seen_pico'] ) );
		$order->update_meta_data( '_monero_shortfall', Monero_Util::pico_to_string( $shortfall ) );
		$order->update_meta_data( '_monero_confirmations', $confirmations );
		$order->save();

		if ( $summary['paid'] ) {
			$this->mark_paid(
				$order,
				array(
					'paid'          => true,
					'received_xmr'  => Monero_Util::pico_to_string( $summary['received_pico'] ),
					'txids'         => $summary['txids'],
					'confirmations' => (int) $summary['confirmations'],
					'overpaid'      => '0' !== $summary['overpaid_pico'],
					'overpaid_xmr'  => Monero_Util::pico_to_string( $summary['overpaid_pico'] ),
				)
			);
			return 'paid';
		}

		if ( gmp_cmp( gmp_init( (string) $summary['seen_pico'], 10 ), 0 ) > 0 ) {
			$order->update_meta_data( '_monero_received', Monero_Util::pico_to_string( $summary['received_pico'] ) );
			if ( 'yes' !== $order->get_meta( '_monero_partial_flagged' ) ) {
				$order->update_meta_data( '_monero_partial_flagged', 'yes' );
				$order->add_order_note(
					sprintf(
						/* translators: 1: received XMR, 2: owed XMR */
						__( 'Partial Monero payment received (%1$s of %2$s XMR). The order stays open and completes when the buyer sends the remainder.', 'monero_gateway' ),
						Monero_Util::pico_to_string( $summary['received_pico'] ),
						(string) $order->get_meta( '_monero_amount' )
					)
				);
			}
			$order->save();
		} elseif ( 'yes' === $order->get_meta( '_monero_partial_flagged' ) ) {
			$order->delete_meta_data( '_monero_partial_flagged' );
			$order->save();
		}
		return $scan_reachable ? 'none' : 'unreachable';
	}

	/** Complete an order once, recording verified payment details. */
	private function mark_paid( $order, $data ) {
		if ( $order->get_payment_method() !== $this->id || $order->is_paid() ) {
			return;
		}
		$lock_key = 'pay_' . $order->get_id();
		if ( ! $this->acquire_lock( $lock_key, 30 ) ) {
			return;
		}
		$order = wc_get_order( $order->get_id() );
		if ( ! $order || $order->is_paid() ) {
			$this->release_lock( $lock_key );
			return;
		}
		if ( in_array( $order->get_status(), array( 'cancelled', 'refunded', 'failed' ), true ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: order status */
					__( 'Monero payment arrived for a %s order — not auto-completed. Reconcile it manually.', 'monero_gateway' ),
					$order->get_status()
				)
			);
			$this->log( 'late payment for ' . $order->get_status() . ' order #' . $order->get_id() . ' — not auto-completed', 'warning' );
			$this->release_lock( $lock_key );
			return;
		}

		$received_raw = isset( $data['received_xmr'] ) ? $data['received_xmr'] : null;
		$overpaid_raw = isset( $data['overpaid_xmr'] ) ? $data['overpaid_xmr'] : null;
		$txid_list    = isset( $data['txids'] ) && is_array( $data['txids'] ) ? array_values( array_map( 'sanitize_text_field', $data['txids'] ) ) : array();
		$txids        = implode( ', ', $txid_list );
		$first_txid   = $txid_list ? $txid_list[0] : '';
		$received     = null !== $received_raw ? sanitize_text_field( (string) $received_raw ) : '';
		$confirmations = isset( $data['confirmations'] ) ? absint( $data['confirmations'] ) : null;
		$owed          = (string) $order->get_meta( '_monero_amount' );
		$overpaid      = ! empty( $data['overpaid'] );
		$overpaid_xmr  = null !== $overpaid_raw ? sanitize_text_field( (string) $overpaid_raw ) : '0';

		if ( '' !== $received ) {
			$order->update_meta_data( '_monero_received', $received );
		}
		if ( null !== $confirmations ) {
			$order->update_meta_data( '_monero_confirmations', $confirmations );
		}
		if ( '' !== $txids ) {
			$order->update_meta_data( '_monero_txids', $txids );
		}
		if ( $overpaid ) {
			$order->update_meta_data( '_monero_overpaid', 'yes' );
			$order->update_meta_data( '_monero_overpaid_xmr', $overpaid_xmr );
		}
		$order->save();

		$note = __( 'Monero payment confirmed.', 'monero_gateway' );
		if ( '' !== $received ) {
			/* translators: 1: amount received, 2: amount owed */
			$note .= ' ' . sprintf( __( 'Received: %1$s XMR (owed %2$s).', 'monero_gateway' ), $received, $owed );
		}
		if ( null !== $confirmations ) {
			/* translators: %d: confirmations */
			$note .= ' ' . sprintf( __( 'Confirmations: %d.', 'monero_gateway' ), $confirmations );
		}
		if ( '' !== $txids ) {
			/* translators: %s: transaction hashes */
			$note .= ' ' . sprintf( __( 'tx: %s', 'monero_gateway' ), $txids );
		}
		if ( $overpaid ) {
			/* translators: %s: amount overpaid */
			$note .= ' ' . sprintf( __( 'Overpaid by %s XMR; refund the difference manually.', 'monero_gateway' ), $overpaid_xmr );
		}
		$order->add_order_note( $note );
		$this->log( 'marked paid #' . $order->get_id() . ' · received ' . $received . ' · tx ' . $txids );

		try {
			$order->payment_complete( $first_txid );
		} finally {
			$this->release_lock( $lock_key );
		}
	}

	/** Check whether a payment address is recorded on another order. */
	private function address_used_elsewhere( $address, $order_id ) {
		global $wpdb;
		if ( class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_monero_address' AND meta_value = %s AND order_id != %d LIMIT 1",
					(string) $address,
					(int) $order_id
				)
			);
			return ! empty( $found );
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_monero_address' AND meta_value = %s AND post_id != %d LIMIT 1",
				(string) $address,
				(int) $order_id
			)
		);
		return ! empty( $found );
	}

	/** Acquire a cross-request mutex. */
	private function acquire_lock( $key, $ttl = 30 ) {
		$option = 'monero_gateway_lock_' . get_current_blog_id() . '_' . $key;
		if ( add_option( $option, time() + (int) $ttl, '', 'no' ) ) {
			return true;
		}
		$expires = (int) get_option( $option );
		if ( $expires > 0 && time() > $expires ) {
			delete_option( $option );
			return (bool) add_option( $option, time() + (int) $ttl, '', 'no' );
		}
		return false;
	}

	private function release_lock( $key ) {
		delete_option( 'monero_gateway_lock_' . get_current_blog_id() . '_' . $key );
	}

	/** Add payment instructions to unpaid customer emails. */
	public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
		if ( $sent_to_admin || ! $order || $order->get_payment_method() !== $this->id || $order->is_paid() ) {
			return;
		}
		$address = (string) $order->get_meta( '_monero_address' );
		$amounts = $this->payment_amounts( $order );
		$amount  = $amounts['pay'];
		if ( '' === $address ) {
			return;
		}
		$pay_url = $order->get_checkout_order_received_url();
		if ( $plain_text ) {
			if ( $amounts['partial'] ) {
				/* translators: 1: amount received in XMR, 2: remaining amount in XMR, 3: Monero address */
				echo "\n" . esc_html( sprintf( __( 'Already received %1$s XMR — send remaining %2$s XMR to: %3$s', 'monero_gateway' ), $amounts['received'], $amount, $address ) ) . "\n";
			} else {
				/* translators: 1: amount of XMR, 2: Monero address */
				echo "\n" . esc_html( sprintf( __( 'Pay %1$s XMR to: %2$s', 'monero_gateway' ), $amount, $address ) ) . "\n";
			}
			echo esc_html__( 'Payment page (QR and live status):', 'monero_gateway' ) . ' ' . esc_url( $pay_url ) . "\n\n";
			return;
		}
		echo '<div style="margin:0 0 24px;padding:14px 16px;border:1px solid #e5e7eb;border-radius:8px">';
		echo '<p style="margin:0 0 8px;font-weight:600">' . esc_html__( 'Complete your Monero payment', 'monero_gateway' ) . '</p>';
		if ( $amounts['partial'] ) {
			/* translators: 1: amount received in XMR, 2: remaining amount in XMR */
			echo '<p style="margin:0 0 6px">' . sprintf( esc_html__( 'Already received %1$s XMR — send remaining %2$s XMR to:', 'monero_gateway' ), '<strong>' . esc_html( $amounts['received'] ) . '</strong>', '<strong>' . esc_html( $amount ) . '</strong>' ) . '</p>';
		} else {
			/* translators: %s: amount of XMR */
			echo '<p style="margin:0 0 6px">' . sprintf( esc_html__( 'Send %s XMR to:', 'monero_gateway' ), '<strong>' . esc_html( $amount ) . '</strong>' ) . '</p>';
		}
		echo '<p style="margin:0 0 10px;word-break:break-all"><code style="font-size:12px">' . esc_html( $address ) . '</code></p>';
		echo '<p style="margin:0"><a href="' . esc_url( $pay_url ) . '" style="color:#ff6600;font-weight:600">' . esc_html__( 'Open the payment page (QR and live status) →', 'monero_gateway' ) . '</a></p>';
		echo '</div>';
	}
}
