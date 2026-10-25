#!/usr/bin/env bash
# Regression test for bug 1: detect_fpm_user must survive a no-php-fpm
# environment, and --web-user alone must derive its primary group.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/detect.sh
source "$ROOT/lib/install/detect.sh"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
meel_test_make_no_fpm_bin "$TMP/bin"

# No pool config and no php-fpm process: the old pipeline exited 1 under
# `set -euo pipefail` before the fallback ran.
(
    export PATH="$TMP/bin:$PATH"
    export MEEL_FPM_POOL_CONFIGS="$TMP/no-such-pool.conf"
    WEB_USER_OVERRIDE=""
    WEB_GROUP_OVERRIDE=""
    export WEB_USER_OVERRIDE WEB_GROUP_OVERRIDE

    detect_fpm_user
    [ -n "$WEB_USER" ]
    [ -n "$WEB_GROUP" ]
)

# --web-user=... alone must be enough; the group comes from `id -gn`.
EXPECTED_USER=$(id -un)
EXPECTED_GROUP=$(id -gn)
(
    export PATH="$TMP/bin:$PATH"
    export MEEL_FPM_POOL_CONFIGS="$TMP/no-such-pool.conf"
    WEB_USER_OVERRIDE="$EXPECTED_USER"
    WEB_GROUP_OVERRIDE=""
    export WEB_USER_OVERRIDE WEB_GROUP_OVERRIDE

    detect_fpm_user
    [ "$WEB_USER" = "$EXPECTED_USER" ]
    [ "$WEB_GROUP" = "$EXPECTED_GROUP" ]
)

echo "fpm detection: OK"
