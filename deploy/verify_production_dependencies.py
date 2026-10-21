#!/usr/bin/env python3
"""Check Composer's production installation before archiving. No package execution."""
import json
from pathlib import Path
import re
import sys


def read_json(path):
    def pairs(rows):
        result = {}
        for key, value in rows:
            if key in result:
                raise ValueError('duplicate JSON key')
            result[key] = value
        return result
    if path.is_symlink() or not path.is_file() or path.stat().st_size > 16 * 1024 * 1024:
        raise ValueError('invalid dependency metadata file')
    return json.loads(path.read_bytes(), object_pairs_hook=pairs)


def package_map(rows):
    if not isinstance(rows, list):
        raise ValueError('invalid package inventory')
    result = {}
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError('invalid package entry')
        name = row.get('name')
        if not isinstance(name, str) or not re.fullmatch(r'[a-z0-9_.-]+/[a-z0-9_.-]+', name):
            raise ValueError('invalid package name')
        if any(part in ('.', '..') for part in name.split('/')) or name in result:
            raise ValueError('duplicate or invalid package name')
        if not isinstance(row.get('version'), str):
            raise ValueError('missing package version')
        result[name] = row
    return result


def verify(root):
    lock = read_json(root / 'composer.lock')
    installed = read_json(root / 'vendor/composer/installed.json')
    if not isinstance(lock, dict) or not isinstance(installed, dict) or installed.get('dev') is not False:
        raise ValueError('production Composer install required')
    production = package_map(lock.get('packages'))
    development = package_map(lock.get('packages-dev'))
    actual = package_map(installed.get('packages'))
    if set(actual) != set(production) or set(production) & set(development):
        raise ValueError('installed packages do not match the production lock inventory')
    for name, expected in production.items():
        row = actual[name]
        # Compare the immutable source/dist identity too, not only the version.
        for field in ('version', 'source', 'dist'):
            if row.get(field) != expected.get(field):
                raise ValueError('installed package identity differs from composer.lock')
    for name in development:
        path = root / 'vendor' / name
        if path.exists() or path.is_symlink():
            raise ValueError('development package remains in the deployment directory')
    allowed_bins = set()
    for row in production.values():
        bins = row.get('bin', [])
        if not isinstance(bins, list) or any(not isinstance(p, str) for p in bins):
            raise ValueError('invalid production binary declaration')
        allowed_bins.update(Path(p).name for p in bins)
    bin_dir = root / 'vendor/bin'
    if bin_dir.is_symlink():
        raise ValueError('invalid Composer binary directory')
    if bin_dir.exists() and any(p.name not in allowed_bins for p in bin_dir.iterdir()):
        raise ValueError('unexpected binary remains in the deployment directory')
    return len(production)


if __name__ == '__main__':
    try:
        count = verify(Path.cwd())
    except (OSError, ValueError, TypeError, KeyError) as error:
        print('Production dependency verification failed: ' + str(error), file=sys.stderr)
        raise SystemExit(1)
    print('Verified production Composer inventory: ' + str(count) + ' packages; no development packages.')
