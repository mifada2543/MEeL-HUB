(function() {
    const ICONS = { like: '❤️', reply: '💬', meelcoin: '🪙', admin_chat: '✉️', system: '🔔' };
    let pollInterval = null;
    let ddOpen = false;

    function apiRoot() {
        const el = document.querySelector('nav') || document.body;
        const base = el.querySelector('[data-meel-root]');
        const root = base ? base.getAttribute('data-meel-root') : (window.MEEL_BASE || '');
        return root ? root + '/' : '/';
    }

    function fetchCount() {
        fetch(apiRoot() + 'api/notification?action=unread_count', { credentials: 'same-origin' })
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
                    <div class="notif-time">${escHtml(n.time_ago || '')}</div>
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
        fetch(apiRoot() + 'api/notification?action=list&limit=15', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    renderList('notif-list', d.list);
                }
            })
            .catch(() => {});
    }

    function markAllRead() {
        const fd = new FormData();
        fd.append('action', 'mark_all_read');
        fd.append('csrf_token', window.MEEL_CSRF || '');
        fetch(apiRoot() + 'api/notification', {
            method: 'POST',
            credentials: 'same-origin',
            body: fd
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
