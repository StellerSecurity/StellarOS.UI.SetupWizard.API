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
    # These PHP API deployments have no Node runtime dependency. ArchiveFiles
    # packages the workspace, so stale frontend dependencies must fail closed.
    node_modules = root / 'node_modules'
    if node_modules.exists() or node_modules.is_symlink():
        raise ValueError('frontend dependency directory remains in the deployment directory')
    vendor = root / 'vendor'
    for directory in (vendor, vendor / 'composer'):
        if directory.is_symlink() or not directory.is_dir():
            raise ValueError('invalid vendor directory')
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
    # Metapackages have no files. Every other locked package must occupy its
    # standard vendor/name directory; custom installer layouts need explicit
    # support rather than an unchecked install-path from installed.json.
    physical = {name for name, row in production.items()
                if row.get('type') != 'metapackage'}
    namespaces = {name.split('/')[0] for name in physical}
    for name in physical:
        parent = vendor / name.split('/')[0]
        package = vendor / name
        if parent.is_symlink() or not parent.is_dir() or package.is_symlink() or not package.is_dir():
            raise ValueError('missing or linked production package directory')
    for entry in vendor.iterdir():
        if entry.name in ('composer', 'bin'):
            if entry.is_symlink() or not entry.is_dir():
                raise ValueError('invalid Composer directory')
            continue
        if entry.name == 'autoload.php':
            if entry.is_symlink() or not entry.is_file():
                raise ValueError('invalid Composer autoloader')
            continue
        if entry.name not in namespaces or entry.is_symlink() or not entry.is_dir():
            raise ValueError('unregistered vendor entry')
    for namespace in namespaces | {'composer'}:
        parent = vendor / namespace
        for entry in parent.iterdir():
            if namespace == 'composer' and entry.is_file() and not entry.is_symlink():
                # Composer's generated metadata shares this namespace with
                # normal composer/* packages. Package directories still count.
                continue
            if namespace + '/' + entry.name not in physical or entry.is_symlink() or not entry.is_dir():
                raise ValueError('unregistered package directory')
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
