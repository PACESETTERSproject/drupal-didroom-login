# Plan: `drupal-didroom-login` — "Log in with Pacesetters wallet" for Drupal 11

## Context
pacesetters.eu (Drupal 11, PHP 8.3, Apache/FreeBSD, open registration, no SSO modules) needs
passwordless login using the Pacesetters/DIDroom wallet. Decisions: **self-hosted DIDroom verifier
microservice** does the OpenID4VP verification; Drupal starts a session, polls the result, and logs in
or **auto-creates** the user matched by the verified **email** claim. Local dev via **DDEV + Docker**.
Repo: remote `PACESETTERSproject/drupal-didroom-login` (public).
Module machine name `didroom_login`.

## Integration contract (verified in code)
Verifier = `ForkbombEu/DIDroom_microservices` (NCR/Zencode, AGPL, image `ghcr.io/forkbombeu/didroom_microservices`).
- `POST {base}/verifier/generate_authorization_request` JSON
  `{response_mode:"direct_post", response_type:"vp_token", dcql_query, url:"{base}/verifier/"}`
  → `{qr_code (data URL), intent_url ("openid4vp://?client_id=…&request_uri=…"), transaction_id ("transaction/<hex>")}`.
- Wallet fetches signed request (5-min, single use) and POSTs `vp_token` to `/verifier/response/<hash>`.
- `GET {base}/verifier/transaction/<hex>`: **404** = pending, **200 JSON** = `{<dcql_cred_id>: [{claims…}]}`
  (one merged object per valid claim set, holding only the requested paths), **200 text** = error string.
- Caller cannot pass its own state/nonce → correlate by `transaction_id`, kept **server-side only**.
- No built-in webhook; the "redirect" only moves the phone's WebView → **polling is the mechanism** (works
  cross-device and same-device).
- Verifier build requires at least one `custom_code/<credential type>.zen`.

**Known verifier gaps the design must cover:** KB-JWT `nonce`/`aud` not checked (replay); any issuer
accepted unless pinned in DCQL; `generate_authorization_request` is unauthenticated and accepts any `url`
(lets an attacker get requests signed with our DID pointing elsewhere → harvest + replay = account takeover);
results are public by id and never expire.

## Threats not covered by the verifier or the claim checks

### QR phishing (cross-device session fixation)
An attacker opens `/didroom/login` on pacesetters.eu and shows that QR on their own page; a victim who scans
it and presents their credential logs the **attacker's** browser in as the victim. The presented credential is
genuine, so `ClaimValidator` cannot catch it. Inherent to cross-device OpenID4VP. Mitigations:
- Short `session_ttl` (lower the default from 300 s to 120 s; keep the 60–1800 range).
- **Email notification on every wallet login** ("You logged in with your wallet at <time> from <IP / user
  agent>. Not you? Reset your password / contact us."), sent via `hook_mail`; on by default, setting `notify_login`.
- Excluded roles (default `administrator`) already cap the damage.
- Upstream: ask for a same-device `response_code` in the wallet's `redirect_uri`, bound to the browser
  session (OpenID4VP 1.0 security considerations), so same-device logins can't be fixed.
- Document the residual risk in the README.

### Pre-hijacked accounts on email linking
If the site does not require email verification on registration, an attacker can register with the victim's
email first; the victim's wallet login would then be linked to the attacker's account, whose password the
attacker knows. Mitigations in `AccountResolver`:
- When `user.settings:verify_mail` is off, refuse automatic linking (`LoginFailure::NoAccount`, logged)
  unless the setting `link_unverified` is on. (Checking past logins doesn't help: the attacker can log in too.)
- On first link of an existing account, **scramble its password** (setting `reset_password_on_link`, default on)
  and tell the user by email, so any password set by someone else stops working.

### Verified email issuer
Login is only as safe as the issuer's email check. The wallet cannot share its own (PocketBase) account email:
OpenID4VP only carries issuer-signed credentials, and a self-asserted address would let anyone log in as anyone.
✅ Built (step 7b): `deploy/issuer/`, a DIDroom authorization server + credential issuer that issue an `email`
SD-JWT only after a one-time code emailed to the address is entered back (small `email-code` service: 6 digits,
10 min, 5 tries, send rate limits, single-use 5-min ticket redeemed internally by the authz custom code).
Production still needs a host, a stable URL (it is the credential `iss`), a real mail server (SPF/DKIM), and
non-sandbox DIDs; or Dyne/Pacesetters may issue the credential from the wallet backend instead, if it verifies
addresses. **Blocks production use** until one of the two runs.

### Verifier identity
The verifier signs requests with its DID key (`client_id = decentralized_identifier:<did>`, ES256 request objects).
On first start it creates a keyring and registers a **sandbox** DID itself (`did:dyne:sandbox.genericissuer:<pk>`,
through apiroom.net and did.dyne.org); fine for development (step 7). Production needs: a non-sandbox DID for
Pacesetters (ask Dyne which spec/registry), backing up the keyring outside the container, a key-rotation procedure,
and documenting which DID wallets will display. The verifier **prints the whole keyring to its log** on that first
start: production must not keep that log (and an upstream issue should be opened).

## Design

### Login flow
1. `hook_form_user_login_form_alter` adds a "Log in with Pacesetters wallet" link → `/didroom/login`,
   **only when the module is configured** (verifier URL, token and trusted issuers set).
2. `LoginController::start()` (anonymous only, flood-limited per IP, not cached): `VerifierClient::createSession()`
   → stores `{transaction_id, created}` in the user's session → renders QR (`qr_code` data URL — no PHP QR lib)
   plus an "Open in wallet" button (`intent_url`, for phone users).
3. `js/didroom-login.js` polls `GET /didroom/login/status` every 3 s (stops after the configured TTL); polls again
   immediately on `visibilitychange`, since mobile browsers throttle background tabs while the wallet is open.
4. `LoginController::status()` reads the transaction id **from the session only**, calls
   `VerifierClient::fetchResult()`; on success → `ClaimValidator` → `AccountResolver` →
   `ExternalAuth::userLoginFinalize()`, sends the login notification, clears the session entry and returns
   `{status:"done", redirect}`; otherwise `pending|error|expired`, with a user message chosen from `LoginFailure`.

### Account resolution (`AccountResolver`, via `externalauth`) — done in step 4
- Identifier = lowercased, validated email; provider `didroom_login`.
- Authmap hit → that user. Else existing user with that mail → link via authmap. Else (if auto-create enabled
  and the site's registration policy allows it) register: username from the email's local part (deduped),
  mail/init set, active (blocked + refused as pending when admin approval is required), configured roles.
- Refuse blocked users and users with excluded roles (default: `administrator`) before linking.
- To add (step 5): the pre-hijack rules above.

### Claim validation (`ClaimValidator`) — done in step 4
- Exactly one claim set for the configured credential id; `iss`/`issuer` in the trusted-issuer list
  (also pinned in the DCQL `values`); valid email at the configured path.

### Configuration (`didroom_login.settings` + `SettingsForm`, permission `administer didroom login`)
`verifier_url`, `dcql_query`, `email_claim_path`, `trusted_issuers[]`, `auto_create`, `default_roles[]`,
`excluded_roles[]`, `session_ttl`; to add: `notify_login`, `link_unverified`, `reset_password_on_link`.
Verifier API secret is **not config** — read from `settings.php` (`$settings['didroom_login_verifier_token']`).

### Drupal integration still missing
- **`hook_requirements('runtime')`**: status report errors/warnings for a missing verifier URL, token or
  trusted issuers, an unreachable verifier, and `verify_mail` off while linking is on.
- **`hook_uninstall()`**: `externalauth.authmap->deleteProvider('didroom_login')`.
- ✅ **Unlinking** (step 6): a "Wallet login" tab on the account (own account and user admins) shows the link and
  turns wallet login off or on. Turning it off removes the authmap row **and** sets a user data flag, since otherwise
  the next wallet login would link the account again by email (`LoginFailure::WalletDisabled`). Admins can list linked
  accounts by adding externalauth's "External Authentication Map" fields to the People view.
- **Privacy**: README section and suggested privacy-notice text on what Drupal and the verifier store
  (email, issuer, timestamps), how long, and the pruning job; the anonymous session cookie is strictly necessary.

### Verifier deployment + hardening (`deploy/verifier/` in the repo)
- ✅ (step 7) compose with the upstream image pinned by digest, contracts fetched by `setup.sh` at the matching
  commit, and `custom_code/email.zen` (named after the DCQL `vct`) that passes the result on.
- ✅ (step 7) Caddy **allowlist**: `generate_authorization_request` and `transaction/<id>` need
  `Authorization: Bearer <token>` (Drupal sends it on every request); `request/<hash>` and `response/<hash>` are open
  for wallets; everything else (API docs, per-contract endpoints ncr creates, precondition files) is 404.
  `test-proxy.sh` checks the rules in CI.
- Cron/systemd job pruning old `public/verifier/transaction/*`, `public/verifier/request/*` and the per-session
  routes in `verifier/response/*` (ncr loads every one of them as an endpoint, so they also slow startup).
- **Host**: pacesetters.eu runs FreeBSD, which can't run the Linux image natively → a separate Linux host or
  a Linux VM (bhyve) is needed; decide where, and who operates it.
- **Monitoring and updates**: uptime check on the verifier's public endpoints and on Drupal → verifier reachability
  (also surfaced by `hook_requirements`); pin the image by digest and document the update procedure.
- **AGPL**: the verifier is publicly reachable, so publish any modified source and configuration
  (custom `.zen`, Caddyfile) — keeping them in `deploy/verifier/` of this public repo covers it; link it from the verifier.
- **Network**: the production Drupal host must be allowed outbound HTTPS to the verifier.
- Open an upstream issue/PR on DIDroom_microservices / slangroom `dcql` for nonce+aud (and LDP challenge/domain)
  checks, the `jwks_uri` fallback bug, and a same-device `response_code`.

### Distribution and project hygiene
- ✅ **CI** (step 6): GitHub Actions running DDEV (with Selenium Chrome): phpunit (Unit/Kernel/Functional/
  FunctionalJavascript) against Drupal 10 and 11, plus phpcs, phpstan, eslint and stylelint on 11.
- **Releases**: semver git tags; install on pacesetters.eu via a composer `vcs` repository entry
  (`"repositories": [{"type":"vcs","url":"https://github.com/PACESETTERSproject/drupal-didroom-login"}]`)
  until/unless it's published on Packagist or drupal.org.
- **License**: repo is GPL-3.0-or-later; drupal.org only hosts GPL-2.0-or-later. Decide before any drupal.org release.

### Repo layout
```
composer.json  didroom_login.info.yml (core ^10.3 || ^11, depends externalauth)  didroom_login.module  didroom_login.install
didroom_login.{routing,services,libraries,permissions,links.menu}.yml
config/install/didroom_login.settings.yml   config/schema/didroom_login.schema.yml
src/Controller/LoginController.php  src/Form/SettingsForm.php
src/Verifier/{VerifierClient,VerificationRequest,VerificationResult,VerificationStatus,VerifierException}.php
src/Login/{ClaimValidator,AccountResolver,VerifiedIdentity,LoginException,LoginFailure}.php
templates/didroom-login.html.twig  js/didroom-login.js  css/didroom-login.css
tests/src/{Unit,Kernel,Functional,FunctionalJavascript,Traits}/…  tests/modules/didroom_login_test/ (fake VerifierClient)
deploy/verifier/  deploy/issuer/  .ddev/  .github/workflows/  phpcs.xml.dist  phpstan.neon  README.md  LICENSE
```

## Implementation steps
1. ✅ **Dev environment** — Docker + DDEV, `ddev-drupal-contrib`, Drupal + `externalauth` (commit e521367, pushed directly).
2. ✅ **Skeleton** — info/composer/routing/services/schema/default config, settings form (PR #1).
3. ✅ **VerifierClient** + unit tests with Guzzle `MockHandler` (PR #2).
4. ✅ **ClaimValidator** + **AccountResolver** + unit/kernel tests (PR #3).
5. ✅ **Controller, template, JS polling, login-form link**; page-cache kill switch / `no_cache` routes; flood control;
   user messages per `LoginFailure`; login-link hidden until configured; `visibilitychange` re-poll;
   **login notification email**; **pre-hijack rules** (`verify_mail` check, password scramble on link);
   new settings (`notify_login`, `link_unverified`, `reset_password_on_link`) and lower default `session_ttl`;
   `hook_requirements`; `hook_uninstall`.
6. ✅ **Functional test** with `didroom_login_test` fake client: start → pending → done → user logged in; expired;
   untrusted issuer; notification sent; linking refused when `verify_mail` is off; password scrambled on link;
   status report warnings. Plus **unlinking UI** and its test. Add the **GitHub Actions CI** workflow here.
   Also browser tests of the polling page (redirect, refusal + "Try again", expiry), which caught the QR code
   staying visible after a failure.
7. ✅ **Local verifier** — `deploy/verifier` compose running the microservice in DDEV-adjacent Docker; expose via
   `cloudflared`/`ngrok` so the phone wallet can reach `/request` + `/response`; create a dev verifier DID.
   `scripts/dev-verifier.sh up` starts verifier + proxy + a cloudflared quick tunnel and configures the DDEV site.
   Checked: Drupal opens real sessions; the signed request is fetched once over the tunnel; a bogus presentation
   is recorded as failed and shown once; the DID survives restarts. Notes: new session routes take ~1–3 s to load;
   a new tunnel name takes 1–2 min to resolve.
7b. ✅ **Email issuer** — `deploy/issuer` (authz server, credential issuer, `email-code`, Caddy allowlist, Mailpit,
   tunnel); own authorize page (email → code → ticket); `scripts/dev-issuer.sh up` configures the issuer URL and
   makes it the DDEV site's trusted issuer. `deploy/issuer/test-issuance.py --login` runs the wallet's own
   mobile_zencode contracts in ncr as a simulated wallet: issuance with a code from Mailpit (wrong code, bogus and
   reused tickets refused), then presentation to the dev verifier → Drupal logs in (account created). Findings:
   ncr exposes `ru_to_ac`, which issues codes for any claims (must stay behind the proxy); the wallet refuses offers
   naming the authorization server when only one is listed; ncr doesn't decode `+` in form posts.
8. **End-to-end** with a real Pacesetters wallet holding an email credential from the chosen issuer —
   both **cross-device** (QR on desktop) and **same-device** (phone browser + wallet on the same phone).
9. **Hardening + docs** — Caddy rules for production (TLS, rate limits) for verifier and issuer, pruning job
   (also the issuer's `credential_issuer/nonces/`), a "get an email credential" link on the login page, monitoring, image pinning, verifier DID key backup/rotation,
   README (install via composer vcs repo, settings.php token, verifier deploy, QR-phishing residual risk,
   privacy text, AGPL note), phpcs + phpstan clean; upstream issues (nonce/aud, same-device `response_code`, `jwks_uri`);
   first tagged release.

## Open items
Settle with the Pacesetters team **before step 7**:
- Who runs the **email issuer** in production (`deploy/issuer`, or Dyne/Pacesetters from the wallet backend),
  its public URL (the credential `iss`) and mail server.
- Where the verifier runs (pacesetters.eu is FreeBSD → separate Linux host or VM), who operates and monitors it,
  and its public hostname (e.g. `verifier.pacesetters.eu`).
- The verifier's production DID: who creates it, where the key is backed up.
- Whether pacesetters.eu requires email verification on registration (`verify_mail`) — decides the linking default.

Before any drupal.org release:
- License: GPL-3.0-or-later (current) vs GPL-2.0-or-later (required by drupal.org).

## Verification
- CI green: `ddev phpunit` (Unit, Kernel, Functional, FunctionalJavascript), `ddev phpcs`, `ddev phpstan`.
- Manual: `ddev launch /user/login` → wallet button → QR page; with the fake client toggled, polling logs the user in;
  status report shows a warning while the module is unconfigured and the login link is hidden.
- E2E: scan QR with Pacesetters wallet on a phone → present email credential → desktop browser auto-logs in and a
  notification email arrives; repeat same-device on the phone; with a new email (account auto-created), an existing
  account's email (linked, password scrambled), an admin's email (refused), an untrusted issuer (refused), and an
  expired session → error shown.
- Confirm `generate_authorization_request` without the bearer token is refused by Caddy (404), and
  `/verifier/transaction/*` is not reachable from outside (`deploy/verifier/test-proxy.sh`, also in CI).
- Issuer: only the OpenID4VCI endpoints and `/email-code/{send,check}` are reachable, never `ru_to_ac` or
  `/email-code/redeem` (`deploy/issuer/test-proxy.sh`, in CI); `deploy/issuer/test-issuance.py --login` passes.
- Uninstall the module on a test site → no `didroom_login` rows left in `authmap`.
