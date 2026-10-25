#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034
#
# Database setup for MEeL installer
# Source: source "$(dirname "$0")/lib/install/database.sh"
# Requires: common.sh, detect.sh

# ─── Test database connection ───
db_test_connection() {
    local host="$1" user="$2" pass="$3" db="${4:-}"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would test connection to $host as $user"
        return 0
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")
    [ -n "$db" ] && mysql_cmd+=("$db")

    "${mysql_cmd[@]}" -e "SELECT 1;" >/dev/null 2>&1
}

# ─── Create database if not exists ───
db_create_if_missing() {
    local host="$1" user="$2" pass="$3" db="$4"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create database: $db"
        return 0
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")

    local mysql_err
    if mysql_err=$("${mysql_cmd[@]}" -e "CREATE DATABASE IF NOT EXISTS \`${db}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>&1); then
        log_ok "Database '\${db}' ready"
        return 0
    else
        log_fail "Failed to create database '\${db}': ${mysql_err:-unknown error}"
        return 1
    fi
}

# ─── Import schema.sql ───
db_import_schema() {
    local host="$1" user="$2" pass="$3" db="$4"
    local schema_file="${5:-database/schema.sql}"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would import schema to: $db"
        return 0
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")

    if [ ! -f "$schema_file" ]; then
        log_fail "Schema file not found: $schema_file"
        return 1
    fi

    if grep -qiE '^[[:space:]]*(CREATE[[:space:]]+DATABASE|USE[[:space:]])' "$schema_file"; then
        log_warn "schema.sql contains CREATE DATABASE / USE -- this may cause import issues"
    fi

    local mysql_err
    if mysql_err=$("${mysql_cmd[@]}" "$db" < "$schema_file" 2>&1); then
        log_ok "Schema imported to '\${db}'"
        return 0
    else
        log_fail "Schema import failed: ${mysql_err:-unknown error}"
        return 1
    fi
}

# ─── Check if database exists and has tables ───
db_exists_and_populated() {
    local host="$1" user="$2" pass="$3" db="$4"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        return 1  # Pretend DB doesn't exist in dry-run
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")

    local count
    count=$("${mysql_cmd[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${db}';" 2>/dev/null || echo "0")
    [ "$count" -gt 0 ]
}

# ─── Create dedicated app database user ───
db_create_app_user() {
    local host="$1" root_user="$2" root_pass="$3" db="$4" app_user="$5" app_pass="$6"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create app user: $app_user@$host"
        return 0
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$root_user")
    [ -n "$root_pass" ] && mysql_cmd+=(-p"$root_pass")

    # Execute each statement separately to avoid quoting issues
    local mysql_err
    if ! mysql_err=$("${mysql_cmd[@]}" -e "CREATE USER IF NOT EXISTS '${app_user}'@'${host}' IDENTIFIED BY '${app_pass}';" 2>&1); then
        log_fail "Failed to create app user '$app_user'@'$host': ${mysql_err:-unknown error}"
        return 1
    fi
    if ! mysql_err=$("${mysql_cmd[@]}" -e "GRANT ALL PRIVILEGES ON \`${db}\`.* TO '${app_user}'@'${host}';" 2>&1); then
        log_fail "Failed to grant privileges to '$app_user'@'$host': ${mysql_err:-unknown error}"
        return 1
    fi
    if ! mysql_err=$("${mysql_cmd[@]}" -e "FLUSH PRIVILEGES;" 2>&1); then
        log_fail "Failed to flush database privileges: ${mysql_err:-unknown error}"
        return 1
    fi

    log_ok "App database user '\${app_user}'@'\${host}' created"
    return 0
}

# ─── Create admin user ───
db_create_admin_user() {
    local host="$1" user="$2" pass="$3" db="$4" admin_user="$5" admin_pass="$6"

    if [ "${DRY_RUN:-false}" = "true" ]; then
        log_verbose "[DRY-RUN] Would create admin user: $admin_user"
        return 0
    fi

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")

    local existing
    existing=$("${mysql_cmd[@]}" "$db" -N -e "SELECT COUNT(*) FROM users WHERE role='admin';" 2>/dev/null || echo "0")
    if [ "$existing" -gt 0 ]; then
        local existing_user
        existing_user=$("${mysql_cmd[@]}" "$db" -N -e "SELECT username FROM users WHERE role='admin' LIMIT 1;" 2>/dev/null || echo "(unknown)")
        log_warn "Admin user already exists: \${existing_user}"
        return 0
    fi

    local admin_hash
    if ! admin_hash=$(MEEL_ADMIN_PASS="$admin_pass" "$PHP_BIN" -r 'echo password_hash((string) getenv("MEEL_ADMIN_PASS"), PASSWORD_DEFAULT);' 2>&1); then
        log_fail "Failed to generate password hash: ${admin_hash:-unknown error}"
        return 1
    fi
    if [ -z "$admin_hash" ]; then
        log_fail "Failed to generate password hash"
        return 1
    fi

    local admin_user_esc
    admin_user_esc=$(printf '%s' "$admin_user" | sed "s/'/''/g")

    local sql="INSERT INTO \`users\` (\`username\`, \`role\`, \`password\`, \`is_active\`) VALUES ('${admin_user_esc}', 'admin', '${admin_hash}', 1);"

    local mysql_err
    if mysql_err=$("${mysql_cmd[@]}" "$db" -e "$sql" 2>&1); then
        log_ok "Admin user '\${admin_user}' created"
        return 0
    else
        log_fail "Failed to create admin user: ${mysql_err:-unknown error}"
        return 1
    fi
}

# ─── Verify migration table exists ───
db_verify_migration_ready() {
    local host="$1" user="$2" pass="$3" db="$4"

    local mysql_cmd=("$MYSQL_BIN" -h "$host" -u "$user")
    [ -n "$pass" ] && mysql_cmd+=(-p"$pass")

    "${mysql_cmd[@]}" "$db" -e "DESCRIBE \`migrations\`;" >/dev/null 2>&1
}

# ─── Main database step ───
database_run() {
    local db_host="${DB_HOST:-localhost}"
    local db_name="${DB_NAME:-MEeL}"
    local db_user="${DB_USER:-root}"
    local db_pass="${DB_PASS:-}"
    local admin_user="${ADMIN_USER:-}"
    local admin_pass="${ADMIN_PASS:-}"
    local create_app_user="${CREATE_APP_USER:-true}"

    log_step "Setting up database"

    if ! db_test_connection "$db_host" "$db_user" "$db_pass"; then
        log_fail "Cannot connect to MySQL/MariaDB at $db_host with user $db_user"
        log_info "Check: service running? credentials correct? host reachable?"
        return 1
    fi
    log_ok "Database connection successful"

    if [ "${HAS_SYSTEMD:-false}" = "true" ]; then
        sudo_cmd "systemctl start mariadb 2>/dev/null || systemctl start mysql 2>/dev/null || true" "Start MariaDB service"
    elif command -v service >/dev/null 2>&1; then
        sudo_cmd "service mariadb start 2>/dev/null || service mysql start 2>/dev/null || true" "Start MariaDB service"
    fi
    sleep 1

    if db_exists_and_populated "$db_host" "$db_user" "$db_pass" "$db_name"; then
        log_warn "Database '\${db_name}' already exists with tables -- skipping import"
        log_info "To reimport: DROP DATABASE \`${db_name}\`; then re-run installer"
        if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Force reimport schema.sql now? (may fail on duplicate data)" N; then
            db_import_schema "$db_host" "$db_user" "$db_pass" "$db_name" || log_warn "Reimport failed -- continuing with existing data"
        fi
    else
        db_create_if_missing "$db_host" "$db_user" "$db_pass" "$db_name" || return 1
        db_import_schema "$db_host" "$db_user" "$db_pass" "$db_name" || return 1
    fi

    if [ "$db_user" = "root" ] && [ "$create_app_user" = "true" ]; then
        log_warn "Using 'root' for database -- creating dedicated app user is recommended"
        if [ "${ASSUME_YES:-false}" = "true" ] || confirm "Create dedicated database user for application?" Y; then
            local app_user="${APP_DB_USER:-meel_app}"
            local app_pass
            app_pass=$(openssl rand -hex 16 2>/dev/null || head -c16 /dev/urandom | od -An -tx1 | tr -d ' \n')
            if db_create_app_user "$db_host" "$db_user" "$db_pass" "$db_name" "$app_user" "$app_pass"; then
                db_user="$app_user"
                db_pass="$app_pass"
                log_info "App will use dedicated user: $app_user"
            else
                log_warn "Continuing with root credentials (not recommended for production)"
            fi
        fi
    fi

    if [ -z "$admin_user" ]; then
        if [ "${ASSUME_YES:-false}" = "true" ]; then
            log_fail "Admin username required in --yes mode (use --admin-user)"
            return 1
        fi
        while true; do
            admin_user=$(ask "Admin username" "")
            admin_user=$(echo "$admin_user" | xargs)
            [ -z "$admin_user" ] && { log_warn "Username cannot be empty"; continue; }
            echo "$admin_user" | grep -q ' ' && { log_warn "Username cannot contain spaces"; continue; }
            break
        done
    fi

    if [ -z "$admin_pass" ]; then
        if [ "${ASSUME_YES:-false}" = "true" ]; then
            log_fail "Admin password required in --yes mode (use --admin-pass)"
            return 1
        fi
        while true; do
            admin_pass=$(ask_secret "Admin password" "")
            [ -z "$admin_pass" ] && { log_warn "Password cannot be empty"; continue; }
            break
        done
    fi

    db_create_admin_user "$db_host" "$db_user" "$db_pass" "$db_name" "$admin_user" "$admin_pass" || return 1

    export DB_HOST="$db_host"
    export DB_NAME="$db_name"
    export DB_USER="$db_user"
    export DB_PASS="$db_pass"
    export ADMIN_USER="$admin_user"
    export ADMIN_PASS="$admin_pass"
}

# Export functions
if [ -n "${BASH_VERSION:-}" ]; then
    export -f db_test_connection db_create_if_missing db_import_schema 2>/dev/null || true
    export -f db_exists_and_populated db_create_app_user db_create_admin_user 2>/dev/null || true
    export -f db_verify_migration_ready database_run 2>/dev/null || true
fi
