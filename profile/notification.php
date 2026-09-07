<?php
require_once '../modules/auth/helpers/session.php';
meel_boot_session();
require_once '../auth/config.php';
require_once '../modules/core/Notification.php';

if (empty($_SESSION['user_id'])) {
    header('Location: ../auth/login');
    exit;
}

$userId = (int)$_SESSION['user_id'];

$validTypes = ['like', 'reply', 'meelcoin', 'admin_chat', 'system'];
$typeFilter = $_GET['type'] ?? null;
if ($typeFilter && !in_array($typeFilter, $validTypes)) {
    $typeFilter = null;
}

$notifications = Notification::getList($conn, $userId, 50, $typeFilter);
Notification::markAllRead($conn, $userId);
$unreadCount = Notification::getUnreadCount($conn, $userId);

$ICONS = [
    'like' => '❤️',
    'reply' => '💬',
    'meelcoin' => '🪙',
    'admin_chat' => '✉️',
    'system' => '🔔',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <?php
    $_META_TITLE = 'Notifikasi — MEeL';
    include '../partials/link.php';
    ?>
    <style>
        body { background: var(--meel-bg); color: var(--meel-text-primary); }
        .glass {
            background: var(--meel-surface);
            border: 1px solid var(--meel-border);
        }
        .notif-item {
            background: var(--meel-surface);
            border: 1px solid var(--meel-border);
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            transition: background 0.15s;
        }
        .notif-item:hover { background: var(--meel-surface-hover); cursor: default; }
        a.notif-item:hover { cursor: pointer; }
        .notif-item.unread { border-left: 3px solid #3b82f6; }
        .notif-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0; font-size: 16px;
        }
        .notif-icon.like { background: rgba(239, 68, 68, 0.12); }
        .notif-icon.reply { background: rgba(59, 130, 246, 0.12); }
        .notif-icon.meelcoin { background: rgba(234, 179, 8, 0.12); }
        .notif-icon.admin_chat { background: rgba(168, 85, 247, 0.12); }
        .notif-icon.system { background: rgba(107, 114, 128, 0.12); }
        .filter-btn {
            padding: 6px 14px; border-radius: 8px; font-size: 11px;
            font-weight: 600; border: 1px solid var(--meel-border);
            color: var(--meel-text-secondary); cursor: pointer;
            transition: all 0.15s; text-decoration: none; display: inline-block;
        }
        .filter-btn:hover { background: var(--meel-surface-hover); }
        .filter-btn.active {
            background: rgba(59, 130, 246, 0.12); color: #3b82f6;
            border-color: rgba(59, 130, 246, 0.3);
        }
        .empty-state {
            background: var(--meel-surface);
            border: 1px solid var(--meel-border);
            border-radius: 16px; padding: 48px 24px; text-align: center;
        }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
    <?php include '../partials/nav.php'; ?>
    <div class="max-w-2xl mx-auto pt-6">
        <div class="flex items-center gap-3 mb-5">
            <a href="javascript:history.back()" class="p-2 rounded-lg hover:bg-white/[.04] transition" style="color:var(--meel-text-secondary)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <h1 class="text-lg font-bold" style="color:var(--meel-text-heading)">Notifikasi</h1>
            <?php if ($unreadCount > 0): ?>
                <span class="text-[10px] bg-blue-500/15 text-blue-400 px-2 py-0.5 rounded-full font-bold"><?= $unreadCount ?> baru</span>
            <?php endif; ?>
            <?php if (!empty($notifications)): ?>
                <button onclick="deleteAllNotif()" class="ml-auto text-[10px] text-red-400 hover:text-red-300 transition-colors" title="Hapus semua notifikasi">Hapus semua</button>
            <?php endif; ?>
        </div>

        <div class="flex gap-2 mb-4 flex-wrap">
            <a href="notification" class="filter-btn <?= !$typeFilter ? 'active' : '' ?>">Semua</a>
            <a href="notification?type=like" class="filter-btn <?= $typeFilter === 'like' ? 'active' : '' ?>">❤️ Like</a>
            <a href="notification?type=reply" class="filter-btn <?= $typeFilter === 'reply' ? 'active' : '' ?>">💬 Reply</a>
            <a href="notification?type=meelcoin" class="filter-btn <?= $typeFilter === 'meelcoin' ? 'active' : '' ?>">🪙 Coin</a>
            <a href="notification?type=admin_chat" class="filter-btn <?= $typeFilter === 'admin_chat' ? 'active' : '' ?>">✉️ Chat</a>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="text-3xl mb-3 opacity-30">🔔</div>
                <p class="text-sm font-medium" style="color:var(--meel-text-secondary)">Tidak ada notifikasi</p>
                <p class="text-[11px] mt-1" style="color:var(--meel-text-secondary)">Notifikasi akan muncul di sini saat ada aktivitas terkait kamu</p>
            </div>
        <?php else: ?>
            <div class="space-y-2">
                <?php $root = meel_base_url_path(); ?>
                <?php foreach ($notifications as $n):
                    $href = null;
                    if ($n['type'] === 'like' && $n['related_id'] && $n['related_slug']) {
                        $href = $root . '/' . $n['related_slug'] . '/watch?v=' . (int)$n['related_id'];
                    } elseif ($n['type'] === 'reply' && $n['related_slug'] && str_contains($n['related_slug'], ':')) {
                        [$rType, $rId] = explode(':', $n['related_slug'], 2);
                        $href = $root . '/' . $rType . '/watch?v=' . (int)$rId;
                    }
                ?>
                    <?php if ($href): ?>
                        <a href="<?= htmlspecialchars($href) ?>" class="notif-item <?= $n['is_read'] == 0 ? 'unread' : '' ?>" style="text-decoration:none;color:inherit;">
                    <?php else: ?>
                        <div class="notif-item <?= $n['is_read'] == 0 ? 'unread' : '' ?>">
                    <?php endif; ?>
                        <div class="notif-icon <?= htmlspecialchars($n['type']) ?>">
                            <?= $ICONS[$n['type']] ?? '🔔' ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] font-semibold mb-0.5" style="color:var(--meel-text-heading)"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="text-[12px] leading-relaxed" style="color:var(--meel-text-secondary)"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="text-[10px] mt-1.5" style="color:#fff" data-time="<?= htmlspecialchars($n['created_at']) ?>"></div>
                        </div>
                        <button onclick="deleteNotif(<?= (int)$n['id'] ?>, this)" style="background:none;border:none;color:#4b5563;cursor:pointer;padding:4px;flex-shrink:0;transition:color 0.15s;" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#4b5563'" title="Hapus">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    <?php if ($href): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        document.querySelectorAll('[data-time]').forEach(function(el) {
            var ts = el.getAttribute('data-time');
            if (!ts) return;
            var diff = (Date.now() - new Date(ts + 'Z').getTime()) / 1000;
            var text = 'Baru saja';
            if (diff >= 86400) text = Math.floor(diff / 86400) + ' hari lalu';
            else if (diff >= 3600) text = Math.floor(diff / 3600) + ' jam lalu';
            else if (diff >= 60) text = Math.floor(diff / 60) + ' menit lalu';
            el.textContent = text;
        });
    })();
    </script>
    <script>
    var API_ROOT = '<?= $root ?>/api/notification';
    function deleteNotif(id, btn) {
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fetch(API_ROOT, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function() {
                var card = btn.closest('.notif-item');
                if (card) {
                    card.style.transition = 'opacity 0.2s, transform 0.2s';
                    card.style.opacity = '0';
                    card.style.transform = 'translateX(20px)';
                    setTimeout(function() { card.remove(); }, 200);
                }
            });
    }
    function deleteAllNotif() {
        if (!confirm('Hapus semua notifikasi?')) return;
        fetch(API_ROOT + '?action=delete_all', { method: 'POST', credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function() {
                var items = document.querySelectorAll('.notif-item');
                items.forEach(function(item, i) {
                    setTimeout(function() {
                        item.style.transition = 'opacity 0.15s';
                        item.style.opacity = '0';
                        setTimeout(function() { item.remove(); }, 150);
                    }, i * 50);
                });
            });
    }
    </script>
    <?php include '../partials/footer.php'; ?>
</body>
</html>
