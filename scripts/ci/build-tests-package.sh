#!/usr/bin/env bash
# Build paket tests opsional dari sebuah git ref.
#
# Paket ini bukan syarat instalasi. Isinya tests/ + konfigurasi PHPUnit,
# untuk developer/QA yang ingin menjalankan suite dari paket distribusi.
# Ekstrak di root paket core/full, lalu `composer install`.
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: scripts/ci/build-tests-package.sh --label <label> [options]

Options:
  --label <label>       Label versi/SHA untuk nama ZIP (wajib)
  --out-dir <dir>       Direktori output (default: dist)
  --ref <git-ref>       Ref git yang diarsipkan (default: HEAD)
  --repo <dir>          Repo git sumber (default: root skrip ini)
  --max-size-mb <mb>    Batas ukuran ZIP tests (default: 10)
  -h, --help            Tampilkan bantuan

Contoh:
  scripts/ci/build-tests-package.sh --label v2.3.0
  scripts/ci/build-tests-package.sh --label v2.3.0 --ref core-v2.3.0
USAGE
}

die() {
  printf '❌ %s\n' "$*" >&2
  exit 1
}

LABEL=""
OUT_DIR="dist"
REF="HEAD"
REPO_DIR=""
MAX_SIZE_MB=10

while [ "$#" -gt 0 ]; do
  case "$1" in
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

[ -n "$LABEL" ] || die "--label wajib diisi"

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

for cmd in zip unzip; do
  command -v "$cmd" >/dev/null 2>&1 || die "$cmd tidak ditemukan"
done

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/meel-tests.XXXXXX")"
IDX_FILE="$(mktemp "${TMPDIR:-/tmp}/meel-tests-index.XXXXXX")"
cleanup() {
  rm -rf "$TMP_DIR"
  rm -f "$IDX_FILE"
}
trap cleanup EXIT

# Ambil hanya berkas tests + konfigurasi PHPUnit. Jangan memakai `git archive`
# karena `.gitattributes` mengecualikan /tests dari arsip rilis.
rm -f "$IDX_FILE"
GIT_INDEX_FILE="$IDX_FILE" git -C "$REPO_DIR" read-tree "$REF"
mapfile -t FILES < <(
  git -C "$REPO_DIR" ls-tree -r --name-only "$REF" -- \
    tests phpunit.xml composer.json composer.lock
)
[ "${#FILES[@]}" -gt 0 ] || die "tidak ada berkas tests pada ref '$REF'"
GIT_INDEX_FILE="$IDX_FILE" git -C "$REPO_DIR" checkout-index \
  --prefix="$TMP_DIR/" -- "${FILES[@]}"

for required in tests/bootstrap.php tests/check_deploy.php phpunit.xml composer.lock; do
  [ -f "$TMP_DIR/$required" ] || die "berkas wajib tidak ditemukan: $required"
done

ZIP="$OUT_DIR/MEeL-HUB-tests-${LABEL}.zip"
rm -f "$ZIP"
( cd "$TMP_DIR" && zip -qr "$ZIP" tests phpunit.xml composer.json composer.lock )

[ -s "$ZIP" ] || die "arsip kosong: $ZIP"
unzip -t "$ZIP" >/dev/null || die "arsip rusak: $ZIP"

ENTRIES="$(unzip -Z1 "$ZIP")"
[ -n "$ENTRIES" ] || die "arsip tidak memuat entri: $ZIP"

if grep -qE '^(vendor|node_modules|\.git)/|^auth/settings\.php$' <<<"$ENTRIES"; then
  die "paket tests memuat berkas yang seharusnya tidak ada"
fi

require_entry() {
  local entry="$1"
  if ! grep -qxF "$entry" <<<"$ENTRIES"; then
    die "paket tests tidak memuat $entry"
  fi
}

require_entry "tests/bootstrap.php"
require_entry "tests/check_deploy.php"
require_entry "phpunit.xml"
require_entry "composer.lock"

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
  die "paket tests melebihi batas ${MAX_SIZE_MB}MB ($(awk -v b="$ZIP_BYTES" 'BEGIN { printf "%.1f", b / 1048576 }')MB)"
fi

printf '✅ tests package: %s (%s / batas %sMB)\n' "$(basename "$ZIP")" "$SIZE" "$MAX_SIZE_MB"
printf '   sha256: %s\n' "$HASH"
printf '   entries: %s\n' "$(wc -l <<<"$ENTRIES")"
