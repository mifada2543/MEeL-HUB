<?php
error_reporting(0);

if (is_file(__DIR__ . '/../auth/settings.php')) {
    require_once __DIR__ . '/../auth/settings.php';
}
require_once __DIR__ . '/../modules/core/helpers.php';

$f = isset($_GET['f']) ? (string) $_GET['f'] : '';
if ($f === '') {
    http_response_code(400);
    exit('Parameter f wajib diisi.');
}

$__rel = ltrim(str_replace('\\', '/', $f), '/');
if (str_starts_with($__rel, 'file/')) {
    meel_boot_session();

    $__base_name = basename($__rel);
    $__id = 0;
    if (isset($server, $db, $username, $password)) {
        $__conn = @new mysqli($server, $username, $password, $db);
        if (!$__conn->connect_error) {
            $__stmt = $__conn->prepare('SELECT id FROM music WHERE filename = ? LIMIT 1');
            if ($__stmt) {
                $__stmt->bind_param('s', $__base_name);
                $__stmt->execute();
                $__row = $__stmt->get_result()->fetch_assoc();
                if ($__row) {
                    $__id = (int) $__row['id'];
                }
                $__stmt->close();
            }
            $__conn->close();
        }
    }

    $__allowed = $__id > 0
        && function_exists('is_stream_authorized')
        && is_stream_authorized($__id);
    session_write_close();

    if (!$__allowed) {
        $__script = $_SERVER['SCRIPT_NAME'] ?? '';
        $__base = rtrim(dirname(dirname($__script)), '/');
        header('Location: ' . $__base . '/err/?code=denied');
        exit;
    }
}
unset($__rel, $__base_name, $__id, $__allowed, $__script, $__base, $__conn, $__stmt, $__row);

meel_serve_media_file('music', $f);

/* reference build: MEeL-C2H5NO2 [812a9caec4620104] */
