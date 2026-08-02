<?php
/** WooCommerce settings field for ordered Monero nodes. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Monero_Node_Fields {
	private function __construct() {}

	public static function render( $rows, $name = 'node_configs', $id = 'monero-gateway' ) {
		$rows = Monero_Node_Config::normalize_list( $rows );
		if ( ! $rows ) {
			$rows = array( array( 'url' => '', 'auth' => 'none', 'username' => '', 'password' => '' ) );
		}
		$html = '<div class="monero-node-list" id="' . esc_attr( $id ) . '-nodes" data-name="' . esc_attr( $name ) . '">';
		foreach ( $rows as $index => $row ) {
			$html .= self::row( $row, $name, $index );
		}
		$html .= '<button type="button" class="button monero-node-add">' . esc_html__( 'Add node', 'monero_gateway' ) . '</button></div>';
		return $html;
	}

	private static function row( $row, $name, $index ) {
		$base  = esc_attr( $name . '[' . $index . ']' );
		$saved = '' !== $row['password'] ? 'true' : 'false';
		$html  = '<div class="monero-node-row" data-password-saved="' . $saved . '">';
		$html .= '<label><span>' . esc_html__( 'Node URL', 'monero_gateway' ) . '</span><input type="url" name="' . $base . '[url]" value="' . esc_attr( $row['url'] ) . '" placeholder="http://127.0.0.1:18081"></label>';
		$html .= '<label><span>' . esc_html__( 'Authentication', 'monero_gateway' ) . '</span><select name="' . $base . '[auth]">';
		foreach ( array( 'none' => 'None', 'basic' => 'Basic', 'digest' => 'Digest' ) as $value => $label ) {
			$html .= '<option value="' . $value . '"' . ( $row['auth'] === $value ? ' selected' : '' ) . '>' . esc_html__( $label, 'monero_gateway' ) . '</option>';
		}
		$html .= '</select></label>';
		$html .= '<label class="monero-node-credential"><span>' . esc_html__( 'Username', 'monero_gateway' ) . '</span><input type="text" name="' . $base . '[username]" value="' . esc_attr( $row['username'] ) . '" autocomplete="username"></label>';
		$html .= '<label class="monero-node-credential"><span>' . esc_html__( 'Password', 'monero_gateway' ) . '</span><input type="password" name="' . $base . '[password]" value="" autocomplete="new-password" placeholder="' . ( 'true' === $saved ? esc_attr__( 'Saved — leave blank to keep', 'monero_gateway' ) : '' ) . '"></label>';
		$html .= '<button type="button" class="button-link-delete monero-node-remove">' . esc_html__( 'Remove', 'monero_gateway' ) . '</button></div>';
		return $html;
	}
}
