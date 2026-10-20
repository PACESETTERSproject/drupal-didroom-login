#!/usr/bin/env bash
# Writes the public URL and name of the issuer into its well-knowns and its
# credential offer. Runs from setup.sh; run it again, then restart, when the
# URL changes:
#
#   ./configure.sh [URL]     default: ISSUER_URL from .env
set -euo pipefail
cd "$(dirname "$0")"

set -a
# shellcheck source=/dev/null
. ./.env
set +a
url=${1:-$ISSUER_URL}
url=${url%/}
case $url in
  http://* | https://*) ;;
  *) echo "Not a URL: $url" >&2; exit 1 ;;
esac
as=$url/authz_server
ci=$url/credential_issuer
pub=run/public

update() {
  local file=$1
  shift
  local tmp
  tmp=$(mktemp)
  jq --indent 4 "$@" "$file" > "$tmp"
  cat "$tmp" > "$file"
  rm "$tmp"
}

update $pub/authz_server/.well-known/oauth-authorization-server --arg as "$as" '
  .issuer = $as
  | .authorization_endpoint = "\($as)/authorize"
  | .pushed_authorization_request_endpoint = "\($as)/par"
  | .token_endpoint = "\($as)/token"
  | .introspection_endpoint = "\($as)/introspection"
  | .scopes_supported = ["email"]'

update $pub/credential_issuer/.well-known/openid-credential-issuer \
  --arg ci "$ci" --arg as "$as" --arg name "$ISSUER_NAME" --arg url "$url" \
  --slurpfile email credential_issuer/email.json '
  .credential_issuer = $ci
  | .credential_endpoint = "\($ci)/credential"
  | .nonce_endpoint = "\($ci)/nonce"
  | .authorization_servers = [$as]
  | .display = [{name: $name, locale: "en-US"}]
  | .credential_configurations_supported = {email: ($email[0] | .display[0].logo.uri |= sub("\\{\\{ url \\}\\}"; $url))}'

# The offer the wallet scans: served as a QR code and as a link. It has no
# grants: the wallet refuses offers that name the authorization server while
# the issuer lists only one (mobile_zencode, holder_qr_to_well-known), and
# zencode would serve an empty grant object as an array, which the wallet's
# offer schema refuses too. Without grants, the wallet uses the
# authorization code flow of the issuer's authorization server.
offer=$(jq -n --arg ci "$ci" '{
  credential_configuration_ids: ["email"],
  credential_issuer: $ci}')
jq -n --argjson offer "$offer" '{offer: $offer,
  qrcode_path: "public/credential_issuer/qrcode",
  command: "./scripts/qrcode.sh public/credential_issuer/qrcode"}' > $pub/credential_issuer/.qrcode.keys.json
jq -n --argjson offer "$offer" '{offer: $offer,
  deeplink_path: "public/credential_issuer/deeplink"}' > $pub/credential_issuer/.deeplink.keys.json

echo "Issuer configured for $url (credential issuer: $ci)"
