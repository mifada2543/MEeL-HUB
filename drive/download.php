<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

// CSRF Token Validation untuk GET parameter
if (!isset($_GET['csrf_token']) || !verify_csrf_token($_GET['csrf_token'])) {
    http_response_code(403);
    echo htmlspecialchars('CSRF token tidak valid.', ENT_QUOTES, 'UTF-8');
    exit();
}

// Rate Limiting
require_once '../modules/System.php';
$sys = new System($conn);
$user_id = $_SESSION['user_id'];

$limit = $sys->checkRateLimit($user_id, 'drive_download', 'user');
if (!$limit['allowed']) {
    http_response_code(429);
    echo htmlspecialchars('Terlalu banyak download. Coba lagi dalam ' . $limit['minutes'] . ' menit.', ENT_QUOTES, 'UTF-8');
    exit();
}

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $file = $storage->getFileForDownload(
        isset($_GET['file']) ? basename($_GET['file']) : null,
        isset($_GET['type']) ? basename($_GET['type']) : null,
        $_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC
    );

    // Audit Logging
    log_drive_operation(
        $user_id,
        $user->username,
        'download',
        $file['name'],
        $_GET['type'] ?? 'unknown',
        $_GET['scope'] ?? 'public',
        'success'
    );
    header('X-Content-Type-Options: nosniff');
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file['name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . (string) $file['size']);

    readfile($file['path']);
    exit();
} catch (RuntimeException $exception) {
    log_drive_operation(
        $user_id,
        $user->username,
        'download',
        $_GET['file'] ?? 'unknown',
        $_GET['type'] ?? 'unknown',
        $_GET['scope'] ?? 'unknown',
        'failed: ' . $exception->getMessage()
    );

    http_response_code(404);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
