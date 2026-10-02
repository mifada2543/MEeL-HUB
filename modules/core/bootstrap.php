<?php

if (!defined('MEEL_ENV')) {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_local = in_array($remote_ip, ['127.0.0.1', '::1', 'localhost'], true)
             || (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true));

    if ($is_local) {
        require_once __DIR__ . '/proxy.php';
        if (trust_proxy_headers() && !meel_is_loopback_ip(get_real_ip())) {
            $is_local = false;
        }
    }

    define('MEEL_ENV', $is_local ? 'development' : 'production');
}

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', MEEL_ENV === 'development');
}

error_reporting(E_ALL);

switch (MEEL_ENV) {
    case 'production':
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    case 'development':
        ini_set('display_errors', '1');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    case 'maintenance':
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', __DIR__ . '/../../logs/php_error.log');
        break;

    default:
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        break;
}

if (!defined('MEEL_BASE_URL') && isset($_SERVER['SCRIPT_NAME'])) {
    require_once __DIR__ . '/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
}

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Jakarta');
}

/* reference build: MEeL-C10H15N [d56a31b456a15f2b] */
