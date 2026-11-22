#!/usr/bin/env python3
"""Manually approved SFTP release with private staging and best-effort rollback."""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import posixpath
import re
import stat
import subprocess
import sys
from urllib.request import urlopen

from alfahosting_preflight import validated_root
from alfahosting_auth import connect

ROOT_FILES = frozenset({
    ".htaccess", "favicon.ico", "manifest.webmanifest", "offline.html",
    "schema.sql", "server.json", "sw.js", "transcriptomics-server.json",
})
PUBLISHED_DIRS = frozenset({"assets", "src", ".well-known"})
CONFIG_FILES = frozenset({
    "config/.htaccess", "config/fungi-gallery.json",
    "config/study-families.json", "config/transcriptomics-snapshot.json",
    "config/transcriptomics-source.json",
})
ALLOWED_DOCS = frozenset({"docs/research-api.openapi.json"})
ALLOWED_BIN = frozenset({
    "bin/alerts.php", "bin/backup-sqlite.php", "bin/classify-existing.php",
    "bin/curate-incomplete-records.php", "bin/import-pubmed-ids.php",
    "bin/openalex-deep-backfill.php", "bin/openalex-quality-review.php",
    "bin/rebuild-normalized-indexes.php", "bin/repair-metadata.php",
    "bin/repair-openalex-visibility.php", "bin/update.php",
})


def allowed_path(name: str) -> bool:
    p = PurePosixPath(name)
    if not name or p.is_absolute() or ".." in p.parts or "\\" in name:
        return False
    if p.as_posix() != name or any(part in (".", "..") for part in p.parts):
        return False
    if name.startswith("assets/msca-impact/"):
        return False
    if len(p.parts) == 1:
        return name.endswith(".php") or name in ROOT_FILES
    if name in CONFIG_FILES | ALLOWED_DOCS | ALLOWED_BIN:
        return True
    if p.parts[0] == "src":
        return name.endswith(".php")
    if p.parts[0] == "assets":
        return (
            not any(part.startswith(".") for part in p.parts[1:])
            and Path(name).suffix.lower()
            in {".css", ".js", ".json", ".map", ".png", ".jpg", ".jpeg", ".webp", ".gif", ".svg", ".ico", ".woff", ".woff2", ".ttf", ".otf", ".txt", ".html", ".md"}
        )
    if p.parts[0] == ".well-known":
        return len(p.parts) >= 2 and not any(part.startswith(".") for part in p.parts[1:])
    return False


def manifest(project: Path) -> list[tuple[str, Path]]:
    result = subprocess.run(
        ["git", "-C", str(project), "ls-files", "-z"],
        stdout=subprocess.PIPE, check=True,
    )
    output = []
    for raw in result.stdout.split(b"\0"):
        if not raw:
            continue
        name = os.fsdecode(raw)
        if not allowed_path(name):
            continue
        path = project / name
        if not path.is_file() or path.is_symlink():
            raise ValueError("A tracked release file is not a regular file")
        output.append((name, path))
    output.sort()
    found = {name for name, _ in output}
    mandatory = {"index.php", ".htaccess", "src/helpers.php", "config/.htaccess"}
    if not mandatory.issubset(found):
        raise ValueError("Release manifest is incomplete")
    return output


def release_paths(site: str, private: str) -> tuple[str, str]:
    site = validated_root(site)
    private = validated_root(private)
    if site == private or site.startswith(private + "/") or private.startswith(site + "/"):
        raise ValueError("Private staging directory must be separate from document root")
    return site, private


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open("rb") as source:
        for data in iter(lambda: source.read(256 * 1024), b""):
            hasher.update(data)
    return hasher.hexdigest()


def remote_entry(sftp, path: str):
    try:
        return sftp.lstat(path)
    except FileNotFoundError:
        return None


def ensure_dir(sftp, path: str) -> None:
    info = remote_entry(sftp, path)
    if info is None:
        sftp.mkdir(path)
        info = sftp.lstat(path)
    if not stat.S_ISDIR(info.st_mode):
        raise RuntimeError("Expected a real directory, not a file or symlink")


def remote_parent(sftp, root: str, name: str, *, create: bool) -> None:
    ensure_dir_existing(sftp, root)
    current = root
    for part in PurePosixPath(name).parts[:-1]:
        current = posixpath.join(current, part)
        if create:
            ensure_dir(sftp, current)
        else:
            info = remote_entry(sftp, current)
            if info is not None and not stat.S_ISDIR(info.st_mode):
                raise RuntimeError("Unsafe target parent")


def ensure_dir_existing(sftp, path: str) -> None:
    info = sftp.lstat(path)
    if not stat.S_ISDIR(info.st_mode):
        raise RuntimeError("Expected directory")


def copy_sftp(sftp, source: str, target: str) -> None:
    with sftp.open(source, "rb") as input_file, sftp.open(target, "wb") as output_file:
        while True:
            chunk = input_file.read(256 * 1024)
            if not chunk:
                break
            output_file.write(chunk)


def remote_matches(sftp, source: Path, target: str) -> bool:
    info = remote_entry(sftp, target)
    if info is None:
        return False
    if not stat.S_ISREG(info.st_mode):
        raise RuntimeError("Refusing to overwrite a non-regular file")
    if info.st_size != source.stat().st_size:
        return False
    remote_digest = hashlib.sha256()
    with sftp.open(target, "rb") as remote:
        while True:
            chunk = remote.read(256 * 1024)
            if not chunk:
                break
            remote_digest.update(chunk)
    return remote_digest.hexdigest() == digest(source)


def remote_hash(sftp, path: str) -> str:
    hasher = hashlib.sha256()
    with sftp.open(path, "rb") as remote:
        while True:
            chunk = remote.read(256 * 1024)
            if not chunk:
                break
            hasher.update(chunk)
    return hasher.hexdigest()


def verify_target(sftp, site: str, private: str) -> None:
    for path in (site, private, site + "/src", site + "/data"):
        ensure_dir_existing(sftp, path)
    for name in (".htaccess", "index.php", "data/.htaccess"):
        info = sftp.lstat(posixpath.join(site, name))
        if not stat.S_ISREG(info.st_mode):
            raise RuntimeError("Expected existing site marker")
    with sftp.open(posixpath.join(site, "index.php"), "rb") as source:
        prefix = source.read(12288)
    if b"PublicationRepository" not in prefix or b"/src/helpers.php" not in prefix:
        raise RuntimeError("Unexpected website identity")


def remote_changes(sftp, site: str, files):
    changed = []
    for name, local in files:
        remote_parent(sftp, site, name, create=False)
        target = posixpath.join(site, name)
        if not remote_matches(sftp, local, target):
            changed.append((name, local))
    return changed


def create_nested(sftp, base: str, name: str) -> str:
    remote_parent(sftp, base, name, create=True)
    return posixpath.join(base, name)


def test_atomic_rename(sftp, stage: str) -> None:
    before, after = stage + "/.test-first", stage + "/.test-second"
    try:
        with sftp.open(before, "wb") as check:
            check.write(b"check")
        sftp.posix_rename(before, after)
    finally:
        for path in (before, after):
            if remote_entry(sftp, path) is not None:
                sftp.remove(path)


def smoke_test() -> None:
    for url in ("https://psilocybin-research.com/health.php", "https://psilocybin-research.com/"):
        with urlopen(url, timeout=25) as response:
            if response.status != 200:
                raise RuntimeError("Production health verification failed")


def publish(sftp, site: str, private: str, changed) -> None:
    run_id = os.environ.get("GITHUB_RUN_ID", "")
    if not re.fullmatch(r"[0-9]{1,24}", run_id):
        raise RuntimeError("Deployment must run in GitHub Actions")
    stage = private + "/release-" + run_id
    if remote_entry(sftp, stage) is not None:
        raise RuntimeError("Release staging directory already exists")
    sftp.mkdir(stage)
    sftp.chmod(stage, 0o700)
    next_root = stage + "/next"
    old_root = stage + "/previous"
    ensure_dir(sftp, next_root)
    ensure_dir(sftp, old_root)
    test_atomic_rename(sftp, stage)
    originals = {}
    for name, local in changed:
        destination = posixpath.join(site, name)
        staged = create_nested(sftp, next_root, name)
        sftp.put(str(local), staged, confirm=True)
        if not remote_matches(sftp, local, staged):
            raise RuntimeError("Staged release checksum mismatch")
        previous = remote_entry(sftp, destination)
        originals[name] = previous is not None
        if previous is not None:
            backup = create_nested(sftp, old_root, name)
            copy_sftp(sftp, destination, backup)
            if remote_hash(sftp, backup) != remote_hash(sftp, destination):
                raise RuntimeError("Unable to verify backed-up version")

    applied = []
    try:
        for name, _ in changed:
            destination = posixpath.join(site, name)
            remote_parent(sftp, site, name, create=True)
            sftp.posix_rename(posixpath.join(next_root, name), destination)
            applied.append(name)
        smoke_test()
    except Exception:
        rollback_failed = False
        for name in reversed(applied):
            destination = posixpath.join(site, name)
            try:
                if originals[name]:
                    sftp.posix_rename(posixpath.join(old_root, name), destination)
                else:
                    sftp.remove(destination)
            except Exception:
                rollback_failed = True
        if rollback_failed:
            raise RuntimeError("Deployment failed and automatic rollback was incomplete")
        raise RuntimeError("Deployment failed; replaced files were rolled back")
    print(f"Deployment passed smoke checks; {len(changed)} files updated.")
    print("Previous versions retained in the private release directory.")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--mode", choices=("plan", "verify", "deploy"), required=True)
    args = parser.parse_args()
    project = Path(__file__).resolve().parents[2]
    files = manifest(project)
    print(f"Release includes {len(files)} explicitly permitted source files.")
    if args.mode == "plan":
        return
    if os.environ.get("GITHUB_REF") != "refs/heads/main":
        raise RuntimeError("Remote operations require main")
    site, private = release_paths(
        os.environ.get("ALFA_DOCUMENT_ROOT", ""),
        os.environ.get("ALFA_PRIVATE_RELEASE_ROOT", ""),
    )
    if args.mode == "deploy" and (
        os.environ.get("ALFA_DEPLOY_APPROVAL") != "DEPLOY"
        or os.environ.get("ALFA_BACKUP_CONFIRMED") != "true"
        or os.environ.get("ALFA_PRODUCTION_DEPLOY_ENABLED") != "yes"
    ):
        raise RuntimeError("Deployment approval and verified backup are required")
    client = connect()
    try:
        with client.open_sftp() as sftp:
            verify_target(sftp, site, private)
            changed = remote_changes(sftp, site, files)
            print(f"Preflight passed. {len(changed)} tracked files differ from production.")
            for name, _ in changed:
                print(f"Change: {name}")
            if args.mode == "deploy" and changed:
                publish(sftp, site, private, changed)
    finally:
        client.close()


if __name__ == "__main__":
    try:
        main()
    except Exception:
        print("Release check failed. See protected deployment documentation.", file=sys.stderr)
        raise SystemExit(1)
