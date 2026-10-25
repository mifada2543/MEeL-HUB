#!/usr/bin/env bash
# Regression test for bug 3: loop variables must not leak out of
# storage_copy_htaccess and clobber storage_create_symlinks' target.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/storage.sh
source "$ROOT/lib/install/storage.sh"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

PROJECT_ROOT="$TMP/project"
HDD_BASE="$TMP/hdd"
export PROJECT_ROOT HDD_BASE
DRY_RUN=false
export DRY_RUN

mkdir -p "$PROJECT_ROOT/video" "$PROJECT_ROOT/music" "$PROJECT_ROOT/books" \
    "$PROJECT_ROOT/data_drive/public"
cp "$ROOT/data_drive/.htaccess" "$PROJECT_ROOT/data_drive/.htaccess"

storage_create_dirs >/dev/null

# If storage_copy_htaccess leaks its loop variable, this sentinel is replaced
# by the last target path and the assertion fails.
probe_scope() {
    local target="sentinel"
    storage_copy_htaccess >/dev/null
    [ "$target" = "sentinel" ]
}
probe_scope

storage_create_symlinks >/dev/null

MODULE=""
for MODULE in video music books; do
    [ "$(readlink "$PROJECT_ROOT/$MODULE/upload")" = "$HDD_BASE/$MODULE/upload" ]
    [ -f "$HDD_BASE/$MODULE/upload/.htaccess" ]
done
[ "$(readlink "$PROJECT_ROOT/data_drive/public")" = "$HDD_BASE/drive/public" ]

# A wrong target must now be a hard failure, not a warning that still lets the
# installer print "deployment sehat".
rm -f "$PROJECT_ROOT/video/upload"
ln -s "$HDD_BASE/drive/private_admins" "$PROJECT_ROOT/video/upload"
if storage_verify >/dev/null 2>&1; then
    echo "storage_verify accepted a wrong upload target" >&2
    exit 1
fi

echo "storage symlinks: OK"
