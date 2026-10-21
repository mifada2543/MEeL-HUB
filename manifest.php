<?php
/**
 * Generator manifest.json (Web App Manifest).
 *
 * Base path diambil dari meel_base_url_path(), jadi PWA tetap benar saat
 * repo dipindah folder. URL di dalam manifest dinormalkan ke root.
 *
 * @see docs/{id,en}/pwa.md
 */

if (!function_exists('meel_base_url_path')) {
    require_once __DIR__ . '/modules/core/base_url.php';
}

$docRootOverride = getenv('MEEL_MANIFEST_DOCROOT');
if (is_string($docRootOverride) && $docRootOverride !== '') {
    $_SERVER['DOCUMENT_ROOT'] = $docRootOverride;
}

$base = function_exists('meel_base_url_path') ? meel_base_url_path() : '';

$base = trim((string)$base);

$projRoot = rtrim(str_replace('\\', '/', __DIR__), '/');

if ($base !== '' && (
    $base === $projRoot
    || str_starts_with($projRoot, $base . '/')
    || str_starts_with($base, $projRoot . '/')
)) {
    error_log(
        '[manifest.php] DOCUMENT_ROOT kosong/tidak cocok — meel_base_url_path() mengembalikan '
        . 'path filesystem (' . $base . '), scope dipaksa ke "/"'
    );
    $base = '';
}

$base = '/' . trim($base, '/');
if ($base === '/') {
    $base = '';
}

$scope = $base === '' ? '/' : $base . '/';

$jsonPath = __DIR__ . '/assets/manifest.json';
$raw = is_file($jsonPath) ? (string)file_get_contents($jsonPath) : '{}';
$manifest = json_decode($raw, true);

if (!is_array($manifest)) {
    $manifest = [
        'short_name' => 'MEeL',
        'name'        => 'MEeL — Media Hub Platform',
        'description' => 'Platform Media Hub Pribadi untuk Streaming Video, Musik, Buku Digital, dan Cloud Storage.',
        'icons'       => [],
    ];
}

/**
 * Ubah URL yang tertulis di assets/manifest.json (yang relatif terhadap
 * folder `assets/`) menjadi URL absolut terhadap root aplikasi.
 *
 * Hasil SELALU di-root (dimulai dengan "/") supaya tidak bergantung
 * folder tempat manifest diletakkan.
 */
$absolutize = static function (string $url) use ($base): string {
    if ($url === '') {
        return $base === '' ? '/' : $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
        return $url;
    }
    if (str_starts_with($url, '/')) {
        return $base . ltrim($url, '/');
    }

    $segments = [];
    foreach (explode('/', $url) as $seg) {
        if ($seg === '.' || $seg === '') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $seg;
    }

    return ($base === '' ? '' : $base) . '/' . implode('/', $segments);
};

$manifest['scope'] = $scope;
$manifest['id']    = $scope;

if (isset($manifest['start_url']) && is_string($manifest['start_url'])) {
    $manifest['start_url'] = $absolutize($manifest['start_url']);
}

if (isset($manifest['shortcuts']) && is_array($manifest['shortcuts'])) {
    foreach ($manifest['shortcuts'] as &$sc) {
        if (isset($sc['url']) && is_string($sc['url'])) {
            $sc['url'] = $absolutize($sc['url']);
        }
    }
    unset($sc);
}

$absolutizeAsset = static function (string $src) use ($absolutize, $base): string {
    if (str_starts_with($src, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $src)) {
        return $absolutize($src);
    }

    return $absolutize('assets/' . ltrim($src, './'));
};

if (isset($manifest['icons']) && is_array($manifest['icons'])) {
    foreach ($manifest['icons'] as &$icon) {
        if (isset($icon['src']) && is_string($icon['src'])) {
            $icon['src'] = $absolutizeAsset($icon['src']);
        }
    }
    unset($icon);
}

if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');

    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
}

echo json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
