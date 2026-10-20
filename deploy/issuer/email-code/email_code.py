"""Confirms email addresses with one-time codes, for the email issuer.

The authorize page asks for a code (POST /send), and trades the code the
person received for a ticket (POST /check). The authorization server then
redeems the ticket (POST /redeem) and learns the confirmed address. Only
/send and /check are public; the proxy keeps /redeem internal.

State is kept in memory: a restart only voids the codes and tickets in
flight. Standard library only.
"""

from __future__ import annotations

import json
import logging
import os
import re
import secrets
import smtplib
import ssl
import threading
import time
from dataclasses import dataclass, field
from email.message import EmailMessage
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Callable

log = logging.getLogger("email-code")

# Deliberately simple: the address only has to be deliverable, and the code
# sent to it proves the rest.
EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
MAX_BODY = 4096
HOUR = 3600


class Refused(Exception):
    """A request the service turns down, with the HTTP status to answer."""

    def __init__(self, status: int, error: str, **extra: int) -> None:
        super().__init__(error)
        self.status = status
        self.body = {"error": error, **extra}


@dataclass
class Limits:
    code_ttl: int = 600
    ticket_ttl: int = 300
    max_attempts: int = 5
    resend_after: int = 60
    codes_per_email_hour: int = 5
    codes_per_client_hour: int = 20
    max_pending: int = 10000


@dataclass
class _Code:
    code: str
    expires: float
    attempts: int = 0


@dataclass
class _Ticket:
    email: str
    expires: float


@dataclass
class Store:
    """Codes, tickets and send counters, safe to use from several threads."""

    limits: Limits = field(default_factory=Limits)
    now: Callable[[], float] = time.time
    _codes: dict[str, _Code] = field(default_factory=dict)
    _tickets: dict[str, _Ticket] = field(default_factory=dict)
    _sent: dict[str, list[float]] = field(default_factory=dict)
    _lock: threading.Lock = field(default_factory=threading.Lock)

    @staticmethod
    def normalize(email: object) -> str:
        if not isinstance(email, str):
            raise Refused(400, "invalid_email")
        email = email.strip().lower()
        if len(email) > 254 or not EMAIL_RE.match(email):
            raise Refused(400, "invalid_email")
        return email

    def _prune(self, now: float) -> None:
        for table in (self._codes, self._tickets):
            for key in [k for k, v in table.items() if v.expires <= now]:
                del table[key]
        for key in list(self._sent):
            recent = [t for t in self._sent[key] if t > now - HOUR]
            if recent:
                self._sent[key] = recent
            else:
                del self._sent[key]

    def new_code(self, email: str, client: str) -> str:
        """Returns a new code for the address, replacing any earlier one."""
        lim = self.limits
        with self._lock:
            now = self.now()
            self._prune(now)
            by_email = self._sent.get("email:" + email, [])
            by_client = self._sent.get("client:" + client, [])
            if by_email and now - by_email[-1] < lim.resend_after:
                raise Refused(429, "rate_limited", retry_after=int(lim.resend_after - (now - by_email[-1])) + 1)
            for sent, limit in ((by_email, lim.codes_per_email_hour), (by_client, lim.codes_per_client_hour)):
                if len(sent) >= limit:
                    raise Refused(429, "rate_limited", retry_after=int(sent[0] + HOUR - now) + 1)
            if len(self._codes) >= lim.max_pending and email not in self._codes:
                raise Refused(503, "busy")
            code = f"{secrets.randbelow(10**6):06d}"
            self._codes[email] = _Code(code, now + lim.code_ttl)
            self._sent.setdefault("email:" + email, []).append(now)
            self._sent.setdefault("client:" + client, []).append(now)
            return code

    def forget_code(self, email: str) -> None:
        with self._lock:
            self._codes.pop(email, None)

    def check(self, email: str, code: object) -> str:
        """Trades a correct code for a ticket. Codes are used once."""
        with self._lock:
            now = self.now()
            self._prune(now)
            pending = self._codes.get(email)
            if pending is None:
                raise Refused(400, "no_code")
            if not isinstance(code, str) or not secrets.compare_digest(code.strip(), pending.code):
                pending.attempts += 1
                left = self.limits.max_attempts - pending.attempts
                if left <= 0:
                    del self._codes[email]
                    raise Refused(400, "no_code")
                raise Refused(400, "invalid_code", attempts_left=left)
            del self._codes[email]
            ticket = secrets.token_hex(16)
            self._tickets[ticket] = _Ticket(email, now + self.limits.ticket_ttl)
            return ticket

    def redeem(self, ticket: object) -> str:
        """Returns the confirmed address of a ticket. Tickets are used once."""
        with self._lock:
            self._prune(self.now())
            found = self._tickets.pop(ticket, None) if isinstance(ticket, str) else None
            if found is None:
                raise Refused(400, "invalid_ticket")
            return found.email


@dataclass
class Mailer:
    host: str
    port: int
    security: str = "starttls"
    user: str = ""
    password: str = ""
    sender: str = ""
    issuer_name: str = "Email issuer"
    timeout: int = 10

    @classmethod
    def from_env(cls) -> Mailer:
        env = os.environ
        security = env.get("SMTP_SECURITY", "starttls")
        if security not in ("starttls", "tls", "none"):
            raise SystemExit("SMTP_SECURITY must be starttls, tls or none")
        sender = env.get("MAIL_FROM", "")
        if not sender:
            raise SystemExit("Set MAIL_FROM")
        return cls(
            host=env.get("SMTP_HOST", "localhost"),
            port=int(env.get("SMTP_PORT", {"tls": "465", "none": "25"}.get(security, "587"))),
            security=security,
            user=env.get("SMTP_USER", ""),
            password=env.get("SMTP_PASSWORD", ""),
            sender=sender,
            issuer_name=env.get("ISSUER_NAME", cls.issuer_name),
        )

    def message(self, email: str, code: str, ttl: int) -> EmailMessage:
        msg = EmailMessage()
        msg["From"] = self.sender
        msg["To"] = email
        msg["Subject"] = f"{code} is your code for {self.issuer_name}"
        msg.set_content(
            f"Your code is {code}\n\n"
            f"Enter it on the page that asked for it, to get an email credential in your wallet. "
            f"It is valid for {ttl // 60} minutes.\n\n"
            f"If you did not ask for it, you can ignore this email: nothing is issued without the code.\n\n"
            f"-- {self.issuer_name}\n"
        )
        return msg

    def send(self, msg: EmailMessage) -> None:
        context = ssl.create_default_context()
        if self.security == "tls":
            smtp: smtplib.SMTP = smtplib.SMTP_SSL(self.host, self.port, timeout=self.timeout, context=context)
        else:
            smtp = smtplib.SMTP(self.host, self.port, timeout=self.timeout)
        with smtp:
            if self.security == "starttls":
                smtp.starttls(context=context)
            if self.user:
                smtp.login(self.user, self.password)
            smtp.send_message(msg)


def masked(email: str) -> str:
    local, _, domain = email.partition("@")
    return f"{local[:1]}***@{domain}"


class Service:
    def __init__(self, store: Store, mailer: Mailer) -> None:
        self.store = store
        self.mailer = mailer

    def handle(self, path: str, body: dict, client: str) -> dict:
        if path == "/send":
            email = self.store.normalize(body.get("email"))
            code = self.store.new_code(email, client)
            try:
                self.mailer.send(self.mailer.message(email, code, self.store.limits.code_ttl))
            except (OSError, smtplib.SMTPException) as e:
                self.store.forget_code(email)
                log.error("Could not send a code to %s: %s", masked(email), e)
                raise Refused(502, "mail_failed") from e
            log.info("Sent a code to %s", masked(email))
            return {"expires_in": self.store.limits.code_ttl, "resend_in": self.store.limits.resend_after}
        if path == "/check":
            email = self.store.normalize(body.get("email"))
            return {"ticket": self.store.check(email, body.get("code")), "expires_in": self.store.limits.ticket_ttl}
        if path == "/redeem":
            email = self.store.redeem(body.get("ticket"))
            log.info("Redeemed a ticket for %s", masked(email))
            return {"email": email}
        raise Refused(404, "not_found")


def handler(service: Service) -> type[BaseHTTPRequestHandler]:
    class Handler(BaseHTTPRequestHandler):
        server_version = "email-code"
        sys_version = ""

        def _reply(self, status: int, body: dict) -> None:
            data = json.dumps(body).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(data)

        def do_GET(self) -> None:
            if self.path == "/health":
                self._reply(200, {"status": "ok"})
            else:
                self._reply(404, {"error": "not_found"})

        def do_POST(self) -> None:
            try:
                length = int(self.headers.get("Content-Length") or 0)
                if length > MAX_BODY:
                    raise Refused(413, "too_large")
                try:
                    body = json.loads(self.rfile.read(length) or b"{}")
                except ValueError:
                    body = None
                if not isinstance(body, dict):
                    raise Refused(400, "invalid_json")
                # The proxy sets this header; direct callers (the
                # authorization server) only use /redeem.
                client = self.headers.get("X-Client-IP", self.client_address[0])
                self._reply(200, service.handle(self.path, body, client))
            except Refused as e:
                self._reply(e.status, e.body)

        def log_message(self, format: str, *args: object) -> None:
            log.debug("%s %s", self.address_string(), format % args)

    return Handler


def main() -> None:
    logging.basicConfig(level=os.environ.get("LOG_LEVEL", "INFO"), format="%(asctime)s %(levelname)s %(message)s")
    env = os.environ
    limits = Limits(
        code_ttl=int(env.get("CODE_TTL", Limits.code_ttl)),
        max_attempts=int(env.get("CODE_ATTEMPTS", Limits.max_attempts)),
    )
    service = Service(Store(limits), Mailer.from_env())
    port = int(env.get("PORT", "8000"))
    server = ThreadingHTTPServer(("", port), handler(service))
    log.info("Listening on port %d, sending mail through %s:%d", port, service.mailer.host, service.mailer.port)
    server.serve_forever()


if __name__ == "__main__":
    main()
