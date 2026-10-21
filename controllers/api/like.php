<?php
define('MEEL_API_CONTEXT', true);
require_once __DIR__ . '/../../modules/core/helpers.php';
meel_boot_session();

include __DIR__ . '/../../auth/config.php';
include __DIR__ . '/../../modules/media/MediaInteraction.php';

if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    exit;
}

$rateKey = 'user_' . ($_SESSION['user_id'] ?? 0);
$rateRole = get_user_role($conn, (int)($_SESSION['user_id'] ?? 0));
$rateCheck = RateLimiter::check($rateKey, 'like', $rateRole);
if (!$rateCheck['allowed']) {
    http_response_code(429);
    $media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : 'video';
    header('HX-Retarget: #like-dislike-container');
    $like_dislike_media_id = (int) ($_POST['id'] ?? 0);
    $like_dislike_media_type = $media_type;
    $like_dislike_rate_limit = (int) $rateCheck['retry_after'];
    include __DIR__ . '/../../partials/like_dislike.php';
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : '';
$type = isset($_POST['type']) ? trim($_POST['type']) : '';

if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - POST: " . json_encode(['id' => $id, 'media_type' => $media_type, 'type' => $type])); }

$user_id = $_SESSION['user_id'] ?? null;

if ($user_id) {
    $stmt_user = $conn->prepare("SELECT is_active, role FROM users WHERE id = ? LIMIT 1");
    $stmt_user->bind_param("i", $user_id);
    $stmt_user->execute();
    $user = $stmt_user->get_result()->fetch_assoc();

    if (!$user || $user['is_active'] != 1 || $user['role'] === 'guest') {
        if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - BLOCKED: User ID $user_id is inactive or guest."); }
        http_response_code(403);
        exit;
    }
} else {
    http_response_code(401);
    exit;
}

$interaction = new MediaInteraction($conn, $user_id);
$result = $interaction->toggleLike($id, $media_type, $type);

if (!$result['success']) {
    if (defined('APP_DEBUG') && APP_DEBUG) { error_log("LIKE.PHP - ERROR: {$result['message']} (Code: {$result['http_code']})"); }
    http_response_code($result['http_code']);
    exit;
}

if ($type === 'like' && isset($result['data']['user_interaction']) && $result['data']['user_interaction'] === 'like') {
    require_once __DIR__ . '/../../modules/core/Notification.php';
    $table = ($media_type === 'music') ? 'music' : 'video';
    $owner_q = $conn->query("SELECT user_id, title FROM $table WHERE id = $id");
    if ($owner_q && $owner_q->num_rows > 0) {
        $owner = $owner_q->fetch_assoc();
        if ((int)$owner['user_id'] !== $user_id) {
            Notification::create($conn, (int)$owner['user_id'], 'like', 'Like Video',
                $_SESSION['username'] . ' menyukai video "' . $owner['title'] . '"',
                $id, $media_type, $user_id);
        }
    }
}

$user_interaction = $result['data']['user_interaction'];
$likes = $result['data']['likes'];
$dislikes = $result['data']['dislikes'];

$like_dislike_media_id = $id;
$like_dislike_media_type = $media_type;
$like_dislike_state = $user_interaction;
$like_dislike_likes = $likes;
$like_dislike_dislikes = $dislikes;
include __DIR__ . '/../../partials/like_dislike.php';
?>

<!-- reference build: MEeL-C3H7NO2S [26928cf156376bc8] -->
