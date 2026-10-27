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

RECOMMENDED_APT_PKGS="yt-dlp composer"
RECOMMENDED_DNF_PKGS="yt-dlp composer"
RECOMMENDED_PACMAN_PKGS="yt-dlp composer"
RECOMMENDED_APK_PKGS="yt-dlp composer"

OPTIONAL_APT_PKGS="apache2 libapache2-mod-php mecab mecab-ipadic-utf8"
OPTIONAL_DNF_PKGS="httpd php-apache mecab mecab-ipadic"
OPTIONAL_PACMAN_PKGS="apache php-apache mecab mecab-ipadic"
OPTIONAL_APK_PKGS="apache2 php83-apache2 mecab mecab-ipadic"

# ─── Check required packages ───
deps_check_required() {
    local missing=()
    local pkg

    case "$PKG_MGR" in
        apt)
            for pkg in $REQUIRED_APT_PKGS; do
                dpkg -l "$pkg" 2>/dev/null | grep -q '^ii' || missing+=("$pkg")
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

# ─── Verify PHP extensions ───
deps_verify_php_ext() {
    local required_ext="mysqli pdo_mysql fileinfo mbstring intl gd zip xml curl"
    local missing_ext=()
    local ext

    for ext in $required_ext; do
        if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
            if command -v phpenmod >/dev/null 2>&1; then
                sudo_cmd "phpenmod $ext" "Enable PHP extension: $ext" || true
                sleep 1
            fi
            if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
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
    local bins="yt-dlp mecab composer node npm"
    local missing=()
    local bin

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

# ─── Main dependency step ───
deps_run() {
    log_step "Checking system dependencies"

    if [ -z "${PHP_BIN:-}" ]; then
        detect_environment
    fi

    if [ "${PHP_VERSION:-}" = "missing" ]; then
        log_fail "PHP not found. Install PHP 8.2+ first."
        return 1
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
        deps_check_optional_bins || true
        return 0
    fi

    if [ "$HAS_APT" = "true" ] || [ "$HAS_DNF" = "true" ] || [ "$HAS_PACMAN" = "true" ] || [ "$HAS_APK" = "true" ]; then
        if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Install/verify system packages via $PKG_MGR now?" Y; then
            deps_install_required || log_warn "Some required packages may have failed"
            deps_verify_php_ext || return 1

            if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Install recommended packages (ffmpeg, yt-dlp, composer)?" Y; then
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

    deps_check_optional_bins || true
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f deps_check_required deps_install_packages deps_install_required 2>/dev/null || true
    export -f deps_install_recommended deps_install_optional deps_verify_php_ext 2>/dev/null || true
    export -f deps_check_optional_bins deps_run 2>/dev/null || true
fi