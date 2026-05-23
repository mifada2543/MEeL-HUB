<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require '../auth/auth.php';
require '../auth/config.php';
require '../helpers.php';
require __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['submit_upload'], $_FILES['file_drive'])) {
    header('Location: index.php');
    exit();
}

$storage = new DriveStorage(dirname(__DIR__) . '/data_drive', $user);

try {
    $storage->enforceQuota($_FILES['file_drive'], 20 * 1024 * 1024 * 1024);
    $result = $storage->upload($_FILES['file_drive'], $_POST['scope'] ?? DriveStorage::SCOPE_PRIVATE);

    header('Location: index.php?scope=' . urlencode($result['scope']) . '&status=success');
    exit();
} catch (RuntimeException $exception) {
    if ($exception->getMessage() === 'quota_full') {
        header('Location: index.php?status=quota_full');
        exit();
    }

    http_response_code(400);
    echo htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
}
