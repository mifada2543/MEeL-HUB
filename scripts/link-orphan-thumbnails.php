<?php
/**
 * Menautkan 4 thumbnail video yang menggantung.
 *
 * Yang ditautkan adalah thumbnail asli yang gagal terpasang karena
 * ketidakkonsistenan nama saat unggah, plus satu pasang folder unggahan
 * ulang yang memakai thumbnail yang sama.
 *
 * Hanya menulis kolom `thumbnail` pada empat baris video. Tidak menyentuh
 * file, tidak menyentuh tabel lain.
 */

require_once __DIR__ . '/../auth/config.php';

$pasang = [
    ['video/assasin-class-room/assasin-class-room.m3u8',            'assasin-class-rom_thumb.webp'],
    ['video/porutagaisuto-hatsune-miku-kai/porutagaisuto-hatsune-miku-kai.m3u8',
                                                                    'porutagaisuto-hatsune-miku_thumb.webp'],
    ['video/rbonhero-1/rbonhero-1.m3u8',                            'rbonhero_thumb.webp'],
    ['video/karakuri-piero-more-more-jump-hatsune-miku-1/karakuri-piero-more-more-jump-hatsune-miku-1.m3u8',
                                                                    'karakuri-piero-more-more-jump-hatsune-miku.webp'],
];

$dirThumb = meel_media_base_path('video') . '/thumbnail/';

foreach ($pasang as [$fn, $thumb]) {
    if (!is_file($dirThumb . $thumb)) {
        fwrite(STDERR, "❌ {$thumb} tidak ada -- dibatalkan.\n");
        exit(1);
    }
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE video SET thumbnail = ? WHERE filename = ?");
    if (!$stmt) throw new RuntimeException('prepare: ' . $conn->error);

    foreach ($pasang as [$fn, $thumb]) {
        $stmt->bind_param('ss', $thumb, $fn);
        if (!$stmt->execute()) throw new RuntimeException("update {$fn}: " . $stmt->error);
        $n = $stmt->affected_rows;
        if ($n !== 1) throw new RuntimeException("{$fn}: Affects {$n} baris, harusnya 1");
        echo "  ✅ {$thumb}  ->  {$fn}\n";
    }
    $stmt->close();

    $cek = $conn->query("SELECT COUNT(*) c FROM video WHERE thumbnail = 'default_thumb.webp'")->fetch_assoc();
    if ((int) $cek['c'] !== 0) {
        throw new RuntimeException("Masih ada {$cek['c']} video yang menunjuk default_thumb.webp");
    }

    $conn->commit();
    echo "\nSelesai. Tabel `users` tidak disentuh.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\n❌ Dibatalkan: " . $e->getMessage() . "\n   Transaksi di-rollback.\n");
    exit(1);
}
