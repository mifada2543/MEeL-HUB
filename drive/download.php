<?php
require '../auth/auth.php';
require '../auth/config.php';
require '../helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $file = $storage->getFileForDownload(
        $_GET['file'] ?? null,
        $_GET['type'] ?? null,
        $_GET['scope'] ?? DriveStorage::SCOPE_PUBLIC
    );

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
    http_response_code(404);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
