"""Tests of the email code service: python3 -m unittest -v (in this directory)."""

import json
import smtplib
import threading
import unittest
import urllib.error
import urllib.request
from http.server import ThreadingHTTPServer

from email_code import Limits, Mailer, Refused, Service, Store, handler


class Clock:
    def __init__(self) -> None:
        self.t = 1_000_000.0

    def __call__(self) -> float:
        return self.t


class FakeMailer(Mailer):
    def __init__(self) -> None:
        super().__init__(host="smtp.invalid", port=25, sender="issuer@example.org", issuer_name="Test issuer")
        self.sent: list = []
        self.fail = False

    def send(self, msg) -> None:
        if self.fail:
            raise smtplib.SMTPException("down")
        self.sent.append(msg)

    def last_code(self) -> str:
        return self.sent[-1]["Subject"].split()[0]


class StoreTest(unittest.TestCase):
    def setUp(self) -> None:
        self.clock = Clock()
        self.store = Store(Limits(), now=self.clock)

    def refused(self, error: str, fn, *args) -> Refused:
        with self.assertRaises(Refused) as cm:
            fn(*args)
        self.assertEqual(error, cm.exception.body["error"])
        return cm.exception

    def test_normalize(self) -> None:
        self.assertEqual("ann@example.org", self.store.normalize(" Ann@Example.ORG "))
        for bad in (None, 3, "", "ann", "ann@", "ann@example", "a b@example.org", "a@b@example.org", "a" * 250 + "@x.org"):
            self.refused("invalid_email", self.store.normalize, bad)

    def test_code_gives_a_ticket_once(self) -> None:
        code = self.store.new_code("ann@example.org", "1.2.3.4")
        self.assertRegex(code, r"^\d{6}$")
        ticket = self.store.check("ann@example.org", code)
        self.assertRegex(ticket, r"^[0-9a-f]{32}$")
        self.refused("no_code", self.store.check, "ann@example.org", code)
        self.assertEqual("ann@example.org", self.store.redeem(ticket))
        self.refused("invalid_ticket", self.store.redeem, ticket)

    def test_code_is_bound_to_the_address(self) -> None:
        code = self.store.new_code("ann@example.org", "1.2.3.4")
        self.refused("no_code", self.store.check, "bob@example.org", code)

    def test_wrong_codes(self) -> None:
        code = self.store.new_code("ann@example.org", "1.2.3.4")
        wrong = f"{(int(code) + 1) % 10**6:06d}"
        for left in (4, 3, 2, 1):
            e = self.refused("invalid_code", self.store.check, "ann@example.org", wrong)
            self.assertEqual(left, e.body["attempts_left"])
        # The fifth wrong attempt voids the code, so the right one fails too.
        self.refused("no_code", self.store.check, "ann@example.org", wrong)
        self.refused("no_code", self.store.check, "ann@example.org", code)

    def test_codes_and_tickets_expire(self) -> None:
        code = self.store.new_code("ann@example.org", "1.2.3.4")
        self.clock.t += 600
        self.refused("no_code", self.store.check, "ann@example.org", code)
        self.clock.t += 60
        ticket = self.store.check("ann@example.org", self.store.new_code("ann@example.org", "1.2.3.4"))
        self.clock.t += 300
        self.refused("invalid_ticket", self.store.redeem, ticket)

    def test_new_code_replaces_the_old_one(self) -> None:
        old = self.store.new_code("ann@example.org", "1.2.3.4")
        self.clock.t += 60
        new = self.store.new_code("ann@example.org", "1.2.3.4")
        if old != new:
            self.refused("invalid_code", self.store.check, "ann@example.org", old)
        self.assertTrue(self.store.check("ann@example.org", new))

    def test_send_limits(self) -> None:
        self.store.new_code("ann@example.org", "1.2.3.4")
        e = self.refused("rate_limited", self.store.new_code, "ann@example.org", "5.6.7.8")
        self.assertEqual(61, e.body["retry_after"])
        for _ in range(4):
            self.clock.t += 60
            self.store.new_code("ann@example.org", "1.2.3.4")
        self.clock.t += 60
        self.refused("rate_limited", self.store.new_code, "ann@example.org", "1.2.3.4")
        # Per client too: 20 an hour, whatever the address.
        for i in range(15):
            self.store.new_code(f"user{i}@example.org", "1.2.3.4")
        self.refused("rate_limited", self.store.new_code, "zed@example.org", "1.2.3.4")
        self.store.new_code("zed@example.org", "5.6.7.8")
        # An hour later, the counters are clear.
        self.clock.t += 3600
        self.store.new_code("ann@example.org", "1.2.3.4")
        self.store.new_code("zed2@example.org", "1.2.3.4")

    def test_pending_cap(self) -> None:
        store = Store(Limits(max_pending=2, codes_per_client_hour=100), now=self.clock)
        store.new_code("a@example.org", "c")
        store.new_code("b@example.org", "c")
        self.refused("busy", store.new_code, "c@example.org", "c")


class HttpTest(unittest.TestCase):
    def setUp(self) -> None:
        self.mailer = FakeMailer()
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), handler(Service(Store(Limits()), self.mailer)))
        threading.Thread(target=self.server.serve_forever, daemon=True).start()
        self.base = f"http://127.0.0.1:{self.server.server_port}"

    def tearDown(self) -> None:
        self.server.shutdown()
        self.server.server_close()

    def post(self, path: str, body, client: str = "1.2.3.4") -> tuple[int, dict]:
        data = body if isinstance(body, bytes) else json.dumps(body).encode()
        req = urllib.request.Request(self.base + path, data=data, headers={"Content-Type": "application/json", "X-Client-IP": client})
        try:
            with urllib.request.urlopen(req) as r:
                return r.status, json.loads(r.read())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read())

    def test_flow(self) -> None:
        self.assertEqual((200, {"expires_in": 600, "resend_in": 60}), self.post("/send", {"email": "Ann@Example.org"}))
        msg = self.mailer.sent[-1]
        self.assertEqual("ann@example.org", msg["To"])
        self.assertEqual("issuer@example.org", msg["From"])
        code = self.mailer.last_code()
        self.assertIn(f"Your code is {code}", msg.get_content())

        status, body = self.post("/check", {"email": "ann@example.org", "code": "x"})
        self.assertEqual((400, {"error": "invalid_code", "attempts_left": 4}), (status, body))
        status, body = self.post("/check", {"email": "ann@example.org", "code": code})
        self.assertEqual(200, status)
        self.assertEqual((200, {"email": "ann@example.org"}), self.post("/redeem", {"ticket": body["ticket"]}))
        self.assertEqual((400, {"error": "invalid_ticket"}), self.post("/redeem", {"ticket": body["ticket"]}))

    def test_bad_requests(self) -> None:
        self.assertEqual((400, {"error": "invalid_json"}), self.post("/send", b"nope"))
        self.assertEqual((400, {"error": "invalid_json"}), self.post("/send", ["a@example.org"]))
        self.assertEqual((400, {"error": "invalid_email"}), self.post("/send", {}))
        self.assertEqual((413, {"error": "too_large"}), self.post("/send", {"email": "a" * 5000}))
        self.assertEqual((404, {"error": "not_found"}), self.post("/other", {}))
        self.assertEqual((400, {"error": "invalid_ticket"}), self.post("/redeem", {}))
        self.assertEqual((400, {"error": "no_code"}), self.post("/check", {"email": "bob@example.org", "code": "123456"}))
        with urllib.request.urlopen(self.base + "/health") as r:
            self.assertEqual(200, r.status)

    def test_mail_failure_voids_the_code(self) -> None:
        self.mailer.fail = True
        self.assertEqual((502, {"error": "mail_failed"}), self.post("/send", {"email": "ann@example.org"}))
        self.assertEqual((400, {"error": "no_code"}), self.post("/check", {"email": "ann@example.org", "code": "000000"}))

    def test_rate_limit_answer(self) -> None:
        self.post("/send", {"email": "ann@example.org"})
        status, body = self.post("/send", {"email": "ann@example.org"})
        self.assertEqual(429, status)
        self.assertEqual("rate_limited", body["error"])
        self.assertEqual(1, len(self.mailer.sent))


class MailerTest(unittest.TestCase):
    def test_from_env(self) -> None:
        import os
        from unittest import mock

        with mock.patch.dict(os.environ, {"MAIL_FROM": "a@example.org", "SMTP_SECURITY": "none", "SMTP_HOST": "mail"}, clear=True):
            m = Mailer.from_env()
        self.assertEqual(("mail", 25, "none"), (m.host, m.port, m.security))
        with mock.patch.dict(os.environ, {"MAIL_FROM": "a@example.org"}, clear=True):
            self.assertEqual(587, Mailer.from_env().port)
        with mock.patch.dict(os.environ, {}, clear=True), self.assertRaises(SystemExit):
            Mailer.from_env()
        with mock.patch.dict(os.environ, {"MAIL_FROM": "a@example.org", "SMTP_SECURITY": "ssl"}, clear=True), self.assertRaises(SystemExit):
            Mailer.from_env()


if __name__ == "__main__":
    unittest.main()
