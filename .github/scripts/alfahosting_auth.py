"""Strict, noninteractive Alfahosting SSH authentication for GitHub Actions.

Uses a dedicated in-memory Ed25519 deployment key by default. Password login
is permitted only when explicitly selected AND independently unlocked.
"""

from __future__ import annotations

import io
import os
import re
import tempfile


def auth_settings(env):
    required = ("ALFA_HOST", "ALFA_USER", "ALFA_HOST_KEY")
    if not all(env.get(name, "").strip() for name in required):
        raise ValueError("Missing required SSH host identity settings")

    host = env["ALFA_HOST"].strip()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,252}", host):
        raise ValueError("Invalid SFTP hostname")

    mode = (env.get("ALFA_AUTH_METHOD") or "key").strip().lower()
    private_key = env.get("ALFA_PRIVATE_KEY", "").strip()
    password = env.get("ALFA_PASSWORD", "")
    passphrase = env.get("ALFA_PRIVATE_KEY_PASSPHRASE", "")
    if mode == "key":
        if not private_key or password:
            raise ValueError("Key authentication requires a dedicated key and no password")
    elif mode == "password":
        if not password or private_key or passphrase:
            raise ValueError("Password authentication requires only a password")
        if env.get("ALFA_ALLOW_PASSWORD_AUTH") != "yes":
            raise ValueError("Password authentication is locked")
    else:
        raise ValueError("Unsupported SSH authentication method")
    return mode


def connect(env=None):
    """Return a connected Paramiko client; never use agent or local key discovery."""
    import paramiko

    env = os.environ if env is None else env
    mode = auth_settings(env)
    client = paramiko.SSHClient()

    try:
        with tempfile.NamedTemporaryFile("w", encoding="utf-8") as known_hosts:
            os.chmod(known_hosts.name, 0o600)
            known_hosts.write(env["ALFA_HOST_KEY"].rstrip() + "\n")
            known_hosts.flush()
            client.load_host_keys(known_hosts.name)

        host = env["ALFA_HOST"].strip()
        if client.get_host_keys().lookup(host) is None:
            raise ValueError("Verified host key entry does not match requested hostname")
        client.set_missing_host_key_policy(paramiko.RejectPolicy())

        options = dict(
            hostname=host,
            port=22,
            username=env["ALFA_USER"],
            look_for_keys=False,
            allow_agent=False,
            timeout=20,
            auth_timeout=20,
            banner_timeout=20,
        )
        if mode == "key":
            key = paramiko.Ed25519Key.from_private_key(
                io.StringIO(env["ALFA_PRIVATE_KEY"]),
                password=env.get("ALFA_PRIVATE_KEY_PASSPHRASE") or None,
            )
            options["pkey"] = key
        else:
            options["password"] = env["ALFA_PASSWORD"]
        client.connect(**options)
        return client
    except Exception:
        client.close()
        raise
