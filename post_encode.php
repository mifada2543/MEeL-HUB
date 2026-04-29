<?php
require_once 'auth/auth.php';
require_once 'auth/config.php';
require_once 'auth/Transcoder.php';
include 'helpers.php';

$temp_file = $_GET['temp_file'] ?? '';
$title     = $_GET['title']     ?? 'Unknown';
$artist    = $_GET['artist']    ?? 'Unknown';
$album     = $_GET['album']     ?? 'Single';
$duration  = (int)($_GET['duration'] ?? 0);

if (empty($temp_file)) {
    die("<h1>Error: Parameter temp_file tidak ditemukan.</h1>");
}

$transcoder = new Transcoder($conn, $_SESSION['user_id']);
$result = $transcoder->encodeMusic($temp_file, $title, $artist, $album, $duration);

if ($result['status'] === 'success') {
    header("Location: upload_advanced.php?success=1&file=" . urlencode($result['filename']));
    exit;
} else {
    echo "<h1>FFmpeg Gagal Menghasilkan Ogg!</h1>";
    echo "<pre>" . htmlspecialchars($result['msg']) . "</pre>";
}