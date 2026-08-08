<?php
/**
 * Vendored Monero cryptography.
 *
 * MoneroPHP files are based on commit
 * 25d4c5838b35cbf1fb55170b831e895681a7410a. Keccak.php is based on
 * kornrunner/php-keccak commit a166c2eb859a21089a6e004949d35b5f9dd0687b.
 * See LICENSES.md for attribution and license terms.
 *
 * Requires the GMP, BCMath, and Mbstring PHP extensions.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

$xmrpay_vendor = __DIR__;
require_once $xmrpay_vendor . '/Keccak.php';     // kornrunner\Keccak
require_once $xmrpay_vendor . '/base58.php';     // MoneroIntegrations\MoneroPhp\base58
require_once $xmrpay_vendor . '/Varint.php';     // ...\Varint
require_once $xmrpay_vendor . '/ed25519.php';    // ...\ed25519
require_once $xmrpay_vendor . '/Cryptonote.php'; // ...\Cryptonote (uses kornrunner\Keccak + the above)
