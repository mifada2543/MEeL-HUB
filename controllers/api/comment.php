<?php
define('MEEL_API_CONTEXT', true);
require_once '../../modules/core/helpers.php';

meel_boot_session();

include '../../auth/config.php';
require_once __DIR__ . '/../../modules/media/MediaViewer.php';
require_once __DIR__ . '/../../modules/core/CommentRenderer.php';

if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">CSRF Token tidak valid. Muat ulang halaman.</div>';
    exit;
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    exit;
}

$media_type = (($_POST['media_type'] ?? 'video') === 'music') ? 'music' : 'video';
$media_id   = (int)($_POST['id'] ?? 0);
if ($media_id <= 0) {
    http_response_code(400);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Media tidak valid.</div>';
    exit;
}

$rateKey   = 'user_' . $user_id;
$rateRole  = get_user_role($conn, $user_id);
$rateCheck = RateLimiter::check($rateKey, 'comment', $rateRole);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-yellow-500/30 bg-yellow-500/10 text-yellow-500">⏱️ Terlalu banyak komentar. Coba lagi dalam ' . (int)$rateCheck['retry_after'] . ' detik.</div>';
    exit;
}

$viewer = new MediaViewer($conn, $user_id, $media_type, $media_id);
if (!$viewer->addComment($_POST)) {
    http_response_code(400);
    header('HX-Retarget: #comment-alert');
    header('HX-Reswap: innerHTML');
    echo '<div class="p-3 rounded-xl text-[10px] font-bold uppercase tracking-wider border border-red-500/30 bg-red-500/10 text-red-400">Gagal mengirim komentar.</div>';
    exit;
}

$parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
if ($parent_id > 0) {
    require_once __DIR__ . '/../../modules/core/Notification.php';
    $parent_q = $conn->query("SELECT user_id, video_id, music_id FROM comments WHERE id = $parent_id");
    if ($parent_q && $parent_q->num_rows > 0) {
        $parent = $parent_q->fetch_assoc();
        if ((int)$parent['user_id'] !== $user_id) {
            $snippet = substr(trim($_POST['comments'] ?? ''), 0, 50);
            $media_type = !empty($parent['video_id']) ? 'video' : 'music';
            $media_id = !empty($parent['video_id']) ? $parent['video_id'] : $parent['music_id'];
            Notification::create($conn, (int)$parent['user_id'], 'reply', 'Balasan Komentar',
                $_SESSION['username'] . ' membalas komentar kamu: "' . $snippet . '..."',
                $parent_id, $media_type . ':' . $media_id, $user_id);
        }
    }
}

$comments_data = $viewer->getComments();
$grouped       = $comments_data['grouped'];
$user_map      = $comments_data['user_map'];

$playlist_context = (int)($_POST['playlist_id'] ?? 0);

$media_row = $viewer->getMediaData();
$GLOBALS['uploader_id'] = (int)($media_row['user_id'] ?? 0);

$GLOBALS['id']        = $media_id;
$GLOBALS['user_map']  = $user_map;

if (empty($grouped)) {
    render_comment_empty_state($media_type);
} else {
    render_comments(0, $grouped, 0, $media_type, $playlist_context);
}
