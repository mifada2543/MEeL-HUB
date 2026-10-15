<?php
/**
 * Menautkan 4 thumbnail video yang menggantung.
 *
 * Konteks: setelah pemulihan media, tinggal 4 "file sampah" di dashboard.
 * Tiga di antaranya adalah thumbnail ASLI yang gagal terpasang karena
 * ketidakkonsistenan nama pada saat unggah -- bukan file orphan sungguhan:
 *
 *   folder video                nama thumbnail di disk      masalah
 *   --------------------------   -------------------------   -----------------------
 *   assasin-class-room          assasin-class-rom_thumb     typo: rom vs room
 *   porutagaisuto-hatsune-miku-kai  porutagaisuto-hatsune-miku_thumb   tanpa akhiran -kai
 *   rbonhero-1                   rbonhero_thumb             tanpa akhiran -1
 *   karakuri-piero-...-hatsune-miku-1  karakuri-piero-...-hatsune-miku.webp  tanpa akhiran -1
 *
 * Yang terakhir bukan ketidakkonsistenan nama, melainkan unggahan ulang: ia
 * punya durasi yang sama persan (260 s) dan nama dasar yang sama dengan baris
 * #299, hanya diberi akhiran `-1`. Satu-satunya thumbnail yang ada untuk kedua
 * folder itu adalah `karakuri-piero-more-more-jump-hatsune-miku.webp`, jadi
 * keduanya memakainya. Berkasnya 139 KB, bukan placeholder.
 *
 * Semuanya saat ini menunjuk `default_thumb.webp` yang TIDAK ADA di direktori
 * thumbnail mana pun -- jadi kartu di grid memakai gambar rusak.
 *
 * File keempat, `music/upload/thumbnail/thumb.thumb.webp`, sengaja TIDAK
 * disentuh: namanya tidak berkorelasi dengan lagu mana pun, dan menautkannya
 * ke lagu tertentu berarti menebak.
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
