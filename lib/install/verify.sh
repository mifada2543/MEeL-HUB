#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Deployment verification for MEeL installer
# Source: source "$(dirname "$0")/lib/install/verify.sh"
# Requires: common.sh

# ─── Run check_deploy.php ───
verify_run_check_deploy() {
    local url="${VERIFY_URL:-}"
    local hdd_override="${VERIFY_HDD_OVERRIDE:-}"
    local no_color="${VERIFY_NO_COLOR:-false}"

    # In dry-run mode, use the HDD override from config if available
    if [ "${DRY_RUN:-false}" = "true" ] && [ -z "$hdd_override" ] && [ -n "${HDD_BASE:-}" ]; then
        hdd_override="$HDD_BASE"
        log_verbose "DRY-RUN: Using HDD override from config: $hdd_override"
    fi

    log_step "Running deployment verification"

    if [ ! -f "tests/check_deploy.php" ]; then
        log_fail "tests/check_deploy.php not found"
        return 1
    fi

    local cmd=("$PHP_BIN" "tests/check_deploy.php")
    [ -n "$url" ] && cmd+=("--url=$url")
    [ -n "$hdd_override" ] && cmd+=("--hdd=$hdd_override")
    [ "$no_color" = "true" ] && cmd+=("--no-color")

    log_verbose "Running: ${cmd[*]}"

    if "${cmd[@]}"; then
        log_ok "Deployment verification PASSED"
        return 0
    else
        local exit_code=$?
        log_fail "Deployment verification FAILED (exit code $exit_code)"
        return 1
    fi
}

# ─── Print summary ───
verify_summary() {
    local check_ok="${1:-0}"

    echo ""
    echo "  Login admin     : ${ADMIN_USER:-unknown}"
    echo "  Database         : ${DB_NAME:-MEeL}@${DB_HOST:-localhost}"
    echo "  Environment      : ${ENV_CHOICE:-production} (APP_DEBUG=${DEBUG_CHOICE:-false})"
    echo "  Storage media     : ${HDD_BASE:-not set}"
    echo ""

    if [ "$check_ok" -eq 1 ]; then
        echo -e "${C_GREEN}${C_BOLD}═══════════════════════════════════════════════════${C_RESET}"
        echo -e "${C_GREEN}${C_BOLD} Instalasi selesai — deployment sehat!${C_RESET}"
        echo -e "${C_GREEN}${C_BOLD}═══════════════════════════════════════════════════${C_RESET}"
        echo ""
        echo "  Ada WARN di verifikasi di atas? Lihat docs/id/installation.md atau"
        echo "  docs/id/troubleshooting.md untuk catatan."
        echo ""
        return 0
    else
        echo -e "${C_RED}${C_BOLD}═══════════════════════════════════════════════════${C_RESET}"
        echo -e "${C_RED}${C_BOLD} Instalasi selesai TAPI verifikasi deployment GAGAL${C_RESET}"
        echo -e "${C_RED}${C_BOLD} — server BELUM siap dipakai.${C_RESET}"
        echo -e "${C_RED}${C_BOLD}═══════════════════════════════════════════════════${C_RESET}"
        echo ""
        echo "  Perbaiki penyebab FAIL di output check_deploy di atas (lihat"
        echo "  docs/id/installation.md atau docs/id/troubleshooting.md), lalu"
        echo "  jalankan ulang verifikasi:"
        echo "    php tests/check_deploy.php"
        echo ""
        return 1
    fi
}

# ─── Main verify step ───
verify_run() {
    local check_ok=1  # 1 = PASS, 0 = FAIL

    if ! verify_run_check_deploy; then
        check_ok=0
    fi

    verify_summary $check_ok
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f verify_run_check_deploy verify_summary verify_run 2>/dev/null || true
fi