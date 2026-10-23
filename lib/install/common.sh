#!/usr/bin/env bash
# shellcheck shell=bash
# shellcheck disable=SC2034,SC2155
#
# Common utilities for MEeL installer modules
# Source this file: source "$(dirname "$0")/lib/install/common.sh"

# ─── Strict mode ───
set -euo pipefail

# ─── Colors ───
if [ -t 1 ]; then
    C_RESET='\033[0m'; C_BOLD='\033[1m'
    C_RED='\033[1;31m'; C_GREEN='\033[1;32m'; C_YELLOW='\033[1;33m'; C_CYAN='\033[1;36m'; C_DIM='\033[2m'
else
    C_RESET=''; C_BOLD=''; C_RED=''; C_GREEN=''; C_YELLOW=''; C_CYAN=''; C_DIM=''
fi

# ─── Logging helpers ───
log_step()   { echo -e "\n${C_CYAN}==>${C_RESET} ${C_BOLD}$1${C_RESET}"; }
log_ok()     { echo -e "  ${C_GREEN}✔${C_RESET} $1"; }
log_warn()   { echo -e "  ${C_YELLOW}⚠${C_RESET} $1"; }
log_fail()   { echo -e "  ${C_RED}✘${C_RESET} $1"; }
log_info()   { echo -e "  ${C_CYAN}ℹ${C_RESET} $1"; }
log_die()    { log_fail "$1"; exit 1; }
log_verbose() {
    if [ "${VERBOSE:-false}" = "true" ]; then
        echo -e "  ${C_DIM}→${C_RESET} $1"
    fi
}

# ─── Interactive helpers ───
# Usage: confirm "Prompt?" [Y|N] — returns 0 for yes, 1 for no
confirm() {
    local prompt="$1" default="${2:-Y}" reply
    if [ "${ASSUME_YES:-false}" = "true" ]; then
        [ "$default" = "Y" ]
        return
    fi
    read -r -p "  $prompt (y = ya, t = tidak) " reply || true
    reply="${reply:-$default}"
    [[ "$reply" =~ ^[Yy]$ ]]
}

# Usage: ask "Prompt" "default" — prints answer to stdout
ask() {
    local prompt="$1" default="$2" reply
    if [ "${ASSUME_YES:-false}" = "true" ]; then
        echo "$default"
        return
    fi
    read -r -p "  $prompt [$default]: " reply || true
    echo "${reply:-$default}"
}

# Usage: ask_secret "Prompt" "default" — prints answer to stdout (hidden input)
ask_secret() {
    local prompt="$1" default="$2" reply
    if [ "${ASSUME_YES:-false}" = "true" ]; then
        echo "$default"
        return
    fi
    read -r -s -p "  $prompt: " reply || true
    echo ""
    echo "${reply:-$default}"
}

# ─── Project root detection ───
# Sets PROJECT_ROOT and cds to it
detect_project_root() {
    PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[1]}")" && pwd)"
    cd "$PROJECT_ROOT"
    export PROJECT_ROOT
}

# ─── Validation helpers ───
require_file() {
    local file="$1" msg="${2:-Required file not found: $1}"
    [ -f "$file" ] || log_die "$msg"
}

require_dir() {
    local dir="$1" msg="${2:-Required directory not found: $1}"
    [ -d "$dir" ] || log_die "$msg"
}

require_command() {
    local cmd="$1" msg="${2:-Required command not found: $1}"
    command -v "$cmd" >/dev/null 2>&1 || log_die "$msg"
}

# ─── Privilege detection ───
# Sets SUDO, CAN_ELEVATE
detect_privileges() {
    SUDO=""
    CAN_ELEVATE=true
    if [ "$(id -u)" -ne 0 ]; then
        if command -v sudo >/dev/null 2>&1; then
            SUDO="sudo"
        else
            CAN_ELEVATE=false
            log_warn "Bukan root dan 'sudo' tidak tersedia — operasi butuh privilege mungkin gagal."
        fi
    fi
    export SUDO CAN_ELEVATE
}

# ─── Package manager detection ───
# Sets HAS_APT, HAS_DNF, HAS_PACMAN, HAS_APK, PKG_MGR
detect_pkg_mgr() {
    HAS_APT=false; HAS_DNF=false; HAS_PACMAN=false; HAS_APK=false
    PKG_MGR=""
    if command -v apt-get >/dev/null 2>&1; then HAS_APT=true; PKG_MGR="apt"; fi
    if command -v dnf >/dev/null 2>&1; then HAS_DNF=true; PKG_MGR="dnf"; fi
    if command -v pacman >/dev/null 2>&1; then HAS_PACMAN=true; PKG_MGR="pacman"; fi
    if command -v apk >/dev/null 2>&1; then HAS_APK=true; PKG_MGR="apk"; fi
    export HAS_APT HAS_DNF HAS_PACMAN HAS_APK PKG_MGR
}

# ─── Dry-run support ───
# Usage: run_cmd "command" "description"
# If DRY_RUN=true, prints command instead of executing
run_cmd() {
    local cmd="$1" desc="${2:-}" dry_run="${DRY_RUN:-false}"
    if [ "$dry_run" = "true" ]; then
        log_verbose "[DRY-RUN] $cmd"
        [ -n "$desc" ] && log_verbose "  ($desc)"
        return 0
    fi
    [ -n "$desc" ] && log_verbose "$desc"
    eval "$cmd"
}

# ─── Sudo wrapper ───
# Usage: sudo_cmd "command" "description"
sudo_cmd() {
    local cmd="$1" desc="${2:-}" dry_run="${DRY_RUN:-false}"
    if [ "${CAN_ELEVATE:-true}" = "true" ] && [ -n "${SUDO:-}" ]; then
        run_cmd "$SUDO $cmd" "$desc"
    else
        run_cmd "$cmd" "$desc"
    fi
}

# ─── Banner ───
print_banner() {
    echo -e "${C_BOLD}"
    echo "  __  __ _____     _        _   _ _   _ ____  "
    echo " |  \/  | ____|___| |      | | | | | | | __ ) "
    echo " | |\/| |  _| / _ \ |______| |_| | | | |  _ \ "
    echo " | |  | | |__|  __/ |______|  _  | |_| | |_) |"
    echo " |_|  |_|_____\___|_|      |_| |_|\___/|____/ "
    echo -e "${C_RESET}"
    echo " Installer — clone → install → run"
    echo " Project root: ${PROJECT_ROOT:-unknown}"
}

# ─── Array to string (space-separated) ───
join_array() {
    local IFS=" "
    echo "$*"
}

# ─── Export all functions for subshells ───
# Only export if in bash and functions exist
if [ -n "${BASH_VERSION:-}" ]; then
    export -f log_step log_ok log_warn log_fail log_info log_die log_verbose 2>/dev/null || true
    export -f confirm ask ask_secret 2>/dev/null || true
    export -f detect_project_root require_file require_dir require_command 2>/dev/null || true
    export -f detect_privileges detect_pkg_mgr run_cmd sudo_cmd print_banner join_array 2>/dev/null || true
fi