<?php
/**
 * Shim auto_prepend untuk eksekusi langsung endpoint chess (satu-satunya
 * subtree arcade/ yang dikecualikan dari aturan 301 *.php → router di
 * root .htaccess — polling-nya harus tetap file nyata).
 *
 * arcade/.htaccess menetapkan `php_value auto_prepend_file _gate.php`
 * (filename polos, tanpa path absolut). PHP me-resolve nama polos relatif
 * terhadap cwd = direktori script utama → untuk request ini cwd =
 * arcade/chess/controller/, sehingga shim inilah yang dimuat. Meneruskan
 * ke gate asli (dengan __DIR__-nya sendiri = arcade/).
 */
require __DIR__ . '/../../_gate.php';
