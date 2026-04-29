<?php
require '../auth/auth.php';
require '../auth/config.php';

// CEK ROLE: Hanya admin dan member yang diizinkan mengunduh
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'member') {
    die(include '../err/denied.php');
}

if (isset($_GET['file']) && isset($_GET['type'])) {
    // Gunakan basename() sangat penting agar orang tidak bisa mengetik "../../etc/passwd"
    $filename = basename($_GET['file']); 
    $type = basename($_GET['type']); // video, audio, dokumen
    $scope = $_GET['scope'] ?? 'public';

    // 1. Tentukan Path Fisik berdasarkan Scope
    if ($scope === 'private') {
        // Hanya Admin yang boleh akses scope private
        if ($_SESSION['role'] !== 'admin') {
            die(include '../err/denied.php');
        }
        $owner = $_SESSION['username'];
        $filepath = "../data_drive/private_admins/" . $owner . "/" . $type . "/" . $filename;
    } else {
        $filepath = "../data_drive/public/" . $type . "/" . $filename;
    }

    // 2. Eksekusi Download jika file ada
    if (file_exists($filepath)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));

        readfile($filepath);
        exit;
    } else {
        die("Error: File fisik tidak ditemukan di server/HDD.");
    }
} else {
    die("Error: Parameter tidak lengkap.");
}