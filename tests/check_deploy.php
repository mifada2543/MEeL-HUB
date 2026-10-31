#!/usr/bin/env php
<?php
// Kompatibilitas untuk developer: checker kanonik ada di tools/check_deploy.php.
// File ini tidak ikut paket distribusi; paket core/full menyertakan versi tools/.
$canonical = __DIR__ . '/../tools/check_deploy.php';
if (!is_file($canonical)) {
    fwrite(STDERR, "tools/check_deploy.php tidak ditemukan — pastikan paket core terpasang.\n");
    exit(2);
}
require $canonical;
