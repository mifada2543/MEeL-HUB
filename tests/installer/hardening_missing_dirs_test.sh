#!/usr/bin/env bash
# Regression test for bug 4: hardening must skip directories that are not on
# disk (for example node_modules before `npm install`) instead of failing.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/hardening.sh
source "$ROOT/lib/install/hardening.sh"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

PROJECT_ROOT="$TMP/project"
export PROJECT_ROOT
mkdir -p "$PROJECT_ROOT/tools" "$PROJECT_ROOT/storage" "$PROJECT_ROOT/assets/dict"

hardening_run

for dir in tools storage assets/dict; do
    [ -f "$PROJECT_ROOT/$dir/.htaccess" ]
    grep -q 'Require all denied\|Deny from all' "$PROJECT_ROOT/$dir/.htaccess"
done
[ ! -e "$PROJECT_ROOT/vendor" ]
[ ! -e "$PROJECT_ROOT/node_modules" ]

echo "hardening missing dirs: OK"
