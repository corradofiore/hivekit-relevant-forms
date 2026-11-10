#!/usr/bin/env python3
"""Scan every blob in a repository's history for content that must not be public.

The mirror is a subset of a private monorepo, so the interesting failures are
not exotic: a file copied from the backend into the plugin, a credential left in
a fixture, an internal hostname in a comment. A path allowlist cannot see inside
a file, so this reads them all — the whole history, not just HEAD, because a
string that was committed once stays reachable in the published objects.

Usage: guard-content.py [repo]

Exits 0 when clean, 1 listing every offence as "<path>: <marker>".
"""

from __future__ import annotations

import re
import subprocess
import sys
from pathlib import Path

# Laravel application code, not Laravel-adjacent words. composer.lock is
# exempted below because it lists Laravel-family *dev tools* as Composer
# package metadata, which is not code and never ships in the release zip.
MARKERS: dict[str, re.Pattern[bytes]] = {
    "laravel namespace": re.compile(rb"namespace\s+App\s*;"),
    "laravel framework use": re.compile(rb"use\s+Illuminate\\"),
    "laravel framework fqcn": re.compile(rb"Illuminate\\[A-Z]"),
    "filament": re.compile(rb"Filament\\[A-Z]"),
    "laravel routes": re.compile(rb"Route::(get|post|put|patch|delete|apiResource)"),
    "laravel controller": re.compile(rb"extends\s+Controller\b"),
    "laravel app path": re.compile(rb"app/(Http|Models|Console|Jobs|Providers)/"),
    "laravel migrations": re.compile(rb"database/(migrations|seeders)/"),
    "laravel views": re.compile(rb"resources/views/"),
    "artisan command": re.compile(rb"artisan\s+(migrate|queue|schedule|test)"),
    "env secret assignment": re.compile(rb"^(APP_KEY|DB_PASSWORD|QUEUE_CONNECTION|REDIS_PASSWORD)=", re.M),
    "private key": re.compile(rb"-----BEGIN [A-Z ]*PRIVATE KEY-----"),
    "gitlab host": re.compile(rb"gitlab\.deep9\.se"),
    "gitlab ce project": re.compile(rb"sentinel-form/"),
    "monorepo name": re.compile(rb"wp-sentinel-form"),
    "ci registry": re.compile(rb"CI_REGISTRY"),
    "internal deploy job": re.compile(rb"deploy:qa"),
    "dev host": re.compile(rb"172\.20\.0\.13"),
    "gitlab token": re.compile(rb"glpat-[0-9A-Za-z_\-]{20,}"),
    "stripe live key": re.compile(rb"\b(sk|rk)_live_[0-9a-zA-Z]{10,}"),
}

# Paths whose contents are exempt, with the reason recorded in the plan and in
# docs/github-mirror.md.
EXEMPT = {"composer.lock"}

# This file and guard.sh both quote the strings they forbid, so they must not
# match themselves.
SELF = {"scripts/guard-content.py", "scripts/guard.sh"}


def git(repo: str, *args: str) -> bytes:
    return subprocess.run(
        ["git", "-C", repo, *args], capture_output=True, check=True
    ).stdout


def main() -> int:
    repo = sys.argv[1] if len(sys.argv) > 1 else "."

    listing = git(repo, "rev-list", "--objects", "--all").decode("utf-8", "replace")
    objects: dict[str, str] = {}
    for line in listing.splitlines():
        parts = line.split(maxsplit=1)
        if len(parts) == 2:
            objects[parts[0]] = parts[1]

    if not objects:
        print("no objects in history")
        return 0

    check = subprocess.run(
        ["git", "-C", repo, "cat-file", "--batch-check=%(objecttype) %(objectname)"],
        input="\n".join(objects).encode(),
        capture_output=True,
        check=True,
    ).stdout.decode().splitlines()

    blobs = [line.split()[1] for line in check if line.split() and line.split()[0] == "blob"]
    payload = subprocess.run(
        ["git", "-C", repo, "cat-file", "--batch"],
        input="\n".join(blobs).encode(),
        capture_output=True,
        check=True,
    ).stdout

    offences: list[str] = []
    index = 0
    total = len(payload)

    while index < total:
        newline = payload.index(b"\n", index)
        header = payload[index:newline].split()
        if len(header) != 3 or header[1] != b"blob":
            break
        size = int(header[2])
        content = payload[newline + 1 : newline + 1 + size]
        index = newline + 1 + size + 1

        path = objects.get(header[0].decode(), "(unknown path)")
        if path in EXEMPT or path in SELF:
            continue

        for name, pattern in MARKERS.items():
            if pattern.search(content):
                offences.append(f"{path}: {name}")

    if offences:
        print(f"{len(offences)} forbidden content match(es) in history:", file=sys.stderr)
        for offence in sorted(set(offences)):
            print(f"  x {offence}", file=sys.stderr)
        return 1

    print(f"content guard passed: {len(blobs)} blob(s) scanned, {len(objects)} object(s)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
