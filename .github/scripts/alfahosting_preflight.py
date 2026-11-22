#!/usr/bin/env python3
"""Read-only verification of the production SFTP target."""

import os
import posixpath
import stat
import sys

from alfahosting_auth import AlfahostingAuthError, ConfigurationError, connect


class SftpError(RuntimeError):
    category = "sftp"


class DocumentRootError(RuntimeError):
    category = "document-root"


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
        try:
            sftp = client.open_sftp()
        except Exception:
            raise SftpError("SFTP initialization failed") from None
        try:
            for name, predicate in (
                ("", stat.S_ISDIR),
                (".htaccess", stat.S_ISREG),
                ("index.php", stat.S_ISREG),
                ("src", stat.S_ISDIR),
                ("data", stat.S_ISDIR),
                ("data/.htaccess", stat.S_ISREG),
            ):
                try:
                    info = sftp.lstat(posixpath.join(root, name))
                except Exception:
                    raise DocumentRootError("Expected document-root entry was not readable") from None
                if not predicate(info.st_mode):
                    raise DocumentRootError("Unexpected document-root structure")
        finally:
            sftp.close()
    finally:
        client.close()


def preflight_failure_message(exc):
    if isinstance(exc, (AlfahostingAuthError, ConfigurationError, SftpError, DocumentRootError)):
        return "SFTP preflight failed: " + getattr(exc, "category", "unexpected")
    if isinstance(exc, ValueError):
        return "SFTP preflight failed: configuration"
    return "SFTP preflight failed: unexpected"


def main():
    try:
        verify()
        print("Read-only SFTP preflight passed. No files changed.")
    except Exception as exc:
        print(preflight_failure_message(exc), file=sys.stderr)
        raise SystemExit(1)


if __name__ == "__main__":
    main()
