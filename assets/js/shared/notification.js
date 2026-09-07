(function() {
    const ICONS = { like: '❤️', reply: '💬', meelcoin: '🪙', admin_chat: '✉️', system: '🔔' };
    let pollInterval = null;
    let ddOpen = false;

    function getRoot() {
        const el = document.querySelector('nav') || document.body;
        const base = el.querySelector('[data-meel-root]');
        return base ? base.getAttribute('data-meel-root') : (window.__MEEL_ROOT || '/');
    }

    function apiRoot() { return getRoot(); }

    function fetchCount() {
        fetch(apiRoot() + 'controllers/api/notification.php?action=unread_count', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                const c = d.count || 0;
                const el = document.getElementById('notif-badge');
                if (el) {
                    el.textContent = c > 99 ? '99+' : c;
                    el.style.display = c > 0 ? 'block' : 'none';
                }
            })
            .catch(() => {});
    }

    function timeAgo(ts) {
        const diff = (Date.now() - new Date(ts).getTime()) / 1000;
        if (diff < 60) return 'Baru saja';
        if (diff < 3600) return Math.floor(diff / 60) + ' menit lalu';
        if (diff < 86400) return Math.floor(diff / 3600) + ' jam lalu';
        return Math.floor(diff / 86400) + ' hari lalu';
    }

    function renderList(containerId, list) {
        const container = document.getElementById(containerId);
        if (!container) return;
        if (!list || list.length === 0) {
            container.innerHTML = '<div class="notif-empty">Tidak ada notifikasi</div>';
            return;
        }
        container.innerHTML = list.map(n => `
            <a href="${apiRoot()}profile/notification" class="notif-item ${n.is_read == 0 ? 'unread' : ''}" data-id="${n.id}">
                <div class="notif-icon ${n.type}">${ICONS[n.type] || '🔔'}</div>
                <div class="notif-body">
                    <div class="notif-title">${escHtml(n.title)}</div>
                    <div class="notif-message">${escHtml(n.message)}</div>
                    <div class="notif-time">${timeAgo(n.created_at)}</div>
                </div>
            </a>
        `).join('');
    }

    function escHtml(s) {
        const d = document.createElement('div');
        d.textContent = s || '';
        return d.innerHTML;
    }

    function fetchList() {
        fetch(apiRoot() + 'controllers/api/notification.php?action=list&limit=15', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    renderList('notif-list', d.list);
                }
            })
            .catch(() => {});
    }

    function markAllRead() {
        fetch(apiRoot() + 'controllers/api/notification.php?action=mark_all_read', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        }).then(() => {
            fetchCount();
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
        });
    }

    window.toggleNotifDropdown = function() {
        const dd = document.getElementById('notif-dropdown');
        if (!dd) return;
        ddOpen = !ddOpen;
        if (ddOpen) {
            dd.classList.add('open');
            fetchList();
            markAllRead();
        } else {
            dd.classList.remove('open');
        }
    };

    window.markAllNotifRead = markAllRead;

    document.addEventListener('click', function(e) {
        const dd = document.getElementById('notif-dropdown');
        const btn = document.getElementById('notif-bell-btn');
        if (!dd || !btn) return;
        if (!dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.remove('open');
            ddOpen = false;
        }
    });

    function init() {
        fetchCount();
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(fetchCount, 30000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
