<?php
/**
 * Hapus baris video/music yang file-nya sudah tidak ada di disk.
 *
 * SEKALI PAKAI. Bukan perkakas yang dijadwalkan.
 *
 * DAFTAR ID DIBEKUAN
 * ------------------
 * Skrip ini TIDAK memindai disk untuk mencari file yang hilang. Daftar id di
 * bawah berasal dari pindai yang sudah diverifikasi pada 15 Oktober 2026, dan
 * id-nya dikunci di dalam berkas ini.
 *
 * Alasannya keselamatan, bukan sekadar-Praktis:
 *
 * Drive media TIDAK ada di /etc/fstab. Storage ada di /dev/sda1 yang dipasang
 * manual, jadi tidak terpasang otomatis saat boot. Kalau drive sedang tidak
 * terpasang, direktori media kosong dan SETIAP file terbaca hilang. Skrip yang
 * memindai saat eksekusi akan menyimpulkan "13.837 file hilang" lalu menghapus
 * seluruh pustaka dari database -- dan itu tidak bisa dibatalkan.
 *
 * Daftar yang dibekukan tidak bisa salah baca karena mount hilang.
 *
 * PEMAKAIAN
 * ---------
 *   php scripts/purge-missing-media.php            # laporan, tidak menulis
 *   php scripts/purge-missing-media.php --apply    # hapus
 *
 * ATURAN
 * ------
 *  - Tanpa --apply tidak ada satu baris pun yang ditulis.
 *  - Menolak jalan bila drive media tidak terpasang.
 *  - Satu transaksi; jumlah baris harus cocok dengan yang diharapkan atau rollback.
 *  - Tabel `users` tidak pernah disentuh.
 *  - Tidak pernah menghapus, memindah, atau mengubah file di disk.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal.');
}

require_once __DIR__ . '/../auth/config.php';

$apply = in_array('--apply', $argv, true);

$VIDEO_ID = [6, 8, 15, 33, 39, 42, 46, 107, 150, 151, 177, 179, 211, 216, 233, 249, 275];
$MUSIC_ID = [53, 111, 141];

$VIEWLOG_ID = [7, 159, 236, 240, 314, 352, 366, 382, 524, 638, 642, 680, 717];

$base = rtrim(MEEL_HDD_BASE, '/');

$mounts = [];
foreach (file('/proc/self/mountinfo') as $line) {
    $f = preg_split('/\s+/', trim($line));
    if (count($f) < 5) continue;

    $mounts[] = ['point' => $f[4], 'fstype' => $f[8] ?? '', 'src' => $f[9] ?? ''];
}
$terpasangPada = null;
foreach ($mounts as $m) {
    if ($base === $m['point'] || str_starts_with($base . '/', rtrim($m['point'], '/') . '/')) {
        if ($terpasangPada === null || strlen($m['point']) > strlen($terpasangPada['point'])) {
            $terpasangPada = $m;
        }
    }
}
if ($terpasangPada === null) {
    fwrite(STDERR, "❌ DITOLAK: {$base} tidak ada di tabel mount.\n");
    fwrite(STDERR, "   Drive media kemungkinan belum terpasang. Menjalankan skrip ini\n");
    fwrite(STDERR, "   dalam keadaan itu akan menghapus seluruh pustaka dari database.\n");
    exit(1);
}
if (str_contains($terpasangPada['src'], '/dev/sda1') === false) {
    fwrite(STDERR, "❌ DITOLAK: {$base} terpasang dari {$terpasangPada['src']}, bukan /dev/sda1.\n");
    fwrite(STDERR, "   Memakai disk yang salah bisa membuat semua file terbaca hilang.\n");
    exit(1);
}

$jumlahBerkas = 0;
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($it as $f) {
    if ($f->isFile()) $jumlahBerkas++;
    if ($jumlahBerkas > 200) break;
}
if ($jumlahBerkas < 200) {
    fwrite(STDERR, "❌ DITOLAK: hanya {$jumlahBerkas} berkas ditemukan di {$base}.\n");
    fwrite(STDERR, "   Disk seharusnya berisi ribuan berkas. Ini bukan kondisi normal.\n");
    exit(1);
}

$sentinel = $conn->query("SELECT filename FROM video ORDER BY id LIMIT 1")->fetch_assoc();
if ($sentinel && !file_exists(meel_media_base_path('video') . '/' . $sentinel['filename'])) {
    fwrite(STDERR, "❌ DITOLAK: file video yang seharusnya ada tidak ditemukan: {$sentinel['filename']}\n");
    fwrite(STDERR, "   Berhenti di sini lebih aman daripada menghapus berdasarkan asumsi.\n");
    exit(1);
}

echo "═══ Konteks ═══\n";
printf("  media base      : %s\n", $base);
printf("  terpasang dari   : %s di %s\n", $terpasangPada['src'], $terpasangPada['point']);
printf("  berkas terlihat : >%d (sanity OK)\n", $jumlahBerkas);
printf("  mode            : %s\n", $apply ? 'MENERAPKAN' : 'laporan saja');

echo "\n═══ Baris yang akan dihapus ═══\n";
$conn->begin_transaction();
try {
    $hapusVideo = 0;
    $hapusMusik = 0;
    $hapusLog   = 0;

    $st = $conn->prepare("DELETE FROM video WHERE id = ?");
    foreach ($VIDEO_ID as $id) {
        $row = $conn->query("SELECT id, title, filename FROM video WHERE id = {$id}")->fetch_assoc();
        if ($row === null) continue;
        $st->bind_param('i', $id);
        $st->execute();
        $hapusVideo += $st->affected_rows;
        printf("  video  #%-4s %s\n", $id, mb_strimwidth($row['title'], 0, 50, '…'));
    }
    $st->close();

    $st = $conn->prepare("DELETE FROM music WHERE id = ?");
    foreach ($MUSIC_ID as $id) {
        $row = $conn->query("SELECT id, title, filename FROM music WHERE id = {$id}")->fetch_assoc();
        if ($row === null) continue;
        $st->bind_param('i', $id);
        $st->execute();
        $hapusMusik += $st->affected_rows;
        printf("  musik  #%-4s %s\n", $id, mb_strimwidth($row['title'], 0, 50, '…'));
    }
    $st->close();

    $st = $conn->prepare("DELETE FROM view_logs WHERE id = ?");
    foreach ($VIEWLOG_ID as $id) {
        $st->bind_param('i', $id);
        $st->execute();
        $hapusLog += $st->affected_rows;
    }
    $st->close();

    $sisa = 0;
    foreach ($VIDEO_ID as $id) {
        $sisa += (int) $conn->query("SELECT COUNT(*) c FROM video WHERE id = {$id}")->fetch_assoc()['c'];
    }
    foreach ($MUSIC_ID as $id) {
        $sisa += (int) $conn->query("SELECT COUNT(*) c FROM music WHERE id = {$id}")->fetch_assoc()['c'];
    }
    if ($sisa !== 0) {
        throw new RuntimeException("Masih ada {$sisa} baris dari daftar beku yang tidak terhapus");
    }

    if ($apply) {
        $conn->commit();
        printf("\n── Ringkasan ──\n");
        printf("  video      : %d\n  musik      : %d\n  view_logs  : %d\n", $hapusVideo, $hapusMusik, $hapusLog);
        echo "  users      : tidak disentuh\n  file disk  : tidak disentuh\n";
        echo "\n✅ Selesai.\n";
    } else {
        $conn->rollback();
        printf("\n── Ringkasan (belum diterapkan) ──\n");
        printf("  akan dihapus: video %d, musik %d, view_logs %d\n", $hapusVideo, $hapusMusik, $hapusLog);
        echo "\nMode laporan -- transaksi di-rollback, TIDAK ADA yang berubah.\n";
        echo "Jalankan ulang dengan --apply bila sudah yakin.\n";
    }
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\n❌ Dibatalkan: " . $e->getMessage() . "\n   Transaksi di-rollback.\n");
    exit(1);
}
