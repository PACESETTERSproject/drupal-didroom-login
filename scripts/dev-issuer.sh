#!/usr/bin/env bash
# Runs the development email issuer from deploy/issuer behind a public tunnel,
# with Mailpit catching its emails, and makes the DDEV site trust it.
#
#   scripts/dev-issuer.sh up     start, and configure the issuer and the site
#   scripts/dev-issuer.sh url    print the public issuer URL
#   scripts/dev-issuer.sh down   stop (keys are kept)
set -euo pipefail
cd "$(dirname "$0")/.."
dir=deploy/issuer
compose=(docker compose --project-directory "$dir" --profile tunnel --profile dev)

tunnel_url() {
  "${compose[@]}" logs tunnel 2>/dev/null | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
}

case "${1:-}" in
  up)
    [ -d "$dir/run/authz_server" ] || "$dir/setup.sh"
    "${compose[@]}" up -d
    echo "Waiting for the tunnel..."
    url=""
    for _ in $(seq 60); do
      url=$(tunnel_url) && [ -n "$url" ] && break
      sleep 1
    done
    [ -n "$url" ] || { echo "No tunnel URL after 60 s: ${compose[*]} logs tunnel" >&2; exit 1; }

    # The URL is in the well-knowns and in every credential (iss): a new
    # tunnel means a new issuer, whose credentials must be issued again.
    sed -i "s|^ISSUER_URL=.*|ISSUER_URL=$url|" "$dir/.env"
    "$dir/configure.sh" "$url"
    "${compose[@]}" restart authz_server credential_issuer >/dev/null
    ddev drush config:set -y --input-format=yaml didroom_login.settings trusted_issuers "['$url/credential_issuer']" >/dev/null

    until "${compose[@]}" logs tunnel 2>/dev/null | grep -q 'Registered tunnel connection'; do sleep 1; done
    echo "Waiting for $url to resolve..."
    reachable=no
    for _ in $(seq 36); do
      sleep 5
      if curl -fs -o /dev/null "$url/credential_issuer/.well-known/openid-credential-issuer" 2>/dev/null; then
        reachable=yes
        break
      fi
    done
    mailpit_port=$(sed -n 's/^MAILPIT_PORT=//p' "$dir/.env")
    echo "Issuer: $url (reachable: $reachable)"
    echo "Credential offer, to scan with the wallet: $url/"
    echo "Emails: http://127.0.0.1:${mailpit_port:-8027}/"
    echo "Trusted by the site: $url/credential_issuer"
    for s in authz_server credential_issuer; do
      echo "DID of $s: $(jq -r '.jwks.keys[0].kid | sub("#.*"; "")' "$dir/run/public/$s/.well-known/"*)"
    done
    ;;
  url)
    tunnel_url
    ;;
  down)
    "${compose[@]}" down
    ;;
  *)
    sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
