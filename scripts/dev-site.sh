#!/usr/bin/env bash
# Puts the DDEV site behind a public tunnel, so a phone's browser can open it
# and log in with the wallet on the same phone.
#
#   scripts/dev-site.sh up     start, and print the public site URL
#   scripts/dev-site.sh url    print the public site URL
#   scripts/dev-site.sh down   stop
set -euo pipefail
cd "$(dirname "$0")/.."
project=$(ddev describe -j | jq -r .raw.name)
name=$project-site-tunnel
# The same cloudflared as the verifier and issuer tunnels.
image=$(sed -n 's/^    image: \(cloudflare\/cloudflared:.*\)$/\1/p' deploy/verifier/compose.yaml)

tunnel_url() {
  docker logs "$name" 2>&1 | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
}

case "${1:-}" in
  up)
    [ "$(ddev describe -j | jq -r .raw.status)" = running ] || ddev start
    docker rm -f "$name" >/dev/null 2>&1 || true
    # The tunnel keeps the public host name: Drupal sets the session cookie
    # for the host it is asked for, and a cookie for the DDEV name would be
    # dropped by the phone's browser.
    docker run -d --name "$name" --network ddev_default "$image" \
      tunnel --no-autoupdate --url "http://ddev-$project-web:80" >/dev/null
    echo "Waiting for the tunnel..."
    url=""
    for _ in $(seq 60); do
      url=$(tunnel_url) && [ -n "$url" ] && docker logs "$name" 2>&1 | grep -q 'Registered tunnel connection' && break
      sleep 1
    done
    [ -n "$url" ] || { echo "No tunnel URL after 60 s: docker logs $name" >&2; exit 1; }
    echo "Waiting for $url to resolve..."
    reachable=no
    for _ in $(seq 36); do
      sleep 5
      if curl -fs -o /dev/null "$url/user/login" 2>/dev/null; then
        reachable=yes
        break
      fi
    done
    echo "Site: $url (reachable: $reachable)"
    echo "Wallet login, to open in the phone's browser: $url/didroom/login"
    ;;
  url)
    tunnel_url
    ;;
  down)
    docker rm -f "$name" >/dev/null
    ;;
  *)
    sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
