<?php
function time_ago($timestamp)
{
    $time_diff = time() - strtotime($timestamp);
    if ($time_diff < 1) return 'Baru saja';
    $condition = [31104000 => 'tahun', 2592000 => 'bulan', 86400 => 'hari', 3600 => 'jam', 60 => 'menit', 1 => 'detik'];
    foreach ($condition as $secs => $str) {
        $d = $time_diff / $secs;
        if ($d >= 1) return round($d) . ' ' . $str . ' yang lalu';
    }
}
function format_bytes($bytes, $precision = 2)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}
// Tentukan path salah satu folder utama di HDD
$hdd_check_path = '/media/muhammaddaffa/MEeL/media';

// Cek apakah folder tersebut bisa diakses
if (!is_dir($hdd_check_path)) {
    // Jika HDD tidak terdeteksi dan user bukan di halaman error itu sendiri
    if (basename($_SERVER['PHP_SELF']) !== 'maintance.php') {
        header("Location: ../err/maintance.php");
        exit();
    }
}
function get_user_usage($username)
{
    $path = __DIR__ . "/data_drive/private_admins/" . $username;
    if (!is_dir($path)) return 0;

    $size = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
        if ($file->isFile()) {
            $size += $file->getSize();
        }
    }
    return $size;
}
