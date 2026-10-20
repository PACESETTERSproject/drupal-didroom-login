#!/usr/bin/env bash
# Checks the proxy rules against a stub verifier that answers 200 to
# everything, without starting the real verifier (which registers a DID).
set -euo pipefail
cd "$(dirname "$0")"

net=didroom-proxy-test-$$
token=test-token
cleanup() {
  docker rm -f "$net-verifier" "$net-proxy" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
}
trap cleanup EXIT

image=$(sed -n 's/^    image: \(caddy:.*\)$/\1/p' compose.yaml)
docker network create "$net" >/dev/null
docker run -d --name "$net-verifier" --network "$net" --network-alias verifier "$image" \
  caddy respond --listen :3000 --body upstream >/dev/null
docker run -d --name "$net-proxy" --network "$net" -p 127.0.0.1::80 -e VERIFIER_TOKEN=$token \
  -v "$PWD/Caddyfile:/etc/caddy/Caddyfile:ro" "$image" >/dev/null
port=$(docker port "$net-proxy" 80 | head -1 | cut -d: -f2)
for _ in $(seq 20); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.5; done

failures=0
expect() {
  local code=$1 method=$2 path=$3 auth=${4:-}
  local args=(-s -o /dev/null -w '%{http_code}' -X "$method")
  [ -n "$auth" ] && args+=(-H "Authorization: Bearer $auth")
  local got
  got=$(curl "${args[@]}" "http://127.0.0.1:$port$path")
  if [ "$got" = "$code" ]; then echo "ok   $code $method $path ${auth:+(token $auth)}"
  else echo "FAIL $got (expected $code) $method $path ${auth:+(token $auth)}"; failures=$((failures + 1)); fi
}

# Drupal's endpoints need the token.
expect 200 POST /verifier/generate_authorization_request $token
expect 404 POST /verifier/generate_authorization_request
expect 404 POST /verifier/generate_authorization_request wrong
expect 200 GET /verifier/transaction/0a1b $token
expect 404 GET /verifier/transaction/0a1b
expect 404 GET /verifier/transaction/0a1b.keys.json $token
# Wallet endpoints are open.
expect 200 GET /verifier/request/0a1b
expect 200 POST /verifier/response/0a1b
expect 404 GET /verifier/request/0a1b.keys.json
expect 404 GET /verifier/response/0a1b_pre.slang
# Everything else is hidden, token or not.
for path in / /verifier/docs /verifier/oas.json /verifier/verifier /verifier/custom_code/email /verifier/transaction/; do
  expect 404 GET "$path" $token
done

[ "$failures" = 0 ] || { echo "$failures check(s) failed"; exit 1; }
echo "All proxy checks passed."
