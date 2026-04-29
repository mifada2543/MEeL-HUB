<?php
require '../auth/auth.php';
require '../auth/config.php';

// 1. Validasi Sesi dan Role
$user_role = $_SESSION['role'] ?? null;
$username_session = $_SESSION['username'] ?? null;

if (!$user_role || ($user_role !== 'admin' && $user_role !== 'member')) {
    die(include '../err/denied.php');
}

// 2. Ambil parameter dari URL
$file_name = $_GET['file'] ?? null;
$type      = $_GET['type'] ?? null;   // video, audio, atau dokumen
$scope     = $_GET['scope'] ?? 'public';

if (!$file_name || !$type) {
    die("Parameter tidak lengkap.");
}

// 3. Tentukan Path File
// Membersihkan nama file dari upaya directory traversal (../)
$safe_file_name = basename($file_name);

if ($scope === 'private') {
    // Jika member, mereka HANYA boleh menghapus di folder username mereka sendiri
    $target_user = ($user_role === 'admin') ? ($_GET['user'] ?? $username_session) : $username_session;
    $file_path = "../data_drive/private_admins/" . $target_user . "/" . $type . "/" . $safe_file_name;
} else {
    // Jika scope public, hanya Admin yang boleh menghapus
    if ($user_role !== 'admin') {
        die("Hanya Admin yang dapat menghapus file di Public Space.");
    }
    $file_path = "../data_drive/public/" . $type . "/" . $safe_file_name;
}

// 4. Eksekusi Penghapusan
if (file_exists($file_path)) {
    if (unlink($file_path)) {
        // Berhasil hapus, kembali ke halaman utama
        header("Location: index.php?scope=" . $scope . "&status=deleted");
        exit;
    } else {
        echo "Gagal menghapus file. Periksa izin folder.";
    }
} else {
    echo "File tidak ditemukan di: " . htmlspecialchars($file_path);
}