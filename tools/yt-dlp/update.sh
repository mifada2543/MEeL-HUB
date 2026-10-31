#!/usr/bin/env bash
# Update yt-dlp binary standalone dari GitHub Releases
# Jalankan manual: cd tools/yt-dlp && ./update.sh

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

log() { printf '\033[1;32m[%s]\033[0m %s\n' "$(date -u +%H:%M:%S)" "$*"; }
err() { printf '\033[1;31m[ERROR]\033[0m %s\n' "$*" >&2; }

# Download binary Linux x86_64 (static, PyInstaller)
log "Download yt-dlp terbaru..."
curl -fL --progress-bar "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux" -o yt-dlp.new
chmod +x yt-dlp.new

# Verifikasi
log "Verifikasi binary..."
./yt-dlp.new --version

# Ganti binary lama
mv yt-dlp.new yt-dlp

log "✅ yt-dlp updated: $(./yt-dlp --version)"

# Catat versi ke file
./yt-dlp --version > VERSION.txt
echo "Updated: $(date -u +"%Y-%m-%d %H:%M:%S UTC")" >> VERSION.txt