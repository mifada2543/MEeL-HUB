#!/usr/bin/env bash
# shellcheck shell=bash
set -euo pipefail

# ─── MEeL Installer — Modular Orchestrator ───
# This is the main entry point. It sources modules from lib/install/ and
# executes them in sequence. Each module is independent and testable.
# ─── Load core modules ───
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$SCRIPT_DIR"
export PROJECT_ROOT
source "$SCRIPT_DIR/lib/install/common.sh"
source "$SCRIPT_DIR/lib/install/detect.sh"
# ─── Parse command line arguments ───
parse_args() {
    ASSUME_YES=false
    SKIP_APT=false
    SKIP_YTDLP=false
    DRY_RUN=false
    VERBOSE=false
    STEP=0
    RESUME_FROM=1
    HDD_OVERRIDE=""
    XSENDFILE_OVERRIDE=""
    ENV_OVERRIDE=""
    TRUST_PROXY_OVERRIDE=""
    VHOST_OVERRIDE=""
    WEB_USER_OVERRIDE=""
    DB_HOST_OVERRIDE=""
    DB_NAME_OVERRIDE=""
    DB_USER_OVERRIDE=""
    DB_PASS_OVERRIDE=""
    ADMIN_USER_OVERRIDE=""
    ADMIN_PASS_OVERRIDE=""
    CREATE_APP_USER_OVERRIDE=""
    ENABLE_REWRITE_OVERRIDE=""
    VERIFY_URL_OVERRIDE=""
    VERIFY_HDD_OVERRIDE=""
    VERIFY_NO_COLOR_OVERRIDE=""
    while [ $# -gt 0 ]; do
        case "$1" in
            --yes|-y) ASSUME_YES=true ;;
            --skip-apt) SKIP_APT=true ;;
            --skip-ytdlp) SKIP_YTDLP=true ;;
            --dry-run) DRY_RUN=true; VERBOSE=true ;;
            --verbose|-v) VERBOSE=true ;;
            --step=*) STEP="${1#--step=}" ;;
            --resume-from=*) RESUME_FROM="${1#--resume-from=}" ;;
            --hdd=*) HDD_OVERRIDE="${1#--hdd=}" ;;
            --xsendfile) XSENDFILE_OVERRIDE=1 ;;
            --no-xsendfile) XSENDFILE_OVERRIDE=0 ;;
            --env=*) ENV_OVERRIDE="${1#--env=}" ;;
            --trust-proxy) TRUST_PROXY_OVERRIDE=1 ;;
            --no-trust-proxy) TRUST_PROXY_OVERRIDE=0 ;;
            --vhost=*) VHOST_OVERRIDE="${1#--vhost=}" ;;
            --web-user=*) WEB_USER_OVERRIDE="${1#--web-user=}" ;;
            --db-host=*) DB_HOST_OVERRIDE="${1#--db-host=}" ;;
            --db-name=*) DB_NAME_OVERRIDE="${1#--db-name=}" ;;
            --db-user=*) DB_USER_OVERRIDE="${1#--db-user=}" ;;
            --db-pass=*) DB_PASS_OVERRIDE="${1#--db-pass=}" ;;
            --admin-user=*) ADMIN_USER_OVERRIDE="${1#--admin-user=}" ;;
            --admin-pass=*) ADMIN_PASS_OVERRIDE="${1#--admin-pass=}" ;;
            --create-app-user) CREATE_APP_USER_OVERRIDE=1 ;;
            --no-create-app-user) CREATE_APP_USER_OVERRIDE=0 ;;
            --enable-rewrite) ENABLE_REWRITE_OVERRIDE=1 ;;
            --disable-rewrite) ENABLE_REWRITE_OVERRIDE=0 ;;
            --verify-url=*) VERIFY_URL_OVERRIDE="${1#--verify-url=}" ;;
            --verify-hdd=*) VERIFY_HDD_OVERRIDE="${1#--verify-hdd=}" ;;
            --verify-no-color) VERIFY_NO_COLOR_OVERRIDE=true ;;
            --help|-h)
                show_help
                exit 0
                ;;
            *)
                log_die "Unknown argument: $1 (see --help)"
                ;;
        esac
        shift
    done
    # Export for modules
    export ASSUME_YES SKIP_APT SKIP_YTDLP DRY_RUN VERBOSE STEP RESUME_FROM
    export HDD_OVERRIDE XSENDFILE_OVERRIDE ENV_OVERRIDE TRUST_PROXY_OVERRIDE
    export VHOST_OVERRIDE WEB_USER_OVERRIDE DB_HOST_OVERRIDE DB_NAME_OVERRIDE
    export DB_USER_OVERRIDE DB_PASS_OVERRIDE ADMIN_USER_OVERRIDE ADMIN_PASS_OVERRIDE
    export CREATE_APP_USER_OVERRIDE ENABLE_REWRITE_OVERRIDE
    export VERIFY_URL_OVERRIDE VERIFY_HDD_OVERRIDE VERIFY_NO_COLOR_OVERRIDE
}
# ─── Help text ───
show_help() {
    cat <<'HELP'
MEeL Installer — Modular, idempotent, testable

Usage: ./install.sh [OPTIONS]

Options:
  --yes, -y                    Non-interactive, accept all defaults
  --skip-apt                   Skip system package installation
  --skip-ytdlp                 Skip automatic yt-dlp download
  --dry-run                    Print actions without executing (implies --verbose)
  --verbose, -v                Verbose output (show commands before execution)
  --step=N                     Run only step N (1=deps, 2=db, 3=config, 4=storage,
                               5=apache, 6=hardening, 7=migrate, 8=verify)
  --resume-from=N              Resume from step N (skip completed steps)
  --hdd=PATH                   Override MEEL_HDD_BASE (storage location)
  --xsendfile                  Enable MEEL_USE_XSENDFILE (requires mod_xsendfile)
  --no-xsendfile               Disable MEEL_USE_XSENDFILE
  --env=production|development Set MEEL_ENV
  --trust-proxy                Enable MEEL_TRUST_PROXY_HEADERS
  --no-trust-proxy             Disable MEEL_TRUST_PROXY_HEADERS
  --vhost=DOMAIN               Create VirtualHost for DOMAIN
  --web-user=USER              Override detected web server user
  --db-host=HOST               Database host (default: localhost)
  --db-name=NAME               Database name (default: MEeL)
  --db-user=USER               Database user (default: root)
  --db-pass=PASS               Database password (default: empty)
  --admin-user=USER            Admin username (required in --yes mode)
  --admin-pass=PASS            Admin password (required in --yes mode)
  --create-app-user            Create dedicated DB user (default: yes if root)
  --no-create-app-user         Skip dedicated DB user creation
  --enable-rewrite             Enable mod_rewrite (default: yes)
  --disable-rewrite            Disable mod_rewrite
  --verify-url=URL             URL for deployment verification (default: auto)
  --verify-hdd=PATH            Override HDD for verification
  --verify-no-color            Disable colors in verification output
  --help, -h                   Show this help

Steps:
  1. deps       — System dependencies (PHP, extensions, MariaDB, optional tools)
  2. database   — Database setup, schema import, admin user creation
  3. config     — auth/settings.php & auth/config.php configuration
  4. storage    — Storage directories, symlinks, permissions, .htaccess
  5. apache     — mod_rewrite, VirtualHost, mod_xsendfile hints
  6. hardening  — Security hardening (.htaccess deny for sensitive dirs)
  7. migrate    — Core + Arcade database migrations
  8. verify     — Deployment health check (tools/check_deploy.php)

Examples:
  ./install.sh                           # Interactive
  ./install.sh --yes                     # Non-interactive with defaults
  ./install.sh --yes --hdd=/mnt/media    # Non-interactive with custom storage
  ./install.sh --step=3                  # Run only config step
  ./install.sh --resume-from=4           # Resume from storage step
  ./install.sh --dry-run --yes           # Preview what would be done

HELP
}
# ─── Step runner ───
# Usage: run_step STEP_NUMBER STEP_NAME MODULE_FUNCTION
run_step() {
    local step_num="$1"
    local step_name="$2"
    local module_func="$3"
    # Skip if not in range
    if [ "$STEP" -gt 0 ] && [ "$STEP" -ne "$step_num" ]; then
        log_info "Skipping step $step_num: $step_name (--step=$STEP)"
        return 0
    fi
    if [ "$step_num" -lt "$RESUME_FROM" ]; then
        log_info "Skipping step $step_num: $step_name (--resume-from=$RESUME_FROM)"
        return 0
    fi
    log_step "$step_num/8 — $step_name"
    # Source the module on demand
    local module_file="$SCRIPT_DIR/lib/install/${module_func%%_*}.sh"
    if [ -f "$module_file" ]; then
        # shellcheck source=/dev/null
        source "$module_file"
    else
        log_fail "Module file not found: $module_file"
        return 1
    fi
    if ! declare -f "$module_func" >/dev/null; then
        log_fail "Function $module_func not found after sourcing $module_file"
        return 1
    fi
    # Debug: show function definition
    if [ "${VERBOSE:-false}" = "true" ]; then
        log_verbose "Calling $module_func..."
    fi

    if $module_func; then
        log_ok "Step $step_num ($step_name) completed"
        return 0
    else
        log_fail "Step $step_num ($step_name) failed with exit code $?"
        return 1
    fi

    log_ok "Step $step_num ($step_name) completed"
    return 0
}

# ─── Main orchestration ───
main() {
    parse_args "$@"

    # Banner
    print_banner

    # Detect environment (required for all steps)
    detect_environment

    # Apply web user override if provided
    if [ -n "$WEB_USER_OVERRIDE" ]; then
        WEB_USER="$WEB_USER_OVERRIDE"
        log_verbose "Web user override: $WEB_USER"
    fi

    # Apply DB overrides
    [ -n "$DB_HOST_OVERRIDE" ] && export DB_HOST="$DB_HOST_OVERRIDE"
    [ -n "$DB_NAME_OVERRIDE" ] && export DB_NAME="$DB_NAME_OVERRIDE"
    [ -n "$DB_USER_OVERRIDE" ] && export DB_USER="$DB_USER_OVERRIDE"
    [ -n "$DB_PASS_OVERRIDE" ] && export DB_PASS="$DB_PASS_OVERRIDE"
    [ -n "$ADMIN_USER_OVERRIDE" ] && export ADMIN_USER="$ADMIN_USER_OVERRIDE"
    [ -n "$ADMIN_PASS_OVERRIDE" ] && export ADMIN_PASS="$ADMIN_PASS_OVERRIDE"
    # Modul membandingkan dengan string "true"; flag CLI memakai 1/0 → normalisasi.
    [ -n "$CREATE_APP_USER_OVERRIDE" ] && export CREATE_APP_USER="$([ "$CREATE_APP_USER_OVERRIDE" = "1" ] && echo true || echo false)"
    [ -n "$ENABLE_REWRITE_OVERRIDE" ] && export ENABLE_REWRITE="$([ "$ENABLE_REWRITE_OVERRIDE" = "1" ] && echo true || echo false)"
    [ -n "$VERIFY_URL_OVERRIDE" ] && export VERIFY_URL="$VERIFY_URL_OVERRIDE"
    [ -n "$VERIFY_HDD_OVERRIDE" ] && export VERIFY_HDD_OVERRIDE="$VERIFY_HDD_OVERRIDE"
    [ -n "$VERIFY_NO_COLOR_OVERRIDE" ] && export VERIFY_NO_COLOR="$VERIFY_NO_COLOR_OVERRIDE"

    # Set verbose mode
    [ "$VERBOSE" = "true" ] && export VERBOSE=true
    [ "$DRY_RUN" = "true" ] && export DRY_RUN=true

    # Execute steps
    run_step 1 "System Dependencies" "deps_run" || exit 1
    run_step 2 "Database Setup" "database_run" || exit 1
    run_step 3 "Application Config" "config_run" || exit 1
    log_verbose "DEBUG: HDD_BASE after config = ${HDD_BASE:-not set}"
    run_step 4 "Storage & Symlinks" "storage_run" || exit 1
    run_step 5 "Apache Configuration" "apache_run" || exit 1
    run_step 6 "Security Hardening" "hardening_run" || exit 1
    run_step 7 "Database Migrations" "migrate_run" || exit 1
    run_step 8 "Deployment Verification" "verify_run" || exit 1
}

# ─── Run ───
main "$@"