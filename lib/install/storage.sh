#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Storage directories & symlinks for MEeL installer
# Source: source "$(dirname "$0")/lib/install/storage.sh"
# Requires: common.sh, detect.sh

# ─── Create storage directory structure ───
storage_create_dirs() {
    local base="${HDD_BASE:-}"
    [ -z "$base" ] && { log_fail "HDD_BASE not set"; return 1; }

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create storage dirs under: $base"
        return 0
    fi

    log_step "Creating storage directories"
    mkdir -p \
        "$base/video/upload/video" \
        "$base/video/upload/thumbnail" \
        "$base/music/upload/file" \
        "$base/music/upload/thumbnail" \
        "$base/books/upload/manga" \
        "$base/books/upload/pdf" \
        "$base/books/upload/thumbnail" \
        "$base/drive/public" \
        "$base/drive/private_admins"
    log_ok "Storage directories created at: $base"
}

# ─── Create mount marker ───
storage_create_mount_marker() {
    local base="${HDD_BASE:-}"
    [ -z "$base" ] && return 1

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create mount marker: $base/.meel_mount"
        return 0
    fi

    touch "$base/.meel_mount"
    log_ok "Volume marker .meel_mount created"
}

# ─── Copy .htaccess hardening to storage targets ───
storage_copy_htaccess() {
    local base="${HDD_BASE:-}"
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$base" ] || [ -z "$project_root" ] && { log_fail "storage_copy_htaccess: base or project_root not set"; return 1; }

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would copy .htaccess to storage targets"
        return 0
    fi

    local repo_htaccess="$project_root/data_drive/.htaccess"
    if [ ! -f "$repo_htaccess" ]; then
        log_fail "Source .htaccess not found: $repo_htaccess"
        return 1
    fi

    for target in \
        "$base/video/upload" \
        "$base/music/upload" \
        "$base/books/upload" \
        "$base/drive/public" \
        "$base/drive/private_admins"; do
        if [ -d "$target" ] && [ ! -f "$target/.htaccess" ]; then
            cp "$repo_htaccess" "$target/.htaccess" 2>/dev/null && \
                log_verbose "Copied .htaccess to $target" || \
                log_warn "Failed to copy .htaccess to $target"
        fi
    done

    local priv_htaccess="$base/drive/private_admins/.htaccess"
    if [ -d "$base/drive/private_admins" ] && [ ! -f "$priv_htaccess" ]; then
        cat > "$priv_htaccess" <<'HTEOF'
Options -Indexes
Require all denied
HTEOF
        log_verbose "Created deny .htaccess for private_admins"
    fi
    return 0
}

# ─── Create symlinks ───
storage_create_symlinks() {
    local base="${HDD_BASE:-}"
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$base" ] || [ -z "$project_root" ] && return 1

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create symlinks: video/upload, music/upload, books/upload, data_drive/public"
        return 0
    fi

    log_step "Creating storage symlinks"

    for module in video music books; do
        local target="$base/${module}/upload"
        local link="$project_root/${module}/upload"

        if [ -L "$link" ]; then
            log_warn "${module}/upload already symlinked — skipping"
            continue
        elif [ -d "$link" ] && [ -n "$(find "$link" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' ! -name '.htaccess' -print -quit 2>/dev/null)" ]; then
            log_warn "${module}/upload has data — NOT replacing"
            continue
        fi

        mkdir -p "$target"
        storage_copy_htaccess
        rm -rf "$link"
        ln -sfn "$target" "$link"
        log_ok "Symlink: ${module}/upload → $target"
    done

    local drive_target="$base/drive/public"
    local drive_link="$project_root/data_drive/public"

    if [ -L "$drive_link" ]; then
        log_warn "data_drive/public already symlinked — skipping"
    elif [ -d "$drive_link" ] && [ -n "$(find "$drive_link" -mindepth 1 -maxdepth 1 ! -name '.gitkeep' ! -name '.htaccess' -print -quit 2>/dev/null)" ]; then
        log_warn "data_drive/public has data — NOT replacing"
    else
        mkdir -p "$drive_target"
        storage_copy_htaccess
        rm -rf "$drive_link"
        ln -sfn "$drive_target" "$drive_link"
        log_ok "Symlink: data_drive/public → $drive_target"
    fi
}

# ─── Create local runtime directories ───
storage_create_local_dirs() {
    local project_root="${PROJECT_ROOT:-}"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create local dirs: data_drive/public, data_drive/private_admins, temp, profile/upload"
        return 0
    fi

    mkdir -p \
        "$project_root/data_drive/public" \
        "$project_root/data_drive/private_admins" \
        "$project_root/temp" \
        "$project_root/profile/upload"
    log_ok "Local runtime directories created"
}

# ─── Set ownership & permissions ───
storage_set_permissions() {
    local base="${HDD_BASE:-}"
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$base" ] || [ -z "$project_root" ] && return 1

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would set storage permissions"
        return 0
    fi

    if [ "${CAN_ELEVATE:-true}" = "true" ] && [ -n "${SUDO:-}" ] && id "${WEB_USER:-www-data}" >/dev/null 2>&1; then
        if confirm "Set storage ownership to ${WEB_USER}:${WEB_USER}?" Y; then
            $SUDO chown -R "${WEB_USER}:${WEB_USER}" \
                "$project_root/data_drive" \
                "$project_root/temp" \
                "$project_root/profile/upload" \
                "$base" 2>/dev/null || log_warn "chown failed — set manually"

            $SUDO chmod -R 775 \
                "$project_root/data_drive" \
                "$project_root/temp" \
                "$project_root/profile/upload" \
                "$base" 2>/dev/null || true

            log_ok "Storage ownership & permissions set"
        fi
    else
        log_warn "Cannot set permissions automatically — run manually:"
        log_info "  chown -R ${WEB_USER}:${WEB_USER} data_drive temp profile/upload $base"
        log_info "  chmod -R 775 data_drive temp profile/upload $base"
    fi
    return 0
}

# ─── Verify storage ───
storage_verify() {
    local base="${HDD_BASE:-}"
    local project_root="${PROJECT_ROOT:-}"
    [ -z "$base" ] || [ -z "$project_root" ] && return 1

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would verify storage setup"
        return 0
    fi

    log_step "Verifying storage setup"
    local failed=0

    if [ ! -d "$base" ]; then
        log_fail "MEEL_HDD_BASE not a directory: $base"; failed=1
    elif [ ! -r "$base" ] || [ ! -w "$base" ]; then
        log_fail "MEEL_HDD_BASE not readable/writable: $base"; failed=1
    else
        log_ok "MEEL_HDD_BASE OK: $base"
    fi

    [ ! -f "$base/.meel_mount" ] && log_warn "Volume marker missing: $base/.meel_mount"

    for subdir in \
        "music/upload/file" \
        "music/upload/thumbnail" \
        "books/upload/pdf" \
        "books/upload/thumbnail"; do
        [ ! -d "$base/$subdir" ] && { log_fail "Required subdir missing: $base/$subdir"; failed=1; }
    done

    for module in video music books; do
        local link="$project_root/${module}/upload"
        if [ ! -L "$link" ]; then
            log_warn "${module}/upload is not a symlink (fallback mode)"
        else
            local target; target=$(readlink "$link")
            local expected="$base/${module}/upload"
            [ "$target" != "$expected" ] && log_warn "${module}/upload target mismatch: $target (expected $expected)" || log_ok "${module}/upload → $target"
        fi
    done

    for u in books/upload music/upload video/upload; do
        local ht="$project_root/$u/.htaccess"
        if [ ! -f "$ht" ]; then
            log_fail "$u/.htaccess missing"; failed=1
        elif ! grep -q "php_flag engine off" "$ht" || ! grep -q "ForceType" "$ht" || ! grep -q "Options -Indexes" "$ht"; then
            log_fail "$u/.htaccess missing required directives"; failed=1
        else
            log_ok "$u/.htaccess OK"
        fi
    done

    return $failed
}

# ─── Main storage step ───
storage_run() {
    storage_create_dirs || { log_fail "storage_create_dirs failed"; return 1; }
    storage_create_mount_marker || { log_fail "storage_create_mount_marker failed"; return 1; }
    storage_copy_htaccess || { log_fail "storage_copy_htaccess failed"; return 1; }
    storage_create_symlinks || { log_fail "storage_create_symlinks failed"; return 1; }
    storage_create_local_dirs || { log_fail "storage_create_local_dirs failed"; return 1; }
    storage_set_permissions || { log_fail "storage_set_permissions failed"; return 1; }
    storage_verify || { log_fail "storage_verify failed"; return 1; }
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f storage_create_dirs storage_create_mount_marker storage_copy_htaccess 2>/dev/null || true
    export -f storage_create_symlinks storage_create_local_dirs storage_set_permissions 2>/dev/null || true
    export -f storage_verify storage_run 2>/dev/null || true
fi