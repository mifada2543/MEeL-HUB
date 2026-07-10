<?php
session_name('meel');
session_start();
include '../auth/config.php';

if (!isset($_SESSION['user_id'])) die('Silakan login terlebih dahulu.');

// 🔒 FIX CSRF: Verifikasi token untuk semua aksi playlist
if (!verify_csrf()) {
    die('CSRF Token tidak valid.');
}

$user_id = (int) $_SESSION['user_id'];
$action  = $_POST['action'] ?? '';

function redirect(string $url): never
{
    header("Location: $url");
    exit;
}

// ── 1. BUAT PLAYLIST BARU ─────────────────────────────────────────────────
if ($action === 'create_playlist') {
    $name     = trim($_POST['playlist_name'] ?? '');
    $music_id = (int) ($_POST['music_id'] ?? 0);

    if ($name !== '') {
        $stmt = $conn->prepare('INSERT INTO playlists (user_id, name) VALUES (?, ?)');
        $stmt->bind_param('is', $user_id, $name);
        $stmt->execute();
        $new_playlist_id = $stmt->insert_id;
        $stmt->close();

        if ($music_id > 0) {
            $stmt2 = $conn->prepare('INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)');
            $stmt2->bind_param('ii', $new_playlist_id, $music_id);
            $stmt2->execute();
            $stmt2->close();
        }
    }
    redirect("watch.php?id=$music_id&msg=playlist_created");
}

// ── 2. TAMBAH LAGU KE PLAYLIST ────────────────────────────────────────────
if ($action === 'add_to_playlist') {
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);
    $music_id    = (int) ($_POST['music_id']    ?? 0);

    $check = $conn->prepare('SELECT id FROM playlist_tracks WHERE playlist_id = ? AND music_id = ?');
    $check->bind_param('ii', $playlist_id, $music_id);
    $check->execute();
    $exists = (int) $check->get_result()->num_rows;
    $check->close();

    if (!$exists) {
        $stmt = $conn->prepare('INSERT INTO playlist_tracks (playlist_id, music_id) VALUES (?, ?)');
        $stmt->bind_param('ii', $playlist_id, $music_id);
        $stmt->execute();
        $stmt->close();
    }
    redirect("watch.php?id=$music_id&msg=added_to_playlist");
}

// ── 3. HAPUS LAGU DARI PLAYLIST ───────────────────────────────────────────
if ($action === 'remove_from_playlist') {
    $pivot_id    = (int) ($_POST['pivot_id']    ?? 0);
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    $stmt = $conn->prepare('DELETE FROM playlist_tracks WHERE id = ?');
    $stmt->bind_param('i', $pivot_id);
    $stmt->execute();
    $stmt->close();
    redirect("view_playlist.php?id=$playlist_id");
}

// ── 4. HAPUS TOTAL PLAYLIST ───────────────────────────────────────────────
if ($action === 'delete_playlist') {
    $playlist_id = (int) ($_POST['playlist_id'] ?? 0);

    $stmt = $conn->prepare('DELETE FROM playlists WHERE id = ? AND user_id = ?');
    $stmt->bind_param('ii', $playlist_id, $user_id);
    $stmt->execute();
    $stmt->close();
    redirect('index.php?msg=playlist_deleted');
}
