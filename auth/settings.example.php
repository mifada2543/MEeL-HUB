<?php
/**
 * MEeL Configuration Template
 * Copy this file to auth/settings.php and customize.
 *
 * All configuration is centralized here. The installer (install.sh) will patch
 * this file with your settings. Do not edit the patched file directly —
 * re-run install.sh to make changes.
 */

// ─── Database Connection ───
// These can also be set via environment variables: MEEL_DB_HOST, MEEL_DB_USER, MEEL_DB_PASS, MEEL_DB_NAME
$server   = "localhost";   // Database host
$username = "root";        // Database username
$password = "";            // Database password
$db       = "MEeL";        // Database name

// ─── Host Configuration ───
define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
define('MEEL_CANONICAL_HOST', '');           // Your production domain (e.g., 'meel.example.com')
define('MEEL_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1');  // Additional trusted hosts for referer gate
define('MEEL_TIMEZONE', 'Asia/Jakarta');     // Application timezone

// ─── Environment ───
// Uncomment and set to 'development' for local development
// define('MEEL_ENV', 'production');
// define('APP_DEBUG', false);

// ─── Trusted Proxy (for reverse proxy / Cloudflare) ───
define('MEEL_TRUST_PROXY_HEADERS', false);   // Set true ONLY if behind trusted proxy
define('MEEL_TRUSTED_PROXIES', '127.0.0.1,::1');  // Comma-separated list of trusted proxy IPs

// ─── Media Storage Paths (TERPUSAT) ───
// ★ CHANGE THIS to your actual storage mount point before production!
define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

// Derived paths (computed from MEEL_HDD_BASE — do not edit manually)
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');

// ─── External Binary Paths ───
// Leave empty for auto-detection (resolveBinary searches PATH + common locations).
// yt-dlp: empty = MEEL_YTDLP_PATH -> tools/yt-dlp/yt-dlp -> PATH.
define('MEEL_FFMPEG_PATH', '');
define('MEEL_FFPROBE_PATH', '');
define('MEEL_NODE_PATH', '');
define('MEEL_YTDLP_PATH', '');

// ─── X-Sendfile (Apache acceleration) ───
define('MEEL_USE_XSENDFILE', false);  // Set true if mod_xsendfile is installed & configured

// ─── Rate Limits & Concurrency ───
if (!defined('MEEL_MAX_DOWNLOAD_BYTES')) {
    define('MEEL_MAX_DOWNLOAD_BYTES', 2 * 1024 * 1024 * 1024);   
}
if (!defined('MEEL_MAX_DOWNLOAD_SECONDS')) {
    define('MEEL_MAX_DOWNLOAD_SECONDS', 2 * 60 * 60);       
}
define('MEEL_MAX_CONCURRENT_QUEUES', 2);         // Max simultaneous transcode jobs

/* reference build: MEeL-C8H11NO2 [cad21924ae703bf5] */