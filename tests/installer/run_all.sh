#!/usr/bin/env bash
# Run every installer regression test in this directory.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

for TEST in \
    fpm_detection_test.sh \
    database_admin_test.sh \
    storage_symlink_test.sh \
    hardening_missing_dirs_test.sh \
    log_placeholders_test.sh \
    ytdlp_install_test.sh \
    install_clean_test.sh; do
    echo "==> $TEST"
    bash "$HERE/$TEST"
done
