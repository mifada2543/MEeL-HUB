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

$root = meel_base_url_path();
$back_url = $root . '/profile/' . rawurlencode($_SESSION['username']);
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $ref_host = parse_url($ref, PHP_URL_HOST);
    $current_host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref_host === $current_host || $ref_host === 'localhost' || $ref_host === '127.0.0.1') {
        $ref_path = parse_url($ref, PHP_URL_PATH);
        if (strpos($ref_path, '/profile/notification') === false) {
            $back_url = $ref;
        }
    }
}

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
        body { background: var(--meel-bg); color: var(--meel-text); }
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
            transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
        }
        .notif-item:hover { background: var(--meel-surface-hover); cursor: default; }
        a.notif-item:hover { cursor: pointer; }
        .notif-item.unread { border-left: 3px solid var(--meel-blue); }
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
            transition: background 0.15s, color 0.15s, border-color 0.15s; text-decoration: none; display: inline-block;
        }
        .filter-btn:hover { background: var(--meel-surface-hover); }
        .filter-btn.active {
            background: rgba(59, 130, 246, 0.12); color: var(--meel-blue);
            border-color: rgba(59, 130, 246, 0.3);
        }
        .empty-state {
            background: var(--meel-surface);
            border: 1px dashed var(--meel-border-strong);
            border-radius: 16px; padding: 48px 24px; text-align: center;
        }
        .notif-delete {
            background: none; border: none; cursor: pointer;
            padding: 4px; flex-shrink: 0; border-radius: 6px;
            color: var(--meel-text-muted);
            transition: color 0.15s, background 0.15s;
        }
        .notif-delete:hover { color: var(--meel-red); background: var(--meel-surface-hover); }
        .notif-delete:focus-visible {
            outline: 2px solid var(--meel-blue);
            outline-offset: 2px;
        }
        .notif-clear {
            background: none; border: none; cursor: pointer;
            font-size: 10px; font-weight: 600;
            color: var(--meel-red);
            padding: 4px 6px; border-radius: 6px;
            transition: opacity 0.15s;
        }
        .notif-clear:hover { opacity: 0.75; text-decoration: underline; }
        .notif-clear:focus-visible {
            outline: 2px solid var(--meel-blue);
            outline-offset: 2px;
        }
        .notif-removing {
            opacity: 0;
            transform: translateX(20px);
        }
        .notif-item,
        .notif-delete { transition-property: opacity, transform, background, border-color, box-shadow, color; }
        .notif-item,
        .notif-delete { transition-duration: 0.15s; }
        @media (prefers-reduced-motion: reduce) {
            .notif-item, .notif-delete { transition: none !important; }
            .notif-removing { opacity: 0; transform: none; }
        }
    </style>
</head>
<body class="min-h-screen p-4 md:p-8">
    <?php include '../partials/nav.php'; ?>
    <div class="max-w-2xl mx-auto pt-6">
        <div class="flex items-center gap-3 mb-5">
            <a href="<?= htmlspecialchars($back_url) ?>" class="p-2 rounded-lg hover:bg-white/[.04] transition" style="color:var(--meel-text-secondary)" aria-label="Kembali">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <h1 class="text-lg font-bold" style="color:var(--meel-text-heading)">Notifikasi</h1>
            <?php if ($unreadCount > 0): ?>
                <span class="text-[10px] bg-blue-500/15 text-blue-400 px-2 py-0.5 rounded-full font-bold"><?= $unreadCount ?> baru</span>
            <?php endif; ?>
            <?php if (!empty($notifications)): ?>
                <button type="button" onclick="deleteAllNotif()" class="notif-clear ml-auto" title="Hapus semua notifikasi" aria-label="Hapus semua notifikasi">Hapus semua</button>
            <?php endif; ?>
        </div>

        <div class="flex gap-2 mb-4 flex-wrap">
            <a href="notification" class="filter-btn <?= !$typeFilter ? 'active' : '' ?>" <?= !$typeFilter ? 'aria-current="page"' : '' ?>>Semua</a>
            <a href="notification?type=like" class="filter-btn <?= $typeFilter === 'like' ? 'active' : '' ?>" <?= $typeFilter === 'like' ? 'aria-current="page"' : '' ?>>❤️ Like</a>
            <a href="notification?type=reply" class="filter-btn <?= $typeFilter === 'reply' ? 'active' : '' ?>" <?= $typeFilter === 'reply' ? 'aria-current="page"' : '' ?>>💬 Reply</a>
            <a href="notification?type=meelcoin" class="filter-btn <?= $typeFilter === 'meelcoin' ? 'active' : '' ?>" <?= $typeFilter === 'meelcoin' ? 'aria-current="page"' : '' ?>>🪙 Coin</a>
            <a href="notification?type=admin_chat" class="filter-btn <?= $typeFilter === 'admin_chat' ? 'active' : '' ?>" <?= $typeFilter === 'admin_chat' ? 'aria-current="page"' : '' ?>>✉️ Chat</a>
        </div>

        <?php if (empty($notifications)): ?>
            <div class="empty-state">
                <div class="text-3xl mb-3 opacity-30" aria-hidden="true">🔔</div>
                <p class="text-sm font-medium" style="color:var(--meel-text-secondary)">Tidak ada notifikasi</p>
                <p class="text-[11px] mt-1" style="color:var(--meel-text-secondary)">Notifikasi akan muncul di sini saat ada aktivitas terkait kamu</p>
            </div>
        <?php else: ?>
            <div class="space-y-2" id="notif-list">
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
                        <div class="notif-icon <?= htmlspecialchars($n['type']) ?>" aria-hidden="true">
                            <?= $ICONS[$n['type']] ?? '🔔' ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-[13px] font-semibold mb-0.5" style="color:var(--meel-text-heading)"><?= htmlspecialchars($n['title']) ?></div>
                            <div class="text-[12px] leading-relaxed" style="color:var(--meel-text-secondary)"><?= htmlspecialchars($n['message']) ?></div>
                            <div class="text-[10px] mt-1.5" style="color:var(--meel-text-muted)"><?= htmlspecialchars(time_ago($n['created_at'])) ?></div>
                        </div>
                        <button type="button" class="notif-delete" onclick="event.preventDefault(); event.stopPropagation(); deleteNotif(<?= (int)$n['id'] ?>, this)" title="Hapus" aria-label="Hapus notifikasi">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                        </button>
                    <?php if ($href): ?>
                        </a>
                    <?php else: ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div id="notif-live" class="sr-only" aria-live="polite"></div>
    </div>

    <script>
    var API_ROOT = '<?= $root ?>/api/notification';
    function announce(msg) {
        var el = document.getElementById('notif-live');
        if (el) el.textContent = msg;
    }
    function refreshEmptyState() {
        var list = document.getElementById('notif-list');
        if (list && list.children.length === 0) {
            var st = document.createElement('div');
            st.className = 'empty-state';
            st.innerHTML = '<div class="text-3xl mb-3 opacity-30" aria-hidden="true">🔔<\/div>'
                + '<p class="text-sm font-medium" style="color:var(--meel-text-secondary)">Tidak ada notifikasi<\/p>'
                + '<p class="text-[11px] mt-1" style="color:var(--meel-text-secondary)">Notifikasi akan muncul di sini saat ada aktivitas terkait kamu<\/p>';
            list.replaceWith(st);
            var delAll = document.querySelector('button[aria-label="Hapus semua notifikasi"]');
            if (delAll) delAll.remove();
            announce('Semua notifikasi telah dihapus');
        }
    }
    function deleteNotif(id, btn) {
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        fetch(API_ROOT, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function() {
                var card = btn.closest('.notif-item');
                if (card) {
                    card.classList.add('notif-removing');
                    setTimeout(function() { card.remove(); refreshEmptyState(); }, 200);
                }
                announce('Notifikasi dihapus');
            });
    }
    function deleteAllNotif() {
        if (!confirm('Hapus semua notifikasi?')) return;
        var fd = new FormData();
        fd.append('action', 'delete_all');
        fd.append('csrf_token', <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
        fetch(API_ROOT, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function() {
                var items = document.querySelectorAll('.notif-item');
                items.forEach(function(item, i) {
                    setTimeout(function() {
                        item.classList.add('notif-removing');
                        setTimeout(function() {
                            item.remove();
                            if (i === items.length - 1) refreshEmptyState();
                        }, 150);
                    }, i * 50);
                });
            });
    }
    </script>
    <?php include '../partials/footer.php'; ?>
</body>
</html>
