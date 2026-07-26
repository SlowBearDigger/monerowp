# Live stagenet E2E

This test creates a real WooCommerce order, sends stagenet XMR from an independent payer wallet, runs the WordPress reconciliation event, and verifies settlement. The payer wallet is test infrastructure. MoneroWP never connects to wallet RPC.

Requirements:

- SSH access to the WordPress host
- a WP-CLI command usable by the SSH account
- `curl`, `jq`, `php`, and `ssh` locally
- a synced stagenet payer wallet RPC

Set the target explicitly:

```sh
export MONEROWP_E2E_SSH=wordpress-test
export MONEROWP_E2E_WP_PATH=/srv/www/example.test/current
export MONEROWP_E2E_WALLET_RPC=http://127.0.0.1:18099
export MONEROWP_E2E_PRODUCT_ID=123
```

Then run:

```sh
tests/e2e/run-live-stagenet.sh
```

Optional variables:

```sh
MONEROWP_E2E_WP_COMMAND='sudo -u www-data -- wp'
MONEROWP_E2E_TOTAL=0.10
```

`MONEROWP_E2E_WP_COMMAND` defaults to `wp`. The script does not start a wallet, accept a seed, store wallet credentials, or require wallet RPC on the WordPress host.
