#!/usr/bin/env bash
# Regression test for bug 2: creating the first admin must select the target
# database. Before the fix MySQL returned "No database selected" and the
# installer aborted, with stderr hidden.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/database.sh
source "$ROOT/lib/install/database.sh"

HOST="${MEEL_TEST_DB_HOST:-127.0.0.1}"
USER="${MEEL_TEST_DB_USER:-root}"
PASS="${MEEL_TEST_DB_PASS:-}"
DB=$(meel_test_db_name)

MYSQL_BIN=$(meel_test_mysql_bin)
export MYSQL_BIN
PHP_BIN="${PHP_BIN:-$(command -v php)}"
export PHP_BIN
DRY_RUN=false
export DRY_RUN

cleanup() {
    meel_test_db_drop "$HOST" "$USER" "$PASS" "$DB" >/dev/null 2>&1 || true
}
trap cleanup EXIT

meel_test_db_create "$HOST" "$USER" "$PASS" "$DB"
"$MYSQL_BIN" -h "$HOST" -u "$USER" ${PASS:+-p"$PASS"} "$DB" \
    < "$ROOT/database/schema.sql"

db_create_admin_user "$HOST" "$USER" "$PASS" "$DB" "installer-admin" "InstallerPass123!"

COUNT=$(meel_test_mysql "$HOST" "$USER" "$PASS" -N -B \
    -e "SELECT COUNT(*) FROM \`$DB\`.users WHERE role='admin';")
NAME=$(meel_test_mysql "$HOST" "$USER" "$PASS" -N -B \
    -e "SELECT username FROM \`$DB\`.users WHERE role='admin' LIMIT 1;")
[ "$COUNT" = "1" ]
[ "$NAME" = "installer-admin" ]

# Re-running must be idempotent, not insert a second admin.
db_create_admin_user "$HOST" "$USER" "$PASS" "$DB" "installer-admin" "InstallerPass123!" >/dev/null
COUNT=$(meel_test_mysql "$HOST" "$USER" "$PASS" -N -B \
    -e "SELECT COUNT(*) FROM \`$DB\`.users WHERE role='admin';")
[ "$COUNT" = "1" ]

echo "database admin creation: OK"
