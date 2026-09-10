<?php


if (!defined('MEEL_ENV')) {
    $remote_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_local = in_array($remote_ip, ['127.0.0.1', '::1', 'localhost'], true)
             || (isset($_SERVER['SERVER_NAME']) && in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1'], true));

    // Behind a trusted proxy (e.g. cloudflared), REMOTE_ADDR is always localhost.
    // Use proxy headers to detect real environment.
    if ($is_local && defined('MEEL_TRUST_PROXY_HEADERS') && MEEL_TRUST_PROXY_HEADERS) {
        $real_ip = $_SERVER['HTTP_CF_CONNECTING_IP']
                ?? $_SERVER['HTTP_X_FORWARDED_FOR']
                ?? '';
        if (is_string($real_ip) && $real_ip !== '') {
            $first_ip = trim(explode(',', $real_ip)[0]);
            if ($first_ip !== '' && $first_ip !== '127.0.0.1' && $first_ip !== '::1') {
                $is_local = false;
            }
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
