#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Apache configuration for MEeL installer
# Source: source "$(dirname "$0")/lib/install/apache.sh"
# Requires: common.sh, detect.sh

# ─── Enable mod_rewrite ───
apache_enable_rewrite() {
    if [ "${HAS_A2ENMOD:-false}" = "true" ] && [ "${CAN_ELEVATE:-true}" = "true" ]; then
        if sudo_cmd "a2enmod rewrite" "Enable mod_rewrite"; then
            log_ok "mod_rewrite enabled"
        else
            log_warn "Failed to enable mod_rewrite via a2enmod"
        fi
    else
        log_warn "a2enmod not available or no sudo — enable mod_rewrite manually"
    fi
}

# ─── Restart Apache ───
apache_restart() {
    if [ "${HAS_SYSTEMD:-false}" = "true" ] && [ "${CAN_ELEVATE:-true}" = "true" ]; then
        sudo_cmd "systemctl restart apache2 2>/dev/null || systemctl restart httpd 2>/dev/null || true" "Restart Apache (systemd)"
    elif command -v service >/dev/null 2>&1 && [ "${CAN_ELEVATE:-true}" = "true" ]; then
        sudo_cmd "service apache2 restart 2>/dev/null || service httpd restart 2>/dev/null || true" "Restart Apache (service)"
    elif [ -x "/opt/lampp/lampp" ] && [ "${CAN_ELEVATE:-true}" = "true" ]; then
        sudo_cmd "/opt/lampp/lampp restart" "Restart XAMPP"
    else
        log_warn "Cannot restart Apache automatically — restart manually"
        return 1
    fi
}

# ─── Generate VirtualHost config ───
apache_generate_vhost() {
    local domain="${VHOST_DOMAIN:-}"
    local project_root="${PROJECT_ROOT:-}"
    local php_fpm_socket="${PHP_FPM_SOCKET:-}"
    local php_fpm_port="${PHP_FPM_PORT:-9000}"

    [ -z "$domain" ] || [ -z "$project_root" ] && return 1

    # Detect FPM connection
    local fpm_config=""
    if [ -n "$php_fpm_socket" ] && [ -S "$php_fpm_socket" ]; then
        fpm_config="ProxyPassMatch ^/(.*\.php(/.*)?)$ unix:$php_fpm_socket|fcgi://localhost$project_root/"
    elif [ -n "$php_fpm_port" ]; then
        fpm_config="ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:$php_fpm_port$project_root/"
    else
        # Default to TCP port 9000 (common for PHP-FPM)
        fpm_config="ProxyPassMatch ^/(.*\.php(/.*)?)$ fcgi://127.0.0.1:9000$project_root/"
    fi

    cat <<VHOSTEOF
<VirtualHost *:80>
    ServerName ${domain}
    UseCanonicalName On
    DocumentRoot ${project_root}

    # PHP-FPM proxy
    ${fpm_config}

    <Directory ${project_root}>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Deny access to sensitive directories
    <Directory ${project_root}/vendor>
        Require all denied
    </Directory>
    <Directory ${project_root}/node_modules>
        Require all denied
    </Directory>
    <Directory ${project_root}/tools>
        Require all denied
    </Directory>
    <Directory ${project_root}/storage>
        Require all denied
    </Directory>
    <Directory ${project_root}/assets/dict>
        Require all denied
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/meel-error.log
    CustomLog \${APACHE_LOG_DIR}/meel-access.log combined
</VirtualHost>
VHOSTEOF
}

# ─── Write and enable VirtualHost ───
apache_create_vhost() {
    local domain="${VHOST_DOMAIN:-}"
    local vhost_file="${APACHE_VHOST_DIR:-/etc/apache2/sites-available}/meel.conf"

    [ -z "$domain" ] && return 1

    log_step "Creating VirtualHost for ${domain}"

    local vhost_config
    vhost_config=$(apache_generate_vhost "$domain")

    if [ "${CAN_ELEVATE:-true}" = "true" ] && [ -n "${SUDO:-}" ]; then
        printf '%s\n' "$vhost_config" | $SUDO tee "$vhost_file" >/dev/null
        log_ok "VirtualHost written to $vhost_file"

        if [ "${HAS_A2ENSITE:-false}" = "true" ]; then
            $SUDO a2ensite meel >/dev/null 2>&1 || true
            log_ok "Site enabled"
        fi
    else
        log_warn "Cannot write VirtualHost (no sudo) — manual config needed:"
        echo ""
        printf '%s\n' "$vhost_config"
        echo ""
        log_info "Save to: $vhost_file"
        log_info "Then: a2ensite meel && systemctl restart apache2"
        return 1
    fi
}

# ─── Check mod_xsendfile ───
apache_check_xsendfile() {
    if [ "${USE_XSENDFILE:-false}" != "true" ]; then
        return 0
    fi

    log_step "Checking mod_xsendfile configuration"

    # Check if module is loaded
    local mod_out
    mod_out=$(apache_get_modules 2>/dev/null || true)

    if echo "$mod_out" | grep -qi xsendfile; then
        log_ok "mod_xsendfile module loaded"
    else
        log_warn "mod_xsendfile NOT loaded — streaming will use PHP fallback"
        log_info "To enable: compile mod_xsendfile and add to Apache config"
    fi

    # Check XSendFilePath configuration hint
    local hdd_base="${HDD_BASE:-}"
    if [ -n "$hdd_base" ]; then
        log_info "Required XSendFilePath directives for Apache config:"
        log_info "  XSendFilePath \"${hdd_base}\""
        log_info "  XSendFilePath \"${project_root}\""
        log_info "  XSendFilePath \"/dev/shm/meel\"  # transcode output"
    fi
}

# ─── Get loaded Apache modules ───
apache_get_modules() {
    local bin
    for bin in "/opt/lampp/bin/apachectl" "/opt/lampp/bin/httpd" "apache2ctl" "apachectl" "httpd"; do
        if [ -x "$bin" ]; then
            "$bin" -M 2>/dev/null && return 0
        fi
    done
    return 1
}

# ─── Verify Apache + PWA (sw.js rewrite) ───
apache_verify_pwa() {
    local url="${1:-http://localhost/}"
    local probe_url="${url%/}/sw.js"

    log_step "Verifying Apache + mod_rewrite (PWA)"

    # Check .htaccess has rewrite rule
    local htaccess_file="${PROJECT_ROOT:-}/.htaccess"
    if [ -f "$htaccess_file" ]; then
        if grep -q "RewriteEngine On" "$htaccess_file" && grep -q "sw.js.php" "$htaccess_file"; then
            log_ok ".htaccess has PWA rewrite rules"
        else
            log_warn ".htaccess missing PWA rewrite rules"
        fi
    fi

    # HTTP probe (optional, only if URL provided and server running)
    if [ -n "$url" ] && command -v curl >/dev/null 2>&1; then
        local response
        response=$(curl -sS -w '\nHTTP:%{http_code}\nCT:%{content_type}' --max-time 5 "$probe_url" 2>/dev/null || true)
        local http_code
        http_code=$(printf '%s\n' "$response" | grep '^HTTP:' | cut -d: -f2 || true)
        local content_type
        content_type=$(printf '%s\n' "$response" | grep '^CT:' | cut -d: -f2 || true)

        if [ "$http_code" = "200" ] && echo "$content_type" | grep -qi javascript; then
            log_ok "PWA rewrite working: $probe_url → HTTP 200 (JavaScript)"
        elif [ -n "$http_code" ]; then
            log_warn "PWA probe: $probe_url → HTTP $http_code (Content-Type: $content_type)"
        fi
    fi
}

# ─── Main Apache step ───
apache_run() {
    local vhost_domain="${VHOST_OVERRIDE:-}"
    local enable_rewrite="${ENABLE_REWRITE:-true}"

    log_step "Configuring Apache"

    # Enable mod_rewrite
    if [ "$enable_rewrite" = "true" ]; then
        if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Enable mod_rewrite and restart Apache?" Y; then
            apache_enable_rewrite
            apache_restart
        fi
    fi

    # Create VirtualHost if domain provided
    if [ -n "$vhost_domain" ]; then
        apache_create_vhost "$vhost_domain"
        apache_restart
        log_info "Add to /etc/hosts: 127.0.0.1 $vhost_domain"
    else
        log_info "VirtualHost skipped — access via DocumentRoot/subfolder (e.g., http://host/MEeL)"
    fi

    # Check mod_xsendfile if enabled
    if [ "${USE_XSENDFILE:-false}" = "true" ]; then
        apache_check_xsendfile
    fi

    # Verify PWA rewrite
    apache_verify_pwa "http://localhost/"
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f apache_enable_rewrite apache_restart apache_generate_vhost 2>/dev/null || true
    export -f apache_create_vhost apache_check_xsendfile apache_get_modules 2>/dev/null || true
    export -f apache_verify_pwa apache_run 2>/dev/null || true
fi