<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

// CSRF Token Validation
if (!verify_csrf()) {
    http_response_code(403);
    echo htmlspecialchars('CSRF token tidak valid.', ENT_QUOTES, 'UTF-8');
    exit();
}

// Rate Limiting
require_once '../modules/System.php';
$sys = new System($conn);
$user_id = $_SESSION['user_id'];

$limit = $sys->checkRateLimit($user_id, 'drive_delete', 'user');
if (!$limit['allowed']) {
    http_response_code(429);
    echo htmlspecialchars('Terlalu banyak penghapusan. Coba lagi dalam ' . $limit['minutes'] . ' menit.', ENT_QUOTES, 'UTF-8');
    exit();
}

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $filename = $_POST['file'] ?? null;
    $type = $_POST['type'] ?? null;
    $scope = $_POST['scope'] ?? DriveStorage::SCOPE_PUBLIC;

    $storage->delete($filename, $type, $scope);

    // Audit Logging
    log_drive_operation(
        $user_id,
        $user->username,
        'delete',
        $filename ?? 'unknown',
        $type ?? 'unknown',
        $scope,
        'success'
    );

    $normalizedScope = $storage->normalizeScope($scope);
    header('Location: index.php?scope=' . urlencode($normalizedScope) . '&status=deleted');
    exit();
} catch (RuntimeException $exception) {
    log_drive_operation(
        $user_id,
        $user->username,
        'delete',
        $_POST['file'] ?? 'unknown',
        $_POST['type'] ?? 'unknown',
        $_POST['scope'] ?? 'unknown',
        'failed: ' . $exception->getMessage()
    );

    http_response_code(400);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}

