/* reference build: MEeL-C8H11NO2 [75aa6be10a299df0] */
(function() {
    var currentUserId = 0;
    var data = document.getElementById('admin-chat-data');
    var API_BASE = data ? data.dataset.apiBase : window.MEEL_BASE + '/api/chat';
    var pollTimer = null;
    var loading = false;
    var requestId = 0;
    var busy = false;
    var revision = '';
    var displayedMessages = [];
    var initialLoad = true;
    var refreshAfterLoad = false;
    var scrollAfterLoad = false;

    function status(message) {
        var el = document.getElementById('chat-status');
        if (!el) {
            el = document.createElement('div');
            el.id = 'chat-status';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            var form = document.getElementById('chat-form');
            (form || document.body).appendChild(el);
        }
        el.textContent = message;
    }

    function setBusy(value) {
        busy = value;
        var button = document.getElementById('chat-send-btn');
        if (button) {
            button.disabled = value;
            button.setAttribute('aria-disabled', String(value));
        }
        var form = document.getElementById('chat-form');
        if (form) form.setAttribute('aria-busy', String(value));
        var container = document.getElementById('chat-messages');
        if (container) Array.prototype.forEach.call(container.querySelectorAll('button'), function(button) {
            button.disabled = value;
            button.setAttribute('aria-disabled', String(value));
        });
    }

    function safeFetch(url, opts) {
        opts = opts || {};
        opts.cache = 'no-store';
        return fetch(url, opts).then(function(r) {
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('json') === -1) {
                throw new Error('Non-JSON response');
            }
            return r.json().then(function(d) {
                if (!r.ok || !d || d.ok !== true) {
                    var error = new Error(d && d.error || 'Gagal memproses permintaan.');
                    error.status = r.status;
                    throw error;
                }
                return d;
            });
        });
    }

    function refreshMessages(forceBottom) {
        if (loading) {
            refreshAfterLoad = true;
            scrollAfterLoad = scrollAfterLoad || !!forceBottom;
        } else {
            window.loadChatMessages(currentUserId, forceBottom);
        }
    }

    window.loadChatMessages = function(userId, forceBottom) {
        userId = Number(userId);
        if (!Number.isInteger(userId) || userId <= 0) return;
        var container = document.getElementById('chat-messages');
        if (!container) return;
        if (currentUserId !== userId) {
            currentUserId = userId;
            revision = '';
            displayedMessages = [];
            initialLoad = true;
            loading = false;
            refreshAfterLoad = false;
            scrollAfterLoad = false;
        }
        if (pollTimer === null) pollTimer = setInterval(function() {
            if (!busy) window.loadChatMessages(currentUserId);
        }, 10000);
        if (loading) {
            if (forceBottom) {
                refreshAfterLoad = true;
                scrollAfterLoad = true;
            }
            return;
        }
        loading = true;
        var id = ++requestId;
        return safeFetch(API_BASE + '?action=get&user_id=' + userId, { credentials: 'same-origin' })
            .then(function(d) {
                if (id !== requestId || refreshAfterLoad) return;
                if (!Array.isArray(d.messages) || typeof d.revision !== 'string' || !/^[a-f0-9]{64}$/.test(d.revision)) {
                    throw new Error('Gagal memuat pesan: respons tidak valid.');
                }
                var previousTop = container.scrollTop;
                var atBottom = container.scrollHeight - container.scrollTop - container.clientHeight <= 60;
                if (d.messages.length === 0) {
                    container.innerHTML = '<div class="admin-chat-loading">Belum ada pesan. Kirim pesan pertama!</div>';
                } else container.innerHTML = d.messages.map(function(m, idx) {
                    var isAdmin = m.from === 'admin';
                    var deleteBtn = isAdmin ? '<button type="button" class="chat-delete-btn" onclick="deleteChatMsg(' + idx + ')" title="Hapus pesan" aria-label="Hapus pesan"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg></button>' : '';
                    return '<div style="display:flex;gap:10px;margin-bottom:12px;' + (isAdmin ? 'justify-content:flex-end;' : 'justify-content:flex-start;') + '">'
                        + (isAdmin ? '' : '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">U</div>')
                        + '<div class="' + (isAdmin ? 'chat-bubble-admin' : 'chat-bubble-user') + '">'
                        + '<div>' + escapeHtml(m.message) + '</div>'
                        + '<div class="chat-message-meta">'
                        + '<span class="chat-message-time">' + escapeHtml(timeAgo(m.created_at)) + '</span>'
                        + deleteBtn
                        + '</div>'
                        + '</div>'
                        + (isAdmin ? '<div style="width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#f97316,#dc2626);display:flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;flex-shrink:0;">A</div>' : '')
                        + '</div>';
                }).join('');
                revision = d.revision;
                displayedMessages = d.messages;
                container.scrollTop = initialLoad || forceBottom || atBottom ? container.scrollHeight : previousTop;
                initialLoad = false;
                setBusy(busy);
                status('');
            })
            .catch(function(error) {
                if (id === requestId) status('Gagal memuat pesan. ' + error.message);
            }).finally(function() {
                if (id !== requestId) return;
                loading = false;
                if (refreshAfterLoad) {
                    var scroll = scrollAfterLoad;
                    refreshAfterLoad = false;
                    scrollAfterLoad = false;
                    window.loadChatMessages(currentUserId, scroll);
                }
            });
    };
    window.deleteChatMsg = function(index) {
        if (!currentUserId || busy || !revision || !Number.isInteger(index) || !displayedMessages[index] || displayedMessages[index].from !== 'admin') return;
        if (!confirm('Hapus pesan ini?')) return;
        setBusy(true);
        status('Menghapus pesan…');
        var userId = currentUserId;
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('user_id', currentUserId);
        fd.append('index', index);
        fd.append('revision', revision);
        fd.append('csrf_token', window.MEEL_CSRF || '');
        safeFetch(API_BASE, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function() {
            if (currentUserId !== userId) return;
            revision = '';
            status('Pesan dihapus.');
            refreshMessages(false);
        }).catch(function(error) {
            if (currentUserId !== userId) return;
            status('Gagal menghapus pesan. ' + error.message);
            if (error.status === 409) {
                revision = '';
                refreshMessages(false);
            }
        }).finally(function() {
            setBusy(false);
        });
    };
    window.sendChat = function(e) {
        e.preventDefault();
        var input = document.getElementById('chat-input');
        if (!input || busy) return false;
        var draft = input.value;
        var msg = draft.trim();
        if (!msg || !currentUserId) return false;
        if (Array.from(msg).length > 5000) {
            status('Pesan maksimal 5000 karakter.');
            return false;
        }
        var userId = currentUserId;
        setBusy(true);
        status('Mengirim pesan…');
        var fd = new FormData();
        fd.append('action', 'send');
        fd.append('user_id', currentUserId);
        fd.append('message', msg);
        fd.append('csrf_token', window.MEEL_CSRF || '');
        safeFetch(API_BASE, {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }).then(function() {
            if (currentUserId !== userId) return;
            if (input.value === draft) input.value = '';
            status('Pesan terkirim.');
            refreshMessages(true);
        }).catch(function(error) {
            if (currentUserId === userId) status('Gagal mengirim pesan. ' + error.message);
        }).finally(function() {
            setBusy(false);
        });
        return false;
    };
    var escapeHtml = window.escapeHtml;
    var timeAgo = window.timeAgo;
})();
