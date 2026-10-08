<?php
if (!function_exists('resolve_binary')) {
    function resolve_binary(array $candidates): string
{

    static $const_map = null;
    if ($const_map === null) {
        $const_map = [];
        foreach (['ffmpeg', 'ffprobe', 'node', 'yt-dlp'] as $bin) {
            $const = 'MEEL_' . strtoupper($bin) . '_PATH';
            if (defined($const) && ($val = constant($const)) !== '') {
                $const_map[$bin] = $val;
            }
        }
    }

    foreach ($candidates as $candidate) {
        $base = basename($candidate);
        if (isset($const_map[$base]) && is_executable($const_map[$base])) {
            return $const_map[$base];
        }
    }

    foreach ($candidates as $candidate) {
        if (strpos($candidate, '/') !== false) {
            if (is_executable($candidate)) return $candidate;
            continue;
        }
        $resolved = trim((string)shell_exec("command -v " . escapeshellarg($candidate) . " 2>/dev/null"));
        if ($resolved !== '') return $resolved;
    }
    return $candidates[0];
}
}

if (!function_exists('base_url')) {

function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        if (defined('MEEL_BASE_URL')) {
            $base = rtrim(MEEL_BASE_URL, '/');
        } else {

            require_once __DIR__ . '/../base_url.php';
            $base = meel_base_url_path();
        }
    }
    return $base . '/' . ltrim($path, '/');
}
}

if (!function_exists('detectProtocol')) {

function detectProtocol(): string
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return 'https';
    }

    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cf = @json_decode($_SERVER['HTTP_CF_VISITOR'], true);
        if (!empty($cf['scheme']) && $cf['scheme'] === 'https') {
            return 'https';
        }
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https') {
        return 'https';
    }

    return 'http';
}
}

if (!function_exists('time_ago')) {
function time_ago(string|int $timestamp): string
{
    $time_diff = time() - (is_int($timestamp) ? $timestamp : strtotime($timestamp));
    if ($time_diff < 1) return 'Baru saja';
    $condition = [31104000 => 'tahun', 2592000 => 'bulan', 86400 => 'hari', 3600 => 'jam', 60 => 'menit', 1 => 'detik'];
    foreach ($condition as $secs => $str) {
        $d = $time_diff / $secs;
        if ($d >= 1) return round($d) . ' ' . $str . ' yang lalu';
    }
    return 'Baru saja';
}
}

if (!function_exists('format_bytes')) {
function format_bytes(int|float $bytes, int $precision = 2): string
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
}

if (!function_exists('meel_asset_version')) {
function meel_asset_version(string $file): string
{
    static $cache = [];
    $path = dirname(__DIR__, 3) . '/' . ltrim($file, '/');
    if (!isset($cache[$path])) {
        $cache[$path] = @filemtime($path);
    }
    return '?v=' . $cache[$path];
}
}

if (!function_exists('meel_css_links')) {
/**
 * Cetak <link rel="stylesheet"> untuk sebuah modul CSS.
 *
 * Kalau `assets/css/<module>/bundle.css` sudah dibangun
 * (scripts/build-css-bundle.php) emit satu request saja; kalau belum ada,
 * jatuh ke daftar individual dari manifest.php. Clone baru tanpa build
 * tetap tampil benar, dan urutan kaskade manifest tetap terjaga di kedua
 * jalur karena bundle disusun persis dari urutan itu.
 */
function meel_css_links(string $module, string $baseUrl): void
{
    $root = dirname(__DIR__, 3);
    $module = trim($module, '/');

    $bundleRel = 'assets/css/' . $module . '/bundle.css';
    if (is_file($root . '/' . $bundleRel)) {
        echo '<link rel="stylesheet" href="' . rtrim($baseUrl, '/') . '/bundle.css'
            . meel_asset_version($bundleRel) . '">' . "\n";
        return;
    }

    $manifest = $root . '/assets/css/' . $module . '/manifest.php';
    if (!is_file($manifest)) {
        return;
    }
    foreach (require $manifest as $file) {
        $rel = 'assets/css/' . $module . '/' . $file;
        echo '<link rel="stylesheet" href="' . rtrim($baseUrl, '/') . '/' . $file
            . meel_asset_version($rel) . '">' . "\n";
    }
}
}

if (!function_exists('meel_asset_dir_version')) {
function meel_asset_dir_version(string $dir): string
{
    static $cache = [];
    $full = dirname(__DIR__, 3) . '/' . ltrim($dir, '/');
    if (!isset($cache[$full])) {
        $max = 0;
        foreach (glob($full . '/*.js') ?: [] as $f) {
            $max = max($max, (int)@filemtime($f));
        }
        $cache[$full] = $max;
    }
    return '?v=' . $cache[$full];
}
}

if (!function_exists('meel_back_url')) {
function meel_back_url(string $default, array $extra_block = []): string
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    if (!is_string($ref) || $ref === '') {
        return $default;
    }

    $ref_host = parse_url($ref, PHP_URL_HOST);
    $cur_host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    if (!is_string($ref_host) || !is_string($cur_host) || $ref_host === ''
        || strcasecmp($ref_host, $cur_host) !== 0) {
        return $default;
    }

    $path = (string) (parse_url($ref, PHP_URL_PATH) ?? '');

    $blocked = array_merge(['edit-music', 'edit-video', 'lrc-editor'], $extra_block);
    $current = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($current !== '' && $current !== '/') {
        $blocked[] = $current;
    }

    foreach ($blocked as $page) {
        if (is_string($page) && $page !== '' && stripos($path, $page) !== false) {
            return $default;
        }
    }

    return $ref;
}
}

/* reference build: MEeL-C6H9N3O3 [c8a10b194723c915] */
