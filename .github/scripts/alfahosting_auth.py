"""Strict, noninteractive Alfahosting SSH authentication for GitHub Actions.

Uses a dedicated in-memory Ed25519 deployment key by default. Password login
is permitted only when explicitly selected AND independently unlocked.
"""

from __future__ import annotations

import io
import os
import re
import socket
import tempfile


class AlfahostingAuthError(RuntimeError):
    category = "authentication"


class ConfigurationError(AlfahostingAuthError, ValueError):
    category = "configuration"


class HostKeyError(AlfahostingAuthError, ValueError):
    category = "host-key"


class NetworkError(AlfahostingAuthError):
    category = "network"


def auth_settings(env):
    required = ("ALFA_HOST", "ALFA_USER", "ALFA_HOST_KEY")
    if not all(env.get(name, "").strip() for name in required):
        raise ConfigurationError("Missing required SSH host identity settings")

    host = env["ALFA_HOST"].strip()
    if not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9.-]{0,252}", host):
        raise ConfigurationError("Invalid SFTP hostname")

    mode = (env.get("ALFA_AUTH_METHOD") or "key").strip().lower()
    private_key = env.get("ALFA_PRIVATE_KEY", "").strip()
    password = env.get("ALFA_PASSWORD", "")
    passphrase = env.get("ALFA_PRIVATE_KEY_PASSPHRASE", "")
    if mode == "key":
        if not private_key or password:
            raise ConfigurationError("Key authentication requires a dedicated key and no password")
    elif mode == "password":
        if not password or private_key or passphrase:
            raise ConfigurationError("Password authentication requires only a password")
        if env.get("ALFA_ALLOW_PASSWORD_AUTH") != "yes":
            raise ConfigurationError("Password authentication is locked")
    else:
        raise ConfigurationError("Unsupported SSH authentication method")
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
            raise HostKeyError("Verified host key entry does not match requested hostname")
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
        try:
            client.connect(**options)
        except Exception as exc:
            class_name = exc.__class__.__name__.lower()
            module_name = exc.__class__.__module__.lower()
            if class_name == "badhostkeyexception" or "badhostkey" in class_name:
                raise HostKeyError("The server host key was rejected") from None
            if isinstance(exc, (socket.timeout, TimeoutError)):
                raise NetworkError("The SSH endpoint timed out") from None
            if "authentication" in class_name or "authentication" in module_name:
                raise AlfahostingAuthError("SSH authentication was rejected") from None
            if isinstance(exc, (ConnectionError, socket.gaierror)):
                raise NetworkError("The SSH endpoint could not be reached") from None
            raise AlfahostingAuthError("The SSH connection could not be established") from None
        return client
    except AlfahostingAuthError:
        client.close()
        raise
    except Exception:
        client.close()
        raise AlfahostingAuthError("The SSH connection could not be established") from None
