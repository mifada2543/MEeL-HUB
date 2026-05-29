<?php
session_name("meel");
session_start();
include '../auth/config.php';

if (!isset($_SESSION['user_id'])) {
    die("Silakan login terlebih dahulu.");
}

$user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? '';

// --- 1. MEMBUAT PLAYLIST BARU ---
if ($action === 'create_playlist') {
    $name = trim($_POST['playlist_name']);
    $music_id = (int)$_POST['music_id']; // ID lagu yang sedang dibuka

    if (!empty($name)) {
        $stmt = $conn->prepare("INSERT INTO playlists (user_id, name) VALUES (?, ?)");
        $stmt->bind_param("is", $user_id, $name);
        $stmt->execute();
        $new_playlist_id = $stmt->insert_id;
        $stmt->close();

        // Otomatis masukkan lagu ke playlist baru tersebut
        if ($music_id > 0) {
            $stmt2 = $conn->prepare("INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)");
            $stmt2->bind_param("ii", $new_playlist_id, $music_id);
            $stmt2->execute();
            $stmt2->close();
        }
    }
    // Karena kita pakai form biasa, kembalikan ke halaman watch
    header("Location: watch.php?id=$music_id&msg=playlist_created");
    exit;
}

// --- 2. MENAMBAH LAGU KE PLAYLIST YANG ADA ---
if ($action === 'add_to_playlist') {
    $playlist_id = (int)$_POST['playlist_id'];
    $music_id = (int)$_POST['music_id'];

    // Cek apakah lagu sudah ada di playlist tersebut
    $cek = $conn->query("SELECT id FROM playlist_tracks WHERE playlist_id = $playlist_id AND music_id = $music_id");

    if ($cek->num_rows == 0) {
        $stmt = $conn->prepare("INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $playlist_id, $music_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: watch.php?id=$music_id&msg=added_to_playlist");
    exit;
}
// --- 3. HAPUS LAGU DARI PLAYLIST ---
if ($action === 'remove_from_playlist') {
    $pivot_id = (int)$_POST['pivot_id'];
    $playlist_id = (int)$_POST['playlist_id'];

    $stmt = $conn->prepare("DELETE FROM playlist_tracks WHERE id = ?");
    $stmt->bind_param("i", $pivot_id);
    $stmt->execute();
    $stmt->close();

    header("Location: view_playlist.php?id=$playlist_id");
    exit;
}
// --- 4. HAPUS TOTAL PLAYLIST ---
if ($action === 'delete_playlist') {
    $playlist_id = (int)$_POST['playlist_id'];
    
    // Pastikan user hanya bisa menghapus playlist miliknya sendiri
    $stmt = $conn->prepare("DELETE FROM playlists WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $playlist_id, $user_id);
    $stmt->execute();
    $stmt->close();

    header("Location: index.php?msg=playlist_deleted");
    exit;
}
