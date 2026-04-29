<?php
session_start();
include 'auth/config.php';

if (isset($_GET['id']) && isset($_SESSION['user_id'])) {
    $comment_id = (int)$_GET['id'];
    $user_id = (int)$_SESSION['user_id'];

    // Validasi: HANYA hapus jika id komentar milik user yang sedang login
    $stmt = $conn->prepare("DELETE FROM comments WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $comment_id, $user_id);
    
    if ($stmt->execute()) {
        // Kembali ke halaman sebelumnya
        header("Location: " . $_SERVER['HTTP_REFERER']);
    } else {
        echo "Gagal menghapus komentar.";
    }
} else {
    header("Location: index.php");
}