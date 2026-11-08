(function () {
  'use strict';
  function byId(id) {
    return document.getElementById(id);
  }
  function formatMS(sec) {
    var s = Math.max(0, Math.floor(Number(sec) || 0));
    var m = Math.floor(s / 60);
    var r = s % 60;
    return m + ':' + (r < 10 ? '0' + String(r) : String(r));
  }
  function cfg() {
    return window.playerConfig || window.MEEL_MUSIC_CONFIG || {};
  }
  function isMusic() {
    return !window.playerConfig && !!window.MEEL_MUSIC_CONFIG;
  }
  function videoId() {
    var id = cfg().id;
    return id ? String(id) : '';
  }
  function videoTitle() {
    return cfg().title || document.title || '';
  }
  function videoUploader() {
    return cfg().uploader || cfg().artist || '';
  }
  function getMediaEl() {
    return byId('main-video') || byId('meel-audio-engine') || document.querySelector('audio, video');
  }
  function mediaDuration() {
    var v = getMediaEl();
    if (!v || !isFinite(v.duration) || v.duration <= 0) return 0;
    return Math.floor(v.duration);
  }
  function parseTimeInput(str) {
    if (str == null) return null;
    var s = String(str).trim();
    if (s === '') return null;
    var parts = s.split(':');
    if (parts.length > 3) return null;
    var total = 0;
    for (var i = 0; i < parts.length; i++) {
      if (!/^\d+$/.test(parts[i].trim())) return null;
      total = total * 60 + parseInt(parts[i], 10);
    }
    return total;
  }
  function clampTime(sec) {
    var dur = mediaDuration();
    var v = Math.max(0, Math.floor(sec || 0));
    if (dur > 0) v = Math.min(v, dur);
    return v;
  }
  function shareTime() {
    var input = byId('meel-share-t-time');
    var raw = input ? input.value : '';
    var parsed = parseTimeInput(raw);
    if (parsed == null) parsed = currentSec();
    return clampTime(parsed);
  }
  function currentSec() {
    var v = getMediaEl();
    if (!v || !isFinite(v.currentTime)) return 0;
    return Math.max(0, Math.floor(v.currentTime));
  }
  function buildUrl() {
    var origin = (window.location && window.location.origin) ? window.location.origin : '';
    var base = origin + (window.MEEL_BASE || '');
    var id = videoId();
    var url = base + (isMusic() ? '/music/watch?v=' : '/video/watch?v=') + encodeURIComponent(id);
    var chk = byId('meel-share-t');
    if (chk && chk.checked) {
      url += '&t=' + shareTime();
    }
    return url;
  }
  function shareLinks(url, title) {
    var u = encodeURIComponent(url);
    var t = encodeURIComponent(title);
    return {
      whatsapp: 'https://wa.me/?text=' + encodeURIComponent(title + ' ' + url),
      twitter: 'https://twitter.com/intent/tweet?text=' + t + '&url=' + u,
      facebook: 'https://www.facebook.com/sharer/sharer.php?u=' + u,
      reddit: 'https://www.reddit.com/submit?url=' + u + '&title=' + t,
      telegram: 'https://t.me/share/url?url=' + u + '&text=' + t
    };
  }
  function syncUi() {
    var input = byId('meel-share-url');
    var timeEl = byId('meel-share-t-time');
    var chkEl = byId('meel-share-t');
    if (timeEl && 'value' in timeEl) {
      timeEl.disabled = !(chkEl && chkEl.checked);
      var current = parseTimeInput(timeEl.value);
      if (current == null) timeEl.value = formatMS(currentSec());
    }
    var url = buildUrl();
    if (input) input.value = url;
    var title = videoTitle();
    var links = shareLinks(url, title);
    var anchors = document.querySelectorAll('.meel-share-soc');
    for (var i = 0; i < anchors.length; i++) {
      var a = anchors[i];
      var net = a.getAttribute('data-net');
      if (net && links[net]) a.href = links[net];
    }
    var modal = byId('meel-share-modal');
    if (modal) {
      var up = videoUploader();
      var pfp = cfg().uploaderPfp || '';
      var avatarImg = byId('meel-share-avatar-img');
      var fallbackSrc = (window.MEEL_BASE || '') ? (window.MEEL_BASE + '/profile/upload/default_avatar.png') : '../profile/upload/default_avatar.png';
      var nextSrc = pfp ? pfp : fallbackSrc;
      if (avatarImg) {
        if (avatarImg.getAttribute('src') !== nextSrc) avatarImg.src = nextSrc;
        avatarImg.style.display = '';
      }
      var nameEl = modal.querySelector('.meel-share-uploader-name');
      if (nameEl && up) nameEl.textContent = up;
      var thumb = byId('meel-share-thumb');
      var sign = cfg().thumbnailUrl || cfg().thumbnail || '';
      if (thumb && sign && thumb.getAttribute('src') !== sign) thumb.src = sign;
      var titleEl = modal.querySelector('.meel-share-title-text');
      if (titleEl) titleEl.textContent = title || '';
    }
    return url;
  }
  var lastFocus = null;
  var toastTimer = null;
  var copyTimer = null;
  function openModal() {
    var modal = byId('meel-share-modal');
    if (!modal) return;
    lastFocus = document.activeElement;
    syncUi();
    modal.classList.remove('hidden');
    try {
      var first = byId('meel-share-close') || byId('meel-share-url');
      if (first) first.focus({ preventScroll: true });
    } catch (e) {
      try {
        var c = byId('meel-share-close');
        if (c) c.focus();
      } catch (_) {}
    }
  }
  function closeModal() {
    var modal = byId('meel-share-modal');
    if (!modal) return;
    modal.classList.add('hidden');
    try {
      if (lastFocus && document.contains(lastFocus)) {
        lastFocus.focus({ preventScroll: true });
      } else {
        var btn = byId('meel-share-btn');
        if (btn) btn.focus({ preventScroll: true });
      }
    } catch (e) {
      try {
        var b = byId('meel-share-btn');
        if (b) b.focus();
      } catch (_) {}
    }
  }
  function fallbackCopy(text, input) {
    try {
      if (input) {
        input.focus({ preventScroll: true });
        input.select();
      }
      return !!(document.execCommand && document.execCommand('copy'));
    } catch (e) {
      return false;
    }
  }
  function copyText(text, input) {
    if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
      return navigator.clipboard.writeText(text).catch(function () {
        return fallbackCopy(text, input);
      });
    }
    return Promise.resolve(fallbackCopy(text, input));
  }
  function showFeedback() {
    var toast = byId('meel-share-toast');
    var btn = byId('meel-share-copy');
    if (toast) {
      toast.textContent = 'Disalin!';
      toast.classList.add('show');
    }
    if (btn) {
      btn.textContent = 'Disalin!';
    }
    if (copyTimer) clearTimeout(copyTimer);
    if (toastTimer) clearTimeout(toastTimer);
    copyTimer = setTimeout(function () {
      if (btn) btn.textContent = 'Salin';
    }, 1500);
    toastTimer = setTimeout(function () {
      if (toast) {
        toast.textContent = '';
        toast.classList.remove('show');
      }
    }, 2000);
  }
  function onCopy() {
    var input = byId('meel-share-url');
    var text = input ? input.value : '';
    if (!text) text = buildUrl();
    if (!text) return;
    copyText(text, input).then(function () { showFeedback(); }, function () { showFeedback(); });
  }
  function openShareOnClick(e) {
    if (e.target && typeof e.target.closest === 'function') {
      if (e.target.closest('#meel-share-btn')) {
        e.preventDefault();
        openModal();
      }
    }
  }
  function controlOnClick(e) {
    var t = (e.target && typeof e.target.closest === 'function') ? e.target : null;
    if (!t) return;
    var closeBtn = t.closest('#meel-share-close');
    if (closeBtn) {
      closeModal();
      return;
    }
    var copyBtn = t.closest('#meel-share-copy');
    if (copyBtn) {
      onCopy();
      return;
    }
    var soc = t.closest('.meel-share-soc');
    if (soc) {
      e.preventDefault();
      syncUi();
      var href = soc.href || '';
      if (!href || href.indexOf('wa.me') === -1 && href.indexOf('twitter.com') === -1 && href.indexOf('facebook.com') === -1 && href.indexOf('reddit.com') === -1 && href.indexOf('t.me') === -1) {
        var url = buildUrl();
        var links = shareLinks(url, videoTitle());
        var net = soc.getAttribute('data-net');
        href = (net && links[net]) ? links[net] : url;
      }
      try { window.open(href, '_blank', 'noopener,width=600,height=640'); } catch (err) {}
      return;
    }
    var modal = byId('meel-share-modal');
    if (modal && e.target === modal) closeModal();
  }
  function normalizeTimeInput(timeInput) {
    if (!timeInput) return;
    var parsed = parseTimeInput(timeInput.value);
    if (parsed == null) parsed = currentSec();
    timeInput.value = formatMS(clampTime(parsed));
    syncUi();
  }
  function init() {
    var modal = byId('meel-share-modal');
    document.addEventListener('click', openShareOnClick);
    document.addEventListener('click', controlOnClick);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var m = byId('meel-share-modal');
        if (m && !m.classList.contains('hidden')) closeModal();
      }
      if (e.key === 'Enter' && e.target && e.target.id === 'meel-share-t-time') {
        e.preventDefault();
        normalizeTimeInput(e.target);
      }
    });
    document.addEventListener('change', function (e) {
      if (e.target && e.target.id === 'meel-share-t') syncUi();
    });
    document.addEventListener('input', function (e) {
      if (e.target && e.target.id === 'meel-share-t-time') syncUi();
      if (e.target && e.target.id === 'meel-share-url') {
        try { e.target.select(); } catch (_) {}
      }
    });
    document.addEventListener('focusout', function (e) {
      if (e.target && e.target.id === 'meel-share-t-time') {
        normalizeTimeInput(e.target);
      }
    });
    try { syncUi(); } catch (e) {}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
