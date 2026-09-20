<?php
error_reporting(0);

require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();

$referer = $_SERVER['HTTP_REFERER'] ?? '';
$currentHost = $_SERVER['HTTP_HOST'] ?? '';
$refererOk = false;

if ($referer !== '' && $currentHost !== '') {
    $refParts = parse_url($referer);
    if ($refParts && isset($refParts['host'])) {
        $currentHostNorm = strtolower(parse_url('http://' . $currentHost, PHP_URL_HOST) ?: $currentHost);
        if (strtolower($refParts['host']) === $currentHostNorm) {
            $refPath = $refParts['path'] ?? '';
            if (preg_match('#/(?:music|admin|profile)(?:/|$)#i', $refPath)) {
                $refererOk = true;
            }
        }
    }
}
if (!$refererOk) {
    header("Location: ../err/?code=denied");
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit("ID Media tidak valid.");
}

if (!is_stream_authorized($id)) {
    header("Location: ../err/?code=denied");
    exit;
}

include '../auth/config.php';
include '../modules/media/MediaViewer.php';

$viewer = new MediaViewer($conn, $_SESSION['user_id'], 'music', $id);
$v = $viewer->getMediaData();

if (!$v || empty($v['filename'])) {
    http_response_code(404);
    exit("Data audio tidak ditemukan.");
}

session_write_close();

meel_serve_media_file('music', 'file/' . basename($v['filename']));
