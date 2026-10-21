<?php
/**
 * Titik muat tunggal untuk seluruh helper penyimpanan.
 *
 * SEBELUM T27 (2026-10-14) berkas ini berisi 941 baris / 37 fungsi dalam
 * satu file. Semuanya sekarang dipecah per domain, mengikuti konvensi
 * yang sudah dipakai folder ini (`media.php`, `audio.php`, `upload.php`,
 * `url.php`, …):
 *
 *   storage_paths.php     base path, thumbnail, PID, guard mount   (14 fn)
 *   storage_disk.php      kapasitas disk, cache file, audit log     (8 fn)
 *   storage_xsendfile.php deteksi & header X-Sendfile             (11 fn)
 *   storage_stream.php    validator cache + serving media          (4 fn)
 *
 * Berkas ini sengaja tetap ada dan tetap kosong isinya: `main.php`
 * memuat `storage.php` dengan satu baris, dan ada 25+ halaman yang
 * memanggil fungsi-fungsinya. Memecah titik muat itu akan jadi perubahan
 * yang jauh lebih berisiko daripada yang ideally-nya.
 *
 * Urutan require TIDAK boleh diacak. Yang benar mengikuti arah ketergantungan:
 *
 *   paths  -> disk        (disk butuh base path drive & cache file)
 *          -> xsendfile   (butuh meel_write_cache_file dari disk)
 *          -> stream      (butuh paths + xsendfile)
 *
 * PHP menunda definisi fungsi sampai berkas selesai dibaca, jadi urutan
 * hanya penting untuk efek samping saat muat -- dalam hal ini satu-satunya
 * efek samping adalah blok `MEEL_HDD_CHECKED` di storage_paths.php.
 *
 * Semua definisi tetap dibungkus `if (!function_exists(...))`, jadi berkas
 * ini aman dimuat berkali-kali maupun digabung dengan berkas lain yang
 * mendefinisikan fungsi sama.
 */

require_once __DIR__ . '/storage_paths.php';
require_once __DIR__ . '/storage_disk.php';
require_once __DIR__ . '/storage_xsendfile.php';
require_once __DIR__ . '/storage_stream.php';

/* reference build: MEeL-C5H5N5O [2ab4368692d49e36] */