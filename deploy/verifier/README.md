# DIDroom verifier for wallet login

The module does not verify credentials itself: a [DIDroom verifier](https://github.com/ForkbombEu/DIDroom_microservices)
runs the OpenID4VP exchange with the wallet, and Drupal reads the verified
claims from it. This directory runs that verifier behind a proxy.

| Service    | What it does                                                                     |
|------------|----------------------------------------------------------------------------------|
| `verifier` | The DIDroom microservice (ncr), pinned by digest to upstream commit `c22341d`.   |
| `proxy`    | Caddy, which allows only the endpoints of the flow ([Caddyfile](Caddyfile)).     |
| `tunnel`   | Optional (`--profile tunnel`): a public `trycloudflare.com` URL for development. |

The proxy allows:

| Endpoint                                   | Used by | Needs the token |
|--------------------------------------------|---------|-----------------|
| `POST /verifier/generate_authorization_request` | Drupal  | yes        |
| `GET /verifier/transaction/<id>`           | Drupal  | yes             |
| `GET /verifier/request/<hash>`             | wallet  | no              |
| `POST /verifier/response/<hash>`           | wallet  | no              |

Everything else answers 404, including the verifier's API docs and the
endpoints ncr creates for every contract. Without the token, anyone could open
sessions signed with the verifier's identity, and read the results.
`test-proxy.sh` checks these rules against a stub, and runs in CI.

## Development

From the repository root, with the DDEV site running:

```sh
scripts/dev-verifier.sh up     # start everything, and point the DDEV site at it
scripts/dev-verifier.sh url    # print the public verifier URL
scripts/dev-verifier.sh down   # stop; keys and results are kept in run/
```

`up` runs `setup.sh` the first time. It then starts the tunnel and sets the
site's verifier URL. It also writes the token to
`web/sites/default/settings.local.php` and includes that file from
settings.php. A new tunnel URL takes a minute or two to resolve; the script
waits, then reports whether Drupal can reach the verifier. The tunnel URL
changes on every start; `up` updates the site each time.

Phones reach the verifier only through the tunnel, so the QR code works from
any network. The Drupal site itself stays local.

To log in on the phone itself, with the browser and the wallet on the same
phone, the phone's browser must open the site too:

```sh
scripts/dev-site.sh up     # put the DDEV site behind a tunnel, and print its URL
scripts/dev-site.sh url    # print the public site URL
scripts/dev-site.sh down   # stop
```

Open the printed wallet login URL in the phone's browser and tap "Open in
wallet app". After the wallet answers, switch back to the browser: the page
logs in. The tunnel URL changes on every start, but nothing stores it.

## Setup without the script

```sh
./setup.sh                      # contracts into run/, and .env with a new token
docker compose up -d            # verifier and proxy on 127.0.0.1:8090
```

Then, on the Drupal site:

1. Set the verifier URL on the wallet login settings. Use the public URL that
   wallets reach, ending in `/verifier/`.
2. Set the token in settings.php:

   ```php
   $settings['didroom_login_verifier_token'] = '<VERIFIER_TOKEN from .env>';
   ```

## Identity

On its first start the verifier creates a keyring. It also registers a DID in
Dyne's sandbox registry (`did:dyne:sandbox.genericissuer:…`); wallets show
this DID as the verifier. The keyring is saved in `run/verifier/secrets.keys`
and `run/keys/`, and is reused on later starts. Deleting `run/` creates a new
identity.

The sandbox DID is for development only. A production verifier needs a DID
created for Pacesetters, and a backup of its keyring outside the server.

The verifier prints the whole keyring to its log on that first start. Do not
keep or ship that log.

## Credential types

The verifier checks the presentation, then runs `custom_code/<type>.zen` for
the credential type: the first `vct_values` entry of the DCQL query for SD-JWT,
or the first `type_values` entry for `ldp_vc`. The module's default query asks
for the type `email`, so `custom_code/email.zen` is provided. It passes the
result on unchanged, because Drupal checks the claims itself. A query for
another type needs its own `<type>.zen` and `<type>.keys.json`, followed by
`./setup.sh` and a restart.

## License

The verifier is AGPL-3.0 and publicly reachable, so the configuration and
custom code it runs with are published here, in this public repository.
`setup.sh` fetches the unmodified upstream contracts.
