#!/usr/bin/env python3
"""Copy/check publishable source against an existing isolated Cte-test checkout."""
import argparse
import hashlib
import shutil
import subprocess
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--check', action='store_true')
parser.add_argument('checkout', type=Path)
args = parser.parse_args()
source = Path(__file__).resolve().parents[1]
target = args.checkout.resolve()
if source == target or source in target.parents or target in source.parents:
    parser.error('Use an independent checkout outside the source tree.')
if not (target / '.git').exists():
    parser.error('Target must be an existing Git checkout.')
remote = subprocess.check_output(['git', '-C', str(target), 'remote', 'get-url', 'origin'], text=True).strip().lower().removesuffix('.git')
if remote not in {'https://github.com/turkerkagan/cte-test', 'git@github.com:turkerkagan/cte-test'}:
    parser.error('Target origin must be turkerkagan/Cte-test.')
directories = {'app', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'tests', 'docs', 'scripts', 'deploy', '.github'}
files = {'.editorconfig', '.gitattributes', '.gitignore', '.npmrc', '.env.example', '.dockerignore', 'AGENTS.md', 'README.md', 'artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'phpunit.xml', 'vite.config.js', 'Dockerfile', 'railway.json'}

def permitted(name):
    p = Path(name)
    if not (p.parts[0] in directories or name in files):
        return False
    if name.startswith(('public/storage/', 'public/build/')) or name == 'public/hot':
        return False
    if name.startswith('bootstrap/cache/') and p.name != '.gitignore':
        return False
    return p.suffix.lower() not in {'.sqlite', '.sqlite3', '.sql', '.zip', '.log', '.bak'}

names = subprocess.check_output(['git', '-C', str(source), 'ls-files', '--cached', '--others', '--exclude-standard', '-z']).decode().split('\0')
expected = {n for n in names if n and permitted(n) and (source/n).is_file() and not (source/n).is_symlink()}
tracked = subprocess.check_output(['git', '-C', str(target), 'ls-files', '-z']).decode().split('\0')
changed = []
for name in sorted(expected):
    src, dst = source/name, target/name
    if not dst.is_file() or hashlib.sha256(src.read_bytes()).digest() != hashlib.sha256(dst.read_bytes()).digest():
        changed.append(name)
        if not args.check:
            dst.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, dst)
# Only remove previously tracked source files that disappeared from canonical source.
for name in filter(None, tracked):
    if permitted(name) and name not in expected and (target/name).is_file():
        changed.append('removed: '+name)
        if not args.check:
            (target/name).unlink()
print(('Source differs: ' if args.check else 'Source synchronized; changed: ')+str(len(changed)))
for name in changed:
    print(name)
if args.check and changed:
    raise SystemExit(1)
print('Environment files, databases and uploaded files are excluded. No database synchronization performed.')
