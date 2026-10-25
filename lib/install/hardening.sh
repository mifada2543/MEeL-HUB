#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Security hardening for MEeL installer
# Source: source "$(dirname "$0")/lib/install/hardening.sh"
# Requires: common.sh

# ─── Harden sensitive directories with .htaccess ───
hardening_deny_dirs() {
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$project_root" ] && return 1

    log_step "Hardening sensitive directories"

    local deny_dirs=(
        "vendor"
        "node_modules"
        "tools"
        "storage"
        "assets/dict"
    )

    local htaccess_template='
Options -Indexes

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} ^(.*/)[^/]+/?$
    RewriteRule ^ - [F,L]
</IfModule>

<IfModule !mod_rewrite.c>
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order allow,deny
        Deny from all
    </IfModule>
</IfModule>
'

    local dir target_dir ht_file
    for dir in "${deny_dirs[@]}"; do
        target_dir="$project_root/$dir"
        ht_file="$target_dir/.htaccess"

        if [ ! -d "$target_dir" ]; then
            log_info "Directory not found (skipping): $target_dir"
            continue
        fi

        if [ -f "$ht_file" ] && grep -q 'Require all denied\|Deny from all' "$ht_file"; then
            log_ok "Hardening already present: $dir/"
            continue
        fi

        if printf '%s\n' "$htaccess_template" > "$ht_file" 2>/dev/null; then
            log_ok "Hardening .htaccess created: $dir/"
        else
            log_warn "Failed to write .htaccess to $dir/"
        fi
    done
}

# ─── Verify hardening ───
hardening_verify() {
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$project_root" ] && return 1

    log_step "Verifying security hardening"

    local deny_dirs=(
        "vendor"
        "node_modules"
        "tools"
        "storage"
        "assets/dict"
    )

    local failed=0
    local dir target_dir ht_file

    for dir in "${deny_dirs[@]}"; do
        target_dir="$project_root/$dir"
        ht_file="$target_dir/.htaccess"
        if [ ! -d "$target_dir" ]; then
            log_info "$dir/ not present -- skipping"
            continue
        fi
        if [ ! -f "$ht_file" ]; then
            log_fail "$dir/.htaccess missing"
            failed=1
        elif ! grep -q 'Require all denied\|Deny from all' "$ht_file"; then
            log_fail "$dir/.htaccess missing deny directive"
            failed=1
        else
            log_ok "$dir/.htaccess OK"
        fi
    done

    return $failed
}

# ─── Main hardening step ───
hardening_run() {
    hardening_deny_dirs
    hardening_verify
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f hardening_deny_dirs hardening_verify hardening_run 2>/dev/null || true
fi