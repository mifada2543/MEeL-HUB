(function () {
  "use strict";

  var lyricsData = [];
  var currentLangIdx = 0;
  var activeLineIdx = -1;
  var modalEl = null;
  var scrollEl = null;
  var lineEls = [];

  function getAudio() {
    var eng = window.meelGetAudioEngine ? window.meelGetAudioEngine() : null;
    return eng ? eng.audio : null;
  }

  function buildModal() {
    if (modalEl) return;
    modalEl = document.getElementById("karaoke-modal");
    scrollEl = document.getElementById("karaoke-scroll");
    if (!modalEl || !scrollEl) return;

    var closeBtn = modalEl.querySelector(".karaoke-close-btn");
    if (closeBtn) closeBtn.addEventListener("click", closeKaraoke);

    var backdrop = modalEl;
    if (backdrop) {
      backdrop.addEventListener("click", function (e) {
        if (e.target === backdrop) closeKaraoke();
      });
    }
  }

  function renderLines(langIdx) {
    if (!scrollEl) return;
    currentLangIdx = langIdx;
    activeLineIdx = -1;
    lineEls = [];

    var data = lyricsData[langIdx];
    if (!data || !data.lines || data.lines.length === 0) {
      scrollEl.innerHTML = '<div class="karaoke-empty"><i data-lucide="file-text" style="width:32px;height:32px;display:inline-block;"></i><div>Tidak ada lirik tersedia</div></div>';
      if (typeof lucide !== "undefined") lucide.createIcons({}, scrollEl);
      return;
    }

    var html = "";
    for (var i = 0; i < data.lines.length; i++) {
      var text = data.lines[i].text || "";
      if (text === "") text = "\u00A0";
      html += '<div class="karaoke-line future" data-idx="' + i + '">' + escapeHtml(text) + '</div>';
    }
    scrollEl.innerHTML = html;

    lineEls = scrollEl.querySelectorAll(".karaoke-line");
    for (var j = 0; j < lineEls.length; j++) {
      lineEls[j].addEventListener("click", onLineClick);
    }
  }

  function escapeHtml(str) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
  }

  function onLineClick(e) {
    var idx = parseInt(e.currentTarget.getAttribute("data-idx"), 10);
    if (isNaN(idx)) return;
    var data = lyricsData[currentLangIdx];
    if (!data || !data.lines[idx]) return;
    var audio = getAudio();
    if (audio) {
      audio.currentTime = data.lines[idx].time;
      if (audio.paused) audio.play().catch(function () {});
    }
  }

  function syncLyrics() {
    var audio = getAudio();
    if (!audio || !lyricsData.length || !lineEls.length) return;

    var currentTime = audio.currentTime;
    var data = lyricsData[currentLangIdx];
    if (!data || !data.lines) return;

    var newIdx = -1;
    for (var i = data.lines.length - 1; i >= 0; i--) {
      if (currentTime >= data.lines[i].time - 0.15) {
        newIdx = i;
        break;
      }
    }

    if (newIdx === activeLineIdx) return;
    activeLineIdx = newIdx;

    for (var j = 0; j < lineEls.length; j++) {
      var el = lineEls[j];
      el.classList.remove("active", "past", "future");
      if (j < newIdx) {
        el.classList.add("past");
      } else if (j === newIdx) {
        el.classList.add("active");
      } else {
        el.classList.add("future");
      }
    }

    if (newIdx >= 0 && lineEls[newIdx] && scrollEl) {
      var container = scrollEl;
      var elActive = lineEls[newIdx];
      var offsetTop = elActive.offsetTop - container.offsetTop;
      var scrollTop = container.scrollTop;
      var containerHeight = container.clientHeight;
      var targetScroll = offsetTop - containerHeight / 2 + elActive.clientHeight / 2;
      container.scrollTo({ top: targetScroll, behavior: "smooth" });
    }
  }

  function renderLangTabs() {
    var tabsContainer = document.getElementById("karaoke-lang-tabs");
    if (!tabsContainer) return;
    if (lyricsData.length <= 1) {
      tabsContainer.style.display = "none";
      return;
    }
    tabsContainer.style.display = "flex";
    var html = "";
    for (var i = 0; i < lyricsData.length; i++) {
      var active = i === currentLangIdx ? " active" : "";
      html += '<button class="karaoke-lang-tab' + active + '" data-lang-idx="' + i + '">' + escapeHtml(lyricsData[i].label || lyricsData[i].lang) + '</button>';
    }
    tabsContainer.innerHTML = html;
    var tabs = tabsContainer.querySelectorAll(".karaoke-lang-tab");
    for (var j = 0; j < tabs.length; j++) {
      tabs[j].addEventListener("click", function (e) {
        var idx = parseInt(e.currentTarget.getAttribute("data-lang-idx"), 10);
        renderLangTabs();
        renderLines(idx);
      });
    }
  }

  window.openKaraoke = function () {
    buildModal();
    if (!modalEl) return;
    if (!lyricsData.length) return;
    modalEl.classList.remove("hidden");
    renderLangTabs();
    renderLines(currentLangIdx);
    var audio = getAudio();
    if (audio) syncLyrics();
    document.body.style.overflow = "hidden";
  };

  window.closeKaraoke = function () {
    if (modalEl) modalEl.classList.add("hidden");
    document.body.style.overflow = "";
  };

  window.toggleKaraoke = function () {
    if (!modalEl) buildModal();
    if (!modalEl) return;
    if (modalEl.classList.contains("hidden")) {
      window.openKaraoke();
    } else {
      window.closeKaraoke();
    }
  };

  window.setKaraokeLyrics = function (lyrics) {
    lyricsData = lyrics || [];
    currentLangIdx = 0;
    activeLineIdx = -1;
    var triggerBtn = document.getElementById("btn-karaoke");
    if (triggerBtn) {
      triggerBtn.style.display = lyricsData.length > 0 ? "" : "none";
    }
  };

  function timeupdateHandler() {
    if (modalEl && !modalEl.classList.contains("hidden")) {
      syncLyrics();
    }
  }

  function initKaraokeSync() {
    var audio = getAudio();
    if (!audio || audio.__meelKaraokeBound) return;
    audio.__meelKaraokeBound = true;
    audio.addEventListener("timeupdate", timeupdateHandler);
  }

  var origInit = window.meelInitWatchPlayer;
  window.meelInitWatchPlayer = function () {
    if (typeof origInit === "function") origInit();
    buildModal();
    initKaraokeSync();
    var config = window.MEEL_MUSIC_CONFIG;
    if (config && config.lyrics) {
      window.setKaraokeLyrics(config.lyrics);
    }
  };

  if (document.readyState !== "loading") {
    buildModal();
    initKaraokeSync();
    var config = window.MEEL_MUSIC_CONFIG;
    if (config && config.lyrics) {
      window.setKaraokeLyrics(config.lyrics);
    }
  } else {
    document.addEventListener("DOMContentLoaded", function () {
      buildModal();
      initKaraokeSync();
      var config = window.MEEL_MUSIC_CONFIG;
      if (config && config.lyrics) {
        window.setKaraokeLyrics(config.lyrics);
      }
    });
  }

  document.addEventListener("keydown", function (e) {
    if (window.meelKeyShortcutIgnored && window.meelKeyShortcutIgnored(e)) return;
    if (window.__meelCurrentView !== "watch") return;
    if (e.key.toLowerCase() === "k") {
      e.preventDefault();
      e.stopPropagation();
      window.toggleKaraoke();
    }
  });
})();

/* reference build: MEeL-KARAOKE-v1 */
