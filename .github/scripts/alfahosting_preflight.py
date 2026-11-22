#!/usr/bin/env python3
"""Read-only verification of the production SFTP target."""

import os
import posixpath
import stat
import sys
import tempfile


def validated_root(value):
    if not value.startswith("/") or value == "/" or ".." in value.split("/"):
        raise ValueError("Invalid document root")
    if posixpath.normpath(value) != value.rstrip("/"):
        raise ValueError("Invalid document root")
    return value.rstrip("/")


def verify():
    import paramiko

    names = ("ALFA_HOST", "ALFA_USER", "ALFA_PASSWORD", "ALFA_HOST_KEY", "ALFA_DOCUMENT_ROOT")
    config = {name: os.environ.get(name, "") for name in names}
    if not all(config.values()):
        raise ValueError("Missing required configuration")
    root = validated_root(config["ALFA_DOCUMENT_ROOT"])

    client = paramiko.SSHClient()
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8") as keys:
        os.chmod(keys.name, 0o600)
        keys.write(config["ALFA_HOST_KEY"].rstrip() + "\n")
        keys.flush()
        client.load_host_keys(keys.name)
    client.set_missing_host_key_policy(paramiko.RejectPolicy())
    try:
        client.connect(
            hostname=config["ALFA_HOST"], port=22,
            username=config["ALFA_USER"], password=config["ALFA_PASSWORD"],
            look_for_keys=False, allow_agent=False,
            timeout=20, auth_timeout=20, banner_timeout=20,
        )
        with client.open_sftp() as sftp:
            for name, predicate in (
                ("", stat.S_ISDIR),
                (".htaccess", stat.S_ISREG),
                ("index.php", stat.S_ISREG),
                ("src", stat.S_ISDIR),
                ("data", stat.S_ISDIR),
                ("data/.htaccess", stat.S_ISREG),
            ):
                if not predicate(sftp.lstat(posixpath.join(root, name)).st_mode):
                    raise RuntimeError("Unexpected document-root structure")
    finally:
        client.close()


if __name__ == "__main__":
    try:
        verify()
        print("Read-only SFTP preflight passed. No files changed.")
    except Exception:
        print("SFTP preflight failed. Verify credentials, host key, and document root.", file=sys.stderr)
        raise SystemExit(1)
