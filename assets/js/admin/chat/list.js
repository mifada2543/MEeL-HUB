(function () {
  var el = document.getElementById('admin-chat-data');
  if (!el) return;
  var API_BASE = el.dataset.apiBase;
  var ADMIN_CHAT_ROOT = el.dataset.chatRoot;
  var escapeHtml = window.escapeHtml;
  var timeAgo = window.timeAgo;
  var searchTimer = null;
  var searchController = null;
  var searchSequence = 0;
  var recentLoading = false;

  function request(url, signal) {
    return fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: signal })
      .then(function (response) {
        if ((response.headers.get('content-type') || '').indexOf('json') === -1) {
          throw new Error('Respons tidak valid');
        }
        return response.json().then(function (data) {
          if (!response.ok || !data || data.ok !== true || !Array.isArray(data.list)) {
            throw new Error('Gagal memuat daftar');
          }
          return data.list;
        });
      });
  }

  function notice(container, text) {
    container.innerHTML = '<div class="admin-chat-loading" role="status" aria-live="polite">' + escapeHtml(text) + '</div>';
  }

  function card(user, recent) {
    var username = String(user.username || '');
    return '<a href="' + escapeHtml(ADMIN_CHAT_ROOT + '/' + encodeURIComponent(username)) + '" class="glass chat-user-card">'
      + '<div class="chat-avatar" style="background:linear-gradient(135deg,#f97316,#dc2626);">'
      + escapeHtml(username.charAt(0).toUpperCase()) + '</div>'
      + '<div style="min-width:0;flex:1;"><div class="chat-user-name">@'
      + escapeHtml(username) + '</div>'
      + '<div class="chat-user-preview">'
      + escapeHtml(recent ? user.last_message || '' : user.role || '') + '</div></div>'
      + (recent ? '<div class="chat-user-time">' + escapeHtml(timeAgo(user.last_at)) + '</div>' : '')
      + '</a>';
  }

  window.searchUsers = function (q) {
    var container = document.getElementById('user-results');
    if (!container) return;
    var sequence = ++searchSequence;
    clearTimeout(searchTimer);
    if (searchController) searchController.abort();
    q = String(q).trim();
    if (!q) {
      container.innerHTML = '';
      return;
    }
    notice(container, 'Mencari pengguna…');
    searchTimer = setTimeout(function () {
      searchController = new AbortController();
      request(API_BASE + '?action=users&q=' + encodeURIComponent(q), searchController.signal)
        .then(function (list) {
          if (sequence !== searchSequence) return;
          if (!list.length) notice(container, 'Pengguna tidak ditemukan.');
          else container.innerHTML = list.map(function (user) { return card(user, false); }).join('');
        })
        .catch(function (error) {
          if (sequence === searchSequence && error.name !== 'AbortError') notice(container, 'Gagal mencari pengguna. Coba lagi.');
        });
    }, 300);
  };

  function loadRecent() {
    var container = document.getElementById('recent-list');
    if (!container || recentLoading) return;
    recentLoading = true;
    request(API_BASE + '?action=recent')
      .then(function (list) {
        if (!list.length) notice(container, 'Belum ada percakapan');
        else container.innerHTML = list.map(function (user) { return card(user, true); }).join('');
      })
      .catch(function () { notice(container, 'Gagal memuat percakapan. Mencoba lagi otomatis.'); })
      .finally(function () { recentLoading = false; });
  }
  loadRecent();
  setInterval(loadRecent, 15000);
})();
