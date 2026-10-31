#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Dependency management for MEeL installer
# Source: source "$(dirname "$0")/lib/install/deps.sh"
# Requires: common.sh, detect.sh

# ─── Package lists ───
REQUIRED_APT_PKGS="php-cli php-mysqli php-pdo-mysql php-fileinfo php-mbstring php-intl php-gd php-zip php-xml php-curl mariadb-server"
REQUIRED_DNF_PKGS="php-cli php-mysqlnd php-pdo php-fileinfo php-mbstring php-intl php-gd php-zip php-xml php-curl mariadb-server"
REQUIRED_PACMAN_PKGS="php php-gd php-intl php-mbstring php-xml php-zip mariadb"
REQUIRED_APK_PKGS="php83 php83-mysqli php83-pdo_mysql php83-fileinfo php83-mbstring php83-intl php83-gd php83-zip php83-xml php83-curl mariadb"

RECOMMENDED_APT_PKGS="composer"
RECOMMENDED_DNF_PKGS="composer"
RECOMMENDED_PACMAN_PKGS="composer"
RECOMMENDED_APK_PKGS="composer"

OPTIONAL_APT_PKGS="apache2 libapache2-mod-php mecab mecab-ipadic-utf8"
OPTIONAL_DNF_PKGS="httpd php-apache mecab mecab-ipadic"
OPTIONAL_PACMAN_PKGS="apache php-apache mecab mecab-ipadic"
OPTIONAL_APK_PKGS="apache2 php83-apache2 mecab mecab-ipadic"

# ─── Letak unduhan yt-dlp ───
# Bisa dioverride untuk cermin/internal: YTDLP_DOWNLOAD_BASE=/path/atau/url
YTDLP_DOWNLOAD_BASE="${YTDLP_DOWNLOAD_BASE:-https://github.com/yt-dlp/yt-dlp/releases/latest/download}"

# ─── Check required packages ───
deps_check_required() {
    local missing=()
    local pkg

    case "$PKG_MGR" in
        apt)
            for pkg in $REQUIRED_APT_PKGS; do
                [ "$(dpkg-query -W -f='${db:Status-Abbrev}' "$pkg" 2>/dev/null | tr -d ' ')" = "ii" ] || missing+=("$pkg")
            done
            ;;
        dnf)
            for pkg in $REQUIRED_DNF_PKGS; do
                rpm -q "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
            done
            ;;
        pacman)
            for pkg in $REQUIRED_PACMAN_PKGS; do
                pacman -Q "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
            done
            ;;
        apk)
            for pkg in $REQUIRED_APK_PKGS; do
                apk info -e "$pkg" >/dev/null 2>&1 || missing+=("$pkg")
            done
            ;;
        *)
            log_warn "Unknown package manager: $PKG_MGR -- cannot verify packages"
            return 0
            ;;
    esac

    if [ ${#missing[@]} -gt 0 ]; then
        echo "${missing[*]}"
        return 1
    fi
    return 0
}

# ─── Install packages via detected package manager ───
deps_install_packages() {
    local packages="$1"
    local desc="${2:-packages}"

    [ -z "$packages" ] && return 0

    case "$PKG_MGR" in
        apt)
            sudo_cmd "apt-get update -y" "Update package index"
            sudo_cmd "apt-get install -y $packages" "Install $desc"
            ;;
        dnf)
            sudo_cmd "dnf install -y $packages" "Install $desc"
            ;;
        pacman)
            sudo_cmd "pacman -Sy --noconfirm $packages" "Install $desc"
            ;;
        apk)
            sudo_cmd "apk add $packages" "Install $desc"
            ;;
        *)
            log_fail "Unsupported package manager: $PKG_MGR"
            return 1
            ;;
    esac
}

# ─── Install required packages ───
deps_install_required() {
    local packages=""

    case "$PKG_MGR" in
        apt) packages="$REQUIRED_APT_PKGS" ;;
        dnf) packages="$REQUIRED_DNF_PKGS" ;;
        pacman) packages="$REQUIRED_PACMAN_PKGS" ;;
        apk) packages="$REQUIRED_APK_PKGS" ;;
        *) return 1 ;;
    esac

    log_step "Installing required system packages ($PKG_MGR)"
    deps_install_packages "$packages" "required packages"
}

# ─── Install recommended packages ───
deps_install_recommended() {
    local packages=""

    case "$PKG_MGR" in
        apt) packages="$RECOMMENDED_APT_PKGS" ;;
        dnf) packages="$RECOMMENDED_DNF_PKGS" ;;
        pacman) packages="$RECOMMENDED_PACMAN_PKGS" ;;
        apk) packages="$RECOMMENDED_APK_PKGS" ;;
        *) return 1 ;;
    esac

    log_step "Installing recommended packages ($PKG_MGR)"
    deps_install_packages "$packages" "recommended packages"
}

# ─── yt-dlp: unduh binary standalone (tidak lagi dibundel di paket) ───
deps_ytdlp_asset() {
    case "$(uname -m 2>/dev/null)" in
        x86_64|amd64) printf '%s\n' 'yt-dlp_linux' ;;
        aarch64|arm64) printf '%s\n' 'yt-dlp_linux_aarch64' ;;
        *) return 1 ;;
    esac
}

deps_download() {
    local url="$1" out="$2"
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 3 --connect-timeout 15 --max-time 300 -o "$out" "$url"
    elif command -v wget >/dev/null 2>&1; then
        wget -q -O "$out" "$url"
    else
        return 127
    fi
}

deps_sha256() {
    local file="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$file" | awk '{ print $1 }'
    elif command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$file" | awk '{ print $1 }'
    else
        return 1
    fi
}

deps_install_ytdlp() {
    local project_root="${PROJECT_ROOT:-$(pwd)}"
    local dest="$project_root/tools/yt-dlp/yt-dlp"

    if [ -x "$dest" ]; then
        log_ok "yt-dlp found: $dest"
        return 0
    fi
    if [ "${YTDLP_FORCE_DOWNLOAD:-false}" != "true" ] && command -v yt-dlp >/dev/null 2>&1; then
        log_ok "yt-dlp found: $(command -v yt-dlp)"
        return 0
    fi
    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_info "[dry-run] would download yt-dlp to $dest"
        return 0
    fi
    if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
        log_warn "curl/wget tidak tersedia — yt-dlp tidak bisa diunduh"
        return 1
    fi

    local asset
    if ! asset="$(deps_ytdlp_asset)"; then
        log_warn "Arsitektur $(uname -m 2>/dev/null || echo tidak dikenal) belum didukung unduhan otomatis yt-dlp"
        log_info "Pasang yt-dlp manual lalu set MEEL_YTDLP_PATH di auth/settings.php"
        return 1
    fi

    local base="${YTDLP_DOWNLOAD_BASE%/}"
    local tmpdir
    tmpdir="$(mktemp -d "${TMPDIR:-/tmp}/meel-ytdlp.XXXXXX")" || return 1

    log_step "Mengunduh yt-dlp ($asset)"
    if ! deps_download "$base/$asset" "$tmpdir/$asset"; then
        log_warn "Unduhan yt-dlp gagal: $base/$asset"
        rm -rf "$tmpdir"
        return 1
    fi

    if deps_download "$base/SHA2-256SUMS" "$tmpdir/SHA2-256SUMS"; then
        local expected actual
        expected="$(awk -v name="$asset" '$2 == name { print $1; exit }' "$tmpdir/SHA2-256SUMS")"
        actual="$(deps_sha256 "$tmpdir/$asset" || true)"
        if [ -z "$expected" ] || [ -z "$actual" ] || [ "$expected" != "$actual" ]; then
            log_warn "Checksum yt-dlp tidak cocok — unduhan dibatalkan"
            rm -rf "$tmpdir"
            return 1
        fi
        log_ok "Checksum yt-dlp terverifikasi"
    else
        log_warn "SHA2-256SUMS tidak tersedia — checksum yt-dlp dilewati"
    fi

    mkdir -p "$(dirname "$dest")" || { rm -rf "$tmpdir"; return 1; }
    if ! mv "$tmpdir/$asset" "$dest" || ! chmod 0755 "$dest"; then
        log_warn "Gagal memasang yt-dlp ke $dest"
        rm -rf "$tmpdir"
        return 1
    fi
    if ! "$dest" --version >/dev/null 2>&1; then
        log_warn "Binary yt-dlp tidak bisa dijalankan — dihapus"
        rm -f "$dest"
        rm -rf "$tmpdir"
        return 1
    fi

    log_ok "yt-dlp terpasang: $("$dest" --version)"
    rm -rf "$tmpdir"
    return 0
}

# ─── Tawarkan unduhan yt-dlp tanpa menggagalkan instalasi ───
deps_offer_ytdlp() {
    if [ "${SKIP_YTDLP:-false}" = "true" ]; then
        log_info "Melewati unduhan yt-dlp (--skip-ytdlp)"
        return 0
    fi
    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_info "[dry-run] would offer yt-dlp download"
        return 0
    fi
    if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Unduh yt-dlp terbaru (~40 MB)?" Y; then
        deps_install_ytdlp || log_warn "yt-dlp tidak tersedia — fitur unduh video nonaktif"
    else
        log_warn "yt-dlp dilewati — fitur unduh video nonaktif"
    fi
}

# ─── Install optional packages ───
deps_install_optional() {
    local packages=""

    case "$PKG_MGR" in
        apt) packages="$OPTIONAL_APT_PKGS" ;;
        dnf) packages="$OPTIONAL_DNF_PKGS" ;;
        pacman) packages="$OPTIONAL_PACMAN_PKGS" ;;
        apk) packages="$OPTIONAL_APK_PKGS" ;;
        *) return 1 ;;
    esac

    log_step "Installing optional packages ($PKG_MGR)"
    deps_install_packages "$packages" "optional packages"
}

# ─── Check one PHP extension ───
deps_php_has_ext() {
    local ext="$1" mods
    mods="$(php -m 2>/dev/null || true)"
    grep -qi "^${ext}\$" <<<"$mods"
}

# ─── Verify PHP extensions ───
deps_verify_php_ext() {
    local required_ext="mysqli pdo_mysql fileinfo mbstring intl gd zip xml curl"
    local missing_ext=()
    local ext

    for ext in $required_ext; do
        if ! deps_php_has_ext "$ext"; then
            if command -v phpenmod >/dev/null 2>&1; then
                sudo_cmd "phpenmod $ext" "Enable PHP extension: $ext" || true
                sleep 1
            fi
            if ! deps_php_has_ext "$ext"; then
                missing_ext+=("$ext")
            fi
        fi
    done

    if [ ${#missing_ext[@]} -gt 0 ]; then
        log_fail "Missing PHP extensions: ${missing_ext[*]}"
        return 1
    fi
    log_ok "All required PHP extensions active: $required_ext"
    return 0
}

# ─── Check optional binaries ───
deps_check_optional_bins() {
    local bins="mecab composer node npm"
    local missing=()
    local bin
    local project_root="${PROJECT_ROOT:-$(pwd)}"

    if [ -x "$project_root/tools/yt-dlp/yt-dlp" ]; then
        log_verbose "yt-dlp found: $project_root/tools/yt-dlp/yt-dlp"
    elif command -v yt-dlp >/dev/null 2>&1; then
        log_verbose "yt-dlp found: $(command -v yt-dlp)"
    else
        missing+=("yt-dlp")
    fi

    for bin in $bins; do
        if ! command -v "$bin" >/dev/null 2>&1; then
            missing+=("$bin")
        else
            log_verbose "$bin found: $(command -v "$bin")"
        fi
    done

    # ffmpeg/ffprobe checked separately via tools/ffmpeg-lite
    if [ -x "$PROJECT_ROOT/tools/ffmpeg-lite/ffmpeg" ] && [ -x "$PROJECT_ROOT/tools/ffmpeg-lite/ffprobe" ]; then
        log_ok "ffmpeg-lite found: $PROJECT_ROOT/tools/ffmpeg-lite/ffmpeg"
    else
        missing+=("ffmpeg-lite (tools/ffmpeg-lite)")
    fi

    if [ ${#missing[@]} -gt 0 ]; then
        log_warn "Optional binaries not found: ${missing[*]}"
        return 1
    fi
    log_ok "All optional binaries found"
    return 0
}

# ─── Bootstrap PHP CLI (diperlukan sebelum cek versi/ekstensi) ───
deps_bootstrap_php() {
    local pkg=""
    case "$PKG_MGR" in
        apt) pkg="php-cli" ;;
        dnf) pkg="php-cli" ;;
        pacman) pkg="php" ;;
        apk) pkg="php83" ;;
        *)
            log_fail "PHP not found and no supported package manager detected. Install PHP 8.2+ manually."
            return 1
            ;;
    esac

    if [ "${SKIP_APT:-false}" = "true" ]; then
        log_fail "PHP not found. Install PHP 8.2+ first (--skip-apt prevents the installer from doing it)."
        return 1
    fi

    if [ "${ASSUME_YES:-false}" != "true" ] && ! confirm "PHP not found. Install PHP CLI ($pkg) via $PKG_MGR now?" Y; then
        log_fail "PHP not found. Install PHP 8.2+ first."
        return 1
    fi

    deps_install_packages "$pkg" "PHP CLI" || {
        log_fail "Failed to install PHP CLI ($pkg)"
        return 1
    }

    # Dry-run tidak benar-benar memasang apa pun -- jangan gagal karena itu.
    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would re-detect PHP after installing $pkg"
        return 0
    fi

    PHP_BIN=""
    detect_php
    if [ "${PHP_VERSION:-missing}" = "missing" ]; then
        log_fail "PHP still not found after installing $pkg"
        return 1
    fi
    log_ok "PHP CLI installed: $PHP_VERSION"
}

# ─── Main dependency step ───
deps_run() {
    log_step "Checking system dependencies"

    if [ -z "${PHP_BIN:-}" ]; then
        detect_environment
    fi

    if [ "${PHP_VERSION:-}" = "missing" ]; then
        deps_bootstrap_php || return 1
        # Dry-run: PHP tidak sungguh terpasang, lewati cek versi/ekstensi.
        if [ "${PHP_VERSION:-}" = "missing" ]; then
            return 0
        fi
    fi

    local php_major
    php_major=$(echo "$PHP_VERSION" | cut -d. -f1)
    local php_minor
    php_minor=$(echo "$PHP_VERSION" | cut -d. -f2)
    if [ "$php_major" -lt 8 ] || { [ "$php_major" -eq 8 ] && [ "$php_minor" -lt 2 ]; }; then
        log_warn "PHP CLI is $PHP_VERSION (8.2+ recommended). FPM may be newer."
        log_warn "Use --skip-apt to bypass this check if FPM is 8.2+"
        if [ "${ASSUME_YES:-false}" != "true" ] && ! confirm "Continue anyway?" N; then
            return 1
        fi
    fi
    log_ok "PHP version: $PHP_VERSION"

    if [ "${SKIP_APT:-false}" = "true" ]; then
        log_info "Skipping package installation (--skip-apt)"
        deps_verify_php_ext || return 1
        deps_offer_ytdlp
        deps_check_optional_bins || true
        return 0
    fi

    if [ "$HAS_APT" = "true" ] || [ "$HAS_DNF" = "true" ] || [ "$HAS_PACMAN" = "true" ] || [ "$HAS_APK" = "true" ]; then
        if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Install/verify system packages via $PKG_MGR now?" Y; then
            deps_install_required || log_warn "Some required packages may have failed"
            deps_verify_php_ext || return 1

            if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Install recommended packages (composer)?" Y; then
                deps_install_recommended || log_warn "Some recommended packages may have failed"
            fi

            if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Install optional packages (apache2, mecab)?" N; then
                deps_install_optional || log_warn "Some optional packages may have failed"
            fi
        fi
    else
        log_warn "No supported package manager found -- please install dependencies manually"
        log_info "Required: PHP 8.2+ (mysqli, pdo_mysql, fileinfo, mbstring, intl, gd, zip, xml, curl), MariaDB/MySQL"
    fi

    deps_offer_ytdlp
    deps_check_optional_bins || true
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f deps_php_has_ext deps_bootstrap_php deps_check_required deps_install_packages deps_install_required 2>/dev/null || true
    export -f deps_install_recommended deps_install_optional deps_verify_php_ext 2>/dev/null || true
    export -f deps_install_ytdlp deps_offer_ytdlp deps_download deps_sha256 deps_ytdlp_asset 2>/dev/null || true
    export -f deps_check_optional_bins deps_run 2>/dev/null || true
fi