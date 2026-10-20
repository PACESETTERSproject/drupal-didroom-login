#!/usr/bin/env bash
# Checks the proxy rules against a stub that stands in for the ncr services
# and the email code service, echoing the path and client address it gets.
set -euo pipefail
cd "$(dirname "$0")"

net=didroom-issuer-proxy-test-$$
stub=$(mktemp)
cleanup() {
  docker rm -f "$net-stub" "$net-proxy" >/dev/null 2>&1 || true
  docker network rm "$net" >/dev/null 2>&1 || true
  rm -f "$stub"
}
trap cleanup EXIT

cat > "$stub" <<'EOF'
:3000, :8000 {
	respond "upstream {path} ip={header.X-Client-IP}"
}
EOF
chmod 644 "$stub"
image=$(sed -n 's/^    image: \(caddy:.*\)$/\1/p' compose.yaml)
docker network create "$net" >/dev/null
docker run -d --name "$net-stub" --network "$net" \
  --network-alias authz_server --network-alias credential_issuer --network-alias email-code \
  -v "$stub:/etc/caddy/Caddyfile:ro" "$image" >/dev/null
docker run -d --name "$net-proxy" --network "$net" -p 127.0.0.1::80 \
  -v "$PWD/Caddyfile:/etc/caddy/Caddyfile:ro" -v "$PWD/www:/srv:ro" "$image" >/dev/null
port=$(docker port "$net-proxy" 80 | head -1 | cut -d: -f2)
for _ in $(seq 20); do curl -s -o /dev/null "http://127.0.0.1:$port/" && break; sleep 0.5; done

failures=0
check() {
  local ok=$1 what=$2
  if [ "$ok" = yes ]; then echo "ok   $what"; else echo "FAIL $what"; failures=$((failures + 1)); fi
}
expect() {
  local code=$1 method=$2 path=$3 got
  got=$(curl -s -o /dev/null -w '%{http_code}' -X "$method" "http://127.0.0.1:$port$path")
  check "$([ "$got" = "$code" ] && echo yes || echo no)" "$code $method $path${got:+ (got $got)}"
}

# The issuance flow.
for path in /authz_server/.well-known/oauth-authorization-server /authz_server/authorize \
  /credential_issuer/.well-known/openid-credential-issuer /credential_issuer/qrcode /credential_issuer/deeplink; do
  expect 200 GET "$path"
done
for path in /authz_server/par /authz_server/authorize_backend /authz_server/token /authz_server/introspection \
  /credential_issuer/credential /credential_issuer/nonce /email-code/send /email-code/check; do
  expect 200 POST "$path"
done
# CORS preflights of a wallet running in a browser (the token request
# carries a DPoP header).
for path in /authz_server/par /authz_server/token /credential_issuer/nonce /credential_issuer/credential; do
  expect 200 OPTIONS "$path"
done
expect 404 OPTIONS /authz_server/ru_to_ac
# The offer page.
expect 200 GET /
expect 200 GET /logo.svg
# Everything else ncr serves is hidden, above all ru_to_ac, which hands out
# authorization codes for any claims; and the ticket redemption.
for path in /authz_server/ru_to_ac /authz_server/ru_to_toc /authz_server/custom_code/email \
  /credential_issuer/custom_code/email /authz_server/.authorize /email-code/redeem /email-code/health; do
  expect 404 POST "$path"
done
for path in /authz_server/docs /credential_issuer/docs /authz_server/oas.json /authz_server/authorize.metadata.json \
  /credential_issuer/.qrcode.keys.json /authz_server/.authorize.keys.json /credential_issuer/nonces/x /index.html/ /email-code/; do
  expect 404 GET "$path"
done
expect 404 DELETE /authz_server/token

# The email code service gets its own paths, and the client address.
body=$(curl -s -X POST -H 'X-Client-IP: 6.6.6.6' -H 'Cf-Connecting-Ip: 203.0.113.7' "http://127.0.0.1:$port/email-code/send")
check "$([ "$body" = "upstream /send ip=203.0.113.7" ] && echo yes || echo no)" "email code service sees /send from the tunnel's client address ($body)"
body=$(curl -s -X POST -H 'X-Client-IP: 6.6.6.6' "http://127.0.0.1:$port/email-code/check")
check "$(case $body in "upstream /check ip=172."* | "upstream /check ip=192.168."* | "upstream /check ip=10."*) echo yes ;; *) echo no ;; esac)" \
  "a client cannot choose its own address ($body)"

# Pages get a content security policy.
csp=$(curl -s -o /dev/null -D - "http://127.0.0.1:$port/authz_server/authorize" | grep -ci '^content-security-policy:.*connect-src .self.' || true)
check "$([ "$csp" = 1 ] && echo yes || echo no)" "the authorize page has a content security policy"

[ "$failures" = 0 ] || { echo "$failures check(s) failed"; exit 1; }
echo "All proxy checks passed."
