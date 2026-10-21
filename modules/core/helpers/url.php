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

            $abs = realpath($root . '/' . $rel);
            if ($abs === false || !is_file($abs)) {
                error_log('[meel_css_links] lewati (file tidak ada): ' . $rel);
                continue;
            }

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

if (!function_exists('meel_canonical_host')) {
    function meel_canonical_host(): string
    {
        if (defined('MEEL_CANONICAL_HOST')) {
            return strtolower(trim((string) constant('MEEL_CANONICAL_HOST')));
        }
        return '';
    }
}
if (!function_exists('meel_trusted_hosts')) {
    function meel_trusted_hosts(?string $canonical = null, ?string $extra = null): array
    {
        $canonical = $canonical ?? meel_canonical_host();
        $extra = $extra ?? (defined('MEEL_TRUSTED_HOSTS') ? (string) constant('MEEL_TRUSTED_HOSTS') : '');
        static $cache = null;
        $key = $canonical . "\0" . $extra;
        if ($cache !== null && $cache[0] === $key) {
            return $cache[1];
        }
        $hosts = [];
        if ($canonical !== '') {
            $hosts[] = $canonical;
        }
        $hosts[] = 'localhost';
        $hosts[] = '127.0.0.1';
        $hosts[] = '::1';
        foreach (explode(',', $extra) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        $cache = [$key, array_values(array_unique($hosts))];
        return $cache[1];
    }
}
if (!function_exists('meel_same_origin_referer')) {
    function meel_same_origin_referer(?string $ref = null): ?string
    {
        $ref = $ref ?? ($_SERVER['HTTP_REFERER'] ?? '');
        if (!is_string($ref) || $ref === '') {
            return null;
        }
        $scheme = strtolower((string) parse_url($ref, PHP_URL_SCHEME));
        if ($scheme !== '' && $scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $refHost = parse_url($ref, PHP_URL_HOST);
        if (!is_string($refHost) || $refHost === '') {
            return null;
        }
        $refHost = strtolower(trim($refHost, '[]'));
        if (!in_array($refHost, meel_trusted_hosts(), true)) {
            return null;
        }
        return $ref;
    }
}
if (!function_exists('meel_path_segments')) {
    function meel_path_segments(string $path): array
    {
        if (!function_exists('meel_base_url_path')) {
            require_once __DIR__ . '/../base_url.php';
        }
        $base = meel_base_url_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        return array_values(array_filter(explode('/', $path), static function ($s) {
            return $s !== '';
        }));
    }
}
if (!function_exists('meel_path_is_blocked')) {
    function meel_path_is_blocked(string $path, array $blocked): bool
    {
        $variants = [$path];
        $segments = meel_path_segments($path);
        $stripped = '/' . implode('/', $segments);
        if ($stripped !== $path) {
            $variants[] = $stripped;
        }
        foreach ($blocked as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $entry = trim($entry, '/');
            if ($entry === '') {
                continue;
            }
            $want = explode('/', $entry);
            $count = count($want);
            foreach ($variants as $variant) {
                $candidate = array_values(array_filter(explode('/', $variant), static function ($s) {
                    return $s !== '';
                }));
                $total = count($candidate);
                for ($i = 0; $i + $count <= $total; $i++) {
                    $match = true;
                    foreach ($want as $k => $w) {
                        if (strcasecmp($candidate[$i + $k], $w) !== 0) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
if (!function_exists('meel_current_path')) {
    function meel_current_path(): string
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '');
        return $path === '' ? '/' : $path;
    }
}
if (!function_exists('meel_same_path')) {
    function meel_same_path(string $a, string $b): bool
    {
        return implode('/', meel_path_segments($a)) === implode('/', meel_path_segments($b));
    }
}
if (!function_exists('meel_back_url')) {
    function meel_back_url(string $default, array $extra_block = []): string
    {
        $ref = meel_same_origin_referer();
        if ($ref === null) {
            return $default;
        }
        $path = (string) (parse_url($ref, PHP_URL_PATH) ?? '');
        if (meel_same_path($path, meel_current_path())) {
            return $default;
        }
        $blocked = array_merge(['edit-music', 'edit-video', 'lrc-editor'], $extra_block);
        if (meel_path_is_blocked($path, $blocked)) {
            return $default;
        }
        return $ref;
    }
}
/* reference build: MEeL-C6H9N3O3 [c8a10b194723c915] */