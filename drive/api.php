<?php
/**
 * MEeL Drive API - RESTful endpoints untuk file operations
 */

require '../auth/auth.php';
require '../auth/config.php';
require 'DriveManager.php';

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$user_role = $_SESSION['role'] ?? null;
$username = $_SESSION['username'] ?? null;

if (!$user_id || !$user_role || ($user_role !== 'admin' && $user_role !== 'member')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$drive = new DriveManager($conn);
$action = $_GET['action'] ?? $_POST['action'] ?? null;

function handleList() {
    global $drive, $user_role, $username;
    $category = $_GET['category'] ?? 'dokumen';
    $scope = $_GET['scope'] ?? 'public';
    
    if ($user_role === 'member' && $scope === 'public') {
        http_response_code(403);
        return ['success' => false, 'message' => 'Member hanya bisa akses Private Cloud'];
    }
    
    $files = $drive->listFiles($category, $scope, $username);
    $stats = $drive->getStatistics($scope, $username);
    
    return ['success' => true, 'data' => $files, 'stats' => $stats];
}

function handleUpload() {
    global $drive, $user_role, $username;
    
    $scope = $user_role === 'member' ? 'private' : ($_POST['scope'] ?? 'public');
    
    if (!isset($_FILES['file_drive'])) {
        http_response_code(400);
        return ['success' => false, 'message' => 'No file provided'];
    }
    
    if ($user_role === 'member') {
        if (!$drive->hasQuotaAvailable($username, $_FILES['file_drive']['size'])) {
            http_response_code(413);
            return ['success' => false, 'message' => 'Storage quota exceeded'];
        }
    }
    
    $result = $drive->uploadFile($_FILES['file_drive'], null, $scope, $username);
    http_response_code($result['success'] ? 200 : 400);
    return $result;
}

function handleDelete() {
    global $drive, $user_role, $username;
    
    $filename = $_GET['file'] ?? null;
    $category = $_GET['category'] ?? 'dokumen';
    $scope = $_GET['scope'] ?? 'public';
    
    if (!$filename) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Filename not provided'];
    }
    
    if ($user_role === 'member' && $scope !== 'private') {
        http_response_code(403);
        return ['success' => false, 'message' => 'Forbidden'];
    }
    
    if ($scope === 'public' && $user_role !== 'admin') {
        http_response_code(403);
        return ['success' => false, 'message' => 'Forbidden'];
    }
    
    return $drive->deleteFile($filename, $category, $scope, $username);
}

function handleDownload() {
    global $drive, $user_role, $username;
    
    $filename = $_GET['file'] ?? null;
    $category = $_GET['category'] ?? 'dokumen';
    $scope = $_GET['scope'] ?? 'public';
    
    if (!$filename) {
        http_response_code(400);
        return ['success' => false, 'message' => 'Filename not provided'];
    }
    
    if ($user_role === 'member' && $scope === 'public') {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied'];
    }
    
    $result = $drive->downloadFile($filename, $category, $scope, $username);
    
    if (!$result['success']) {
        http_response_code(404);
        echo json_encode($result);
        exit;
    }
    
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $result['mime']);
    header('Content-Disposition: attachment; filename="' . $result['name'] . '"');
    header('Content-Length: ' . $result['size']);
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    readfile($result['path']);
    exit;
}

function handleGetStats() {
    global $drive, $user_role, $username;
    
    $scope = $_GET['scope'] ?? 'public';
    
    if ($user_role === 'member' && $scope === 'public') {
        http_response_code(403);
        return ['success' => false, 'message' => 'Access denied'];
    }
    
    $stats = $drive->getStatistics($scope, $user_role === 'member' ? $username : null);
    
    if ($user_role === 'member') {
        $usage = $drive->getUserUsage($username);
        $quota = 20 * 1024 * 1024 * 1024;
        $stats['quota'] = [
            'used' => $usage,
            'total' => $quota,
            'percentUsed' => round(($usage / $quota) * 100, 2)
        ];
    }
    
    return ['success' => true, 'data' => $stats];
}

$response = null;

switch ($action) {
    case 'list':
        $response = handleList();
        break;
    case 'upload':
        $response = ($_SERVER['REQUEST_METHOD'] === 'POST') ? handleUpload() : ['success' => false];
        break;
    case 'delete':
        $response = handleDelete();
        break;
    case 'download':
        handleDownload();
        break;
    case 'stats':
        $response = handleGetStats();
        break;
    default:
        http_response_code(400);
        $response = ['success' => false, 'message' => 'Invalid action'];
        break;
}

echo json_encode($response);
