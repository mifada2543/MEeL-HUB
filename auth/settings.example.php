<?php
if (!isset($server))   $server   = (getenv('MEEL_DB_HOST') ?: "localhost");
if (!isset($username)) $username = (getenv('MEEL_DB_USER') ?: "root");
if (!isset($password)) $password = (getenv('MEEL_DB_PASS') ?: "");
if (!isset($db))       $db       = (getenv('MEEL_DB_NAME') ?: "MEeL");

if (!defined('MEEL_HOST')) {
    define('MEEL_HOST', $_SERVER['HTTP_HOST'] ?? '');
}
if (!defined('MEEL_CANONICAL_HOST')) {
    define('MEEL_CANONICAL_HOST', '');
}
if (!defined('MEEL_TRUSTED_HOSTS')) {
    define('MEEL_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1');
}
if (!defined('MEEL_TIMEZONE')) {
    define('MEEL_TIMEZONE', 'Asia/Jakarta');
}

if (!defined('MEEL_TRUST_PROXY_HEADERS')) {
    define('MEEL_TRUST_PROXY_HEADERS', false);
}

if (!defined('MEEL_TRUSTED_PROXIES')) {
    define('MEEL_TRUSTED_PROXIES', ['127.0.0.1', '::1']);
}

if (!defined('MEEL_FFMPEG_PATH')) {
    define('MEEL_FFMPEG_PATH', '');
}
if (!defined('MEEL_FFPROBE_PATH')) {
    define('MEEL_FFPROBE_PATH', '');
}
if (!defined('MEEL_NODE_PATH')) {
    define('MEEL_NODE_PATH', '');
}
if (!defined('MEEL_YTDLP_PATH')) {
    define('MEEL_YTDLP_PATH', '');
}

if (!defined('MEEL_HDD_BASE')) {
    define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

    define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
    define('MEEL_HDD_VIDEO_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'video/');
    define('MEEL_HDD_THUMB_DIR',    MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
    define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
    define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
    define('MEEL_HDD_DRIVE',        MEEL_HDD_BASE . '/drive/');
}

if (!defined('MEEL_USE_XSENDFILE')) {
    define('MEEL_USE_XSENDFILE', false);
}

/* reference build: MEeL-C8H11NO2 [cad21924ae703bf5] */