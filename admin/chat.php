<?php
/**
 * @var \mysqli $conn
 */
include __DIR__ . '/../auth/config.php';
include __DIR__ . '/../auth/auth.php';
include_once __DIR__ . '/../modules/core/helpers.php';
require_admin($conn);
define('MEEL_ADMIN_CONTEXT', true);
$root = meel_base_url_path();
$chat_root = $root . '/admin/chat';
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
            header('Location: ' . $chat_root . '/' . rawurlencode($row['username']), true, 301);
            exit;
        }
    }
    header('Location: ' . $chat_root);
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
    header('Location: ' . $chat_root);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $_META_TITLE = 'Obrolan Admin | MEeL'; include __DIR__ . '/../partials/link.php'; ?>
    <?php meel_css_links('admin', $root . '/assets/css/admin'); ?>
    <link rel="stylesheet" href="<?= $root ?>/assets/css/admin/chat.css<?= meel_asset_version('assets/css/admin/chat.css') ?>">
</head>

<body class="min-h-screen admin-chat-page">
    <div id="admin-chat-data" data-api-base="<?= htmlspecialchars($root) ?>/api/chat" data-chat-root="<?= htmlspecialchars($chat_root) ?>" hidden></div>
    <?php
    $is_admin = true;
    $page_title = 'Obrolan Admin';
    $media_type = 'dashboard';
    $back_url = $root . '/admin/beranda';
    include __DIR__ . '/header-admin.php';
    ?>
    <main class="admin-chat" aria-label="Obrolan Admin">
        <?php if ($chat_user): ?>
            <section class="admin-chat-panel admin-chat-recipient" aria-label="Penerima pesan">
                <div class="chat-avatar">
                    <?php if (!empty($chat_user['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars(meel_profile_image_url($chat_user['profile_picture'], $root . '/profile/upload/'), ENT_QUOTES, 'UTF-8') ?>" onerror="this.hidden=true;this.nextElementSibling.hidden=false" alt="">
                    <?php endif; ?>
                    <span class="chat-avatar-fallback" <?= !empty($chat_user['profile_picture']) ? 'hidden' : '' ?>><?= htmlspecialchars(mb_strtoupper(mb_substr($chat_user['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <div class="admin-chat-recipient-info">
                    <h1 class="admin-chat-heading">@<?= htmlspecialchars($chat_user['username']) ?></h1>
                    <p class="admin-chat-muted">Pesan 1 arah: Admin → Pengguna</p>
                </div>
                <a href="<?= $chat_root ?>" class="admin-chat-back" aria-label="Kembali ke daftar chat" title="Kembali ke daftar chat">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </a>
            </section>
            <section id="chat-messages" class="admin-chat-panel admin-chat-messages" aria-label="Riwayat pesan">
                <div class="admin-chat-loading">Memuat pesan...</div>
            </section>
            <section class="admin-chat-panel" aria-label="Tulis pesan">
                <label for="chat-input" class="admin-chat-label">Pesan untuk @<?= htmlspecialchars($chat_user['username']) ?></label>
                <form id="chat-form" onsubmit="return sendChat(event)" class="admin-chat-form">
                    <input type="hidden" name="user_id" value="<?= $chat_user_id ?>">
                    <textarea id="chat-input" name="message" rows="2" maxlength="5000" placeholder="Ketik pesan untuk @<?= htmlspecialchars($chat_user['username']) ?>..." class="chat-input" required></textarea>
                    <button id="chat-send-btn" type="submit" class="chat-send-btn" aria-label="Kirim pesan">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"></line>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                        </svg>
                    </button>
                </form>
                <p id="chat-status" class="admin-chat-muted" role="status" aria-live="polite"></p>
            </section>
        <?php else: ?>
            <section class="admin-chat-panel" aria-label="Cari pengguna">
                <label for="user-search" class="admin-chat-label">Cari Pengguna</label>
                <input type="text" id="user-search" placeholder="Ketik nama pengguna..." class="chat-input" oninput="searchUsers(this.value)">
            </section>
            <div id="user-results" class="admin-chat-list"></div>
            <section id="recent-chats" class="admin-chat-panel" aria-labelledby="recent-chats-title">
                <h1 id="recent-chats-title" class="admin-chat-label">Percakapan Terakhir</h1>
                <div id="recent-list" class="admin-chat-list">
                    <div class="admin-chat-loading">Memuat...</div>
                </div>
            </section>
        <?php endif; ?>
    </main>
    <?php if ($chat_user): ?>
        <script src="<?= $root ?>/assets/js/shared/format-helpers.js<?= meel_asset_version('assets/js/shared/format-helpers.js') ?>"></script>
        <script src="<?= $root ?>/assets/js/admin/chat.js<?= meel_asset_version('assets/js/admin/chat.js') ?>"></script>
        <script>
            loadChatMessages(<?= $chat_user_id ?>);
        </script>
    <?php else: ?>
        <script src="<?= $root ?>/assets/js/shared/format-helpers.js<?= meel_asset_version('assets/js/shared/format-helpers.js') ?>"></script>
        <script src="<?= $root ?>/assets/js/admin/chat/list.js<?= meel_asset_version('assets/js/admin/chat/list.js') ?>"></script>
    <?php endif; ?>
</body>

</html>

<!-- reference build: MEeL-C8H11NO2 [71b2f2af520d2785] -->
