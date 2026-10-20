#!/usr/bin/env bash
# Fetches the verifier contracts matching the pinned image into run/, adds
# the custom code and creates .env with a random token. Keeps existing keys.
set -euo pipefail
cd "$(dirname "$0")"

UPSTREAM=https://github.com/ForkbombEu/DIDroom_microservices.git
COMMIT=c22341d0d56bc5e5ce407a552e4cf082ab5aba30

if [ ! -f .env ]; then
  sed "s/^VERIFIER_TOKEN=$/VERIFIER_TOKEN=$(openssl rand -hex 32)/" .env.example > .env
  echo "Created .env with a new token."
fi

src=$(mktemp -d)
trap 'rm -rf "$src"' EXIT
git -C "$src" init -q
git -C "$src" fetch -q --depth 1 "$UPSTREAM" "$COMMIT"
git -C "$src" checkout -q FETCH_HEAD

mkdir -p run/verifier run/public/verifier run/keys
# Contracts are replaced; the keyring (secrets.keys) and results stay.
rsync -a --exclude secrets.keys --exclude custom_code "$src/verifier/" run/verifier/
rsync -a --delete custom_code/ run/verifier/custom_code/
rsync -a --exclude transaction --exclude request "$src/public/verifier/" run/public/verifier/

echo "Contracts from $COMMIT are in run/. Start with: docker compose up -d"
