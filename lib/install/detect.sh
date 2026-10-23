#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034,SC2155
#
# Environment detection for MEeL installer
# Source this file: source "$(dirname "$0")/lib/install/detect.sh"
# Requires: common.sh already sourced

# ─── OS Detection ───
detect_os() {
    OS_ID="unknown"
    OS_VERSION="unknown"
    OS_PRETTY="unknown"

    if [ -f /etc/os-release ]; then
        # shellcheck source=/dev/null
        . /etc/os-release
        OS_ID="${ID:-unknown}"
        OS_VERSION="${VERSION_ID:-unknown}"
        OS_PRETTY="${PRETTY_NAME:-unknown}"
    elif [ -f /etc/lsb-release ]; then
        # shellcheck source=/dev/null
        . /etc/lsb-release
        OS_ID="${DISTRIB_ID,,}"
        OS_VERSION="${DISTRIB_RELEASE}"
        OS_PRETTY="${DISTRIB_DESCRIPTION}"
    fi

    export OS_ID OS_VERSION OS_PRETTY
    log_verbose "OS: $OS_PRETTY ($OS_ID $OS_VERSION)"
}

# ─── PHP Detection ───
detect_php() {
    PHP_BIN="${PHP_BIN:-}"
    PHP_VERSION=""
    PHP_SAPI=""
    PHP_MODULES=""

    # Prefer explicit PHP_BIN, then look in common locations
    if [ -z "$PHP_BIN" ]; then
        for candidate in \
            "/opt/lampp/bin/php" \
            "/usr/bin/php" \
            "/usr/local/bin/php" \
            "$(command -v php 2>/dev/null || true)"; do
            if [ -x "$candidate" ]; then
                PHP_BIN="$candidate"
                break
            fi
        done
    fi

    if [ -n "$PHP_BIN" ] && [ -x "$PHP_BIN" ]; then
        PHP_VERSION=$("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null || echo "unknown")
        PHP_SAPI=$("$PHP_BIN" -r 'echo PHP_SAPI;' 2>/dev/null || echo "unknown")
        PHP_MODULES=$("$PHP_BIN" -m 2>/dev/null | tr '\n' ' ' || echo "")
    else
        PHP_VERSION="missing"
        PHP_SAPI="missing"
        PHP_BIN="php"
    fi

    export PHP_BIN PHP_VERSION PHP_SAPI PHP_MODULES
    log_verbose "PHP: $PHP_VERSION ($PHP_SAPI) at $PHP_BIN"
}

# ─── PHP Extensions Check ───
# Usage: check_php_ext "mysqli pdo_mysql fileinfo mbstring intl gd zip xml curl"
check_php_ext() {
    local required="$1"
    local missing=()

    for ext in $required; do
        if ! echo "$PHP_MODULES" | grep -qi "\\b${ext}\\b"; then
            missing+=("$ext")
        fi
    done

    if [ ${#missing[@]} -gt 0 ]; then
        echo "${missing[*]}"
        return 1
    fi
    return 0
}

# ─── Apache Detection ───
detect_apache() {
    APACHE_BIN="${APACHE_BIN:-}"
    APACHE_VERSION=""
    APACHE_MPM=""
    APACHE_MODULES=""
    APACHE_VHOST_DIR="/etc/apache2/sites-available"
    APACHE_ENABLED_DIR="/etc/apache2/sites-enabled"

    if [ -z "$APACHE_BIN" ]; then
        for candidate in \
            "/opt/lampp/bin/httpd" \
            "/opt/lampp/bin/apachectl" \
            "/usr/sbin/httpd" \
            "/usr/sbin/apache2" \
            "/usr/sbin/apachectl" \
            "$(command -v apachectl 2>/dev/null || true)" \
            "$(command -v httpd 2>/dev/null || true)"; do
            if [ -x "$candidate" ]; then
                APACHE_BIN="$candidate"
                break
            fi
        done
    fi

    if [ -n "$APACHE_BIN" ] && [ -x "$APACHE_BIN" ]; then
        # Get version
        APACHE_VERSION=$("$APACHE_BIN" -v 2>/dev/null | head -n1 | sed 's/.* \([0-9.]*\).*/\1/' || echo "unknown")

        # Get MPM
        APACHE_MPM=$("$APACHE_BIN" -V 2>/dev/null | grep -i 'mpm' | head -n1 | sed 's/.*MPM=\([^ ]*\).*/\1/' || echo "unknown")

        # Get loaded modules
        APACHE_MODULES=$("$APACHE_BIN" -M 2>/dev/null | tr '\n' ' ' || echo "")
    else
        APACHE_VERSION="missing"
        APACHE_MPM="missing"
        APACHE_BIN=""
    fi

    # Detect vhost directories
    if [ -d /etc/apache2/sites-available ]; then
        APACHE_VHOST_DIR="/etc/apache2/sites-available"
        APACHE_ENABLED_DIR="/etc/apache2/sites-enabled"
    elif [ -d /etc/httpd/conf.d ]; then
        APACHE_VHOST_DIR="/etc/httpd/conf.d"
        APACHE_ENABLED_DIR="/etc/httpd/conf.d"
    elif [ -d /opt/lampp/etc/extra ]; then
        APACHE_VHOST_DIR="/opt/lampp/etc/extra"
        APACHE_ENABLED_DIR="/opt/lampp/etc/extra"
    fi

    export APACHE_BIN APACHE_VERSION APACHE_MPM APACHE_MODULES APACHE_VHOST_DIR APACHE_ENABLED_DIR
    log_verbose "Apache: $APACHE_VERSION ($APACHE_MPM) at $APACHE_BIN"
    log_verbose "Vhost dir: $APACHE_VHOST_DIR"
}

# ─── MariaDB/MySQL Detection ───
detect_mariadb() {
    MARIADB_BIN="${MARIADB_BIN:-}"
    MARIADB_VERSION=""
    MYSQL_BIN="${MYSQL_BIN:-}"

    if [ -z "$MARIADB_BIN" ]; then
        for candidate in \
            "/opt/lampp/bin/mysql" \
            "/usr/bin/mysql" \
            "/usr/local/bin/mysql" \
            "$(command -v mysql 2>/dev/null || true)"; do
            if [ -x "$candidate" ]; then
                MARIADB_BIN="$candidate"
                MYSQL_BIN="$candidate"
                break
            fi
        done
    fi

    if [ -n "$MARIADB_BIN" ] && [ -x "$MARIADB_BIN" ]; then
        MARIADB_VERSION=$("$MARIADB_BIN" --version 2>/dev/null | sed 's/.*Distrib \([0-9.]*\).*/\1/' || echo "unknown")
    else
        MARIADB_VERSION="missing"
        MARIADB_BIN="mysql"
    fi

    export MARIADB_BIN MARIADB_VERSION MYSQL_BIN
    log_verbose "MariaDB/MySQL: $MARIADB_VERSION at $MARIADB_BIN"
}

# ─── FPM Pool User Detection ───
# Detects the user/group that PHP-FPM runs as
detect_fpm_user() {
    WEB_USER="${WEB_USER_OVERRIDE:-}"
    WEB_GROUP="${WEB_GROUP_OVERRIDE:-}"

    # If explicitly set via override, use that
    if [ -n "$WEB_USER" ] && [ -n "$WEB_GROUP" ]; then
        export WEB_USER WEB_GROUP
        log_verbose "FPM user (override): $WEB_USER:$WEB_GROUP"
        return
    fi

    # Try to detect from php-fpm pool config
    local pool_configs=(
        "/etc/php/8.3/fpm/pool.d/www.conf"
        "/etc/php/8.2/fpm/pool.d/www.conf"
        "/etc/php/8.1/fpm/pool.d/www.conf"
        "/etc/php-fpm.d/www.conf"
        "/usr/local/etc/php-fpm.d/www.conf"
        "/opt/lampp/etc/php-fpm.d/www.conf"
    )

    for config in "${pool_configs[@]}"; do
        if [ -f "$config" ]; then
            local user group
            user=$(grep -E '^user\s*=' "$config" | head -n1 | sed 's/.*=\s*//' | tr -d ' ')
            group=$(grep -E '^group\s*=' "$config" | head -n1 | sed 's/.*=\s*//' | tr -d ' ')
            if [ -n "$user" ] && [ -n "$group" ]; then
                WEB_USER="$user"
                WEB_GROUP="$group"
                export WEB_USER WEB_GROUP
                log_verbose "FPM user (detected from $config): $WEB_USER:$WEB_GROUP"
                return
            fi
        fi
    done

    # Try to detect from running processes
    if command -v ps >/dev/null 2>&1; then
        local fpm_user
        fpm_user=$(ps aux | grep -E 'php-fpm: pool' | grep -v grep | head -n1 | awk '{print $1}')
        if [ -n "$fpm_user" ] && [ "$fpm_user" != "root" ]; then
            WEB_USER="$fpm_user"
            WEB_GROUP="$fpm_user"
            export WEB_USER WEB_GROUP
            log_verbose "FPM user (detected from ps): $WEB_USER:$WEB_GROUP"
            return
        fi
    fi

    # Fallback to common defaults
    if id "www-data" >/dev/null 2>&1; then
        WEB_USER="www-data"
        WEB_GROUP="www-data"
    elif id "daemon" >/dev/null 2>&1; then
        WEB_USER="daemon"
        WEB_GROUP="daemon"
    elif id "nginx" >/dev/null 2>&1; then
        WEB_USER="nginx"
        WEB_GROUP="nginx"
    elif id "apache" >/dev/null 2>&1; then
        WEB_USER="apache"
        WEB_GROUP="apache"
    else
        WEB_USER="www-data"
        WEB_GROUP="www-data"
        log_warn "Could not detect FPM user, defaulting to www-data"
    fi

    export WEB_USER WEB_GROUP
    log_verbose "FPM user (fallback): $WEB_USER:$WEB_GROUP"
}

# ─── Systemd Detection ───
detect_systemd() {
    HAS_SYSTEMD=false
    if command -v systemctl >/dev/null 2>&1 && systemctl --version >/dev/null 2>&1; then
        HAS_SYSTEMD=true
    fi
    export HAS_SYSTEMD
}

# ─── a2enmod/a2ensite Detection ───
detect_apache_tools() {
    HAS_A2ENMOD=false
    HAS_A2ENSITE=false
    HAS_A2DISMOD=false
    HAS_A2DISSITE=false

    command -v a2enmod >/dev/null 2>&1 && HAS_A2ENMOD=true
    command -v a2ensite >/dev/null 2>&1 && HAS_A2ENSITE=true
    command -v a2dismod >/dev/null 2>&1 && HAS_A2DISMOD=true
    command -v a2dissite >/dev/null 2>&1 && HAS_A2DISSITE=true

    export HAS_A2ENMOD HAS_A2ENSITE HAS_A2DISMOD HAS_A2DISSITE
}

# ─── Main detection entry point ───
# Usage: detect_environment [--verbose]
detect_environment() {
    local verbose="${1:-false}"
    if [ "$verbose" = "true" ]; then
        export VERBOSE=true
    fi

    log_step "Detecting environment"

    detect_os
    detect_privileges
    detect_pkg_mgr
    detect_php
    detect_apache
    detect_mariadb
    detect_fpm_user
    detect_systemd
    detect_apache_tools

    log_ok "Environment detection complete"
}

# ─── Export all functions ───
if [ -n "${BASH_VERSION:-}" ]; then
    export -f detect_os detect_php check_php_ext detect_apache detect_mariadb 2>/dev/null || true
    export -f detect_fpm_user detect_systemd detect_apache_tools detect_environment 2>/dev/null || true
fi