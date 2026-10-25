#!/usr/bin/env bash
# End-to-end clean install regression test. It runs the real installer against
# an empty database and a temporary HDD, with no php-fpm process and without
# vendor/ or node_modules/ (the normal first-install state).
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"

HOST="${MEEL_TEST_DB_HOST:-127.0.0.1}"
USER="${MEEL_TEST_DB_USER:-root}"
PASS="${MEEL_TEST_DB_PASS:-}"
DB=$(meel_test_db_name)
ADMIN_USER="installer-admin"
ADMIN_PASS="InstallerPass123!"

TMP=$(mktemp -d)
PROJECT="$TMP/project"
HDD="$TMP/hdd"
BIN="$TMP/bin"
LOG="$TMP/install.log"
RESUME_LOG="$TMP/resume.log"

cleanup() {
    meel_test_db_drop "$HOST" "$USER" "$PASS" "$DB" >/dev/null 2>&1 || true
    rm -rf "$TMP"
}
trap cleanup EXIT

meel_test_copy_tree "$PROJECT"
meel_test_prepare_bin "$BIN"
mkdir -p "$HDD"
meel_test_db_create "$HOST" "$USER" "$PASS" "$DB"

PHP_BIN="${PHP_BIN:-$(command -v php)}"
ARGS=(
    --yes
    --skip-apt
    --db-host="$HOST"
    --db-name="$DB"
    --db-user="$USER"
    --hdd="$HDD"
    --web-user="$(id -un)"
    --admin-user="$ADMIN_USER"
    --admin-pass="$ADMIN_PASS"
    --no-create-app-user
    --verify-no-color
)
if [ -n "$PASS" ]; then
    ARGS+=(--db-pass="$PASS")
fi

run_install() {
    local log="$1"
    shift
    set +e
    (
        cd "$PROJECT" || exit 1
        PATH="$BIN:$PATH" \
        MEEL_FPM_POOL_CONFIGS="$TMP/no-such-pool.conf" \
        PHP_BIN="$PHP_BIN" \
        ./install.sh "${ARGS[@]}" "$@" >"$log" 2>&1
    )
    local rc=$?
    set -e
    return "$rc"
}

if ! run_install "$LOG"; then
    echo "clean install failed" >&2
    tail -120 "$LOG" >&2
    exit 1
fi

# Fresh-install state: neither dependency directory should exist.
[ ! -e "$PROJECT/node_modules" ]
[ ! -e "$PROJECT/vendor" ]

# Admin created in the target database, not in the server's default schema.
ADMIN_COUNT=$(meel_test_mysql "$HOST" "$USER" "$PASS" -N -B \
    -e "SELECT COUNT(*) FROM \`$DB\`.users WHERE username='$ADMIN_USER' AND role='admin';")
[ "$ADMIN_COUNT" = "1" ]

# The three upload symlinks must point at this install's HDD.
for MODULE in video music books; do
    [ -L "$PROJECT/$MODULE/upload" ]
    [ "$(readlink "$PROJECT/$MODULE/upload")" = "$HDD/$MODULE/upload" ]
    [ -f "$HDD/$MODULE/upload/.htaccess" ]
done
[ "$(readlink "$PROJECT/data_drive/public")" = "$HDD/drive/public" ]

# Hardening applies to directories that exist and leaves absent ones absent.
for DIR in tools storage assets/dict; do
    [ -f "$PROJECT/$DIR/.htaccess" ]
    grep -q 'Require all denied\|Deny from all' "$PROJECT/$DIR/.htaccess"
done

# check_deploy must reject a symlink that escapes the configured HDD, not just
# print a warning and still exit successfully.
CHECK_LOG="$TMP/check-deploy-wrong.log"
rm -f "$PROJECT/video/upload"
ln -s "$HDD/drive/private_admins" "$PROJECT/video/upload"
set +e
(
    cd "$PROJECT" || exit 1
    "$PHP_BIN" tests/check_deploy.php --hdd="$HDD" --no-color
) >"$CHECK_LOG" 2>&1
CHECK_RC=$?
set -e
if [ "$CHECK_RC" -eq 0 ]; then
    echo "check_deploy accepted a symlink outside MEEL_HDD_BASE" >&2
    tail -40 "$CHECK_LOG" >&2
    exit 1
fi
grep -q 'FAIL.*target' "$CHECK_LOG"
rm -f "$PROJECT/video/upload"
ln -s "$HDD/video/upload" "$PROJECT/video/upload"

# Regression assertions for the logs and final verdict.
if grep -qi 'target mismatch' "$LOG"; then
    echo "installer reported a symlink target mismatch" >&2
    exit 1
fi
if grep -qE '\$\{(db|db_name|existing_user|app_user|admin_user|host)\}' "$LOG"; then
    echo "installer printed a literal variable placeholder" >&2
    exit 1
fi
grep -q 'FAIL: 0' "$LOG"
grep -q 'Step 6 (Security Hardening) completed' "$LOG"
grep -q 'Step 8 (Deployment Verification) completed' "$LOG"

# migrate_verify must query the table the migration runner actually writes.
if ! grep -q 'Migration verification passed' "$LOG"; then
    echo "migration verification did not pass" >&2
    exit 1
fi
if grep -q 'Could not verify migration version' "$LOG"; then
    echo "migration verification reported a false failure" >&2
    exit 1
fi

# --resume-from skips the config step, but the final summary must still show
# the --hdd value instead of "not set".
if ! run_install "$RESUME_LOG" --resume-from=8; then
    echo "resumed summary run failed" >&2
    tail -80 "$RESUME_LOG" >&2
    exit 1
fi
grep -q "Storage media     : $HDD" "$RESUME_LOG"

echo "clean install: OK"
