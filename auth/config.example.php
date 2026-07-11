<?php
/**
 * MEeL-HUB — Contoh Konfigurasi Aplikasi
 * 
 * Copy file ini ke config.php dan sesuaikan dengan environment Anda:
 *   cp config.example.php config.php
 * 
 * ─── PORTABILITY TIP ───
 * Semua path penyimpanan media terpusat di konstanta MEEL_HDD_BASE.
 * Cukup ubah nilai MEEL_HDD_BASE, seluruh sistem akan mengikuti.
 * 
 * Contoh:
 *   - HDD eksternal: /media/[user]/MEeL/media
 *   - Lokal SSD:     /var/www/meel-storage/media
 *   - Docker volume: /data/media
 *   - Relative:      __DIR__ . '/../storage/media'
 */

// ════════════════════════════════════════════════════════════════
// DATABASE CONFIGURATION
// ════════════════════════════════════════════════════════════════

$server   = "localhost";     // Host database (localhost atau IP)
$username = "root";          // Username database
$password = "";              // Password database
$db       = "MEeL";          // Nama database

$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    die("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $conn->connect_error);
}

// ════════════════════════════════════════════════════════════════
// MEDIA STORAGE PATHS (TERPUSAT)
// ════════════════════════════════════════════════════════════════
// ★ UBAH DI SINI jika ingin memindahkan lokasi penyimpanan media
//    Semua modul (Video, Music, Books, Drive) akan mengikuti path ini
// ★ Untuk HDD eksternal, pastikan sudah di-mount dan writable

define('MEEL_HDD_BASE', '/path/to/your/media');

// ── Path turunan (jangan diubah kecuali paham struktur folder) ──
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

// ════════════════════════════════════════════════════════════════
// SESSION & SECURITY
// ════════════════════════════════════════════════════════════════

$timeout = 43200; // 12 jam session lifetime
ini_set('session.gc_maxlifetime', $timeout);
session_set_cookie_params($timeout, "/");
session_name('meel');
session_start();

// ── Security Headers ──
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header("Strict-Transport-Security: max-age=15552000; includeSubDomains");
    }
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval'");
}

// ── CSRF Token ──
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── CSRF Verification ──
if (!function_exists('verify_csrf')) {
    function verify_csrf()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token'])
                || !isset($_SESSION['csrf_token'])
                || $_POST['csrf_token'] !== $_SESSION['csrf_token']
            ) {
                return false;
            }
        }
        return true;
    }
}

// ── Session Timeout Check ──
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// ── Activity Logger ──
include_once __DIR__ . '/../modules/activity_logger.php';
