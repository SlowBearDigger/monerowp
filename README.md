# Monero Gateway for WooCommerce

WooCommerce payment gateway for Monero (XMR). The merchant sets a primary address, private view key, and monerod node URL(s). WordPress derives a unique subaddress per order and scans the blockchain in PHP to detect and confirm payment. No `monero-wallet-rpc`. No third-party block explorer. The private view key never leaves the server.

**Contributors:** serhack, mosu-forge and Monero Integrations contributors
**Donate:** https://monerointegrations.com/donate.html
**License:** [MIT](LICENSE)
**Repository:** https://github.com/monero-integrations/monerowp

## Features

* Watch-mode verification against monerod daemon RPC (HTTP/HTTPS), with comma-separated node failover
* Per-order subaddress derivation; amount locked at checkout
* Configurable confirmations; optional order expiry (hours); partial-payment top-up; overpayment recorded on the order
* Price from CoinGecko, custom URL, fixed rate, or store currency set to XMR
* Classic checkout and WooCommerce Blocks; HPOS-compatible
* Cron for reconcile and unpaid-order expiry; optional WooCommerce debug log
* View key may be set in settings or as `MONERO_GATEWAY_VIEW_KEY` in `wp-config.php`
* Optional WooCommerce-native discount when Monero is selected
* `[monero-price currency="USD"]` and `[monero-accepted-here]` shortcodes

## Requirements

* WordPress 6.2+, PHP 8.0+, WooCommerce
* PHP extensions **GMP**, **BCMath**, and **Mbstring** (all required; gateway hidden until they are enabled)
* Monero standard (primary) address and matching private view key
* monerod daemon RPC reachable from the WordPress host (same network as the address)

## Setup

### Automatic

WordPress admin → Plugins → Add New → search “monero” → install **Monero WooCommerce Extension** (mosu-forge, SerHack). Official releases only for auto-updates.

### Manual

1. Download from the [releases page](https://github.com/monero-integrations/monerowp) or `git clone https://github.com/monero-integrations/monerowp`
2. Place the plugin under `wp-content/plugins`
3. Activate **Monero Woocommerce Gateway**
4. Prefer system cron for scanning/expiry: `define('DISABLE_WP_CRON', true);` in `wp-config.php` and a crontab line that hits `wp-cron.php` every minute

## Configuration

**WooCommerce → Settings → Payments → Monero**

| Setting | Notes |
| --- | --- |
| Enable | Turn gateway on/off |
| Title / Description | Checkout label and text |
| Payment box theme | Light or dark |
| QR code | Show or hide the QR while keeping address, amount, and live status |
| Monero payment discount | Percentage discount applied through WooCommerce when Monero is selected |
| Redirect after payment | Optional URL; `{order_id}`, `{order_key}` (key only on same-site URLs) |
| Monero address | Primary address only; network detected from it |
| Private view key | 64 hex chars; `MONERO_GATEWAY_VIEW_KEY` in `wp-config.php` overrides the field |
| Monero node(s) | Comma-separated `http(s)://` URLs; must match address network |
| Confirmations required | Default 1; `0` = mempool |
| Underpayment tolerance (XMR) | Accept shortfall below 0.01 XMR |
| Check setup | Node, network, and view-key check |
| Price source | CoinGecko / custom URL (+ JSON path) / fixed rate; ignored if store currency is XMR |
| CoinGecko API key | Optional |
| Fixed rate / fallback | Used as fixed source or when live feed fails |
| Auto-cancel after (hours) | `0` disables expiry |
| Debug log | WooCommerce logs |

The Monero discount is represented as a virtual WooCommerce coupon. It is not stored as a reusable shop coupon; WooCommerce applies item discounts and recalculates taxes in the normal totals pipeline. Changing payment method removes it.

Use `[monero-price currency="USD"]` to show the configured price of one XMR. Use `[monero-accepted-here]` to show the bundled acceptance image.

## Uninstall

Deactivate and delete via Plugins. Uninstall removes gateway settings, plugin options, related transients/locks, and cron hooks. Order meta is kept.

## Donations

monero-integrations: 44krVcL6TPkANjpFwS2GWvg1kJhTrN7y9heVeQiDJ3rP8iGbCd5GeA4f3c2NKYHC1R4mCgnW7dsUUUae2m9GiNBGT4T8s2X
