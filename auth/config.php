<?php
$meel_settings = __DIR__ . '/settings.php';
if (!file_exists($meel_settings)) {
    error_log("[MEeL SYSTEM ERROR]\nFile auth/settings.php tidak ditemukan.\n"
        . "Copy dari settings.example.php lalu isi kredensial:\n"
        . "  cp auth/settings.example.php auth/settings.php\n");

    exit(1);
}
require_once $meel_settings;
require_once __DIR__ . '/../modules/core/bootstrap.php';
if (!isset($server))   $server   = 'localhost';
if (!isset($username)) $username = 'root';
if (!isset($password)) $password = '';
if (!isset($db))       $db       = 'MEeL';
$meel_env = static function (string $name): ?string {
    $value = getenv($name);
    return $value === false ? null : $value;
};
$server   = $meel_env('MEEL_DB_HOST') ?? $server;
$username = $meel_env('MEEL_DB_USER') ?? $username;
$password = $meel_env('MEEL_DB_PASS') ?? $password;
$db       = $meel_env('MEEL_DB_NAME') ?? $db;
unset($meel_env);
if (!function_exists('meel_database_unavailable')) {
    function meel_database_unavailable(): void
    {
        if (!headers_sent()) {
            http_response_code(503);
            header('Retry-After: 30');
            header('Cache-Control: no-store, max-age=0');
        }
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $xhr    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $wants_json = defined('MEEL_API_CONTEXT')
            || stripos((string) $accept, 'application/json') !== false
            || strcasecmp((string) $xhr, 'XMLHttpRequest') === 0;
        if ($wants_json) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'      => false,
                'error'   => 'database_unavailable',
                'message' => 'Layanan database sedang tidak tersedia. Coba lagi beberapa saat lagi.',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
        $code = 'db_unavailable';
        require __DIR__ . '/../err/index.php';
    }
}
if (!isset($conn) || $conn === null) {
    $meel_db_error = 'Unknown error';
    try {
        $conn = @new mysqli($server, $username, $password, $db);
        if ($conn->connect_errno) {
            throw new \RuntimeException(
                (string) ($conn->connect_error ?: 'Unknown error'),
                (int) $conn->connect_errno
            );
        }
    } catch (\Throwable $e) {
        $meel_db_error = $e->getMessage();
        $conn = null;
    }
    if (!$conn instanceof \mysqli) {
        error_log("[MEeL SYSTEM ERROR]\nKoneksi ke database gagal: " . $meel_db_error . "\n");
        if (PHP_SAPI === 'cli') {
            exit(1);
        }
        meel_database_unavailable();
        exit(1);
    }
    unset($meel_db_error);
    $GLOBALS['conn'] = $conn;
    $conn->set_charset('utf8mb4');
    $tzOffset = date('P');
    if ($tzOffset !== '') {
        try {
            $conn->query("SET time_zone = '" . $conn->real_escape_string($tzOffset) . "'");
        } catch (\Throwable $e) {
            error_log('[MEeL] gagal menyamakan time_zone MySQL: ' . $e->getMessage());
        }
    }
}
if (!defined('MEEL_BASE_URL')) {
    require_once __DIR__ . '/../modules/core/base_url.php';
    define('MEEL_BASE_URL', meel_base_url_path());
}
require_once __DIR__ . '/../modules/auth/helpers/session.php';
meel_boot_session();
require_once __DIR__ . '/../modules/autoload.php';
require_once __DIR__ . '/../modules/core/helpers.php';
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header("Strict-Transport-Security: max-age=15552000; includeSubDomains");
    }
    $csp_script_src = "script-src 'self' 'unsafe-inline'";
    if (defined('MEEL_ENV') && MEEL_ENV === 'development') {
        $csp_script_src .= " 'unsafe-eval'";
    }
    $csp_worker_src = "worker-src 'self' blob:";
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'self'; frame-src 'self' blob:; frame-ancestors 'self'; form-action 'self'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; style-src 'self' 'unsafe-inline'; {$csp_script_src}; {$csp_worker_src}");
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!defined('MEEL_API_CONTEXT')) {
    if (isset($_SESSION['LAST_ACTIVITY'])) {
        $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
        if ($elapsed_time > 43200) {
            session_unset();
            session_destroy();
            header("Location: " . base_url('/auth/login?reason=expired'));
            exit;
        }
    }
    $_SESSION['LAST_ACTIVITY'] = time();
    if (PHP_SAPI !== 'cli' && isset($conn) && $conn instanceof \mysqli) {
        require_once __DIR__ . '/../modules/core/activity_logger.php';
        ActivityLogger::onRequest($conn);
    }
}

/* reference build: MEeL-C4H9NO2 [c2f22a62f9866f0a] */