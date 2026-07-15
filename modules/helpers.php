<?php

/**
 * Deteksi protokol HTTPS/HTTP dengan dukungan proxy/Cloudflare.
 * Cloudflare Tunnel menghubungkan origin via HTTP, sehingga $_SERVER['HTTPS']
 * tidak ter-set. Sebagai gantinya, Cloudflare mengirim header:
 *   - HTTP_X_FORWARDED_PROTO: https
 *   - HTTP_CF_VISITOR: {"scheme":"https"}
 */
function detectProtocol(): string
{
    // 1. Standard HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    // 2. Forwarded proto (Cloudflare, Nginx, Apache mod_proxy, dll)
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return 'https';
    }
    // 3. Cloudflare CF-Visitor header
    if (!empty($_SERVER['HTTP_CF_VISITOR'])) {
        $cf = @json_decode($_SERVER['HTTP_CF_VISITOR'], true);
        if (!empty($cf['scheme']) && $cf['scheme'] === 'https') {
            return 'https';
        }
    }
    // 4. Forwarded scheme
    if (!empty($_SERVER['HTTP_X_FORWARDED_SCHEME']) && strtolower($_SERVER['HTTP_X_FORWARDED_SCHEME']) === 'https') {
        return 'https';
    }
    // 5. Fallback
    return 'http';
}

function time_ago($timestamp)
{
    $time_diff = time() - strtotime($timestamp);
    if ($time_diff < 1) return 'Baru saja';
    $condition = [31104000 => 'tahun', 2592000 => 'bulan', 86400 => 'hari', 3600 => 'jam', 60 => 'menit', 1 => 'detik'];
    foreach ($condition as $secs => $str) {
        $d = $time_diff / $secs;
        if ($d >= 1) return round($d) . ' ' . $str . ' yang lalu';
    }
}
function format_bytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function music_thumbnail_url(?string $thumbnail): string
{
    $thumbnail = trim((string)$thumbnail);
    $thumb_dir = __DIR__ . '/../music/upload/thumbnail/';
    $fallback  = '../assets/img/music0.webp';

    // Cache default path untuk menghindari is_file() berulang
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

    // Cari dalam urutan prioritas
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
// Tentukan path salah satu folder utama di HDD (dari config.php)
$hdd_check_path = defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : '/path/to/your/media';

// Cek apakah folder tersebut bisa diakses
if (!is_dir($hdd_check_path)) {
    // Lewati pengecekan untuk request HTMX (mis. swap recovery).
    // HTMX mengirim header HX-Request: true pada setiap AJAX request.
    if (!isset($_SERVER['HTTP_HX_REQUEST'])) {
        // Jika HDD tidak terdeteksi dan user bukan di halaman error itu sendiri
        if (basename($_SERVER['PHP_SELF']) !== 'maintance.php') {
            header("Location: ../err/maintance.php");
            exit();
        }
    }
}
function get_user_usage($username)
{
    $path = dirname(__DIR__) . "/data_drive/private_admins/" . $username;
    if (!is_dir($path)) return 0;

    // Pakai du -sb (jauh lebih cepat dari RecursiveIterator untuk folder besar)
    $output = @shell_exec("du -sb " . escapeshellarg($path) . " 2>/dev/null");
    if ($output && preg_match('/^(\d+)/', $output, $m)) {
        return (float)$m[1];
    }

    // Fallback: RecursiveIterator jika shell_exec tidak tersedia
    $size = 0;
    try {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
    } catch (Exception $e) {
        return 0;
    }
    return $size;
}

/**
 * Get CSRF token dari session (sudah diinisialisasi di config.php)
 */
function get_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}

/**
 * Verifikasi CSRF token (wrapper untuk verify_csrf dari config.php)
 */
function verify_csrf_token(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

// ════════════════════════════════════════════════════════════════
// PRE-FLIGHT DISK SPACE HELPERS
// ════════════════════════════════════════════════════════════════

/**
 * Periksa apakah path memiliki ruang disk yang cukup.
 *
 * @param int    $required_bytes Jumlah byte yang dibutuhkan
 * @param string $path           Path untuk diperiksa (file atau direktori)
 * @return array ['ok' => bool, 'free' => float, 'required' => float, 'path' => string]
 */
function check_disk_space(int $required_bytes, string $path): array
{
    // Jika path bukan direktori (kemungkinan file), ambil direktori parent-nya
    if (!is_dir($path)) {
        $path = dirname($path);
        // Traverse up jika parent tidak ditemukan
        $parent = dirname($path);
        while ($parent !== '/' && $parent !== '.' && !is_dir($parent)) {
            $parent = dirname($parent);
        }
        $path = $parent;
    }

    $free_bytes = @disk_free_space($path);
    if ($free_bytes === false) {
        return [
            'ok'       => false,
            'free'     => 0,
            'required' => $required_bytes,
            'path'     => $path,
            'error'    => 'Tidak dapat membaca kapasitas disk.',
        ];
    }

    return [
        'ok'       => ($free_bytes >= $required_bytes),
        'free'     => $free_bytes,
        'required' => $required_bytes,
        'path'     => $path,
        'error'    => null,
    ];
}

/**
 * Pre-flight check: lempar RuntimeException jika ruang disk tidak cukup.
 *
 * @param int    $required_bytes Jumlah byte minimum yang diperlukan
 * @param string $path           Path tujuan (folder HDD, RAM disk, dll)
 * @param string $label          Label deskriptif (contoh: 'video storage', 'RAM disk')
 * @throws \RuntimeException Jika disk space tidak mencukupi
 */
function require_disk_space(int $required_bytes, string $path, string $label): void
{
    $result = check_disk_space($required_bytes, $path);
    if ($result['ok']) return;

    $free_gb  = sprintf('%.1f', $result['free'] / (1024 ** 3));
    $need_gb  = sprintf('%.1f', $result['required'] / (1024 ** 3));
    $error_ms = $result['error'] ?? "Hanya tersedia {$free_gb} GB, butuh minimal {$need_gb} GB";

    throw new \RuntimeException("Ruang {$label} tidak mencukupi! {$error_ms}");
}

/**
 * Log drive operations untuk audit trail
 */
function log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success'): void
{
    global $conn;
    
    $logDir = dirname(__DIR__) . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
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
    
    @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
}
