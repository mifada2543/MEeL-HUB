<?php
session_start();
include 'auth/config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit;
}

$user_id    = (int) $_SESSION['user_id'];
$id         = isset($_POST['id'])         ? intval($_POST['id'])       : 0;
$media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : '';
$type       = isset($_POST['type'])       ? trim($_POST['type'])       : '';

if ($id <= 0 || !in_array($media_type, ['music', 'video'], true) || !in_array($type, ['like', 'dislike'], true)) {
    http_response_code(400);
    exit;
}

$col   = ($media_type === 'music') ? 'music_id' : 'video_id';
$table = ($media_type === 'music') ? 'music'    : 'video';

// 1. Cek interaksi sebelumnya
$check = $conn->prepare("SELECT `TYPE` FROM interactions WHERE user_id = ? AND $col = ?");
$check->bind_param("ii", $user_id, $id);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

// 2. INSERT / UPDATE / DELETE
if ($existing) {
    if ($existing['TYPE'] === $type) {
        $op = $conn->prepare("DELETE FROM interactions WHERE user_id = ? AND $col = ?");
        $op->bind_param("ii", $user_id, $id);
    } else {
        $op = $conn->prepare("UPDATE interactions SET `TYPE` = ? WHERE user_id = ? AND $col = ?");
        $op->bind_param("sii", $type, $user_id, $id);
    }
} else {
    $op = $conn->prepare("INSERT INTO interactions (user_id, $col, `TYPE`) VALUES (?, ?, ?)");
    $op->bind_param("iis", $user_id, $id, $type);
}
$op->execute();
$op->close();

// 3. Sinkronisasi likes/dislikes
$sync = $conn->prepare(
    "UPDATE $table t SET
        likes    = (SELECT COUNT(*) FROM interactions WHERE $col = t.id AND `TYPE` = 'like'),
        dislikes = (SELECT COUNT(*) FROM interactions WHERE $col = t.id AND `TYPE` = 'dislike')
     WHERE t.id = ?"
);
$sync->bind_param("i", $id);
$sync->execute();
$sync->close();

// 4. Ambil jumlah terbaru
$res_stmt = $conn->prepare("SELECT likes, dislikes FROM $table WHERE id = ?");
$res_stmt->bind_param("i", $id);
$res_stmt->execute();
$counts = $res_stmt->get_result()->fetch_assoc();
$res_stmt->close();

// 5. Ambil status interaksi user sekarang
$cur_stmt = $conn->prepare("SELECT `TYPE` FROM interactions WHERE user_id = ? AND $col = ?");
$cur_stmt->bind_param("ii", $user_id, $id);
$cur_stmt->execute();
$cur = $cur_stmt->get_result()->fetch_assoc();
$cur_stmt->close();

$user_interaction = $cur['TYPE'] ?? null;
$likes            = (int) ($counts['likes']    ?? 0);
$dislikes         = (int) ($counts['dislikes'] ?? 0);
$accent           = ($media_type === 'music') ? 'orange' : 'red';
?>
<div id="like-dislike-container" class="flex gap-2">
    <button
        hx-post="../like.php"
        hx-target="#like-dislike-container"
        hx-swap="outerHTML"
        hx-vals='{"id": "<?= $id ?>", "media_type": "<?= $media_type ?>", "type": "like"}'
        class="flex items-center gap-2 px-5 py-2.5 rounded-full transition-all text-[11px] font-black uppercase tracking-wider <?= $user_interaction === 'like' ? "bg-{$accent}-600 text-white shadow-lg" : "bg-gray-800/50 text-gray-400 hover:bg-gray-700" ?>">
        <i data-lucide="thumbs-up" class="w-4 h-4 <?= $user_interaction === 'like' ? 'fill-white' : '' ?>"></i>
        Like <?= $likes > 0 ? "<span class='tabular-nums'>$likes</span>" : '' ?>
    </button>

    <button
        hx-post="../like.php"
        hx-target="#like-dislike-container"
        hx-swap="outerHTML"
        hx-vals='{"id": "<?= $id ?>", "media_type": "<?= $media_type ?>", "type": "dislike"}'
        class="flex items-center gap-2 px-5 py-2.5 rounded-full transition-all text-[11px] font-black uppercase tracking-wider <?= $user_interaction === 'dislike' ? "bg-white text-{$accent}-600 shadow-lg" : "bg-gray-800/50 text-gray-400 hover:bg-{$accent}-600 hover:text-white" ?>">
        <i data-lucide="thumbs-down" class="w-4 h-4 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
        <?= $dislikes > 0 ? "<span class='tabular-nums'>$dislikes</span>" : '' ?>
    </button>
</div>
<script>if(typeof lucide !== 'undefined') lucide.createIcons();</script>