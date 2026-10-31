#!/usr/bin/env bash
# Build paket distribusi dari sebuah git ref, lalu verifikasi isinya.
#
# Paket:
#   core    Semua berkas kecuali arcade/ (paket utama, berdiri sendiri)
#   arcade  Hanya arcade/ (addon; butuh core)
#   full    core + arcade dalam satu paket
#
# Menggunakan `git archive` + aturan `export-ignore` di .gitattributes,
# bukan rsync ke direktori kerja. Hasilnya hanya berisi berkas yang
# benar-benar ter-track pada ref tersebut.
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/ci/build-package.sh --track core|arcade|full --label <label> [options]

Options:
  --track <core|arcade|full>  Jenis paket (wajib)
  --label <label>             Label versi/SHA untuk nama ZIP (wajib)
  --out-dir <dir>             Direktori output (default: dist)
  --ref <git-ref>             Ref git yang diarsipkan (default: HEAD)
  --repo <dir>                Repo git sumber (default: root skrip ini)
  --max-size-mb <mb>          Batas ukuran ZIP utama (default: 30)
  -h, --help                  Tampilkan bantuan

Contoh:
  scripts/ci/build-package.sh --track core --label v2.1.0
  scripts/ci/build-package.sh --track arcade --label v2.1.0 --ref core-v2.1.0
  scripts/ci/build-package.sh --track full --label 8cb70db12345 --repo /tmp/meel-source
USAGE
}

die() {
  printf '❌ %s\n' "$*" >&2
  exit 1
}

TRACK=""
LABEL=""
OUT_DIR="dist"
REF="HEAD"
REPO_DIR=""
MAX_SIZE_MB=30

while [ "$#" -gt 0 ]; do
  case "$1" in
    --track)
      [ "$#" -ge 2 ] || die "--track membutuhkan nilai"
      TRACK="$2"
      shift 2
      ;;
    --label)
      [ "$#" -ge 2 ] || die "--label membutuhkan nilai"
      LABEL="$2"
      shift 2
      ;;
    --out-dir)
      [ "$#" -ge 2 ] || die "--out-dir membutuhkan nilai"
      OUT_DIR="$2"
      shift 2
      ;;
    --ref)
      [ "$#" -ge 2 ] || die "--ref membutuhkan nilai"
      REF="$2"
      shift 2
      ;;
    --repo)
      [ "$#" -ge 2 ] || die "--repo membutuhkan nilai"
      REPO_DIR="$2"
      shift 2
      ;;
    --max-size-mb)
      [ "$#" -ge 2 ] || die "--max-size-mb membutuhkan nilai"
      MAX_SIZE_MB="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "argumen tidak dikenal: $1"
      ;;
  esac
done

[ -n "$TRACK" ] || die "--track wajib diisi"
[ -n "$LABEL" ] || die "--label wajib diisi"

case "$TRACK" in
  core|arcade|full) ;;
  *) die "--track harus salah satu dari: core, arcade, full" ;;
esac

if [[ ! "$LABEL" =~ ^[0-9A-Za-z][0-9A-Za-z._+-]*$ ]] || [[ "$LABEL" == *".."* ]]; then
  die "--label hanya boleh berisi huruf, angka, titik, garis bawah, plus, dan minus"
fi

if [[ ! "$MAX_SIZE_MB" =~ ^[1-9][0-9]*$ ]]; then
  die "--max-size-mb harus bilangan bulat positif"
fi

SCRIPT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REPO_DIR="${REPO_DIR:-$SCRIPT_ROOT}"
case "$REPO_DIR" in
  /*) ;;
  *) REPO_DIR="$SCRIPT_ROOT/$REPO_DIR" ;;
esac
[ -d "$REPO_DIR" ] || die "repo sumber tidak ditemukan: $REPO_DIR"

git -C "$REPO_DIR" rev-parse --git-dir >/dev/null 2>&1 || die "bukan repository git: $REPO_DIR"
git -C "$REPO_DIR" rev-parse --verify "${REF}^{commit}" >/dev/null 2>&1 \
  || die "ref '$REF' tidak ditemukan pada $REPO_DIR"

case "$OUT_DIR" in
  /*) ;;
  *) OUT_DIR="$SCRIPT_ROOT/$OUT_DIR" ;;
esac
mkdir -p "$OUT_DIR"

command -v unzip >/dev/null 2>&1 || die "unzip tidak ditemukan"

if [ "$TRACK" = "arcade" ] || [ "$TRACK" = "full" ]; then
  git -C "$REPO_DIR" cat-file -e "${REF}:arcade/index.php" 2>/dev/null \
    || die "folder arcade/ tidak ada pada ref '$REF'"
fi

# Pathspec ini membuat hasil archive konsisten walau tag lama belum punya
# aturan export-ignore di .gitattributes. .gitattributes tetap dipertahankan
# untuk pemakaian `git archive` manual; jaga kedua daftar tetap sinkron.
ARCHIVE_EXCLUDES=(
  ':(exclude,top).github'
  ':(exclude,top).gitignore'
  ':(exclude,top).gitattributes'
  ':(exclude,top)tests'
  ':(exclude,top)phpunit.xml'
  ':(exclude,top)composer.lock'
  ':(exclude,top)CHANGELOG-core.md'
  ':(exclude,top)CHANGELOG-arcade.md'
  ':(exclude,top)vendor'
  ':(exclude,top)node_modules'
  ':(exclude,top)tools/yt-dlp/yt-dlp'
)

case "$TRACK" in
  core)
    ZIP="$OUT_DIR/MEeL-HUB-core-${LABEL}.zip"
    git -C "$REPO_DIR" archive --format=zip --output="$ZIP" "$REF" -- . \
      ':(exclude,top)arcade' "${ARCHIVE_EXCLUDES[@]}"
    ;;
  arcade)
    ZIP="$OUT_DIR/MEeL-Arcade-${LABEL}.zip"
    git -C "$REPO_DIR" archive --format=zip --output="$ZIP" "$REF" -- arcade
    ;;
  full)
    ZIP="$OUT_DIR/MEeL-HUB-full-${LABEL}.zip"
    git -C "$REPO_DIR" archive --format=zip --output="$ZIP" "$REF" -- . \
      "${ARCHIVE_EXCLUDES[@]}"
    ;;
esac

[ -s "$ZIP" ] || die "arsip kosong: $ZIP"

unzip -t "$ZIP" >/dev/null || die "arsip rusak: $ZIP"

ENTRIES="$(unzip -Z1 "$ZIP")"
[ -n "$ENTRIES" ] || die "arsip tidak memuat entri: $ZIP"

if grep -qE '(^/|(^|/)\.\.(/|$))' <<<"$ENTRIES"; then
  die "arsip memuat path tidak aman: $ZIP"
fi

require_entry() {
  local entry="$1"
  if ! grep -qxF "$entry" <<<"$ENTRIES"; then
    die "paket $TRACK tidak memuat $entry"
  fi
}

require_core() {
  require_entry "index.php"
  require_entry "install.sh"
  require_entry ".htaccess"
  require_entry "auth/settings.example.php"
  require_entry "database/schema.sql"
  require_entry "assets/css/tailwind.min.css"
  require_entry "assets/dict/jmdict.sqlite3"
  require_entry "router.php"
  require_entry "modules/autoload.php"
  require_entry "tools/check_deploy.php"
  require_entry "tools/.htaccess"
}

require_arcade() {
  require_entry "arcade/index.php"
  require_entry "arcade/schema.sql"
  require_entry "arcade/migrate.php"
  require_entry "arcade/.htaccess"
}

forbid_entries() {
  local pattern="$1"
  local description="$2"
  if grep -qE "$pattern" <<<"$ENTRIES"; then
    die "paket $TRACK memuat $description yang seharusnya tidak ada"
  fi
}

forbid_entries '^(tests|\.github|vendor|node_modules|\.git)/' "berkas dev/test"
forbid_entries '^(phpunit\.xml|composer\.lock|CHANGELOG-core\.md|CHANGELOG-arcade\.md|\.gitignore|\.gitattributes)$' "berkas metadata dev"
forbid_entries '^push\.sh$' "helper rilis lokal"
forbid_entries '^tools/yt-dlp/yt-dlp$' "binary yt-dlp"

case "$TRACK" in
  core)
    require_core
    if grep -q '^arcade/' <<<"$ENTRIES"; then
      die "paket core tidak boleh memuat arcade/"
    fi
    ;;
  arcade)
    require_arcade
    if grep -qvE '^arcade/' <<<"$ENTRIES"; then
      die "paket arcade hanya boleh memuat arcade/"
    fi
    ;;
  full)
    require_core
    require_arcade
    ;;
esac

if command -v sha256sum >/dev/null 2>&1; then
  ( cd "$OUT_DIR" && sha256sum -- *.zip > SHA256SUMS )
else
  ( cd "$OUT_DIR" && shasum -a 256 *.zip > SHA256SUMS )
fi

SIZE="$(du -h "$ZIP" | cut -f1)"
HASH="$(awk -v name="$(basename "$ZIP")" '$2 == name { print $1 }' "$OUT_DIR/SHA256SUMS")"
[ -n "$HASH" ] || die "checksum untuk $(basename "$ZIP") tidak ditemukan"

ZIP_BYTES="$(wc -c < "$ZIP" | tr -d '[:space:]')"
MAX_BYTES=$((MAX_SIZE_MB * 1024 * 1024))
if [ "$ZIP_BYTES" -gt "$MAX_BYTES" ]; then
  die "paket $(basename "$ZIP") melebihi batas ${MAX_SIZE_MB}MB ($(awk -v b="$ZIP_BYTES" 'BEGIN { printf "%.1f", b / 1048576 }')MB)"
fi

printf '✅ %s package: %s (%s / batas %sMB)\n' "$TRACK" "$(basename "$ZIP")" "$SIZE" "$MAX_SIZE_MB"
printf '   sha256: %s\n' "$HASH"
printf '   entries: %s\n' "$(wc -l <<<"$ENTRIES")"
