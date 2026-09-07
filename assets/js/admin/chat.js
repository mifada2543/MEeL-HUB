(function() {
    var currentUserId = 0;
    var API_BASE = window.MEEL_BASE + '/api/chat';

    function safeFetch(url, opts) {
        opts = opts || {};
        opts.cache = 'no-store';
        return fetch(url, opts).then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('json') === -1) {
                throw new Error('Non-JSON response');
            }
            return r.json();
        });
    }

    window.loadChatMessages = function(userId) {
        currentUserId = userId;
        var container = document.getElementById('chat-messages');
        if (!container) return;

        safeFetch(API_BASE + '?action=get&user_id=' + userId, { credentials: 'same-origin' })
            .then(function(d) {
                if (!d.ok || !d.messages || d.messages.length === 0) {
                    container.innerHTML = '<div style="text-align:center;color:#6b7280;font-size:11px;padding:32px 0;">Belum ada pesan. Kirim pesan pertama!</div>';
                    return;
                }
                container.innerHTML = d.messages.map(function(m, idx) {
                    var isAdmin = m.from === 'admin';
                    var deleteBtn = isAdmin ? '<button onclick="deleteChatMsg(' + idx + ')" style="background:none;border:none;color:rgba(196,181,253,0.3);cursor:pointer;padding:2px 4px;font-size:9px;transition:color 0.15s;" onmouseover="this.style.color=\'#ef4444\'" onmouseout="this.style.color=\'rgba(196,181,253,0.3)\'" title="Hapus pesan"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>' : '';
                    return '<div style="display:flex;gap:10px;margin-bottom:12px;' + (isAdmin ? 'justify-content:flex-end;' : 'justify-content:flex-start;') + '">'
                        + (isAdmin ? '' : '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">U</div>')
                        + '<div class="' + (isAdmin ? 'chat-bubble-admin' : 'chat-bubble-user') + '">'
                        + '<div>' + escapeHtml(m.message) + '</div>'
                        + '<div style="display:flex;align-items:center;gap:6px;margin-top:4px;' + (isAdmin ? 'justify-content:flex-end;' : '') + '">'
                        + '<span style="font-size:9px;' + (isAdmin ? 'color:rgba(196,181,253,0.5);' : 'color:#4b5563;') + '">' + timeAgo(m.created_at) + '</span>'
                        + deleteBtn
                        + '</div>'
                        + '</div>'
                        + (isAdmin ? '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#f97316,#dc2626);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">A</div>' : '')
                        + '</div>';
                }).join('');
                container.scrollTop = container.scrollHeight;
            })
            .catch(function() {
                container.innerHTML = '<div style="text-align:center;color:#ef4444;font-size:11px;padding:32px 0;">Gagal memuat pesan. Coba refresh halaman.</div>';
            });
    };

    window.deleteChatMsg = function(index) {
        if (!currentUserId) return;
        if (!confirm('Hapus pesan ini?')) return;

        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('user_id', currentUserId);
        fd.append('index', index);

        safeFetch(API_BASE, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function(d) {
            if (d.ok) {
                loadChatMessages(currentUserId);
            } else {
                alert(d.error || 'Gagal menghapus pesan');
            }
        }).catch(function() {
            alert('Gagal menghapus pesan.');
        });
    };

    window.sendChat = function(e) {
        e.preventDefault();
        var input = document.getElementById('chat-input');
        var msg = input.value.trim();
        if (!msg || !currentUserId) return false;

        var fd = new FormData();
        fd.append('action', 'send');
        fd.append('user_id', currentUserId);
        fd.append('message', msg);

        safeFetch(API_BASE, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function(d) {
            if (d.ok) {
                input.value = '';
                loadChatMessages(currentUserId);
            } else {
                alert(d.error || 'Gagal mengirim pesan');
            }
        }).catch(function() {
            alert('Gagal mengirim pesan. Coba refresh halaman.');
        });
        return false;
    };

    function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
    function timeAgo(ts) {
        var diff = (Date.now() - new Date(ts).getTime()) / 1000;
        if (diff < 60) return 'Baru saja';
        if (diff < 3600) return Math.floor(diff / 60) + 'm lalu';
        if (diff < 86400) return Math.floor(diff / 3600) + 'j lalu';
        return Math.floor(diff / 86400) + 'h lalu';
    }

    if (currentUserId) {
        setInterval(function() { loadChatMessages(currentUserId); }, 10000);
    }
})();
