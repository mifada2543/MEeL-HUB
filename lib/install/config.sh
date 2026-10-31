#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Application configuration for MEeL installer
# Source: source "$(dirname "$0")/lib/install/config.sh"
# Requires: common.sh, detect.sh, ConfigPatcher.php

# ─── Create config files from templates ───
config_create_from_templates() {
    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_info "[DRY-RUN] Would create auth/settings.php and auth/config.php from templates (if missing)"
        return 0
    fi

    if [ ! -f "auth/settings.php" ]; then
        cp auth/settings.example.php auth/settings.php
        log_ok "auth/settings.php created from template"
    else
        log_warn "auth/settings.php already exists — not overwriting"
    fi

    if [ ! -f "auth/config.php" ]; then
        cp auth/config.example.php auth/config.php
        log_ok "auth/config.php created from template"
    else
        log_ok "auth/config.php already exists"
    fi
}

# ─── Patch settings.php using ConfigPatcher.php ───
config_patch_settings() {
    local hdd_base="${HDD_BASE:-}"
    local env_choice="${ENV_CHOICE:-production}"
    local debug_choice="${DEBUG_CHOICE:-false}"
    local trust_proxy="${TRUST_PROXY:-false}"
    local trusted_proxies="${TRUSTED_PROXIES:-127.0.0.1,::1}"
    local xsendfile="${USE_XSENDFILE:-false}"
    local canonical_host="${CANONICAL_HOST:-}"
    local trusted_hosts="${TRUSTED_HOSTS:-localhost,127.0.0.1,::1}"
    local ffmpeg_path="${FFMPEG_PATH:-}"
    local ffprobe_path="${FFPROBE_PATH:-}"
    local node_path="${NODE_PATH:-}"
    local ytdlp_path="${YTDLP_PATH:-}"

    # Build ConfigPatcher command
    local cmd=("php" "lib/install/php/ConfigPatcher.php")
    local settings_file="auth/settings.php"
    # Dry-run tidak membuat file; patcher cukup membaca template.
    if [ "${DRY_RUN:-false}" = "true" ] && [ ! -f "$settings_file" ]; then
        settings_file="auth/settings.example.php"
    fi
    cmd+=("--settings=${settings_file}")
    cmd+=("--db-host=${DB_HOST:-localhost}")
    cmd+=("--db-user=${DB_USER:-root}")
    cmd+=("--db-pass=${DB_PASS:-}")
    cmd+=("--db-name=${DB_NAME:-MEeL}")
    cmd+=("--hdd=${hdd_base}")
    cmd+=("--env=${env_choice}")
    cmd+=("--debug=${debug_choice}")

    [ "$trust_proxy" = "true" ] && cmd+=("--trust-proxy" "--trusted-proxies=${trusted_proxies}")
    [ "$xsendfile" = "true" ] && cmd+=("--xsendfile")
    [ -n "$canonical_host" ] && cmd+=("--canonical-host=${canonical_host}" "--trusted-hosts=${trusted_hosts}")
    [ -n "$ffmpeg_path" ] && cmd+=("--ffmpeg=${ffmpeg_path}")
    [ -n "$ffprobe_path" ] && cmd+=("--ffprobe=${ffprobe_path}")
    [ -n "$node_path" ] && cmd+=("--node=${node_path}")
    [ -n "$ytdlp_path" ] && cmd+=("--ytdlp=${ytdlp_path}")

    if [ "${DRY_RUN:-false}" = "true" ]; then
        cmd+=("--dry-run")
    fi

    log_step "Configuring application (auth/settings.php)"
    log_verbose "Running: ${cmd[*]}"

    if ! "${cmd[@]}"; then
        log_fail "ConfigPatcher failed"
        return 1
    fi

    log_ok "auth/settings.php configured"
}

# ─── Set file permissions ───
config_set_permissions() {
    if [ ! -f "auth/settings.php" ] || [ ! -f "auth/config.php" ]; then
        log_warn "Config files not found — skipping permissions"
        return 0
    fi

    if [ "${CAN_ELEVATE:-true}" = "true" ] && id "${WEB_USER:-www-data}" >/dev/null 2>&1; then
        if $SUDO chgrp "${WEB_USER}" auth/settings.php auth/config.php 2>/dev/null \
            && $SUDO chmod 640 auth/settings.php auth/config.php 2>/dev/null; then
            log_ok "auth/settings.php & auth/config.php → 640 (group: ${WEB_USER})"
        else
            log_warn "Failed to set permissions — run manually: chgrp ${WEB_USER} auth/*.php && chmod 640 auth/*.php"
        fi
    else
        log_warn "Cannot set permissions (no sudo or user ${WEB_USER:-www-data} not found) — set manually"
    fi
}

# ─── Verify config loads ───
config_verify() {
    log_step "Verifying configuration"
    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_info "[DRY-RUN] Skipping config load check (no files were written)"
        return 0
    fi
    if "$PHP_BIN" -r 'require "auth/settings.php"; require "auth/config.php";' 2>/dev/null; then
        log_ok "Configuration loads successfully"
        return 0
    else
        log_fail "Configuration failed to load — check auth/settings.php syntax"
        return 1
    fi
}

# ─── Main config step ───
config_run() {
    local hdd_base="${HDD_OVERRIDE:-}"
    local env_choice="${ENV_OVERRIDE:-}"
    local trust_proxy="${TRUST_PROXY_OVERRIDE:-}"
    local xsendfile="${XSENDFILE_OVERRIDE:-}"
    local vhost_domain="${VHOST_OVERRIDE:-}"

    # Interactive prompts for missing values
    if [ -z "$hdd_base" ]; then
        local default_hdd="$PROJECT_ROOT/storage/media"
        hdd_base=$(ask "Storage media location (MEEL_HDD_BASE)" "$default_hdd")
    fi
    hdd_base="${hdd_base%/}"

    if [ -z "$env_choice" ]; then
        env_choice=$(ask "Environment (production/development)" "production")
        case "$env_choice" in
            production|development) ;;
            *) log_warn "Unknown environment '$env_choice', using production"; env_choice="production" ;;
        esac
    fi
    local debug_choice=false
    [ "$env_choice" = "development" ] && debug_choice=true

    # Trusted proxy
    if [ -z "$trust_proxy" ]; then
        if confirm "Application behind reverse proxy (nginx/Cloudflare)?" N; then
            trust_proxy="true"
        else
            trust_proxy="false"
        fi
    fi

    # X-Sendfile
    if [ -z "$xsendfile" ]; then
        if confirm "Enable X-Sendfile? (requires mod_xsendfile installed & configured)" N; then
            xsendfile="true"
        else
            xsendfile="false"
        fi
    fi

    local default_ffmpeg="" default_ffprobe="" default_ytdlp=""
    if [ ! -f "${PROJECT_ROOT:-}/auth/settings.php" ]; then
        default_ffmpeg="$PROJECT_ROOT/tools/ffmpeg-lite/ffmpeg"
        default_ffprobe="$PROJECT_ROOT/tools/ffmpeg-lite/ffprobe"
        if [ -x "$PROJECT_ROOT/tools/yt-dlp/yt-dlp" ]; then
            default_ytdlp="$PROJECT_ROOT/tools/yt-dlp/yt-dlp"
        elif command -v yt-dlp >/dev/null 2>&1; then
            default_ytdlp="$(command -v yt-dlp)"
        fi
        [ -x "$default_ffmpeg" ] || default_ffmpeg=""
        [ -x "$default_ffprobe" ] || default_ffprobe=""
    fi
    local ffmpeg_path="${FFMPEG_PATH-$default_ffmpeg}" ffprobe_path="${FFPROBE_PATH-$default_ffprobe}" node_path="" ytdlp_path="${YTDLP_PATH-$default_ytdlp}"
    if ! [ "${ASSUME_YES:-false}" = "true" ] && confirm "Set binary paths manually? (empty = auto-detect)" N; then
        ffmpeg_path=$(ask "ffmpeg path" "$ffmpeg_path")
        ffprobe_path=$(ask "ffprobe path" "$ffprobe_path")
        node_path=$(ask "node path" "")
        ytdlp_path=$(ask "yt-dlp path" "$ytdlp_path")
    fi

    # Export for sub-functions
    export HDD_BASE="$hdd_base"
    export ENV_CHOICE="$env_choice"
    export DEBUG_CHOICE="$debug_choice"
    export TRUST_PROXY="$trust_proxy"
    export USE_XSENDFILE="$xsendfile"
    export FFMPEG_PATH="$ffmpeg_path"
    export FFPROBE_PATH="$ffprobe_path"
    export NODE_PATH="$node_path"
    export YTDLP_PATH="$ytdlp_path"

    # Run configuration
    config_create_from_templates
    config_patch_settings || return 1
    config_set_permissions
    config_verify || return 1
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f config_create_from_templates config_patch_settings 2>/dev/null || true
    export -f config_set_permissions config_verify config_run 2>/dev/null || true
fi