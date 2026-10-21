#!/usr/bin/env bash
#
# Backup database MEeL.
#
# SEKALI PAKAI SECARA MANUAL, tidak memakai cron.
#
#   scripts/backup.sh              # backup lalu verifikasi isinya
#   scripts/backup.sh --keep 60    # rotasi 60 hari, bukan 30
#   scripts/backup.sh --list       # lihat backup yang ada, tanpa menulis apa pun
#   scripts/backup.sh --verify-only  # restore ke DB tiruan, lalu buang
#   scripts/backup.sh --to /path/lain
#
# BAHAYA YANG DIHINDARI
# ---------------------
# 1. Drive media TIDAK ada di /etc/fstab. Kalau tidak terpasang, folder tujuan
#    tetap ADA di disk sistem -- dump akan ditulis ke sana, laporkan berhasil,
#    dan mengisi disk sistem tanpa terlihat. Karena itu ada penjaga mount + sentinel
#    sebelum satu baris pun ditulis.
#
# 2. mysqldump TIDAK ada di PATH (/opt/lampp/bin tidak termasuk PATH cron maupun
#    PATH interaktif di mesin ini). Panggil dengan path absolut.
#
# 3. Dump ditulis ke file sementara lalu diperiksa, baru di-pindah ke nama
#    final. Dump yang ditulis langsung ke nama final bisa meninggalkan file
#    SETENGAH kalau proses mati -- dan file setengah itu terlihat sah di glance
#    berikutnya.
#
# 4. Dump yang menghasilkan stderr TIDAK dipercaya: identifier hilang diam-diam
#    dan restore-nya gagal jauh di hari yang paling tidak tepat.
#
# Backup yang tidak pernah di-restore BUKAN backup. Karena itu setiap dump
# dicek isinya (jumlah CREATE TABLE) dan tersedia --verify-only untuk
# membuktikannya pulih ke database sungguhan.

set -uo pipefail

REPO="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
SETTINGS="$REPO/auth/settings.php"

PHP_BIN="${MEEL_PHP:-/usr/bin/php}"
KEEP=30
VERIFY_ONLY=0
LIST_ONLY=0
DEST=""
DO_VERIFY=1

die() { printf '❌ %s\n' "$*" >&2; exit 1; }
info() { printf '   %s\n' "$*"; }
say()  { printf '%s\n' "$*"; }

while [ $# -gt 0 ]; do
  case "$1" in
    --keep)         KEEP="${2:-}"; shift 2 ;;
    --to)           DEST="${2:-}"; shift 2 ;;
    --list)         LIST_ONLY=1; shift ;;
    --no-verify)    DO_VERIFY=0; shift ;;
    --verify-only)  VERIFY_ONLY=1; shift ;;
    -h|--help)      sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *)              die "Argumen tidak dikenal: $1  (--help untuk daftar)" ;;
  esac
done

[ -f "$SETTINGS" ] || die "auth/settings.php tidak ditemukan di $REPO"
[ -x "$PHP_BIN" ] || die "PHP CLI tidak ditemukan di $PHP_BIN (set MEEL_PHP)"

# ──.mycnf / kredensial: dibaca dari settings.php, bukan ditulis di sini ────
# Kalau password root nanti berubah, skrip ini ikut berubah sendiri. Nilai
# yang ditulis mati di dalam skrip akan diam-diam gagal setelah cron berjalan.
eval "$("$PHP_BIN" -r '
$src = @file_get_contents($argv[1]) ?: "";
$o = [];
foreach (["server" => "H", "username" => "U", "password" => "P", "db" => "D"] as $k => $v) {
    if (preg_match("/\\\$" . $k . "\s*=\s*\"([^\"]*)\"/", $src, $m)) { $o[$v] = $m[1]; }
}
if (isset($o["D"], $o["H"])) {
    printf("DB_HOST=%s\nDB_USER=%s\nDB_PASS=%s\nDB_NAME=%s\n",
        escapeshellarg($o["H"]), escapeshellarg($o["U"] ?? "root"),
        escapeshellarg($o["P"] ?? ""), escapeshellarg($o["D"]));
}
' "$SETTINGS")" || die "gagal membaca kredensial dari settings.php"

: "${DB_HOST:?}" "${DB_NAME:?}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

# ── alat: cari mysqldump/mysql, jangan andalkan PATH ─────────────────────
find_bin() {
  local name="$1" cand
  for cand in "/opt/lampp/bin/$name" "/usr/bin/$name" "/usr/local/bin/$name"; do
    [ -x "$cand" ] && { printf '%s' "$cand"; return 0; }
  done
  command -v "$name" 2>/dev/null && return 0
  return 1
}

if [ "$LIST_ONLY" -eq 1 ]; then
  for d in ${DEST:+"$DEST"} /media/muhammaddaffa/MEeL/media/backup; do
    [ -d "$d" ] || continue
    say "── $d"
    ls -la --time-style=long-iso "$d" 2>/dev/null | sed 's/^/   /'
  done
  exit 0
fi

# ── tujuan ───────────────────────────────────────────────────────────────
if [ -n "$DEST" ]; then
  DEST="$(mkdir -p "$DEST" 2>/dev/null && cd -- "$DEST" && pwd)" \
    || die "tujuan tidak bisa dibuat: $DEST"
else
  HDD_BASE="$("$PHP_BIN" -r '
    $src = @file_get_contents($argv[1]) ?: "";
    // Polanya harus cope dengan bentuk `define(\x27MEEL_HDD_BASE\x27, \x27/path\x27)`,
    // bukan hanya `MEEL_HDD_BASE \x27, \x27/path\x27`. Pola yang terlalu sempit
    // gagal SENYAP: variabel kosong, pesan errornya menuduh --to, padahal
    // masalahnya cuma regex.
    $p = "/define\\s*\\(\\s*[\x27\x22]MEEL_HDD_BASE[\x27\x22]\\s*,\\s*[\x27\x22]([^\x27\x22]+)[\x27\x22]/";
    if (preg_match($p, $src, $m)) { echo $m[1]; }
  ' "$SETTINGS")"
  [ -n "$HDD_BASE" ] || die "MEEL_HDD_BASE tidak terbaca dari settings.php; pakai --to"
  DEST="$HDD_BASE/backup"

  # PENJAGA 1 — tabel mount. Tanpa ini, HDD yang tidak terpasang berarti folder
  # tujuan jatuh ke disk sistem dan dump menumpuk di sana tanpa terlihat.
  #
  # WAJIB -T: `findmnt <path>` tanpa -T hanya cocok kalau path itu TITIK MOUNT.
  # MEEL_HDD_BASE = .../MEeL/media, sedangkan yang ter-mount adalah .../MEeL,
  # jadi tanpa -T penjaganya menolak drive yang benar-benar terpasang.
  MOUNT_SRC="$(findmnt -no SOURCE -T "$HDD_BASE" 2>/dev/null || true)"
  [ -n "$MOUNT_SRC" ] || die "DITOLAK: $HDD_BASE tidak ada di tabel mount.
   Drive media belum terpasang. Dump yang ditulis sekarang akan mendarat di
   disk sistem dan hilang bersama drive. Pasang drive dulu, atau pakai --to."

  # PENJAGA 2 — sentinel. .meel_mount hanya ada DI DALAM drive; kalau drive
  # tidak terpasang, folder di atas kosong dan sentinel-nya ikut hilang.
  [ -f "$HDD_BASE/.meel_mount" ] || die "DITOLAK: sentinel $HDD_BASE/.meel_mount tidak ada.
   Folder media kosong tanpa penanda ini = drive belum terpasang, meski tabel
   mount tampak benar."

  mkdir -p "$DEST" || die "tidak bisa membuat $DEST"
  DEST="$(cd -- "$DEST" && pwd)"
  info "drive media : $MOUNT_SRC -> $HDD_BASE"
fi

# ── mode: restore ke DB tiruan, lalu buang ───────────────────────────────
if [ "$VERIFY_ONLY" -eq 1 ]; then
  MYSQL_BIN="$(find_bin mysql)" || die "klien mysql tidak ditemukan"
  LATEST="$(ls -1t "$DEST"/MEeL-*.sql 2>/dev/null | head -1 || true)"
  [ -n "$LATEST" ] || die "tidak ada backup di $DEST"
  CHECK_DB="meel_backup_verify"

  say "── Restore uji: $LATEST"
  # Nama DB tiruan dikunci di sini. Kalau nama ini berubah, skrip ini bisa
  # saja meng-DROP database yang bukan miliknya -- persis kelas insiden yang
  # sudah pernah terjadi sekali di repo ini.
  case "$CHECK_DB" in
    *_verify|*_check*) : ;;
    *) die "nama DB verifikasi tidak aman: $CHECK_DB" ;;
  esac

  "$MYSQL_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
    -e "DROP DATABASE IF EXISTS \`$CHECK_DB\`;
        CREATE DATABASE \`$CHECK_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" \
    || die "gagal menyiapkan database verifikasi"
  "$MYSQL_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} "$CHECK_DB" < "$LATEST" \
    || die "RESTORE GAGAL — dump ini tidak bisa dipulihkan"
  N=$("$MYSQL_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -N -B \
        -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$CHECK_DB';")
  "$MYSQL_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} -e "DROP DATABASE \`$CHECK_DB\`;"

  if [ "${N:-0}" -lt 20 ]; then
    die "hanya $N tabel hasil restore — dump tidak bisa dianggap benar"
  fi
  say "✅ Restore OK: $N tabel dipulihkan ke $CHECK_DB, lalu database itu dibuang."
  exit 0
fi

# ── dump ─────────────────────────────────────────────────────────────────
MYSQLDUMP_BIN="$(find_bin mysqldump)" || die "mysqldump tidak ditemukan (butuh /opt/lampp/bin)"
STAMP="$(date +%F_%H%M%S)"
TMP="$DEST/.tmp_MEeL-$STAMP.sql"
OUT="$DEST/MEeL-$STAMP.sql"
ERR="$TMP.err"

say "═══ Backup MEeL ═══"
info "tujuan   : $DEST"
info "database : $DB_NAME @ $DB_USER/$DB_HOST"
info "alat     : $MYSQLDUMP_BIN"

# `--set-gtid-purged=OFF` SENGAJA tidak dipakai: itu opsi MySQL 5.7/8.0, dan
# MariaDB 10.4 menolaknya sebagai "unknown variable" -- dump gagal total.
"$MYSQLDUMP_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
  --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" > "$TMP" 2> "$ERR"
RC=$?

# mysqldump-reported failures land in stderr even with exit 0 (mis. table yang
# hilang di tengah dump). Dump dengan stderr TIDAK boleh dipercaya.
ERRTEXT="$(head -c 400 "$ERR" 2>/dev/null || true)"
rm -f "$ERR"
if [ "$RC" -ne 0 ] || [ -n "$ERRTEXT" ]; then
  rm -f "$TMP"
  die "mysqldump gagal (rc=$RC)${ERRTEXT:+: $ERRTEXT}"
fi

if [ ! -s "$TMP" ]; then
  rm -f "$TMP"
  die "dump kosong"
fi

NTABLES="$(grep -c '^CREATE TABLE' "$TMP" || true)"
[ "${NTABLES:-0}" -ge 20 ] || { rm -f "$TMP"; die "hanya ${NTABLES:-0} CREATE TABLE — dump tidak layak disimpan"; }

mv -- "$TMP" "$OUT" || die "gagal memindahkan dump ke nama final"
SIZE="$(stat -c%s "$OUT")"
say "✅ Disimpan: $(basename "$OUT") — $SIZE bytes, $NTABLES tabel"

# ── rotasi ───────────────────────────────────────────────────────────────
if [ "$KEEP" -gt 0 ] 2>/dev/null; then
  REMOVED="$(find "$DEST" -maxdepth 1 -name 'MEeL-*.sql' -mtime "+$KEEP" -print -delete 2>/dev/null | wc -l)"
  [ "$REMOVED" -gt 0 ] && info "rotasi    : $REMOVED file lebih tua dari $KEEP hari dihapus"
fi
info "tersimpan : $(find "$DEST" -maxdepth 1 -name 'MEeL-*.sql' | wc -l) backup di $DEST"

# ── bukti restore ────────────────────────────────────────────────────────
if [ "$DO_VERIFY" -eq 1 ]; then
  "$0" --to "$DEST" --verify-only
fi