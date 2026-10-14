#!/usr/bin/env bash
# Builds the installable module zip from the working tree (tracked + untracked, respecting .gitignore).
# Only what runs in the shop and its documentation: no tests, dev stack or helper scripts.
# Usage: scripts/package.sh [output-dir]   (default: dist/)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT_DIR="$(mkdir -p "${1:-$ROOT/dist}" && cd "${1:-$ROOT/dist}" && pwd)"
VERSION="$(sed -n 's/.*"version": *"\([^"]*\)".*/\1/p' "$ROOT/composer.json")"
ZIP="$OUT_DIR/bluebarry-magento2-module-$VERSION.zip"

cd "$ROOT"
rm -f "$ZIP"
git ls-files -co --exclude-standard \
    | grep -vE '^(dev/|dist/|Test/|scripts/|\.github/|\.gitattributes|\.gitignore|copy\.sh$|README\.pdf$)' \
    | while read -r f; do [ -f "$f" ] && echo "$f"; done \
    | zip -q -X "$ZIP" -@

echo "$ZIP"
