<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
require_admin($conn);
define('MEEL_ADMIN_CONTEXT', true);

$chat_username = $_GET['username'] ?? '';
$chat_user = null;
$chat_user_id = 0;

if ($chat_username === '' && !empty($_GET['user_id'])) {
    $old_id = (int)$_GET['user_id'];
    if ($old_id > 0) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $old_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            header('Location: chat/' . $row['username'], true, 301);
            exit;
        }
    }
    header('Location: chat');
    exit;
}
if ($chat_username !== '') {
    $stmt = $conn->prepare("SELECT id, username, role, profile_picture FROM users WHERE username = ?");
    $stmt->bind_param("s", $chat_username);
    $stmt->execute();
    $chat_user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($chat_user) {
        $chat_user_id = (int)$chat_user['id'];
    }
}
if ($chat_username !== '' && !$chat_user) {
    header('Location: chat');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Admin | MEeL</title>
    <?php include '../partials/link.php'; ?>
    <?php $root = meel_base_url_path(); ?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link href="<?= $root ?>/assets/css/admin/<?= $__f ?>" rel="stylesheet">
    <?php endforeach; ?>
    <link rel="stylesheet" href="<?= $root ?>/assets/css/admin/chat.css?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . meel_base_url_path() . '/assets/css/admin/chat.css') ?>">
    <div id="admin-chat-data" data-api-base="<?= htmlspecialchars($root) ?>/api/chat" data-chat-root="<?= htmlspecialchars($root) ?>/admin/chat" style="display:none;"></div>
</head>
<body class="bg-[#0b0e14] min-h-screen">
    <?php
    $is_admin = true;
    $page_title = 'Chat Admin';
    $media_type = 'dashboard';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>
    <div style="max-width:640px;margin:0 auto;padding:32px 16px;">

        <?php if ($chat_user): ?>
            <div class="glass" style="border-radius:16px;padding:16px;margin-bottom:16px;display:flex;align-items:center;gap:12px;">
                <div class="chat-avatar">
                    <?php if (!empty($chat_user['profile_picture'])): ?>
                        <img src="<?= $root ?>/profile/upload/<?= htmlspecialchars($chat_user['profile_picture']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:50%;" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <?php else: ?>
                        <?= strtoupper(substr($chat_user['username'], 0, 1)) ?>
                    <?php endif; ?>
                </div>
                <div>
                    <div style="font-size:13px;font-weight:700;color:var(--meel-text-heading,#f3f4f6);">@<?= htmlspecialchars($chat_user['username']) ?></div>
                    <div style="font-size:10px;color:#6b7280;">Pesan 1 arah: Admin → User</div>
                </div>
                <a href="<?= $root ?>/admin/chat" style="margin-left:auto;font-size:10px;color:#6b7280;text-decoration:none;" title="Kembali ke daftar chat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
                </a>
            </div>

            <div id="chat-messages" class="glass" style="border-radius:16px;padding:16px;margin-bottom:16px;min-height:300px;max-height:500px;overflow-y:auto;">
                <div style="text-align:center;color:#6b7280;font-size:11px;padding:32px 0;">Memuat pesan...</div>
            </div>

            <div class="glass" style="border-radius:16px;padding:16px;">
                <form id="chat-form" onsubmit="return sendChat(event)" style="display:flex;gap:8px;">
                    <input type="hidden" name="user_id" value="<?= $chat_user_id ?>">
                    <textarea id="chat-input" name="message" rows="2" placeholder="Ketik pesan untuk @<?= htmlspecialchars($chat_user['username']) ?>..." class="chat-input" style="flex:1;" required></textarea>
                    <button type="submit" class="chat-send-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"></line><polygon points="22 2 15 22 11 13 2 9 22 2"></polygon></svg>
                    </button>
                </form>
            </div>
        <?php else: ?>
            <div class="glass" style="border-radius:16px;padding:20px;margin-bottom:16px;">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#6b7280;margin-bottom:10px;">Cari User</div>
                <input type="text" id="user-search" placeholder="Ketik nama user..." class="chat-input" oninput="searchUsers(this.value)">
            </div>
            <div id="user-results" style="display:flex;flex-direction:column;gap:8px;"></div>
            <div id="recent-chats" class="glass" style="border-radius:16px;padding:20px;margin-top:16px;">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#6b7280;margin-bottom:10px;">Percakapan Terakhir</div>
                <div id="recent-list" style="display:flex;flex-direction:column;gap:4px;">
                    <div style="text-align:center;color:#6b7280;font-size:11px;padding:16px 0;">Memuat...</div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($chat_user): ?>
    <script src="<?= $root ?>/assets/js/admin/chat.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . meel_base_url_path() . '/assets/js/admin/chat.js') ?>"></script>
    <script>
        loadChatMessages(<?= $chat_user_id ?>);
    </script>
    <?php else: ?>
    <script src="<?= $root ?>/assets/js/admin/chat/list.js?v=<?= filemtime($_SERVER['DOCUMENT_ROOT'] . meel_base_url_path() . '/assets/js/admin/chat/list.js') ?>"></script>
    <?php endif; ?>
</body>
</html>
