(function() {
    var el = document.getElementById('admin-chat-data');
    if (!el) return;
    var API_BASE = el.dataset.apiBase;
    var ADMIN_CHAT_ROOT = el.dataset.chatRoot;

    function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function timeAgo(ts) {
        var diff = (Date.now() - new Date(ts).getTime()) / 1000;
        if (diff < 60) return 'Baru saja';
        if (diff < 3600) return Math.floor(diff / 60) + 'm lalu';
        if (diff < 86400) return Math.floor(diff / 3600) + 'j lalu';
        return Math.floor(diff / 86400) + 'h lalu';
    }

    window.searchUsers = function(q) {
        if (q.length < 1) { document.getElementById('user-results').innerHTML = ''; return; }
        fetch(API_BASE + '?action=users&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok) return;
                document.getElementById('user-results').innerHTML = d.list.map(function(u) {
                    return '<a href="' + ADMIN_CHAT_ROOT + '/' + u.username + '" class="glass chat-user-card">'
                        + '<div class="chat-avatar" style="background:linear-gradient(135deg,#f97316,#dc2626);">' + u.username.charAt(0).toUpperCase() + '</div>'
                        + '<div><div style="font-size:13px;font-weight:700;color:var(--meel-text-heading,#f3f4f6);">@' + escapeHtml(u.username) + '</div>'
                        + '<div style="font-size:10px;color:#6b7280;">' + u.role + '</div></div></a>';
                }).join('');
            });
    };

    function loadRecent() {
        fetch(API_BASE + '?action=recent', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (!d.ok || d.list.length === 0) {
                    document.getElementById('recent-list').innerHTML = '<div style="text-align:center;color:#6b7280;font-size:11px;padding:16px 0;">Belum ada percakapan</div>';
                    return;
                }
                document.getElementById('recent-list').innerHTML = d.list.map(function(c) {
                    return '<a href="' + ADMIN_CHAT_ROOT + '/' + c.username + '" class="chat-user-card glass" style="border:none;">'
                        + '<div class="chat-avatar" style="background:linear-gradient(135deg,#f97316,#dc2626);">' + c.username.charAt(0).toUpperCase() + '</div>'
                        + '<div style="min-width:0;flex:1;"><div style="font-size:12px;font-weight:700;color:var(--meel-text-heading,#f3f4f6);">@' + c.username + '</div>'
                        + '<div style="font-size:10px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(c.last_message) + '</div></div>'
                        + '<div style="font-size:9px;color:#4b5563;flex-shrink:0;">' + timeAgo(c.last_at) + '</div></a>';
                }).join('');
            });
    }

    loadRecent();
    setInterval(loadRecent, 15000);
})();
