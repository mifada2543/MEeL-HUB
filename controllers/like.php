<?php
include '../auth/config.php';
include '../modules/MediaInteraction.php';

// Get POST data
$id         = isset($_POST['id'])         ? intval($_POST['id'])       : 0;
$media_type = isset($_POST['media_type']) ? trim($_POST['media_type']) : '';
$type       = isset($_POST['type'])       ? trim($_POST['type'])       : '';

error_log("LIKE.PHP - POST: " . json_encode(['id' => $id, 'media_type' => $media_type, 'type' => $type]));

// Gunakan MediaInteraction class
$interaction = new MediaInteraction($conn, $_SESSION['user_id'] ?? null);
$result = $interaction->toggleLike($id, $media_type, $type);

// Handle response
if (!$result['success']) {
    error_log("LIKE.PHP - ERROR: {$result['message']} (Code: {$result['http_code']})");
    http_response_code($result['http_code']);
    exit;
}

// Extract data
$user_interaction = $result['data']['user_interaction'];
$likes            = $result['data']['likes'];
$dislikes         = $result['data']['dislikes'];
$table = ($media_type === 'music') ? 'music' : 'video';

// Konfigurasi style Tailwind yang ditulis penuh (Hardcoded class names)
$like_active_class = ($media_type === 'music') 
    ? 'bg-orange-500/15 border-orange-500/30 text-orange-400' 
    : 'bg-red-600/15 border-red-600/30 text-red-400';

$dislike_active_class = 'bg-white/10 border-white/15 text-white';
$inactive_class = 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300';
?>

<div id="like-dislike-container" class="flex items-center gap-2">
    <button
        hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $id ?>","media_type":"<?= $media_type ?>","type":"like"}'
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $user_interaction === 'like' ? $like_active_class : $inactive_class ?>">
        <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
        Like<?= $likes > 0 ? " <span class='tabular-nums ml-0.5'>{$likes}</span>" : '' ?>
    </button>
    
    <button
        hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $id ?>","media_type":"<?= $media_type ?>","type":"dislike"}'
        class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $user_interaction === 'dislike' ? $dislike_active_class : $inactive_class ?>">
        <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
        <?= $dislikes > 0 ? "<span class='tabular-nums'>{$dislikes}</span>" : '' ?>
    </button>

    <?php if ($media_type === 'music'): ?>
        <button onclick="document.getElementById('playlist-modal').classList.remove('hidden')"
            class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300">
            <i data-lucide="list-plus" class="w-3.5 h-3.5"></i> Simpan
        </button>
    <?php endif; ?>
</div>

<script>
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
