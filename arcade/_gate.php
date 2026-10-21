<?php
require_once __DIR__ . '/../modules/core/Modules.php';

if (!Modules::enabled('arcade')) {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH);

    if (str_contains($path, '/arcade/rhythm/api/')) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => 'Module not available']));
    }

    require_once __DIR__ . '/../modules/core/base_url.php';
    header('Location: ' . meel_base_url_path() . '/', true, 302);
    exit;
}

/* reference build: MEeL-C2H5NO2 [50691659154f0671] */
