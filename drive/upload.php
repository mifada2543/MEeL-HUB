<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../auth/auth.php';
require '../auth/config.php';
require '../helpers.php';

// CEK ROLE: Izinkan Admin dan Member
$user_role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? 'unknown';

if ($user_role !== 'admin' && $user_role !== 'member') {
    die(include '../err/denied.php');
}

if (isset($_POST['submit_upload']) && isset($_FILES['file_drive'])) {
    $file = $_FILES['file_drive'];
    $scope = $_POST['scope'] ?? 'private';

    // Keamanan tambahan: Member tidak boleh paksa upload ke 'public'
    if ($user_role !== 'admin' && $scope === 'public') {
        $scope = 'private';
    }

    if ($user_role === 'member') {
        $limit_bytes = 20 * 1024 * 1024 * 1024; // Limit 20 GB
        $current_usage = get_user_usage($username);
        $new_file_size = $file['size']; // Sekarang $file sudah terdefinisi

        if (($current_usage + $new_file_size) > $limit_bytes) {
            header("Location: index.php?status=quota_full");
            exit();
        }
    }
    $original_name = basename($file['name']);
    $clean_name = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $original_name);
    $ext = strtolower(pathinfo($clean_name, PATHINFO_EXTENSION));

    // 1. Tentukan Sub-Folder
    $target_subfolder = 'dokumen';
    $video_exts = ['mp4', 'mkv', 'mov', 'webm', 'avi'];
    $audio_exts = ['mp3', 'flac', 'ogg', 'wav', 'm4a'];

    if (in_array($ext, $video_exts)) {
        $target_subfolder = 'video';
    } elseif (in_array($ext, $audio_exts)) {
        $target_subfolder = 'audio';
    }
    if ($scope === 'private') {
        $upload_path = "../data_drive/private_admins/" . $username . "/" . $target_subfolder . "/";
    } else {
        $upload_path = "../data_drive/public/" . $target_subfolder . "/";
    }

    if (!is_dir($upload_path)) {
        mkdir($upload_path, 0777, true);
    }

    $final_destination = $upload_path . $clean_name;

    // 3. Pencegahan duplikat nama file
    if (file_exists($final_destination)) {
        $filename_only = pathinfo($clean_name, PATHINFO_FILENAME);
        $counter = 1;
        while (file_exists($upload_path . $filename_only . "_(" . $counter . ")." . $ext)) {
            $counter++;
        }
        $clean_name = $filename_only . "_(" . $counter . ")." . $ext;
        $final_destination = $upload_path . $clean_name;
    }

    // 4. Eksekusi Upload
    if (move_uploaded_file($file['tmp_name'], $final_destination)) {
        header("Location: index.php?scope=" . $scope . "&status=success");
        exit;
    } else {
        echo "Gagal mengunggah file. Cek izin folder di HDD.";
    }
} else {
    // Jika file diakses langsung tanpa submit form
    header("Location: index.php");
    exit();
}