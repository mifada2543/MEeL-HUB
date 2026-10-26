#!/usr/bin/env bash

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

eval "$("$PHP_BIN" -r '
$src = @file_get_contents($argv[1]) ?: "";
$o = [];
foreach (["server" => "H", "username" => "U", "password" => "P", "db" => "D"] as $k => $v) {
    $p = "/\\\$" . $k . "\s*=\s*[\x27\x22]([^\x27\x22]*)[\x27\x22]/";
    if (preg_match($p, $src, $m)) { $o[$v] = $m[1]; }
}
if (isset($o["D"], $o["H"])) {
    printf("DB_HOST=%s\nDB_USER=%s\nDB_PASS=%s\nDB_NAME=%s\n",
        escapeshellarg($o["H"]), escapeshellarg($o["U"] ?? "root"),
        escapeshellarg($o["P"] ?? ""), escapeshellarg($o["D"]));
}
' "$SETTINGS")" || die "gagal membaca kredensial dari settings.php"

[ -n "${DB_HOST:-}" ] && [ -n "${DB_NAME:-}" ] \
  || die "kredensial DB tidak terbaca dari $SETTINGS (butuh \$server dan \$db)"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

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

  MOUNT_SRC="$(findmnt -no SOURCE -T "$HDD_BASE" 2>/dev/null || true)"
  [ -n "$MOUNT_SRC" ] || die "DITOLAK: $HDD_BASE tidak ada di tabel mount.
   Drive media belum terpasang. Dump yang ditulis sekarang akan mendarat di
   disk sistem dan hilang bersama drive. Pasang drive dulu, atau pakai --to."

  [ -f "$HDD_BASE/.meel_mount" ] || die "DITOLAK: sentinel $HDD_BASE/.meel_mount tidak ada.
   Folder media kosong tanpa penanda ini = drive belum terpasang, meski tabel
   mount tampak benar."

  mkdir -p "$DEST" || die "tidak bisa membuat $DEST"
  DEST="$(cd -- "$DEST" && pwd)"
  info "drive media : $MOUNT_SRC -> $HDD_BASE"
fi

if [ "$VERIFY_ONLY" -eq 1 ]; then
  MYSQL_BIN="$(find_bin mysql)" || die "klien mysql tidak ditemukan"
  LATEST="$(ls -1t "$DEST"/MEeL-*.sql 2>/dev/null | head -1 || true)"
  [ -n "$LATEST" ] || die "tidak ada backup di $DEST"
  CHECK_DB="meel_backup_verify"

  say "── Restore uji: $LATEST"
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

MYSQLDUMP_BIN="$(find_bin mysqldump)" || die "mysqldump tidak ditemukan (butuh /opt/lampp/bin)"
STAMP="$(date +%F_%H%M%S)"
TMP="$DEST/.tmp_MEeL-$STAMP.sql"
OUT="$DEST/MEeL-$STAMP.sql"
ERR="$TMP.err"

say "═══ Backup MEeL ═══"
info "tujuan   : $DEST"
info "database : $DB_NAME @ $DB_USER/$DB_HOST"
info "alat     : $MYSQLDUMP_BIN"

"$MYSQLDUMP_BIN" -h "$DB_HOST" -u "$DB_USER" ${DB_PASS:+-p"$DB_PASS"} \
  --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_NAME" > "$TMP" 2> "$ERR"
RC=$?

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

if [ "$KEEP" -gt 0 ] 2>/dev/null; then
  REMOVED="$(find "$DEST" -maxdepth 1 -name 'MEeL-*.sql' -mtime "+$KEEP" -print -delete 2>/dev/null | wc -l)"
  [ "$REMOVED" -gt 0 ] && info "rotasi    : $REMOVED file lebih tua dari $KEEP hari dihapus"
fi
info "tersimpan : $(find "$DEST" -maxdepth 1 -name 'MEeL-*.sql' | wc -l) backup di $DEST"

if [ "$DO_VERIFY" -eq 1 ]; then
  "$0" --to "$DEST" --verify-only
fi
