# Email credential issuer

Wallet login matches accounts by email, so the email in the credential must
be one its holder really controls. This directory runs a DIDroom issuer
([DIDroom_microservices](https://github.com/ForkbombEu/DIDroom_microservices),
OpenID4VCI) that issues an `email` credential only after a one-time code sent
to the address is entered back.

| Service             | What it does                                                                      |
|---------------------|-----------------------------------------------------------------------------------|
| `authz_server`      | DIDroom authorization server: shows the authorize page, then redeems the ticket.  |
| `credential_issuer` | DIDroom credential issuer: signs the `email` SD-JWT credential.                   |
| `email-code`        | Sends the codes and trades a correct one for a ticket ([email_code.py](email-code/email_code.py)). |
| `proxy`             | Caddy, which allows only the endpoints of the flow ([Caddyfile](Caddyfile)).     |
| `mailpit`           | Optional (`--profile dev`): catches the emails, web UI on http://127.0.0.1:8027.  |
| `tunnel`            | Optional (`--profile tunnel`): a public `trycloudflare.com` URL for development.  |

Both DIDroom services run the same image as the verifier, pinned by digest
to upstream commit `c22341d`.

## How a credential is issued

1. The wallet scans the credential offer (the QR code on the issuer's home
   page, `/`) and makes a pushed authorization request.
2. It opens the authorize page in a browser: [authz_server/authorize](authz_server/authorize).
   The person enters an address; the page asks `email-code` to send a code
   (`POST /email-code/send`).
3. The person enters the code; `email-code` checks it and returns a ticket
   (`POST /email-code/check`). A wrong code can be tried again on the page.
4. The page posts the ticket to `authorize_backend`. Its custom code
   ([authz_server/custom_code/email.zen](authz_server/custom_code/email.zen))
   redeems the ticket with `email-code` over the internal network
   (`POST /redeem`), and gets the confirmed address back. The browser returns
   to the wallet with an authorization code.
5. The wallet gets a token, then the credential.
   [credential_issuer/custom_code/email.zen](credential_issuer/custom_code/email.zen)
   puts the address in it.

The credential is an SD-JWT with `vct` `email` and the claim `email`; its
`iss` is `<ISSUER_URL>/credential_issuer`. This matches the module's default
DCQL query. Put that `iss` in the module's trusted issuers. Credentials are
valid for a year ([email.time.json](credential_issuer/custom_code/email.time.json)).

### Limits

| What                          | Limit                                                |
|-------------------------------|------------------------------------------------------|
| Code                          | 6 digits, valid 10 minutes, one per address at a time |
| Wrong codes                   | 5 per code; then a new code is needed                |
| Codes per address             | 1 a minute, 5 an hour                                |
| Codes per client address      | 20 an hour                                           |
| Ticket                        | 128 random bits, valid 5 minutes, used once          |

So someone guessing has at most 25 tries an hour at one address, out of a
million codes. `email-code` keeps all this in memory: a restart voids the
codes and tickets in flight, and resets the counters. It never says whether
an address is known; it knows no addresses.

The client address comes from `Cf-Connecting-Ip` or `X-Forwarded-For` when
the request reaches Caddy from a private network (the tunnel), and from the
connection otherwise. Behind another proxy, set `trusted_proxies` in the
Caddyfile to that proxy only.

## The proxy

The proxy allows:

| Endpoint                                                  | Used by          |
|-----------------------------------------------------------|------------------|
| `GET /`, `/logo.svg`                                      | people: the offer page |
| `GET /authz_server/.well-known/oauth-authorization-server` | wallet           |
| `POST /authz_server/par`, `/token`                        | wallet           |
| `GET /authz_server/authorize`                             | wallet's browser |
| `POST /authz_server/authorize_backend`                    | authorize page   |
| `POST /authz_server/introspection`                        | credential issuer |
| `GET /credential_issuer/.well-known/openid-credential-issuer` | wallet, verifier |
| `POST /credential_issuer/nonce`, `/credential`            | wallet           |
| `GET /credential_issuer/qrcode`, `/deeplink`              | offer page       |
| `POST /email-code/send`, `/email-code/check`              | authorize page   |

Everything else answers 404. This matters: ncr serves every contract as an
endpoint, and `ru_to_ac` issues an authorization code for whatever claims it
is given. Reachable, it would hand out credentials for any address.
`/email-code/redeem` is also internal. `test-proxy.sh` checks these rules
against a stub, and runs in CI.

The credential issuer calls the introspection endpoint at its public URL, as
the authorization server's metadata gives it. That endpoint answers only for
a valid access token, with what the token's holder already knows.

## Development

From the repository root, with the DDEV site running:

```sh
scripts/dev-issuer.sh up     # start everything, and make the DDEV site trust it
scripts/dev-issuer.sh url    # print the public issuer URL
scripts/dev-issuer.sh down   # stop; keys are kept in run/
```

`up` runs `setup.sh` the first time. It starts the tunnel, writes its URL into
the issuer's metadata (`configure.sh`), and sets the URL as the site's only
trusted issuer. Open the printed URL on a computer and scan the QR code with
the wallet; read the code in Mailpit.

The tunnel URL changes on every start, and it is in every credential: after a
restart, credentials from the previous run are no longer trusted. Get a new
one.

With `scripts/dev-verifier.sh up` running too, `test-issuance.py` checks the
whole chain without a phone:

```sh
deploy/issuer/test-issuance.py --login https://didroom-login.ddev.site
```

It runs the Pacesetters wallet's own contracts (`mobile_zencode`, from a
checkout of the wallet next to this repository, or `WALLET_DIR`) in ncr as
the wallet. They come with a test keyring and DID. It gets a credential with
a code read from Mailpit, checks that a wrong code, a bogus ticket and a
reused ticket are refused, then presents the credential to the site's wallet
login and waits for it to log in.

## Setup without the script

```sh
./setup.sh                 # contracts into run/, .env, and configure.sh
$EDITOR .env               # ISSUER_URL, ISSUER_NAME, the mail server
./configure.sh             # after changing ISSUER_URL or ISSUER_NAME
docker compose up -d       # proxy on 127.0.0.1:8091
```

Without the dev profile there is no Mailpit: set `SMTP_*` and `MAIL_FROM` to
a real mail server. The emails go to any address that is entered, so the
server and its sender domain need SPF and DKIM to arrive.

## Identity

On their first start the two DIDroom services each create a keyring, and
register a DID in Dyne's sandbox registry (`did:dyne:sandbox.genericissuer:…`);
`dev-issuer.sh up` prints both. The keyrings are saved in
`run/*/secrets.keys` and `run/keys/`, and are reused on later starts.
Deleting `run/` creates new identities. Like the verifier, the services print
the whole keyring to the log on that first start: do not keep or ship that
log.

The sandbox DIDs are for development only. A production issuer needs DIDs
created for Pacesetters, a backup of the keyrings outside the server, and a
stable public URL (`ISSUER_URL` is in every credential).

## Notes on DIDroom

- The authorize page replaces DIDroom's generic one, which builds a single
  form from `custom_code/*.schema.json`. An emailed code needs two steps, and
  the page loads no scripts from elsewhere. `email.schema.json` only describes
  what the page submits.
- The credential offer does not name the authorization server: the wallet
  refuses offers that do while the issuer lists only one
  (`holder_qr_to_well-known` in mobile_zencode), though the specification
  allows it.
- ncr does not decode `+` in form posts: the page sends compact JSON, as
  `JSON.stringify` does.
- A refused ticket sends the wallet back with an error, and the authorization
  request can still be used. Once a code has been issued for it, it cannot.

## License

DIDroom_microservices is AGPL-3.0 and publicly reachable, so the
configuration, custom code and pages it runs with are published here, in this
public repository. `setup.sh` fetches the unmodified upstream contracts.
