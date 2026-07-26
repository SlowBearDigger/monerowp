#!/usr/bin/env bash
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

required_vars=(
	MONEROWP_E2E_SSH
	MONEROWP_E2E_WP_PATH
	MONEROWP_E2E_WALLET_RPC
	MONEROWP_E2E_PRODUCT_ID
)

for variable in "${required_vars[@]}"; do
	[[ -n "${!variable:-}" ]] || { echo "Missing required environment variable: $variable" >&2; exit 1; }
done

ssh_host="$MONEROWP_E2E_SSH"
wp_path="$MONEROWP_E2E_WP_PATH"
wallet_rpc="$MONEROWP_E2E_WALLET_RPC"
product_id="$MONEROWP_E2E_PRODUCT_ID"
wp_command="${MONEROWP_E2E_WP_COMMAND:-wp}"
order_total="${MONEROWP_E2E_TOTAL:-0.10}"

for command in curl jq php ssh; do
	command -v "$command" >/dev/null || { echo "Missing command: $command" >&2; exit 1; }
done

[[ "$product_id" =~ ^[1-9][0-9]*$ ]] || { echo 'MONEROWP_E2E_PRODUCT_ID must be a positive integer.' >&2; exit 1; }
[[ "$order_total" =~ ^[0-9]+([.][0-9]{1,2})?$ ]] || { echo 'MONEROWP_E2E_TOTAL must be a positive amount with at most two decimals.' >&2; exit 1; }
php -r 'exit( (float) $argv[1] > 0 ? 0 : 1 );' "$order_total" || { echo 'MONEROWP_E2E_TOTAL must be greater than zero.' >&2; exit 1; }
[[ "$wallet_rpc" =~ ^https?:// ]] || { echo 'MONEROWP_E2E_WALLET_RPC must use HTTP or HTTPS.' >&2; exit 1; }

printf -v wp_path_quoted '%q' "$wp_path"
remote_wp="$wp_command --path=$wp_path_quoted"
create_exec="putenv(\"MONEROWP_E2E_PRODUCT_ID=$product_id\"); putenv(\"MONEROWP_E2E_TOTAL=$order_total\");"
printf -v create_exec_quoted '%q' "$create_exec"

order_json="$({
	ssh "$ssh_host" "$remote_wp --exec=$create_exec_quoted eval-file -" < "$script_dir/create-order.php"
} 2> >(grep -v 'No configuration file found' >&2))"

jq -e '(.order_id > 0) and .result == "success" and .status == "on-hold" and (.address | length == 95) and (.birthday > 0) and (.scan_height == .birthday)' <<<"$order_json" >/dev/null

order_id="$(jq -r '.order_id' <<<"$order_json")"
address="$(jq -r '.address' <<<"$order_json")"
amount_xmr="$(jq -r '.amount_xmr' <<<"$order_json")"
amount_atomic="$(php -r '$p = explode(".", $argv[1], 2); $f = substr(str_pad($p[1] ?? "", 12, "0"), 0, 12); echo ((int) $p[0] * 1000000000000) + (int) $f;' "$amount_xmr")"

transfer_payload="$(jq -nc --arg address "$address" --argjson amount "$amount_atomic" '{jsonrpc:"2.0",id:"0",method:"transfer",params:{destinations:[{address:$address,amount:$amount}],priority:1,get_tx_key:true}}')"
transfer_json="$(curl -fsS -H 'Content-Type: application/json' --data "$transfer_payload" "$wallet_rpc/json_rpc")"
jq -e '.error == null and (.result.tx_hash | length == 64)' <<<"$transfer_json" >/dev/null
tx_hash="$(jq -r '.result.tx_hash' <<<"$transfer_json")"

confirmed=false
for _ in {1..18}; do
	transfer_state="$(curl -fsS -H 'Content-Type: application/json' --data "$(jq -nc --arg txid "$tx_hash" '{jsonrpc:"2.0",id:"0",method:"get_transfer_by_txid",params:{txid:$txid}}')" "$wallet_rpc/json_rpc")"
	confirmations="$(jq -r '.result.transfer.confirmations // 0' <<<"$transfer_state")"
	if (( confirmations >= 1 )); then
		confirmed=true
		break
	fi
	sleep 20
done

[[ "$confirmed" == true ]] || { echo "Payment did not confirm: $tx_hash" >&2; exit 1; }

ssh "$ssh_host" "$remote_wp cron event run monero_gateway_reconcile" >/dev/null
state_exec="putenv(\"MONEROWP_E2E_ORDER_ID=$order_id\");"
printf -v state_exec_quoted '%q' "$state_exec"
state_json="$(ssh "$ssh_host" "$remote_wp --exec=$state_exec_quoted eval '\$o = wc_get_order( (int) getenv( \"MONEROWP_E2E_ORDER_ID\" ) ); echo wp_json_encode( array( \"status\" => \$o->get_status(), \"payment_status\" => (string) \$o->get_meta( \"_monero_payment_status\" ), \"received_xmr\" => (string) \$o->get_meta( \"_monero_seen\" ), \"confirmations\" => (int) \$o->get_meta( \"_monero_confirmations\" ) ) );'" 2>/dev/null)"

jq -e --arg amount "$amount_xmr" '(.status == "processing" or .status == "completed") and .payment_status == "paid" and .received_xmr == $amount and .confirmations >= 1' <<<"$state_json" >/dev/null

jq -n \
	--argjson order "$order_json" \
	--arg tx_hash "$tx_hash" \
	--argjson settlement "$state_json" \
	'{ok:true, order:$order, tx_hash:$tx_hash, settlement:$settlement}'
