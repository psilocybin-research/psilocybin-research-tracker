#!/usr/bin/env python3
"""Validate a release command against the currently checked-out main commit."""

from __future__ import annotations

import os
import re
import subprocess
import sys

COMMAND = re.compile(r"\A/release (verify|deploy) ([0-9a-f]{40})\Z")


def parse_command(body: str, current_sha: str) -> tuple[str, str]:
    match = COMMAND.fullmatch(body)
    if match is None:
        raise ValueError("Invalid release command")
    mode, sha = match.groups()
    if sha != current_sha:
        raise ValueError("Release command does not target the current main commit")
    return mode, sha


def main() -> None:
    actor = os.environ.get("RELEASE_ACTOR", "")
    association = os.environ.get("RELEASE_ASSOCIATION", "")
    if actor != "psilocybin-research" or association != "OWNER":
        raise ValueError("Only the repository owner can request a release")
    if os.environ.get("GITHUB_REF") != "refs/heads/main":
        raise ValueError("Release commands require the default branch")

    current_sha = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], text=True,
    ).strip()
    mode, sha = parse_command(os.environ.get("RELEASE_COMMENT", ""), current_sha)
    output = os.environ.get("GITHUB_OUTPUT", "")
    if not output:
        raise ValueError("GitHub Actions output file is required")
    with open(output, "a", encoding="utf-8") as stream:
        stream.write("valid=true\n")
        stream.write(f"mode={mode}\n")
        stream.write(f"sha={sha}\n")
    print(f"Authorized {mode} request for the current main commit.")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, subprocess.CalledProcessError):
        print("Release command rejected.", file=sys.stderr)
        raise SystemExit(1)
