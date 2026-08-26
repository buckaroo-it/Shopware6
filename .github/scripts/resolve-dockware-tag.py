#!/usr/bin/env python3
"""Resolve the newest dockware/shopware image tag for a Shopware series.

The pinned-version problem this solves: when Shopware ships a patch, a
hardcoded matrix keeps testing the previous one and the new release goes
unverified until somebody notices.

dockware publishes a plain multi-arch tag (6.7.13.0) plus per-arch tags
(6.7.13.0-amd64). A fresh release often appears as -amd64 first and only
gains its manifest hours later, so prefer the plain tag and fall back to
-amd64 rather than skipping a release that is already testable.
"""
import json
import re
import sys
import urllib.request

REGISTRY = 'https://hub.docker.com/v2/repositories/dockware/shopware/tags'
MAX_PAGES = 5


def fetch_tags():
    names, url = [], f'{REGISTRY}?page_size=100'
    for _ in range(MAX_PAGES):
        with urllib.request.urlopen(url, timeout=30) as res:
            payload = json.load(res)
        names.extend(t['name'] for t in payload.get('results', []))
        url = payload.get('next')
        if not url:
            break
    return names


def resolve(series, names):
    pattern = re.compile(r'^' + re.escape(series) + r'\.(\d+)\.(\d+)(-amd64)?$')
    versions = {}
    for name in names:
        match = pattern.match(name)
        if not match:
            continue
        key = (int(match.group(1)), int(match.group(2)))
        versions.setdefault(key, set()).add(name)

    if not versions:
        raise SystemExit(f'no dockware tag found for series {series}')

    newest = max(versions)
    candidates = versions[newest]
    plain = f'{series}.{newest[0]}.{newest[1]}'
    # Plain tag is multi-arch; the -amd64 fallback still runs on GitHub runners.
    return plain if plain in candidates else f'{plain}-amd64'


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('usage: resolve-dockware-tag.py <series, e.g. 6.7>')
    print(resolve(sys.argv[1], fetch_tags()))
