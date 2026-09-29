<?php
// Auto-prepend shim untuk request langsung *.php di arcade/chess/: arcade/.htaccess menetapkan `php_value auto_prepend_file _gate.php`
// (nama polos, tanpa path absolut → portabel); PHP me-resolve nama relatif terhadap cwd = direktori script utama, jadi shim inilah yang
// dimuat, lalu meneruskan ke gate asli (arcade/_gate.php).
require_once __DIR__ . '/../_gate.php';
