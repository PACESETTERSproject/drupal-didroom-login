#!/usr/bin/env bash
# Fetches the issuer contracts matching the pinned image into run/, adds the
# email custom code and authorize page, creates .env, and applies the public
# URL from .env (configure.sh). Keeps existing keys.
set -euo pipefail
cd "$(dirname "$0")"

UPSTREAM=https://github.com/ForkbombEu/DIDroom_microservices.git
COMMIT=c22341d0d56bc5e5ce407a552e4cf082ab5aba30

if [ ! -f .env ]; then
  cp .env.example .env
  echo "Created .env; set the mail server in it for real use."
fi

src=$(mktemp -d)
trap 'rm -rf "$src"' EXIT
git -C "$src" init -q
git -C "$src" fetch -q --depth 1 "$UPSTREAM" "$COMMIT"
git -C "$src" checkout -q FETCH_HEAD

for s in authz_server credential_issuer; do
  mkdir -p "run/$s" "run/public/$s/.well-known"
  # Contracts are replaced; the keyring (secrets.keys) and nonces stay.
  rsync -a --exclude secrets.keys --exclude custom_code --exclude nonces "$src/$s/" "run/$s/"
  rsync -a --delete "$s/custom_code/" "run/$s/custom_code/"
  # The well-knowns hold the keys written on first start: configure.sh
  # updates them in place.
  rsync -a --exclude .well-known "$src/public/$s/" "run/public/$s/"
  rsync -a --ignore-existing "$src/public/$s/.well-known/" "run/public/$s/.well-known/"
done
# authorize.sh rewrites the page as root on start: replace, do not write into it.
rsync authz_server/authorize run/public/authz_server/authorize
mkdir -p run/keys

./configure.sh
echo "Contracts from $COMMIT are in run/. Start with: docker compose up -d"
