<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../modules/helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $storage->delete(
        $_GET['file'] ?? null,
        $_GET['type'] ?? null,
        $_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC
    );

    $scope = $storage->normalizeScope($_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC);
    header('Location: index.php?scope=' . urlencode($scope) . '&status=deleted');
    exit();
} catch (RuntimeException $exception) {
    http_response_code(400);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
