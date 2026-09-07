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
    <style>
        .glass {
            background: var(--meel-surface, rgba(22, 27, 34, 0.7));
            border: 1px solid var(--meel-border, rgba(255, 255, 255, 0.05));
        }
        .chat-bubble-admin {
            background: rgba(139, 92, 246, 0.15);
            border: 1px solid rgba(139, 92, 246, 0.2);
            border-radius: 16px 16px 4px 16px;
            padding: 10px 14px;
            color: #c4b5fd;
            font-size: 12px;
            line-height: 1.5;
            max-width: 75%;
        }
        .chat-bubble-user {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px 16px 16px 4px;
            padding: 10px 14px;
            color: #d1d5db;
            font-size: 12px;
            line-height: 1.5;
            max-width: 75%;
        }
        .chat-user-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            border-radius: 12px;
            transition: background 0.15s;
            text-decoration: none;
            color: inherit;
        }
        .chat-user-card:hover { background: var(--meel-surface-hover, rgba(255,255,255,0.03)); }
        .chat-avatar {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 13px; font-weight: 700; color: #fff;
        }
        .chat-input {
            background: var(--meel-surface, rgba(22, 27, 34, 0.7));
            border: 1px solid var(--meel-border, rgba(255, 255, 255, 0.08));
            border-radius: 12px;
            padding: 10px 14px;
            color: var(--meel-text-primary, #f3f4f6);
            font-size: 12px;
            resize: none;
            outline: none;
            width: 100%;
        }
        .chat-input:focus { border-color: rgba(139, 92, 246, 0.4); }
        .chat-send-btn {
            background: rgba(139, 92, 246, 0.2);
            border: 1px solid rgba(139, 92, 246, 0.3);
            border-radius: 10px;
            padding: 10px 14px;
            color: #a78bfa;
            cursor: pointer;
            transition: all 0.15s;
            flex-shrink: 0;
        }
        .chat-send-btn:hover { background: rgba(139, 92, 246, 0.3); }
    </style>
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
    <script>
        var API_BASE = '<?= $root ?>/api/chat';
        var ADMIN_CHAT_ROOT = '<?= $root ?>/admin/chat';
        function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
        function searchUsers(q) {
            if (q.length < 1) { document.getElementById('user-results').innerHTML = ''; return; }
            fetch(API_BASE + '?action=users&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok) return;
                    document.getElementById('user-results').innerHTML = d.list.map(u => `
                        <a href="${ADMIN_CHAT_ROOT}/${u.username}" class="glass chat-user-card">
                            <div class="chat-avatar" style="background:linear-gradient(135deg,#f97316,#dc2626);">${u.username.charAt(0).toUpperCase()}</div>
                            <div>
                                <div style="font-size:13px;font-weight:700;color:var(--meel-text-heading,#f3f4f6);">@${escapeHtml(u.username)}</div>
                                <div style="font-size:10px;color:#6b7280;">${u.role}</div>
                            </div>
                        </a>
                    `).join('');
                });
        }

        function loadRecent() {
            fetch(API_BASE + '?action=recent', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (!d.ok || d.list.length === 0) {
                        document.getElementById('recent-list').innerHTML = '<div style="text-align:center;color:#6b7280;font-size:11px;padding:16px 0;">Belum ada percakapan</div>';
                        return;
                    }
                    document.getElementById('recent-list').innerHTML = d.list.map(c => `
                        <a href="${ADMIN_CHAT_ROOT}/${c.username}" class="chat-user-card glass" style="border:none;">
                            <div class="chat-avatar" style="background:linear-gradient(135deg,#f97316,#dc2626);">${c.username.charAt(0).toUpperCase()}</div>
                            <div style="min-width:0;flex:1;">
                                <div style="font-size:12px;font-weight:700;color:var(--meel-text-heading,#f3f4f6);">@${c.username}</div>
                                <div style="font-size:10px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(c.last_message)}</div>
                            </div>
                            <div style="font-size:9px;color:#4b5563;flex-shrink:0;">${timeAgo(c.last_at)}</div>
                        </a>
                    `).join('');
                });
        }

        function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
        function timeAgo(ts) {
            const diff = (Date.now() - new Date(ts).getTime()) / 1000;
            if (diff < 60) return 'Baru saja';
            if (diff < 3600) return Math.floor(diff / 60) + 'm lalu';
            if (diff < 86400) return Math.floor(diff / 3600) + 'j lalu';
            return Math.floor(diff / 86400) + 'h lalu';
        }

        loadRecent();
        setInterval(loadRecent, 15000);
    </script>
    <?php endif; ?>
</body>
</html>
