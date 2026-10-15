<?php
if (!defined('MEEL_MANAGE_ACCESS')) {
    header('HTTP/1.0 403 Forbidden');
    exit('Direct access not allowed.');
}
require_once __DIR__ . '/../../modules/core/helpers/pending_delete.php';
function handleDeleteVideo(int $id, int $user_id, mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT filename, thumbnail, user_id FROM video WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $video = $stmt->get_result()->fetch_assoc();

    if (!$video) {
        return ['success' => false, 'message' => 'Video tidak ditemukan.'];
    }

    if ((int)$video['user_id'] !== $user_id) {
        return ['success' => false, 'message' => 'Anda tidak memiliki akses ke video ini.'];
    }

    $pending = [
        'timestamp' => time(),
        'files' => []
    ];

    $video_base = meel_media_base_path('video') . '/video/';
    $video_file = $video['filename'];
    $video_path = $video_base . $video_file;
    if (file_exists($video_path)) {
        $pending['files'][] = $video_path;
    }
    $hls_dir = $video_base . pathinfo($video_file, PATHINFO_FILENAME);
    if (is_dir($hls_dir)) {
        $pending['files'][] = $hls_dir;
    }
    if (!empty($video['thumbnail']) && !meel_is_default_thumbnail($video['thumbnail'])) {
        $thumb_name = basename($video['thumbnail']);
        $thumb_path = meel_media_base_path('video') . '/thumbnail/' . $thumb_name;
        if (file_exists($thumb_path)) {
            $pending['files'][] = $thumb_path;
        }
        $sm_name = video_thumbnail_sm_name($thumb_name);
        if ($sm_name !== '' && !meel_is_default_thumbnail($sm_name)) {
            $sm_path = meel_media_base_path('video') . '/thumbnail/' . $sm_name;
            if (file_exists($sm_path)) {
                $pending['files'][] = $sm_path;
            }
        }
    }
    $stmt_del = $conn->prepare("DELETE FROM video WHERE id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $id, $user_id);
    if (!$stmt_del->execute()) {
        return ['success' => false, 'message' => 'Gagal menghapus dari database.'];
    }
    meel_invalidate_media_stats_cache('video');

    if (!empty($pending['files'])) {
        savePendingDeletions($pending);
    }
    include_once __DIR__ . '/../../modules/core/activity_logger.php';
    logActivity($conn, $user_id, 'delete', 'video', $id);
    return ['success' => true, 'message' => 'Video dihapus. File akan dibersihkan otomatis.'];
}
function handleDeleteMusic(int $id, int $user_id, mysqli $conn): array
{
    $stmt = $conn->prepare("SELECT filename, thumbnail, user_id FROM music WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $music = $stmt->get_result()->fetch_assoc();
    if (!$music) {
        return ['success' => false, 'message' => 'Musik tidak ditemukan.'];
    }
    if ((int)$music['user_id'] !== $user_id) {
        return ['success' => false, 'message' => 'Anda tidak memiliki akses ke musik ini.'];
    }
    $pending = [
        'timestamp' => time(),
        'files' => []
    ];
    $audio_path = meel_media_base_path('music') . '/file/' . $music['filename'];
    if (file_exists($audio_path)) {
        $pending['files'][] = $audio_path;
    }
    if (!empty($music['thumbnail']) && !meel_is_default_thumbnail($music['thumbnail'])) {
        $thumb_path = meel_media_base_path('music') . '/thumbnail/' . basename($music['thumbnail']);
        if (file_exists($thumb_path)) {
            $pending['files'][] = $thumb_path;
        }
    }
    $stmt_del = $conn->prepare("DELETE FROM music WHERE id = ? AND user_id = ?");
    $stmt_del->bind_param("ii", $id, $user_id);
    if (!$stmt_del->execute()) {
        return ['success' => false, 'message' => 'Gagal menghapus dari database.'];
    }
    meel_invalidate_media_stats_cache('music');
    if (!empty($pending['files'])) {
        savePendingDeletions($pending);
    }
    include_once __DIR__ . '/../../modules/core/activity_logger.php';
    logActivity($conn, $user_id, 'delete', 'music', $id);

    return ['success' => true, 'message' => 'Musik dihapus. File akan dibersihkan otomatis.'];
}
function logActivity(mysqli $conn, int $user_id, string $action, string $media_type, int $media_id): void
{
    $ip = (function_exists('get_real_ip') ? get_real_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, media_type, media_id, ip_address) VALUES (?, ?, ?, ?, ?)");
    if ($stmt === false) {
        error_log('[MEeL] logActivity gagal: ' . $conn->error);
        return;
    }
    $stmt->bind_param("issis", $user_id, $action, $media_type, $media_id, $ip);
    $stmt->execute();
    $stmt->close();
}
/* reference build: MEeL-C5H5N5O [8738a8d3222d5ed7] */
