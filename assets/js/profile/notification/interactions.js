(function() {
    var root = document.getElementById('notif-data');
    if (!root) return;
    var API_ROOT = root.dataset.apiRoot;
    var csrfToken = root.dataset.csrfToken;

    function announce(msg) {
        var el = document.getElementById('notif-live');
        if (el) el.textContent = msg;
    }

    function refreshEmptyState() {
        var list = document.getElementById('notif-list');
        if (list && list.children.length === 0) {
            var st = document.createElement('div');
            st.className = 'empty-state';
            st.innerHTML = '<div class="text-3xl mb-3 opacity-30" aria-hidden="true">\uD83D\uDD14</div>'
                + '<p class="text-sm font-medium" style="color:var(--meel-text-secondary)">Tidak ada notifikasi</p>'
                + '<p class="text-[11px] mt-1" style="color:var(--meel-text-secondary)">Notifikasi akan muncul di sini saat ada aktivitas terkait kamu</p>';
            list.replaceWith(st);
            var delAll = document.querySelector('button[aria-label="Hapus semua notifikasi"]');
            if (delAll) delAll.remove();
            announce('Semua notifikasi telah dihapus');
        }
    }

    window.deleteNotif = function(id, btn) {
        var fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', id);
        fd.append('csrf_token', csrfToken);
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
    };

    window.deleteAllNotif = function() {
        if (!confirm('Hapus semua notifikasi?')) return;
        var fd = new FormData();
        fd.append('action', 'delete_all');
        fd.append('csrf_token', csrfToken);
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
    };
})();
