#!/usr/bin/env bash
# Regression test for bug 5: install logs must render actual values, not
# literal \${...} placeholders, and the final summary must fall back to the
# --hdd override when the config step did not run (for example --resume-from).
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/verify.sh
source "$ROOT/lib/install/verify.sh"

if matches=$(grep -rnE 'log_(ok|warn|fail|info).*\\\$\{' \
    --include='*.sh' "$ROOT/lib/install"); then
    printf 'literal placeholders found in log messages:\n%s\n' "$matches" >&2
    exit 1
fi

unset HDD_BASE
HDD_OVERRIDE="/tmp/meel-summary-override"
OUTPUT=$(verify_summary 1)
case "$OUTPUT" in
    *"Storage media     : $HDD_OVERRIDE"*) ;;
    *)
        printf 'summary did not use HDD_OVERRIDE:\n%s\n' "$OUTPUT" >&2
        exit 1
        ;;
esac

echo "log placeholders and summary: OK"
