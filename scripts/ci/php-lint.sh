#!/usr/bin/env bash
# Cek sintaks semua berkas PHP dengan `php -l`.
# Dipakai oleh CI matrix dan test gate rilis agar aturannya identik.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

failed=0
count=0

while IFS= read -r -d '' file; do
  if ! output="$(php -l "$file" 2>&1)"; then
    printf '%s\n' "$output" >&2
    printf '❌ Syntax error in: %s\n' "$file" >&2
    failed=1
  fi
  count=$((count + 1))
done < <(find . -name '*.php' \
  -not -path './vendor/*' \
  -not -path './.git/*' \
  -not -path './node_modules/*' \
  -not -path './assets/dict/*' \
  -print0)

if [ "$failed" -ne 0 ]; then
  printf '❌ %s berkas PHP diperiksa; ada syntax error.\n' "$count" >&2
  exit 1
fi

printf '✅ Semua %s berkas PHP lolos syntax check (PHP %s).\n' \
  "$count" "$(php -r 'echo PHP_VERSION;')"
