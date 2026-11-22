#!/usr/bin/env python3
"""Read-only verification of the production SFTP target."""

import os
import posixpath
import stat
import sys

from alfahosting_auth import connect


def validated_root(value):
    if not value.startswith("/") or value == "/" or ".." in value.split("/"):
        raise ValueError("Invalid document root")
    if posixpath.normpath(value) != value.rstrip("/"):
        raise ValueError("Invalid document root")
    return value.rstrip("/")


def verify():
    root = validated_root(os.environ.get("ALFA_DOCUMENT_ROOT", ""))
    client = connect()
    try:
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
