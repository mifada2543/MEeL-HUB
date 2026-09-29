<?php
// Shim auto_prepend untuk eksekusi langsung endpoint chess — satu-satunya subtree arcade/ yang dikecualikan dari aturan 301 *.php → router
// di root .htaccess (polling-nya harus tetap file nyata). arcade/.htaccess menetapkan `php_value auto_prepend_file _gate.php` (nama polos,
// tanpa path absolut) yang PHP resolve relatif cwd = arcade/chess/controller/ → shim inilah yang dimuat, lalu meneruskan ke gate asli (arcade/).
require __DIR__ . '/../../_gate.php';
