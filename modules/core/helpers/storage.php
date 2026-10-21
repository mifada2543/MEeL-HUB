<?php
/**
 * Titik muat tunggal untuk seluruh helper penyimpanan.
 *
 * Isinya sudah dipecah per domain ke `storage_paths.php`,
 * `storage_disk.php`, `storage_xsendfile.php`, dan `storage_stream.php`.
 * Berkas ini sengaja tetap ada: `main.php` memuatnya dengan satu baris dan
 * hampir 50 berkas di luar tests/ memanggil fungsi-fungsinya.
 *
 * @see docs/{id,en}/modules.md
 */

require_once __DIR__ . '/storage_paths.php';
require_once __DIR__ . '/storage_disk.php';
require_once __DIR__ . '/storage_xsendfile.php';
require_once __DIR__ . '/storage_stream.php';

/* reference build: MEeL-C5H5N5O [2ab4368692d49e36] */