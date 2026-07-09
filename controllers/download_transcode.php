<?php
/**
 * Download proxy untuk file hasil transcode.
 * File disimpan di RAM disk (/dev/shm/meel/transcode/), tidak bisa diakses
 * langsung via web server. Controller ini membaca dan mengirimkannya ke browser.
 *
 * Tidak menggunakan auth/auth.php untuk menghindari redirect (302) yang
 * akan memecah download browser.
 */

require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../modules/Transcoder.php';

// Session check manual (tanpa redirect)
if (session_status() === PHP_SESSION_NONE) {
    session_name('meel');
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$filename = $_GET['file'] ?? '';
if (empty($filename) || preg_match('/[\/:*?"<>|\\\\]/', $filename) || strpos($filename, '..') !== false) {
    http_response_code(400);
    die('Invalid file name.');
}

$transcoder = new Transcoder($conn, $_SESSION['user_id']);
$file_path = $transcoder->getTranscodeFilePath($filename);

if ($file_path === null) {
    http_response_code(404);
    die('File not found or expired.');
}

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime_types = [
    'mp3'  => 'audio/mpeg',
    'ogg'  => 'audio/ogg',
    'm4a'  => 'audio/mp4',
    'opus' => 'audio/ogg',
];
$mime = $mime_types[$ext] ?? 'application/octet-stream';

$size = filesize($file_path);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
header('X-Accel-Buffering: no');
header('Cache-Control: no-cache, must-revalidate');

// Bersihkan output buffer
while (ob_get_level()) ob_end_clean();

readfile($file_path);
exit;
