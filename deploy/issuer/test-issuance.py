#!/usr/bin/env python3
"""Gets an email credential from the running dev issuer, as a wallet would.

The wallet is simulated by the Pacesetters wallet's own contracts
(mobile_zencode), run by ncr; the emailed code is read from Mailpit. With
--login, the credential is then presented to the Drupal site's wallet login,
which must be configured for this issuer and a verifier.

    deploy/issuer/test-issuance.py [--email ADDRESS] [--login https://site.ddev.site]

Needs the issuer up with the tunnel and dev profiles (scripts/dev-issuer.sh
up), and a checkout of the wallet (WALLET_DIR, default ../pacesetters-wallet
next to this repository). Standard library only.
"""

from __future__ import annotations

import argparse
import http.cookiejar
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
from datetime import datetime
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
IMAGE = re.search(r"image: (ghcr.io/forkbombeu/didroom_microservices@sha256:[0-9a-f]+)", (HERE / "compose.yaml").read_text())[1]
HOLDER_PORT = 3013


def env() -> dict[str, str]:
    values = {}
    for line in (HERE / ".env").read_text().splitlines():
        if "=" in line and not line.lstrip().startswith("#"):
            key, _, value = line.partition("=")
            values[key.strip()] = value.strip().strip('"')
    return values


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def request(url: str, data: bytes | None = None, headers: dict | None = None, opener=None, method: str | None = None):
    req = urllib.request.Request(url, data=data, headers=headers or {}, method=method)
    try:
        r = (opener or urllib.request.build_opener(NoRedirect)).open(req, timeout=60)
    except urllib.error.HTTPError as e:
        r = e
    return r.status, dict(r.headers), r.read()


def post_json(url: str, body: dict, opener=None) -> tuple[int, dict]:
    status, _, raw = request(url, json.dumps(body).encode(), {"Content-Type": "application/json"}, opener)
    try:
        return status, json.loads(raw)
    except ValueError:
        return status, {"raw": raw.decode(errors="replace")[:2000]}


def step(name: str) -> None:
    print(f"- {name}", flush=True)


def fail(message: str) -> None:
    print(f"FAILED: {message}", file=sys.stderr)
    sys.exit(1)


class Holder:
    """ncr serving the wallet's contracts on 127.0.0.1."""

    def __init__(self, wallet_dir: Path) -> None:
        contracts = wallet_dir / "src/lib/mobile_zencode/wallet"
        if not contracts.is_dir():
            fail(f"No wallet contracts in {contracts}; set WALLET_DIR")
        self.tmp = Path(tempfile.mkdtemp(prefix="didroom-holder-"))
        shutil.copytree(contracts, self.tmp / "wallet")
        (self.tmp / "public").mkdir()
        self.name = f"didroom-holder-{os.getpid()}"
        subprocess.run(
            ["docker", "run", "-d", "--rm", "--name", self.name, "-p", f"127.0.0.1:{HOLDER_PORT}:3000",
             "-v", f"{self.tmp}/wallet:/app/wallet", "-v", f"{self.tmp}/public:/app/public",
             "--entrypoint", "./ncr", IMAGE, "-p", "3000", "-z", "wallet"],
            check=True, stdout=subprocess.DEVNULL)
        self.url = f"http://127.0.0.1:{HOLDER_PORT}"
        for _ in range(60):
            try:
                request(self.url + "/docs")
                return
            except OSError:
                time.sleep(0.5)
        fail("The simulated wallet did not start")

    def call(self, contract: str, body: dict) -> dict:
        status, out = post_json(f"{self.url}/{contract}", body)
        if status != 200:
            fail(f"wallet {contract}: {status} {json.dumps(out)[:1500]}")
        return out

    def close(self) -> None:
        subprocess.run(["docker", "rm", "-f", self.name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        shutil.rmtree(self.tmp, ignore_errors=True)


def mailpit_code(mailpit: str, email: str, after: float) -> str:
    query = urllib.parse.urlencode({"query": f"to:{email}"})
    for _ in range(30):
        _, _, raw = request(f"{mailpit}/api/v1/search?{query}")
        for msg in json.loads(raw).get("messages", []):
            if datetime.fromisoformat(msg["Created"]).timestamp() >= after - 2:
                found = re.match(r"(\d{6}) is your code", msg["Subject"])
                if found:
                    return found[1]
        time.sleep(1)
    fail(f"No code for {email} in Mailpit")
    return ""


def issue(holder: Holder, cfg: dict[str, str], email: str, mailpit: str) -> str:
    url = cfg["ISSUER_URL"].rstrip("/")
    redirect_uri = "pacesetters-wallet://finalize-authentication"

    step("scan the credential offer")
    with open(HERE / "run/public/credential_issuer/.qrcode.keys.json") as f:
        offer = json.load(f)["offer"]
    params = holder.call("holder_qr_to_well-known", {"!external-qr-code-content": offer})["credential_parameters"]

    step("pushed authorization request")
    par = holder.call("call_par", {"credential_parameters": params, "redirect_uri": redirect_uri})
    authorize = f"{par['authorization_endpoint']}?client_id={par['client_id']}&request_uri={par['request_uri']}"

    step("open the authorize page")
    status, _, page = request(authorize)
    if status != 200 or b"email-code" not in page:
        fail(f"authorize page: {status}")

    step(f"ask for a code for {email}")
    sent_at = time.time()
    status, out = post_json(f"{url}/email-code/send", {"email": email})
    if status != 200:
        fail(f"send: {status} {out}")
    code = mailpit_code(mailpit, email, sent_at)

    step("a wrong code is refused")
    wrong = f"{(int(code) + 1) % 10**6:06d}"
    status, out = post_json(f"{url}/email-code/check", {"email": email, "code": wrong})
    if (status, out.get("error")) != (400, "invalid_code"):
        fail(f"wrong code: {status} {out}")

    step("confirm the code")
    status, out = post_json(f"{url}/email-code/check", {"email": email, "code": code})
    if status != 200:
        fail(f"check: {status} {out}")
    ticket = out["ticket"]

    def submit(ticket: str) -> tuple[int, str, bytes]:
        form = {"params": {"client_id": par["client_id"], "request_uri": par["request_uri"]},
                "data": {"redeem": {"ticket": ticket}}, "custom_code": "email"}
        # Compact, like JSON.stringify in the page: ncr does not decode "+" in forms.
        body = urllib.parse.urlencode({"form_input_and_params": json.dumps(form, separators=(",", ":"))},
                                      quote_via=urllib.parse.quote).encode()
        status, headers, raw = request(f"{url}/authz_server/authorize_backend", body,
                                       {"Content-Type": "application/x-www-form-urlencoded"})
        return status, headers.get("Location") or headers.get("location") or "", raw

    step("a bogus ticket sends the wallet back with an error")
    status, location, raw = submit("0" * 32)
    if status != 302 or not location.startswith(redirect_uri) or "error=" not in location or "code=" in location:
        fail(f"bogus ticket: {status} {location[:300]} {raw[:300]!r}")

    step("submit the authorize page")
    status, location, raw = submit(ticket)
    query = urllib.parse.parse_qs(urllib.parse.urlsplit(location).query)
    if status != 302 or not location.startswith(redirect_uri) or "code" not in query:
        fail(f"authorize_backend: {status} {location[:300]} {raw[:500]!r}")

    step("the ticket works once")
    status, location, raw = submit(ticket)
    if "code=" in location:
        fail(f"reused ticket gave a code: {location[:300]}")

    step("token and credential")
    out = holder.call("call_token_and_credential", {
        "credential_parameters": params, "code": query["code"][0],
        "code_verifier": par["code_verifier"], "redirect_uri": redirect_uri})
    credential = out["result"]["credentials"][0]["credential"]
    decoded = holder.call("utils_print_decoded_sdjwt", {"credential": credential})["credential"]
    payload = decoded["jwt"]["payload"]
    disclosed = {d[1]: d[2] for d in decoded["disclosures"]}
    print(f"  iss={payload['iss']} vct={payload.get('vct', payload.get('type'))} email={disclosed.get('email')}")
    if payload["iss"] != f"{url}/credential_issuer" or disclosed.get("email") != email:
        fail("unexpected credential content")
    return credential


def login(holder: Holder, credential: str, site: str) -> None:
    jar = http.cookiejar.CookieJar()
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar), NoRedirect)

    step(f"open the wallet login page of {site}")
    status, _, page = request(f"{site}/didroom/login", opener=opener)
    found = re.search(rb'href="(openid4vp://[^"]+)"', page)
    if status != 200 or not found:
        fail(f"login page: {status}")
    intent = urllib.parse.urlsplit(found[1].decode().replace("&amp;", "&"))
    query = {k: v[0] for k, v in urllib.parse.parse_qs(intent.query).items()}

    step("present the credential")
    info = holder.call("openid4vp_qr_to_info", {**query, "credentials": {"dc+sd-jwt": [credential]}})
    # The first matching credential for each requested one, as the wallet
    # offers when the person accepts.
    matching = info["vps"][0]["matching_credential_sets"][0]
    body = {"vp_token": {cid: [options[0]["signed"]] for cid, options in matching.items()}}
    if info.get("state"):
        body["state"] = info["state"]
    response = holder.call("openid4vp_response", {"body": body, "url": info["post_url"]})
    print(f"  verifier answered: {response.get('result', {}).get('status')}")

    step("wait for the site to log in")
    for _ in range(20):
        status, _, raw = request(f"{site}/didroom/login/status", opener=opener)
        result = json.loads(raw)
        if result.get("status") != "pending":
            break
        time.sleep(2)
    print(f"  status: {result}")
    if result.get("status") != "done":
        fail("not logged in")
    status, headers, _ = request(f"{site}/user", opener=opener)
    print(f"  /user answered {status} {headers.get('Location', '')}")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--email", default=f"wallet-{int(time.time())}@example.org")
    parser.add_argument("--login", metavar="SITE_URL", help="then log in to this Drupal site with the credential")
    args = parser.parse_args()

    cfg = env()
    mailpit = f"http://127.0.0.1:{cfg.get('MAILPIT_PORT', '8027')}"
    holder = Holder(Path(os.environ.get("WALLET_DIR", REPO.parent / "pacesetters-wallet")))
    try:
        credential = issue(holder, cfg, args.email.lower(), mailpit)
        print("Issued an email credential.")
        if args.login:
            login(holder, credential, args.login.rstrip("/"))
            print("Logged in with the credential.")
    finally:
        holder.close()


if __name__ == "__main__":
    main()
