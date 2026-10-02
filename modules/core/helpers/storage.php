<?php

if (!function_exists('meel_media_base_path')) {
function meel_media_base_path(string $module): string
{
    $const = [
        'video' => 'MEEL_HDD_VIDEO_UPLOAD',
        'music' => 'MEEL_HDD_MUSIC_UPLOAD',
        'books' => 'MEEL_HDD_BOOKS_UPLOAD',
    ][$module] ?? null;
    if ($const !== null && defined($const)) {
        $v = (string) constant($const);
        if ($v !== '') {
            return rtrim($v, '/\\');
        }
    }
    return dirname(__DIR__, 3) . '/' . $module . '/upload';
}
}

if (!function_exists('music_thumbnail_url')) {
function music_thumbnail_url(?string $thumbnail): string
{
    $thumbnail = trim((string)$thumbnail);
    $thumb_dir = meel_media_base_path('music') . '/thumbnail/';
    $fallback = '../assets/img/music0.webp';
    static $default_thumb = null;
    if ($thumbnail === '') {
        if ($default_thumb === null) {
            $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
                : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
                : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
        }
        return $default_thumb;
    }

    $thumbnail = basename($thumbnail);
    if (str_ends_with($thumbnail, '.thumb.webp') && is_file($thumb_dir . $thumbnail)) {
        return 'upload/thumbnail/' . rawurlencode($thumbnail);
    }

    $base = preg_replace('/\\.thumb$/', '', pathinfo($thumbnail, PATHINFO_FILENAME)) ?: pathinfo($thumbnail, PATHINFO_FILENAME);
    $candidates = [
        $base . '.thumb.webp',
        $base . '.webp',
        $thumbnail
    ];
    foreach ($candidates as $candidate) {
        if (is_file($thumb_dir . $candidate)) {
            return 'upload/thumbnail/' . rawurlencode($candidate);
        }
    }

    if ($default_thumb === null) {
        $default_thumb = is_file($thumb_dir . 'default.thumb.webp') ? 'upload/thumbnail/default.thumb.webp'
            : (is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp'
            : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback));
    }
    return $default_thumb;
}
}

if (!function_exists('meel_storage_base_path')) {
function meel_storage_base_path(): string
{
    if (!defined('MEEL_HDD_BASE')) {
        return '';
    }
    return rtrim((string) MEEL_HDD_BASE, '/\\');
}
}

if (!function_exists('meel_storage_marker_path')) {
function meel_storage_marker_path(): string
{
    $base = meel_storage_base_path();
    return $base === '' ? '' : $base . '/.meel_mount';
}
}

if (!function_exists('meel_storage_ready')) {
function meel_storage_ready(): bool
{
    $base = meel_storage_base_path();

    if ($base === '') {
        return true;
    }

    if (!is_dir($base)) {
        return false;
    }

    if (is_file($base . '/.meel_mount')) {
        return true;
    }

    $entries = @scandir($base);
    if (!is_array($entries) || count(array_diff($entries, ['.', '..'])) === 0) {
        error_log('[MEeL] Folder storage kosong tanpa penanda .meel_mount, diasumsikan belum ter-mount: ' . $base);
        return false;
    }

    error_log('[MEeL] Penanda .meel_mount belum ada di ' . $base . ' — jalankan: touch "' . $base . '/.meel_mount"');
    return true;
}
}

if (!function_exists('meel_storage_path_in_volume')) {
function meel_storage_path_in_volume(string $path): bool
{
    $base = meel_storage_base_path();
    if ($base === '') {
        return false;
    }

    $cleanPath = rtrim(str_replace('\\', '/', $path), '/');
    $cleanBase = str_replace('\\', '/', $base);

    return $cleanPath === $cleanBase || str_starts_with($cleanPath, $cleanBase . '/');
}
}

if (!function_exists('meel_pid_dir_candidates')) {
function meel_pid_dir_candidates(): array
{
    return [
        '/tmp/meel_pids',
        dirname(__DIR__, 3) . '/temp/meel_pids',
    ];
}
}

if (!function_exists('meel_pid_dir')) {
function meel_pid_dir(): string
{
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    foreach (meel_pid_dir_candidates() as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
            @chmod($dir, 01777);
        }
        if (is_writable($dir)) {
            $resolved = $dir;
            return $dir;
        }
        @chmod($dir, 01777);
        if (is_writable($dir)) {
            $resolved = $dir;
            return $dir;
        }
    }

    $resolved = meel_pid_dir_candidates()[1];
    return $resolved;
}
}

if (!function_exists('meel_pid_filename')) {
function meel_pid_filename(string $task_type, int $queue_id): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $task_type)) {
        return '';
    }
    return $task_type . '_' . $queue_id . '.pid';
}
}

if (!function_exists('meel_storage_guard')) {
function meel_storage_guard(string $mode = 'html'): void
{
    if (meel_storage_ready()) {
        return;
    }

    $message = 'Penyimpanan media belum ter-mount. Silakan coba lagi nanti.';

    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('Penyimpanan media belum ter-mount: ' . meel_storage_base_path());
    }

    if (!headers_sent()) {
        header('Cache-Control: no-store');
    }

    if ($mode === 'html') {
        if (!headers_sent()) {
            require_once __DIR__ . '/../base_url.php';
            header(
                'Location: ' . meel_base_url_path()
                . '/err/?code=storage&back=beranda&reason=' . rawurlencode($message),
                true,
                303
            );
        }
        exit;
    }

    http_response_code(503);

    if ($mode === 'json') {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['status' => 'error', 'code' => 'storage_unmounted', 'message' => $message]);
        exit;
    }

    exit;
}
}

if (PHP_SAPI !== 'cli' && !defined('MEEL_HDD_CHECKED')) {
    define('MEEL_HDD_CHECKED', true);
    if (!meel_storage_ready()) {
        error_log('[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: ' . (meel_storage_base_path() ?: '(tidak didefinisikan)'));
    }
}

if (!function_exists('meel_drive_base_path')) {
function meel_drive_base_path(?string $hddDriveOverride = null): string
{
    $hddDrive = $hddDriveOverride ?? (defined('MEEL_HDD_DRIVE') ? (string) MEEL_HDD_DRIVE : '');
    if ($hddDrive !== '') {
        return rtrim($hddDrive, '/\\');
    }
    return dirname(__DIR__, 3) . '/data_drive';
}
}

if (!function_exists('check_disk_space')) {
function check_disk_space(int $required_bytes, string $path): array
{

    if (!is_dir($path)) {
        $path = dirname($path);
        $parent = dirname($path);
        while ($parent !== '/' && $parent !== '.' && !is_dir($parent)) {
            $parent = dirname($parent);
        }
        $path = $parent;
    }

    $free_bytes = disk_free_space($path);
    if ($free_bytes === false) {
        return [
            'ok' => false,
            'free' => 0,
            'required' => $required_bytes,
            'path' => $path,
            'error' => 'Tidak dapat membaca kapasitas disk.',
        ];
    }

    return [
        'ok' => ($free_bytes >= $required_bytes),
        'free' => $free_bytes,
        'required' => $required_bytes,
        'path' => $path,
        'error' => null,
    ];
}
}

if (!function_exists('require_disk_space')) {
function require_disk_space(int $required_bytes, string $path, string $label): void
{
    $result = check_disk_space($required_bytes, $path);
    if ($result['ok']) return;

    $free_gb = sprintf('%.1f', $result['free'] / (1024 ** 3));
    $need_gb = sprintf('%.1f', $result['required'] / (1024 ** 3));
    $error_ms = $result['error'] ?? "Hanya tersedia {$free_gb} GB, butuh minimal {$need_gb} GB";

    throw new \RuntimeException("Ruang {$label} tidak mencukupi! {$error_ms}");
}
}

if (!function_exists('dir_size')) {

function dir_size(string $path, int $cache_ttl = 300): float
{
    $cache_key = 'dirsize_' . md5($path);
    $cache_file = dirname(__DIR__, 3) . '/temp/' . $cache_key . '.cache';

    if (is_readable($cache_file)) {
        $content = file_get_contents($cache_file);
        $cached = $content !== false ? json_decode($content, true) : null;
        if ($cached && isset($cached['size'], $cached['time'])) {
            if (time() - $cached['time'] < $cache_ttl) {
                return (float)$cached['size'];
            }
        }
    }

    if (!is_dir($path)) return 0.0;

    $output = shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
    if ($output && preg_match('/^(\d+)/', $output, $m)) {
        $size = (float)$m[1];
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
        return $size;
    }

    $size = 0.0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        meel_write_cache_file($cache_file, json_encode(['size' => $size, 'time' => time()]));
    } catch (RuntimeException $e) {
        return 0.0;
    }
    return $size;
}
}

if (!function_exists('invalidate_dir_size_cache')) {

function invalidate_dir_size_cache(string $username): void
{
    $userPath = meel_drive_base_path() . '/private_admins/' . $username;
    $cacheFile = dirname(__DIR__, 3) . '/temp/dirsize_' . md5($userPath) . '.cache';
    if (is_file($cacheFile)) {
        if (!is_writable(dirname($cacheFile))) {
            error_log("[MEeL] invalidate_dir_size_cache: direktori tidak writable: " . dirname($cacheFile));
        } elseif (!unlink($cacheFile)) {
            error_log("[MEeL] invalidate_dir_size_cache: gagal menghapus cache: {$cacheFile}");
        }
    }
}
}

if (!function_exists('meel_write_cache_file')) {

function meel_write_cache_file(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        error_log("[MEeL] storage.php: cache file tidak bisa ditulis: {$path}");
        return;
    }
    file_put_contents($path, $content, LOCK_EX);
}
}

if (!function_exists('log_drive_operation')) {
function log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success'): void
{
    global $conn;

    $logDir = dirname(__DIR__, 3) . '/logs';
    if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
        error_log("[MEeL] log_drive_operation: gagal membuat log dir: {$logDir}");
    }

    $logFile = $logDir . '/drive_audit.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200);

    $logEntry = json_encode([
        'timestamp' => $timestamp,
        'user_id' => $userId,
        'username' => $username,
        'operation' => $operation,
        'filename' => $filename,
        'type' => $type,
        'scope' => $scope,
        'status' => $status,
        'ip' => $ip,
        'user_agent' => $userAgent
    ]) . "\n";

    if (!is_dir($logDir) || !is_writable($logDir)) {
        error_log("[MEeL] log_drive_operation: log dir tidak writable: {$logDir}");
    } elseif (file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
        error_log("[MEeL] log_drive_operation: gagal menulis log: {$logFile}");
    }
}
}

if (!function_exists('meel_xsendfile_enabled')) {
function meel_xsendfile_enabled(): bool
{
    if (!defined('MEEL_USE_XSENDFILE') || MEEL_USE_XSENDFILE !== true) {
        return false;
    }
    if (!function_exists('apache_get_modules')) {
        return false;
    }
    return in_array('mod_xsendfile', apache_get_modules(), true);
}
}

if (!function_exists('meel_xsendfile_config_files')) {
function meel_xsendfile_config_files(): array
{
    static $files = null;
    if ($files !== null) {
        return $files;
    }

    $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $candidates = [];
    if ($docRoot !== '') {
        $candidates[] = dirname($docRoot) . '/etc/httpd.conf';
    }
    $candidates[] = '/opt/lampp/etc/httpd.conf';
    $candidates[] = '/etc/apache2/apache2.conf';
    $candidates[] = '/etc/httpd/conf/httpd.conf';

    $main = '';
    foreach ($candidates as $candidate) {
        if (is_readable($candidate)) {
            $main = $candidate;
            break;
        }
    }
    if ($main === '') {
        $files = [];
        return $files;
    }

    $found = [];
    $queue = [[$main, 0]];
    while ($queue !== []) {
        [$file, $depth] = array_shift($queue);
        $real = realpath($file);
        if ($real === false || isset($found[$real]) || !is_readable($real) || count($found) > 60) {
            continue;
        }
        $found[$real] = true;
        if ($depth >= 4) {
            continue;
        }
        $content = @file_get_contents($real);
        if ($content === false) {
            continue;
        }
        $dir = dirname($real);
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!preg_match('/^\s*Include(?:Optional)?\s+(.+?)\s*$/i', $line, $m)) {
                continue;
            }
            $target = trim($m[1], " \t\"'");
            if ($target === '' || $target[0] === '#') {
                continue;
            }
            if ($target[0] === '/') {
                $resolved = [$target];
            } else {
                $resolved = [$dir . '/' . $target, dirname($dir) . '/' . $target];
            }
            foreach ($resolved as $candidate) {
                $matches = (strpbrk($target, '*?[') !== false) ? (glob($candidate) ?: []) : [$candidate];
                foreach ($matches as $match) {
                    $queue[] = [$match, $depth + 1];
                }
            }
        }
    }

    $files = array_keys($found);
    return $files;
}
}

if (!function_exists('meel_xsendfile_flag_from_text')) {
function meel_xsendfile_flag_from_text(string $content): ?bool
{
    $flag = null;
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
        if (!preg_match('/^\s*XSendFile\s+(on|off)\b/i', $line, $m)) {
            continue;
        }
        $flag = strtolower($m[1]) === 'on';
    }
    return $flag;
}
}

if (!function_exists('meel_xsendfile_config_data')) {
function meel_xsendfile_config_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }

    $confFiles = meel_xsendfile_config_files();
    $sig = '';
    foreach ($confFiles as $confFile) {
        $sig .= $confFile . ':' . (int) @filemtime($confFile) . ';';
    }
    $sig = md5($sig);

    $cacheFile = dirname(__DIR__, 3) . '/temp/xsendfile_roots.cache';
    if (is_readable($cacheFile)) {
        $cached = json_decode((string) @file_get_contents($cacheFile), true);
        if (is_array($cached)
            && ($cached['sig'] ?? '') === $sig
            && array_key_exists('flag', $cached)
            && (int) ($cached['time'] ?? 0) > time() - 3600) {
            $data = [
                'roots' => array_values((array) ($cached['roots'] ?? [])),
                'flag' => (bool) $cached['flag'],
            ];
            return $data;
        }
    }

    $parsed = [];
    $flag = false;
    foreach ($confFiles as $confFile) {
        $content = @file_get_contents($confFile);
        if ($content === false) {
            continue;
        }
        $fileFlag = meel_xsendfile_flag_from_text($content);
        if ($fileFlag !== null) {
            $flag = $fileFlag;
        }
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            if (!preg_match('/^\s*XSendFilePath\s+(.+?)\s*$/i', $line, $m)) {
                continue;
            }
            $path = trim($m[1], " \t\"'");
            if ($path === '' || $path[0] === '#') {
                continue;
            }
            $parsed[] = rtrim(realpath($path) ?: $path, '/');
        }
    }
    $roots = array_values(array_unique(array_filter($parsed)));

    meel_write_cache_file($cacheFile, json_encode([
        'sig' => $sig,
        'time' => time(),
        'roots' => $roots,
        'flag' => $flag,
    ]));

    $data = ['roots' => $roots, 'flag' => $flag];

    return $data;
}
}

if (!function_exists('meel_xsendfile_roots')) {
function meel_xsendfile_roots(): array
{
    return meel_xsendfile_config_data()['roots'];
}
}

if (!function_exists('meel_xsendfile_server_flag')) {
function meel_xsendfile_server_flag(): bool
{
    return meel_xsendfile_config_data()['flag'];
}
}

if (!function_exists('meel_xsendfile_htaccess_dirs')) {
function meel_xsendfile_htaccess_dirs(): array
{
    $docRoot = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($docRoot === '' || $docRoot === '/' || !is_dir($docRoot)) {
        return [];
    }

    $paths = [];
    $uri = (string) ($_SERVER['REDIRECT_URL'] ?? '');
    if ($uri === '') {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    }
    if ($uri !== '') {
        $path = parse_url($uri, PHP_URL_PATH);
        if (is_string($path)) {
            $paths[] = $path;
        }
    }
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '') {
        $paths[] = $script;
    }

    $dirs = [$docRoot];
    foreach ($paths as $path) {
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0")) {
            continue;
        }
        $current = $docRoot;
        foreach (explode('/', str_replace('\\', '/', $decoded)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                break;
            }
            $next = $current . '/' . $segment;
            if (!is_dir($next)) {
                break;
            }
            $current = $next;
            $dirs[] = $current;
        }
    }

    $dirs = array_values(array_unique($dirs));
    usort($dirs, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

    return $dirs;
}
}

if (!function_exists('meel_xsendfile_merge_flag')) {
function meel_xsendfile_merge_flag(bool $base, array $contents): bool
{
    $flag = $base;
    foreach ($contents as $content) {
        if (!is_string($content)) {
            continue;
        }
        $value = meel_xsendfile_flag_from_text($content);
        if ($value !== null) {
            $flag = $value;
        }
    }
    return $flag;
}
}

if (!function_exists('meel_xsendfile_effective_flag')) {
function meel_xsendfile_effective_flag(): bool
{
    static $flag = null;
    if ($flag !== null) {
        return $flag;
    }

    $contents = [];
    foreach (meel_xsendfile_htaccess_dirs() as $dir) {
        $file = $dir . '/.htaccess';
        if (!is_readable($file)) {
            $contents[] = null;
            continue;
        }
        $content = @file_get_contents($file);
        $contents[] = ($content === false) ? null : $content;
    }

    $flag = meel_xsendfile_merge_flag(meel_xsendfile_server_flag(), $contents);

    return $flag;
}
}

if (!function_exists('meel_xsendfile_ready')) {
function meel_xsendfile_ready(string $realPath): bool
{
    if (!meel_xsendfile_enabled()) {
        return false;
    }
    if (!meel_xsendfile_effective_flag()) {
        return false;
    }
    $real = realpath($realPath);
    if ($real === false) {
        return false;
    }
    foreach (meel_xsendfile_roots() as $root) {
        if ($root !== '' && str_starts_with($real, $root . '/')) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('meel_xsendfile_header')) {
function meel_xsendfile_header(string $realPath): string
{
    return str_replace('%', '%25', $realPath);
}
}

if (!function_exists('meel_serve_media_file')) {
function meel_serve_media_file(string $module, string $relPath, array $opts = []): void
{
    meel_storage_guard('binary');

    $base = meel_media_base_path($module);
    $baseReal = realpath($base);
    if ($baseReal === false) {
        http_response_code(503);
        exit('Storage tidak tersedia.');
    }

    $relPath = str_replace('\\', '/', (string) $relPath);
    $relPath = ltrim($relPath, '/');
    if ($relPath === '' || str_contains($relPath, '..') || str_contains($relPath, "\0")) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    $full = $base . '/' . $relPath;
    $realFull = realpath($full);
    if ($realFull === false || !str_starts_with($realFull, $baseReal . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('File tidak ditemukan.');
    }
    if (!is_file($realFull) || !is_readable($realFull)) {
        http_response_code(404);
        exit('File tidak ditemukan.');
    }

    $ext = strtolower(pathinfo($realFull, PATHINFO_EXTENSION));
    $allowed = [
        'm3u8', 'ts', 'vtt', 'mp4', 'webm', 'mkv',
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf',
        'mp3', 'ogg', 'm4a', 'flac', 'wav', 'opus',
    ];
    if (!in_array($ext, $allowed, true)) {
        http_response_code(403);
        exit('Tipe file tidak diizinkan.');
    }

    $mimeMap = [
        'm3u8' => 'application/vnd.apple.mpegurl', 'ts' => 'video/mp2t',
        'vtt' => 'text/vtt', 'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'mkv' => 'video/x-matroska', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'pdf' => 'application/pdf', 'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg',
        'm4a' => 'audio/mp4', 'flac' => 'audio/flac', 'wav' => 'audio/wav',
        'opus' => 'audio/ogg',
    ];
    $mime = $mimeMap[$ext] ?? 'application/octet-stream';

    if (!empty($opts['hls_gate']) && (str_starts_with($relPath, 'video/') || str_contains($relPath, '/video/'))) {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $refOk = false;
        if ($referer !== '' && $host !== '') {
            $parts = parse_url($referer);
            $hostNorm = strtolower(parse_url('http://' . $host, PHP_URL_HOST) ?: $host);
            if ($parts && isset($parts['host']) && strtolower($parts['host']) === $hostNorm) {
                $refPath = $parts['path'] ?? '';
                if (preg_match('#/video(?:/(?:watch(?:\.php)?|index(?:\.php)?|beranda))?(?:[?\#]|/?$)#i', $refPath)) {
                    $refOk = true;
                }
            }
        }
        if (!$refOk) {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = rtrim(dirname(dirname($script)), '/');
            header('Location: ' . $basePath . '/err/?code=denied');
            exit;
        }
    }

    if (meel_xsendfile_ready($realFull)) {
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, must-revalidate');
        header('X-Sendfile: ' . meel_xsendfile_header($realFull));
        exit;
    }

    $size = (int) @filesize($realFull);
    $start = 0;
    $end = $size - 1;
    $range = $_SERVER['HTTP_RANGE'] ?? '';
    $isPartial = false;
    if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
        $rStart = $m[1] !== '' ? (int) $m[1] : null;
        $rEnd = $m[2] !== '' ? (int) $m[2] : null;
        if ($rStart === null && $rEnd === null) {
            $rStart = 0;
        }
        if ($rStart !== null) {
            $start = max(0, $rStart);
            $end = ($rEnd !== null && $rEnd < $size) ? $rEnd : ($size - 1);
            if ($start > $end) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $isPartial = true;
        } elseif ($rEnd !== null) {
            $start = max(0, $size - $rEnd);
            $end = $size - 1;
            $isPartial = true;
        }
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    if ($isPartial) {
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }

    set_time_limit(0);
    ignore_user_abort(false);

    while (@ob_get_level()) {
        @ob_end_clean();
    }
    @ob_implicit_flush(true);

    $fp = @fopen($realFull, 'rb');
    if ($fp === false) {
        http_response_code(500);
        exit('Gagal membuka file.');
    }
    if ($start > 0) {
        fseek($fp, $start);
    }
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($fp)) {
        $chunk = fread($fp, min(8192, $remaining));
        if ($chunk === false) break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fp);
    exit;
}
}

/* reference build: MEeL-C5H5N5O [2ab4368692d49e36] */
