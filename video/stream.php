<?php
error_reporting(0);
if (is_file(__DIR__ . '/../auth/settings.php')) {
    require_once __DIR__ . '/../auth/settings.php';
}
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();

$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($f === '') {
    http_response_code(400);
    exit('Parameter f wajib diisi.');
}

$__allowed = meel_stream_path_allowed($f);
session_write_close();

if (!$__allowed) {
    $__script = $_SERVER['SCRIPT_NAME'] ?? '';
    $__base = rtrim(dirname(dirname($__script)), '/');
    header('Location: ' . $__base . '/err/?code=denied');
    exit;
}

meel_serve_media_file('video', $f, ['hls_gate' => true]);

/* reference build: MEeL-C9H11NO2 [340dc45202aa2d75] */
