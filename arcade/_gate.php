<?php
/**
 * arcade/_gate.php — Guard modul opsional untuk semua file di bawah arcade/.
 * Dipanggil otomatis via php_value auto_prepend_file di arcade/.htaccess.
 * Saat modul nonaktif: halaman → 302 ke HUB, API → JSON 404.
 */
require_once __DIR__ . '/../modules/core/Modules.php';

if (!Modules::enabled('arcade')) {
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);

    // API rhythm → JSON 404 (konsisten dengan Router.php OPTIONAL_API_PREFIXES)
    if (str_contains($path, '/arcade/rhythm/api/')) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => 'Module not available']));
    }

    // Halaman lain → 302 redirect ke HUB
    require_once __DIR__ . '/../modules/core/base_url.php';
    header('Location: ' . meel_base_url_path() . '/', true, 302);
    exit;
}
