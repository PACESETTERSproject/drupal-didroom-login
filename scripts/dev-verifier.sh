#!/usr/bin/env bash
# Runs the development verifier from deploy/verifier behind a public tunnel,
# and points the DDEV site at it.
#
#   scripts/dev-verifier.sh up     start, and configure the DDEV site
#   scripts/dev-verifier.sh url    print the public verifier URL
#   scripts/dev-verifier.sh down   stop (keys and results are kept)
set -euo pipefail
cd "$(dirname "$0")/.."
dir=deploy/verifier
compose=(docker compose --project-directory "$dir" --profile tunnel)

tunnel_url() {
  "${compose[@]}" logs tunnel 2>/dev/null | grep -o 'https://[a-z0-9-]*\.trycloudflare\.com' | tail -1
}

case "${1:-}" in
  up)
    [ -d "$dir/run/verifier" ] || "$dir/setup.sh"
    "${compose[@]}" up -d
    echo "Waiting for the tunnel..."
    url=""
    for _ in $(seq 60); do
      url=$(tunnel_url) && [ -n "$url" ] && break
      sleep 1
    done
    [ -n "$url" ] || { echo "No tunnel URL after 60 s: ${compose[*]} logs tunnel" >&2; exit 1; }
    verifier_url="$url/verifier/"

    # The token goes in settings.local.php, which settings.php must include.
    token=$(grep '^VERIFIER_TOKEN=' "$dir/.env" | cut -d= -f2)
    settings=web/sites/default
    printf "<?php\n\n// Written by scripts/dev-verifier.sh.\n\$settings['didroom_login_verifier_token'] = '%s';\n" "$token" > "$settings/settings.local.php"
    if ! grep -qF "include __DIR__ . '/settings.local.php';" "$settings/settings.php"; then
      chmod u+w "$settings" "$settings/settings.php"
      printf "\nif (file_exists(__DIR__ . '/settings.local.php')) {\n  include __DIR__ . '/settings.local.php';\n}\n" >> "$settings/settings.php"
    fi

    ddev drush config:set -y didroom_login.settings verifier_url "$verifier_url" >/dev/null
    # A new tunnel name takes a minute or two to resolve, and a failed lookup
    # is cached for a while, so wait for the tunnel before asking for it.
    until "${compose[@]}" logs tunnel 2>/dev/null | grep -q 'Registered tunnel connection'; do sleep 1; done
    echo "Waiting for $url to resolve..."
    reachable=no
    for _ in $(seq 36); do
      sleep 5
      reachable=$(ddev drush php:eval "echo \Drupal::service('didroom_login.verifier_client')->isReachable() ? 'yes' : 'no';" 2>/dev/null) || true
      [ "$reachable" = yes ] && break
    done
    echo "Verifier: $verifier_url (reachable from Drupal: $reachable)"
    echo "DID: $(docker compose --project-directory "$dir" exec -T verifier jq -r .did verifier/secrets.keys)"
    ;;
  url)
    echo "$(tunnel_url)/verifier/"
    ;;
  down)
    "${compose[@]}" down
    ;;
  *)
    sed -n '2,7p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
