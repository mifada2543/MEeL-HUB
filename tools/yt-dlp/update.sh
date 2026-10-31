#!/usr/bin/env bash
# Update yt-dlp binary standalone dari GitHub Releases.
# Jalankan manual: cd tools/yt-dlp && ./update.sh
#
# Binary tidak lagi ikut paket rilis; skrip ini mengunduhnya ke direktori ini
# dan memverifikasi checksum rilis sebelum mengganti binary lama.
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

log() { printf '\033[1;32m[%s]\033[0m %s\n' "$(date -u +%H:%M:%S)" "$*"; }
err() { printf '\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; }

case "$(uname -m)" in
    x86_64|amd64) ASSET="yt-dlp_linux" ;;
    aarch64|arm64) ASSET="yt-dlp_linux_aarch64" ;;
    *) err "Arsitektur $(uname -m) belum didukung skrip ini"; exit 1 ;;
esac

BASE="${YTDLP_DOWNLOAD_BASE:-https://github.com/yt-dlp/yt-dlp/releases/latest/download}"
TMP=$(mktemp -d "${TMPDIR:-/tmp}/meel-ytdlp-update.XXXXXX")
trap 'rm -rf "$TMP"' EXIT

log "Mengunduh $ASSET terbaru..."
curl -fsSL --retry 3 --connect-timeout 15 --max-time 300 -o "$TMP/$ASSET" "$BASE/$ASSET"
curl -fsSL --retry 3 --connect-timeout 15 --max-time 60 -o "$TMP/SHA2-256SUMS" "$BASE/SHA2-256SUMS"

EXPECTED=$(awk -v name="$ASSET" '$2 == name { print $1; exit }' "$TMP/SHA2-256SUMS")
if [ -z "$EXPECTED" ]; then
    err "Checksum untuk $ASSET tidak ditemukan di SHA2-256SUMS"
    exit 1
fi
if command -v sha256sum >/dev/null 2>&1; then
    ACTUAL=$(sha256sum "$TMP/$ASSET" | awk '{ print $1 }')
else
    ACTUAL=$(shasum -a 256 "$TMP/$ASSET" | awk '{ print $1 }')
fi
if [ "$EXPECTED" != "$ACTUAL" ]; then
    err "Checksum tidak cocok (expected $EXPECTED, dapat $ACTUAL)"
    exit 1
fi

chmod +x "$TMP/$ASSET"
log "Memverifikasi binary..."
"$TMP/$ASSET" --version >/dev/null

mv "$TMP/$ASSET" yt-dlp
chmod 0755 yt-dlp

log "✅ yt-dlp updated: $(./yt-dlp --version)"

./yt-dlp --version > VERSION.txt
echo "Updated: $(date -u +"%Y-%m-%d %H:%M:%S UTC")" >> VERSION.txt
