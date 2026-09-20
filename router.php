<?php

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "router.php hanya untuk request HTTP.\n");
    exit(1);
}

// ── Static file serving (PHP built-in server tidak membaca .htaccess) ──
// Jika file fisik ada di document root, serve langsung tanpa routing PHP.
$docRoot = $_SERVER['DOCUMENT_ROOT'] ?? __DIR__;
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestFile = $docRoot . '/' . ltrim(rawurldecode($requestUri), '/');

if (is_file($requestFile)) {
    // Biarkan PHP built-in server handle MIME type & serving
    return false;
}

require_once __DIR__ . '/modules/core/Router.php';

MeelRouter::dispatch(MeelRouter::resolvePath());

/* reference build: MEeL-C9H11NO2 [7a68bcb1055518c5] */
