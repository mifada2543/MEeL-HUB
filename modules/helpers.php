<?php

/**
 * Cache-busting: Generate asset URL dengan timestamp filemtime.
 * Browser/CDN akan fetch ulang saat file diubah.
 */
function asset_url(string $relative_path): string
{
    static $base_dir = null;
    if ($base_dir === null) {
        $base_dir = dirname(__DIR__); // project root
    }

    // Normalize: buang leading ./ atau ../
    $path = ltrim($relative_path, '.');
    $path = ltrim($path, '/');

    // Cari bagian "assets/..." untuk rebuild full path
    if (preg_match('#(assets/.+)$#', $path, $m)) {
        $real_path = $base_dir . '/' . $m[1];
        if (file_exists($real_path)) {
            return $relative_path . '?v=' . filemtime($real_path);
        }
    }

    return $relative_path;
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
    $fallback  = '../assets/img/music0.png';

    if ($thumbnail === '') {
        if (is_file($thumb_dir . 'default.thumb.webp')) return 'upload/thumbnail/default.thumb.webp';
        return is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp' : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback);
    }

    $thumbnail = basename($thumbnail);
    if (str_ends_with($thumbnail, '.thumb.webp') && is_file($thumb_dir . $thumbnail)) {
        return 'upload/thumbnail/' . rawurlencode($thumbnail);
    }

    $filename = pathinfo($thumbnail, PATHINFO_FILENAME);
    $base     = preg_replace('/\\.thumb$/', '', $filename) ?: $filename;
    $thumb_webp = $base . '.thumb.webp';
    $webp_file  = $base . '.webp';

    if (is_file($thumb_dir . $thumb_webp)) {
        return 'upload/thumbnail/' . rawurlencode($thumb_webp);
    }

    if (is_file($thumb_dir . $webp_file)) {
        return 'upload/thumbnail/' . rawurlencode($webp_file);
    }

    if (is_file($thumb_dir . $thumbnail)) {
        return 'upload/thumbnail/' . rawurlencode($thumbnail);
    }

    if (is_file($thumb_dir . 'default.thumb.webp')) return 'upload/thumbnail/default.thumb.webp';
    return is_file($thumb_dir . 'default.webp') ? 'upload/thumbnail/default.webp' : (is_file($thumb_dir . 'default.png') ? 'upload/thumbnail/default.png' : $fallback);
}
// Tentukan path salah satu folder utama di HDD
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';

// Cek apakah folder tersebut bisa diakses
if (!is_dir($hdd_check_path)) {
    // Jika HDD tidak terdeteksi dan user bukan di halaman error itu sendiri
    if (basename($_SERVER['PHP_SELF']) !== 'maintance.php') {
        header("Location: ../err/maintance.php");
        exit();
    }
}
function get_user_usage($username)
{
    $path = dirname(__DIR__) . "/data_drive/private_admins/" . $username;
    if (!is_dir($path)) return 0;

    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
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
