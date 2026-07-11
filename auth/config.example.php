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
    // 🔒 CSP NONCE: generate per request untuk gantikan 'unsafe-inline'
    $GLOBALS['_csp_nonce'] = bin2hex(random_bytes(16));
    // Nonce mengamankan <script> inline; 'unsafe-inline' diperlukan untuk 132+ event handler
    // (onclick, onerror, dll). Ketika nonce + unsafe-inline hadir bersamaan,
    // nonce mengontrol <script> element, unsafe-inline mengontrol event handler.
    // style-src juga 'unsafe-inline' karena JS library (SweetAlert2, HTMX) membuat
    // style secara dinamis yang tidak bisa dijangkau nonce.
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self' 'nonce-{$GLOBALS['_csp_nonce']}' 'unsafe-inline' 'unsafe-eval'");
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

// ════════════════════════════════════════════════════════════════
// CSP NONCE OUTPUT BUFFER
// Inject nonce="..." ke semua tag <script> dan <style> inline
// ════════════════════════════════════════════════════════════════

if (!isset($GLOBALS['_csp_ob_started'])) {
    $GLOBALS['_csp_ob_started'] = true;

    if (!isset($GLOBALS['_csp_nonce'])) {
        $GLOBALS['_csp_nonce'] = bin2hex(random_bytes(16));
    }
    $_nonce = $GLOBALS['_csp_nonce'];

    ob_start(function (string $html) use ($_nonce): string {
        // Inject nonce ke <script> inline (tanpa src attribute)
        $html = preg_replace(
            '/<script\b(?![^>]*\bsrc\s*=)(?![^>]*\bnonce\s*=)([^>]*)>/i',
            '<script$1 nonce="' . $_nonce . '">',
            $html
        );

        // Inject nonce ke <style> inline
        $html = preg_replace(
            '/<style\b(?![^>]*\bnonce\s*=)([^>]*)>/i',
            '<style$1 nonce="' . $_nonce . '">',
            $html
        );

        return $html;
    });
}

// ── Activity Logger ──
include_once __DIR__ . '/../modules/activity_logger.php';
