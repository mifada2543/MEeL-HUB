#!/usr/bin/env bash
# Regression test: yt-dlp is no longer bundled, so the installer must be able
# to download it, verify its checksum, and refuse a tampered download.
set -euo pipefail

HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ROOT=$(cd "$HERE/../.." && pwd)

# shellcheck source=common.sh
source "$HERE/common.sh"
# shellcheck source=../../lib/install/common.sh
source "$ROOT/lib/install/common.sh"
# shellcheck source=../../lib/install/deps.sh
source "$ROOT/lib/install/deps.sh"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

ASSET_DIR="$TMP/assets"
PROJECT_ROOT="$TMP/project"
mkdir -p "$ASSET_DIR" "$PROJECT_ROOT/tools/yt-dlp"
export PROJECT_ROOT
export YTDLP_DOWNLOAD_BASE="file://$ASSET_DIR"
export YTDLP_FORCE_DOWNLOAD=true

ASSET="$(deps_ytdlp_asset)" || {
    echo "arsitektur tidak didukung test: SKIP"
    exit 0
}

# Dry-run must not touch the network or create a binary.
DRY_ROOT="$TMP/dry-project"
mkdir -p "$DRY_ROOT"
DRY_RUN=true PROJECT_ROOT="$DRY_ROOT" deps_install_ytdlp >/dev/null
[ ! -e "$DRY_ROOT/tools/yt-dlp/yt-dlp" ] || { echo "dry-run membuat binary" >&2; exit 1; }

cat > "$ASSET_DIR/$ASSET" <<'EOF'
#!/usr/bin/env bash
echo "2099.01.01"
EOF
chmod +x "$ASSET_DIR/$ASSET"
EXPECTED="$(deps_sha256 "$ASSET_DIR/$ASSET")"
printf '%s  %s\n' "$EXPECTED" "$ASSET" > "$ASSET_DIR/SHA2-256SUMS"

DEST="$PROJECT_ROOT/tools/yt-dlp/yt-dlp"
deps_install_ytdlp
[ -x "$DEST" ] || { echo "yt-dlp tidak terpasang" >&2; exit 1; }
[ "$("$DEST" --version)" = "2099.01.01" ] || { echo "versi yt-dlp salah" >&2; exit 1; }

# Tampered binary must be rejected and must not replace the destination.
cat > "$ASSET_DIR/$ASSET" <<'EOF'
#!/usr/bin/env bash
echo "tampered"
EOF
chmod +x "$ASSET_DIR/$ASSET"
rm -f "$DEST"
if deps_install_ytdlp; then
    echo "checksum rusak seharusnya ditolak" >&2
    exit 1
fi
[ ! -e "$DEST" ] || { echo "binary rusak tetap terpasang" >&2; exit 1; }

echo "ytdlp installer: OK"
