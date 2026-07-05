<?php
// Matikan penampilan error agar output binary audio tidak rusak jika ada notice
error_reporting(0);
ini_set('display_errors', 0);

session_name('meel');
session_start();
if (!isset($_SERVER['HTTP_REFERER']) || strpos($_SERVER['HTTP_REFERER'], 'watch.php') === false) {
    header("Location: ../err/denied.php");
    exit;
}
include '../auth/config.php';
require_once '../modules/helpers.php';
include '../modules/MediaViewer.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    header("HTTP/1.1 400 Bad Request");
    exit("ID Media tidak valid.");
}

// 2. Ambil data nama berkas asli dari database lewat MediaViewer
$viewer = new MediaViewer($conn, $_SESSION['user_id'], 'music', $id);
$v = $viewer->getMediaData();

if (!$v || empty($v['filename'])) {
    header("HTTP/1.1 404 Not Found");
    exit("Data audio tidak ditemukan.");
}

$filePath = __DIR__ . "/upload/file/" . $v['filename'];

if (!file_exists($filePath)) {
    header("HTTP/1.1 404 Not Found");
    exit("File fisik tidak tersedia di server.");
}

// 3. Tentukan MIME Type yang sesuai secara dinamis
$ext = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
$mimeType = match ($ext) {
    'mp3'  => 'audio/mpeg',
    'm4a'  => 'audio/mp4',
    'ogg', 'opus' => 'audio/ogg',
    'flac' => 'audio/flac',
    'wav'  => 'audio/wav',
    default => 'audio/ogg'
};

// 4. Proses Streaming dengan dukungan HTTP Byte-Range (Sangat Krusial untuk Player Web)
$size = filesize($filePath);
$length = $size;
$start = 0;
$end = $size - 1;

header("Content-Type: " . $mimeType);
header("Accept-Ranges: bytes");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

if (isset($_SERVER['HTTP_RANGE'])) {
    $c_start = $start;
    $c_end = $end;

    list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
    if (strpos($range, ',') !== false) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    if ($range == '-') {
        $c_start = $size - substr($range, 1);
    } else {
        $range = explode('-', $range);
        $c_start = $range[0];
        $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $size - 1;
    }
    $c_end = ($c_end > $end) ? $end : $c_end;
    if ($c_start > $c_end || $c_start > $size - 1 || $c_end >= $size) {
        header('HTTP/1.1 416 Requested Range Not Satisfiable');
        header("Content-Range: bytes $start-$end/$size");
        exit;
    }
    $start = $c_start;
    $end = $c_end;
    $length = $end - $start + 1;
    header('HTTP/1.1 206 Partial Content');
    header("Content-Range: bytes $start-$end/$size");
}

header("Content-Length: " . $length);

// 5. Salurkan data berkas dalam bentuk chunks (hemat RAM server)
$fp = fopen($filePath, 'rb');
fseek($fp, $start);
while (!feof($fp) && ($p = ftell($fp)) <= $end) {
    if ($p + 8192 > $end) {
        echo fread($fp, $end - $p + 1);
    } else {
        echo fread($fp, 8192);
    }
    flush();
}
fclose($fp);
exit;
