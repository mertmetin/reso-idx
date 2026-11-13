#!/usr/bin/env bash
# Boots WordPress in Playground (PHP + SQLite) with this plugin mounted, then
# checks the demo listings render through the block, the REST API and a listing page.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PORT="${PORT:-9411}"
BASE="http://127.0.0.1:${PORT}"
JAR="$(mktemp)"

npx -y @wp-playground/cli@latest server --port="$PORT" \
	--mount="$ROOT:/wordpress/wp-content/plugins/reso-idx" \
	--blueprint="$ROOT/tests/e2e/blueprint.json" > /tmp/reso-idx-playground.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true; rm -f "$JAR"' EXIT

fail() { echo "FAIL: $1"; tail -20 /tmp/reso-idx-playground.log; exit 1; }

# The port opens before the blueprint has finished, so wait for the page itself.
page=""
for _ in $(seq 1 60); do
	page="$(curl -sL -b "$JAR" -c "$JAR" "$BASE/homes/" || true)"
	grep -q 'homes for sale' <<<"$page" && break
	sleep 3
done
grep -q '51 homes for sale' <<<"$page" || fail "search page does not list the 51 demo homes"
grep -q 'Listed by' <<<"$page" || fail "brokerage attribution missing"
grep -q 'reso-idx-disclaimer' <<<"$page" || fail "disclaimer missing"
echo "ok - search block renders 51 demo listings"

api="$(curl -sL -b "$JAR" "$BASE/wp-json/reso-idx/v1/listings?beds=4&sort=price_desc&per_page=1")"
grep -q '"total":28' <<<"$api" || fail "REST filter returned: ${api:0:200}"
echo "ok - REST API filters (4+ beds: 28)"

code="$(curl -sL -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/listing/DEMO00003/")"
[ "$code" = 200 ] || fail "listing page returned $code"
code="$(curl -sL -o /dev/null -w '%{http_code}' -b "$JAR" "$BASE/listing/DEMO00017/")"
[ "$code" = 404 ] || fail "closed listing returned $code instead of 404"
echo "ok - listing page 200, closed listing 404"
