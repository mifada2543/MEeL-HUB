#!/usr/bin/env bash
# shellcheck shell=bash
# Shared helpers for installer regression tests.
set -euo pipefail

meel_test_root() {
    local script_dir
    script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
    cd "$script_dir/../.." && pwd
}

# Copy the working tree (tracked + untracked, minus ignored files) so tests
# exercise the code being edited, not only the last commit.
meel_test_copy_tree() {
    local dest="$1" root
    root=$(meel_test_root)
    mkdir -p "$dest"
    git -C "$root" ls-files -z -co --exclude-standard |
        tar -C "$root" --null -T - -cf - |
        tar -C "$dest" -xf -
}

meel_test_mysql_bin() {
    if [ -n "${MEEL_TEST_MYSQL_BIN:-}" ]; then
        printf '%s\n' "$MEEL_TEST_MYSQL_BIN"
        return 0
    fi

    local candidate
    for candidate in \
        /opt/lampp/bin/mysql \
        "$(command -v mariadb 2>/dev/null || true)" \
        "$(command -v mysql 2>/dev/null || true)"; do
        if [ -n "$candidate" ] && [ -x "$candidate" ]; then
            printf '%s\n' "$candidate"
            return 0
        fi
    done

    printf 'mysql/mariadb client not found\n' >&2
    return 1
}

meel_test_mysql() {
    local host="$1" user="$2" pass="$3"
    shift 3

    local mysql_bin
    mysql_bin=$(meel_test_mysql_bin)
    local -a args=("$mysql_bin" -h "$host" -u "$user")
    if [ -n "$pass" ]; then
        args+=(-p"$pass")
    fi
    "${args[@]}" "$@"
}

meel_test_db_name() {
    printf 'meel_installer_test_%s_%s\n' "$(date +%s)" "$RANDOM"
}

meel_test_db_create() {
    local host="$1" user="$2" pass="$3" db="$4"
    meel_test_mysql "$host" "$user" "$pass" \
        -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
}

meel_test_db_drop() {
    local host="$1" user="$2" pass="$3" db="$4"
    meel_test_mysql "$host" "$user" "$pass" \
        -e "DROP DATABASE IF EXISTS \`$db\`;"
}

# A deterministic "no php-fpm process" environment. The real installer must
# fall back instead of aborting when this command returns no matching lines.
meel_test_make_no_fpm_bin() {
    local bin="$1"
    mkdir -p "$bin"
    cat > "$bin/ps" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
    chmod +x "$bin/ps"
}

# The installer invokes sudo for file ownership and service restarts. Keep the
# test runnable on a developer machine that has sudo but not passwordless sudo
# by providing a no-op shim; file operations on the disposable tree are owned
# by the current user anyway. CI uses its real passwordless sudo.
meel_test_prepare_bin() {
    local bin="$1"
    meel_test_make_no_fpm_bin "$bin"
    if sudo -n true 2>/dev/null; then
        return 0
    fi
    cat > "$bin/sudo" <<'EOF'
#!/usr/bin/env bash
case "$1" in
    systemctl|service|chown|chmod|chgrp|a2enmod|a2ensite|a2dismod|a2dissite|phpenmod|/opt/lampp/lampp)
        exit 0
        ;;
    *)
        exec "$@"
        ;;
esac
EOF
    chmod +x "$bin/sudo"
}
