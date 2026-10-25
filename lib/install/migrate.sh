#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Database migration for MEeL installer
# Source: source "$(dirname "$0")/lib/install/migrate.sh"
# Requires: common.sh

# ─── Run core migrations ───
migrate_run_core() {
    log_step "Running database migrations (core)"

    if [ ! -f "database/migrate.php" ]; then
        log_fail "database/migrate.php not found"
        return 1
    fi

    if "$PHP_BIN" database/migrate.php; then
        log_ok "Core migrations completed"
        return 0
    else
        local exit_code=$?
        if [ $exit_code -eq 1 ]; then
            log_warn "Migrations completed with warnings (exit code 1)"
            return 0
        else
            log_fail "Core migrations failed (exit code $exit_code)"
            return 1
        fi
    fi
}

# ─── Run arcade migrations ───
migrate_run_arcade() {
    if [ ! -d "arcade" ] || [ ! -f "arcade/migrate.php" ]; then
        log_info "Arcade module not found — skipping"
        return 0
    fi

    log_step "Running arcade migrations"

    if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Enable Arcade module? (creates rooms, moves, arcade_song, arcade_score tables)" N; then
        if "$PHP_BIN" arcade/migrate.php; then
            log_ok "Arcade migrations completed"
            return 0
        else
            local exit_code=$?
            if [ $exit_code -eq 1 ]; then
                log_warn "Arcade migrations completed with warnings"
                return 0
            else
                log_fail "Arcade migrations failed (exit code $exit_code)"
                return 1
            fi
        fi
    else
        log_ok "Arcade skipped — module not enabled"
        return 0
    fi
}

# ─── Verify migrations table ───
migrate_verify() {
    log_step "Verifying migrations"

    if [ ! -f "auth/settings.php" ]; then
        log_warn "auth/settings.php not found — cannot verify"
        return 0
    fi

    local migration_version
    if migration_version=$("$PHP_BIN" -r '
        require "auth/settings.php";
        require "auth/config.php";
        try {
            $result = $conn->query("SELECT MAX(version) AS v FROM db_version");
            $v = $result ? $result->fetch_assoc() : null;
            echo "Current migration version: " . ($v["v"] ?? "none");
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    ' 2>&1); then
        log_ok "Migration verification passed: $migration_version"
        return 0
    else
        log_warn "Could not verify migration version: ${migration_version:-unknown error}"
        return 0
    fi
}

# ─── Main migration step ───
migrate_run() {
    migrate_run_core || return 1
    migrate_run_arcade
    migrate_verify
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f migrate_run_core migrate_run_arcade migrate_verify migrate_run 2>/dev/null || true
fi