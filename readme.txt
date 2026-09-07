=== Monero WooCommerce Extension ===
Contributors: serhack, mosu-forge and Monero Integrations contributors
Donate link: https://monerointegrations.com/donate.html
Tags: monero, woocommerce, payment, cryptocurrency, accept monero
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 4.0.0-dev
License: MIT
License URI: https://github.com/monero-integrations/monerowp/blob/master/LICENSE

Accept Monero (XMR) payments in WooCommerce. WordPress derives a per-order subaddress and scans the chain via your monerod node using the merchant private view key.

= Benefits =

* Watch-mode only: primary address, private view key, and one or more monerod HTTP(S) node URLs. No monero-wallet-rpc. No third-party block explorer.
* Optional Monero payment discount, legacy QR visibility setting, payment filters, and restored price/acceptance shortcodes.
* Per-order subaddress derived on the server; payment detection and amount decode run in PHP against public chain data from the node. The private view key stays on the WordPress host.
* Comma-separated nodes with failover; network (mainnet / stagenet / testnet) follows the configured address.
* Configurable confirmations (0 = mempool, 1 = first block, or higher). Optional order auto-cancel by hours. Partial payments with top-up QR; overpayment recorded on the order.
* XMR amount locked when the order is placed. Price from CoinGecko, a custom rate URL, a fixed rate, or native store currency XMR (no feed).
* Classic checkout and WooCommerce Blocks checkout. HPOS-compatible. Cron reconciles unpaid orders and expires stale ones. Optional debug log via WooCommerce logs.

= Requirements =

* WordPress 6.2+, PHP 8.0+, WooCommerce
* PHP extensions GMP, BCMath, and Mbstring (all required; the gateway is unavailable until they are enabled)
* A Monero standard (primary) address and matching private view key
* Reachable monerod daemon RPC (local or remote) on the same network as the address

= Installation =

== Automatic method ==

In the "Add Plugins" section of the WordPress admin UI, search for "monero" and click Install Now next to "Monero WooCommerce Extension" by mosu-forge, SerHack. Auto-updates apply only to official releases; use the manual method for git or a fork.

== Manual method ==

* Download from https://github.com/monero-integrations/monerowp or clone with `git clone https://github.com/monero-integrations/monerowp`
* Place the plugin folder in `wp-content/plugins`
* Activate "Monero Woocommerce Gateway" in the WordPress admin
* For reliable scanning and expiry, prefer real system cron over WordPress pseudo-cron: `define('DISABLE_WP_CRON', true);` in `wp-config.php` and a crontab entry that hits `wp-cron.php` every minute

= Configuration =

WooCommerce → Settings → Payments → Monero.

* Enable – turn the gateway on or off
* Title / Description – checkout label and helper text
* Payment box theme – light or dark
* Redirect after payment (URL) – optional; `{order_id}` and `{order_key}` placeholders (`{order_key}` only for same-site URLs)
* Monero address – standard primary address (mainnet `4…`, stagenet/testnet as applicable). Subaddresses and integrated addresses are not accepted here. Network is detected from this address.
* Private view key – 64 hex characters for that address. Optional: define `MONERO_GATEWAY_VIEW_KEY` in `wp-config.php` (takes precedence over the saved field)
* Monero node(s) – your own or otherwise trusted `http://` or `https://` daemon URLs; must match the address network
* Confirmations required – default 1; 0 accepts mempool detection
* Underpayment tolerance (XMR) – accept shortfalls up to this amount (below 0.01 XMR)
* Check setup – admin button for node reachability, network match, and view-key check
* Price source – CoinGecko, custom URL (+ JSON path), or fixed rate; ignored when store currency is XMR. Optional CoinGecko API key; fixed rate also used as fallback when a live feed fails
* Price-feed outage handling – reuse the last verified rate for a configurable grace period, then remove Monero from checkout and show the merchant-defined outage message
* Auto-cancel after (hours) – cancel unpaid orders after this period; 0 disables expiry
* Debug log – write scan activity to WooCommerce logs

== Remove plugin ==

1. Deactivate the plugin under Plugins
2. Delete the plugin under Plugins

Uninstall removes gateway settings, plugin options, related transients/locks, and scheduled hooks. Order meta (payment history) is kept.

== Screenshots ==
1. Monero Payment Box
2. Monero Options

== Changelog ==

= 4.0.0 =
* Watch-mode gateway: local subaddress derivation and on-server chain scan via monerod; settings and checkout updated for current WooCommerce.

= 3.0.5 =
* Removed cryptocompare.com API and switched to CoinGecko

= 3.0.4 =
* Bug fixing;

= 3.0.3 =
* Fixed the problem related to explorer;

= 3.0.2 =
* Fixed the problem of 'hard-coded' prices which causes a division by zero: now any currencies supported by cryptocompare API should work;

= 3.0.1 =
* Fixed the incorrect generation of integrated addresses;

= 3.0.0 =
* Configurable confirmations, locked XMR amount after order, AJAX order page updates, QR codes, cron-based validation, email hooks.

= 2.3 =
* Bug fixing

= 2.2 =
* Fix some bugs

= 2.1 =
* Verify transactions without monero-wallet-rpc
* Optionally accept zero confirmation transactions
* bug fixing

= 1.0 =
* Added the view key option

= 0.1 =
* First version ! Yay!

== Frequently Asked Questions ==

* What is Monero?
Monero is private digital cash. See https://getmonero.org

* What wallet do I need?
Any wallet that can show a primary address and private view key (GUI, CLI, or hardware). The spend key is not used by this plugin.

* Does the view key leave my server?
No. Scanning uses the view key only on the WordPress host against public data returned by your configured monerod node(s).

* Why is the payment method missing at checkout?
GMP, BCMath, or Mbstring may be missing, the address/view key/nodes may be incomplete or invalid, or the node network may not match the address. Check WooCommerce → Settings → Payments → Monero and the admin notices.

* Do buyers need to stay on the thank-you page?
No. Cron continues scanning and can expire unpaid orders. The payment page still polls for live status while open.
