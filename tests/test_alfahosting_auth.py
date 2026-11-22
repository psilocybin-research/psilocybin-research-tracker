"""Offline authentication tests; no production connection or private credentials."""

import importlib.util
from pathlib import Path
import sys
import types
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / ".github/scripts/alfahosting_auth.py"
SPEC = importlib.util.spec_from_file_location("alfahosting_auth", SCRIPT)
AUTH = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(AUTH)

BASE = {
    "ALFA_HOST": "host226.alfahosting-server.de",
    "ALFA_USER": "example-account",
    "ALFA_HOST_KEY": "host226.alfahosting-server.de ssh-ed25519 EXAMPLE",
}


class FakeClient:
    def __init__(self, *, host_matches=True):
        self.host_matches = host_matches
        self.connected = None
        self.closed = False
        self.hostkeys = ""
        self.policy = None

    def load_host_keys(self, path):
        with open(path, encoding="utf-8") as source:
            self.hostkeys = source.read()

    def get_host_keys(self):
        class HostKeys:
            def lookup(_, hostname):
                return object() if self.host_matches and hostname == BASE["ALFA_HOST"] else None
        return HostKeys()

    def set_missing_host_key_policy(self, policy):
        self.policy = policy

    def connect(self, **kwargs):
        self.connected = kwargs

    def close(self):
        self.closed = True


class FakeEd25519Key:
    @staticmethod
    def from_private_key(stream, password=None):
        assert stream.read() == "FAKE TEST KEY"
        assert password in (None, "test-passphrase")
        return "test-key-object"


class AuthenticationTests(unittest.TestCase):
    def test_key_is_required_by_default(self):
        with self.assertRaises(ValueError):
            AUTH.auth_settings(BASE)

    def test_ed25519_key_mode_disallows_implicit_password_fallback(self):
        with self.assertRaises(ValueError):
            AUTH.auth_settings({
                **BASE, "ALFA_PRIVATE_KEY": "FAKE TEST KEY",
                "ALFA_PASSWORD": "not-a-real-password",
            })

    def test_password_requires_two_explicit_settings(self):
        with self.assertRaises(ValueError):
            AUTH.auth_settings({**BASE, "ALFA_PASSWORD": "fake"})
        with self.assertRaises(ValueError):
            AUTH.auth_settings({
                **BASE, "ALFA_AUTH_METHOD": "password",
                "ALFA_PASSWORD": "fake",
            })
        self.assertEqual(
            AUTH.auth_settings({
                **BASE, "ALFA_AUTH_METHOD": "password",
                "ALFA_PASSWORD": "fake",
                "ALFA_ALLOW_PASSWORD_AUTH": "yes",
            }),
            "password",
        )

    def test_invalid_mode_and_host_rejected(self):
        for env in (
            {**BASE, "ALFA_PRIVATE_KEY": "FAKE TEST KEY", "ALFA_AUTH_METHOD": "auto"},
            {**BASE, "ALFA_PRIVATE_KEY": "FAKE TEST KEY", "ALFA_HOST": "host;command"},
        ):
            with self.subTest(env=env["ALFA_AUTH_METHOD"] if "ALFA_AUTH_METHOD" in env else "host"):
                with self.assertRaises(ValueError):
                    AUTH.auth_settings(env)

    def fake_paramiko(self, client):
        return types.SimpleNamespace(
            SSHClient=lambda: client,
            RejectPolicy=lambda: "reject-policy",
            Ed25519Key=FakeEd25519Key,
        )

    def test_key_auth_disables_local_agent_and_password(self):
        client = FakeClient()
        with patch.dict(sys.modules, {"paramiko": self.fake_paramiko(client)}):
            connected = AUTH.connect({
                **BASE,
                "ALFA_PRIVATE_KEY": "FAKE TEST KEY",
                "ALFA_PRIVATE_KEY_PASSPHRASE": "test-passphrase",
            })
        self.assertIs(connected, client)
        self.assertEqual(client.policy, "reject-policy")
        self.assertEqual(client.connected["pkey"], "test-key-object")
        self.assertNotIn("password", client.connected)
        self.assertFalse(client.connected["look_for_keys"])
        self.assertFalse(client.connected["allow_agent"])
        self.assertEqual(client.connected["port"], 22)
        self.assertIn(BASE["ALFA_HOST_KEY"], client.hostkeys)

    def test_wrong_host_key_entry_fails_closed(self):
        client = FakeClient(host_matches=False)
        with patch.dict(sys.modules, {"paramiko": self.fake_paramiko(client)}):
            with self.assertRaises(ValueError):
                AUTH.connect({**BASE, "ALFA_PRIVATE_KEY": "FAKE TEST KEY"})
        self.assertTrue(client.closed)
        self.assertIsNone(client.connected)

    def test_explicit_password_mode(self):
        client = FakeClient()
        with patch.dict(sys.modules, {"paramiko": self.fake_paramiko(client)}):
            AUTH.connect({
                **BASE, "ALFA_AUTH_METHOD": "password",
                "ALFA_PASSWORD": "test-only",
                "ALFA_ALLOW_PASSWORD_AUTH": "yes",
            })
        self.assertEqual(client.connected["password"], "test-only")
        self.assertNotIn("pkey", client.connected)

    def test_connection_failure_closes_client(self):
        client = FakeClient()
        def fail_connect(**kwargs):
            raise RuntimeError("authentication failed")
        client.connect = fail_connect
        with patch.dict(sys.modules, {"paramiko": self.fake_paramiko(client)}):
            with self.assertRaises(AUTH.AlfahostingAuthError):
                AUTH.connect({**BASE, "ALFA_PRIVATE_KEY": "FAKE TEST KEY"})
        self.assertTrue(client.closed)

    def test_password_unlock_is_exact_and_no_private_key_is_used(self):
        client = FakeClient()
        with patch.dict(sys.modules, {"paramiko": self.fake_paramiko(client)}):
            AUTH.connect({
                **BASE,
                "ALFA_AUTH_METHOD": "password",
                "ALFA_PASSWORD": "test-only",
                "ALFA_ALLOW_PASSWORD_AUTH": "yes",
            })
        self.assertNotIn("pkey", client.connected)
        self.assertEqual(client.connected["password"], "test-only")


if __name__ == "__main__":
    unittest.main()
