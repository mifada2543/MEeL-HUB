(function () {
  "use strict";

  var currentMode = "simple";
  var audioEl = null;
  var activeLineIdx = -1;
  var syncedLines = [];
  var isPlaying = false;

  var LS_KEY = "meel_lrc_editor_" + (typeof MUSIC_ID !== "undefined" ? MUSIC_ID : 0);

  function formatTime(sec) {
    if (isNaN(sec) || sec < 0) sec = 0;
    var m = Math.floor(sec / 60);
    var s = Math.floor(sec % 60);
    var ms = Math.floor((sec - Math.floor(sec)) * 100);
    return (m < 10 ? "0" : "") + m + ":" + (s < 10 ? "0" : "") + s + "." + (ms < 10 ? "0" : "") + ms;
  }

  function saveToStorage() {
    try {
      localStorage.setItem(LS_KEY, JSON.stringify(syncedLines));
    } catch (e) {}
  }

  function loadFromStorage() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (raw) {
        var data = JSON.parse(raw);
        if (Array.isArray(data) && data.length > 0) return data;
      }
    } catch (e) {}
    return null;
  }

  window.switchMode = function (mode) {
    if (mode !== currentMode) {
      if (currentMode === "synced") {
        syncFromInputs();
        saveToStorage();
        var textarea = document.getElementById("lyrics-textarea");
        if (textarea && syncedLines.length > 0) {
          textarea.value = syncedLines.map(function (l) {
            if (l.time > 0) return "[" + formatTime(l.time) + "] " + l.text;
            return l.text;
          }).join("\n");
        }
      }
    }
    currentMode = mode;
    document.querySelectorAll(".editor-mode-tab").forEach(function (tab) {
      tab.classList.toggle("active", tab.getAttribute("data-mode") === mode);
    });
    document.getElementById("mode-simple").style.display = mode === "simple" ? "flex" : "none";
    document.getElementById("mode-synced").style.display = mode === "synced" ? "flex" : "none";
    document.getElementById("editor-player-bar").style.display = mode === "synced" ? "" : "none";

    var url = new URL(window.location);
    url.searchParams.set("tab", mode === "synced" ? "disinkronkan" : "sederhana");
    history.replaceState(null, "", url);

    if (mode === "synced") {
      loadFromSimple();
      initPlayer();
    }
  };

  function loadFromSimple() {
    var textarea = document.getElementById("lyrics-textarea");
    if (!textarea) return;
    var text = textarea.value.trim();
    if (text === "") {
      syncedLines = [];
      saveToStorage();
      renderSyncedLines();
      return;
    }
    var lines = text.split("\n");
    var oldLines = syncedLines.slice();
    var oldByText = {};
    for (var j = 0; j < oldLines.length; j++) {
      var key = oldLines[j].text.trim();
      if (key && !oldByText[key]) oldByText[key] = oldLines[j].time;
    }
    syncedLines = [];
    for (var i = 0; i < lines.length; i++) {
      var raw = lines[i].trim();
      var timeMatch = raw.match(/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,2}))?\]\s*(.*)$/);
      var t, txt;
      if (timeMatch) {
        t = parseInt(timeMatch[1]) * 60 + parseInt(timeMatch[2]);
        if (timeMatch[3]) t += parseInt(timeMatch[3].padEnd(2, "0")) / 100;
        txt = timeMatch[4].trim();
      } else {
        t = oldByText[raw] || 0;
        txt = raw;
      }
      syncedLines.push({ time: t, text: txt });
    }
    saveToStorage();
    renderSyncedLines();
  }

  window.addSyncedLine = function () {
    syncedLines.push({ time: 0, text: "" });
    saveToStorage();
    renderSyncedLines();
    var container = document.getElementById("synced-lines-container");
    if (container) {
      var rows = container.querySelectorAll(".synced-line-row");
      if (rows.length > 0) {
        var lastInput = rows[rows.length - 1].querySelector(".synced-text-input");
        if (lastInput) lastInput.focus();
      }
    }
  };

  window.removeSyncedLine = function (idx) {
    syncedLines.splice(idx, 1);
    saveToStorage();
    renderSyncedLines();
  };

  window.markLineTime = function (idx) {
    if (!audioEl) return;
    syncedLines[idx].time = audioEl.currentTime;
    saveToStorage();
    renderSyncedLines();
  };

  function renderSyncedLines() {
    var container = document.getElementById("synced-lines-container");
    if (!container) return;
    var html = "";
    for (var i = 0; i < syncedLines.length; i++) {
      var line = syncedLines[i];
      var timeStr = formatTime(line.time);
      var hasTime = line.time > 0;
      var activeClass = i === activeLineIdx ? " active-line" : "";
      html += '<div class="synced-line-row' + activeClass + '" data-idx="' + i + '">';
      html += '  <div class="synced-time-col">';
      html += '    <div class="synced-time-badge' + (hasTime ? " has-time" : "") + '" onclick="markLineTime(' + i + ')" title="Klik untuk tandai waktu">';
      html += '      <i data-lucide="clock" style="width:11px;height:11px;"></i>';
      html += '      <span>' + timeStr + '</span>';
      html += '    </div>';
      html += '  </div>';
      html += '  <div class="synced-text-col">';
      html += '    <input type="text" class="synced-text-input" value="' + escapeAttr(line.text) + '" placeholder="Teks lirik..." onchange="updateLineText(' + i + ', this.value)">';
      html += '  </div>';
      html += '  <div class="synced-actions-col">';
      html += '    <button type="button" class="synced-action-btn delete-btn" onclick="removeSyncedLine(' + i + ')" title="Hapus baris">';
      html += '      <i data-lucide="trash-2" style="width:12px;height:12px;"></i>';
      html += '    </button>';
      html += '  </div>';
      html += '</div>';
    }
    container.innerHTML = html;
    if (typeof lucide !== "undefined") lucide.createIcons({}, container);
  }

  window.updateLineText = function (idx, val) {
    if (syncedLines[idx]) {
      syncedLines[idx].text = val;
      saveToStorage();
    }
  };

  function escapeAttr(s) {
    return s.replace(/&/g, "&amp;").replace(/"/g, "&quot;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
  }

  window.beforeSubmit = function () {
    if (currentMode === "synced") {
      syncFromInputs();
      saveToStorage();
      var textarea = document.getElementById("lyrics-textarea");
      if (textarea) {
        textarea.value = syncedLines.map(function (l) {
          if (l.time > 0) return "[" + formatTime(l.time) + "] " + l.text;
          return l.text;
        }).join("\n");
      }
    }
    return true;
  };

  function syncFromInputs() {
    var container = document.getElementById("synced-lines-container");
    if (!container) return;
    var rows = container.querySelectorAll(".synced-line-row");
    for (var i = 0; i < rows.length; i++) {
      var input = rows[i].querySelector(".synced-text-input");
      if (input && syncedLines[i]) {
        syncedLines[i].text = input.value;
      }
    }
  }

  /* Audio Player Controls */
  function initPlayer() {
    if (audioEl) return;
    audioEl = new Audio();
    audioEl.preload = "metadata";
    audioEl.src = STREAM_URL;

    audioEl.addEventListener("loadedmetadata", function () {
      updateSeekBar();
    });
    audioEl.addEventListener("timeupdate", function () {
      updateSeekBar();
      highlightActiveLine();
      var ct = document.getElementById("editor-current-time");
      if (ct) ct.textContent = formatTime(audioEl.currentTime);
    });
    audioEl.addEventListener("ended", function () {
      isPlaying = false;
      updatePlayBtn();
    });
  }

  window.editorPlayPause = function () {
    initPlayer();
    if (audioEl.paused) {
      audioEl.play();
      isPlaying = true;
    } else {
      audioEl.pause();
      isPlaying = false;
    }
    updatePlayBtn();
  };

  window.editorSkipBack = function () {
    initPlayer();
    audioEl.currentTime = Math.max(0, audioEl.currentTime - 5);
  };

  window.editorSkipForward = function () {
    initPlayer();
    audioEl.currentTime = Math.min(audioEl.duration || 0, audioEl.currentTime + 5);
  };

  window.editorSeek = function (val) {
    initPlayer();
    if (audioEl.duration) {
      audioEl.currentTime = (val / 1000) * audioEl.duration;
    }
  };

  function updatePlayBtn() {
    var btn = document.getElementById("editor-play-btn");
    if (!btn) return;
    btn.innerHTML = isPlaying
      ? '<i data-lucide="pause" style="width:20px;height:20px;"></i>'
      : '<i data-lucide="play" style="width:20px;height:20px;"></i>';
    if (typeof lucide !== "undefined") lucide.createIcons({}, btn);
  }

  function updateSeekBar() {
    var seekbar = document.getElementById("editor-seekbar");
    if (!seekbar || !audioEl || !audioEl.duration) return;
    var pct = (audioEl.currentTime / audioEl.duration) * 100;
    seekbar.value = Math.floor(pct * 10);
    var trackColor = document.documentElement.getAttribute("data-theme") === "light"
      ? "rgba(0,0,0,0.10)" : "rgba(255,255,255,0.1)";
    seekbar.style.background = "linear-gradient(to right, #f97316 " + pct + "%, " + trackColor + " " + pct + "%)";
  }

  function highlightActiveLine() {
    if (currentMode !== "synced" || !audioEl) return;
    var ct = audioEl.currentTime;
    var newIdx = -1;
    for (var i = syncedLines.length - 1; i >= 0; i--) {
      if (syncedLines[i].time > 0 && ct >= syncedLines[i].time - 0.15) {
        newIdx = i;
        break;
      }
    }
    if (newIdx !== activeLineIdx) {
      activeLineIdx = newIdx;
      var container = document.getElementById("synced-lines-container");
      if (container) {
        var rows = container.querySelectorAll(".synced-line-row");
        rows.forEach(function (row, idx) {
          row.classList.toggle("active-line", idx === newIdx);
        });
        if (newIdx >= 0 && rows[newIdx]) {
          rows[newIdx].scrollIntoView({ behavior: "smooth", block: "center" });
        }
      }
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    var stored = loadFromStorage();
    if (stored && stored.length > 0) {
      syncedLines = stored;
    } else if (EXISTING_LINES && EXISTING_LINES.length > 0) {
      syncedLines = EXISTING_LINES.map(function (l) {
        return { time: l.time || 0, text: l.text || "" };
      });
      saveToStorage();
    }

    var urlTab = new URLSearchParams(window.location.search).get("tab");
    if (urlTab === "disinkronkan") {
      switchMode("synced");
    }
  });

  document.addEventListener("keydown", function (e) {
    if (e.target.tagName === "INPUT" || e.target.tagName === "TEXTAREA") return;
    if (currentMode !== "synced") return;
    if (e.code === "Space") {
      e.preventDefault();
      editorPlayPause();
    } else if (e.key === "ArrowLeft") {
      e.preventDefault();
      editorSkipBack();
    } else if (e.key === "ArrowRight") {
      e.preventDefault();
      editorSkipForward();
    }
  });
})();

/* reference build: MEeL-KARAOKE-v1 */
