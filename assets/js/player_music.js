// ========================================
// MEeL MUSIC PLAYER
// ========================================

// --- GLOBAL STATE ---
let player, audio, storageKeyMusic;
let isFinished = false;
let isMiniPlayerActive = false;
let watchUrl;
let skipResumeModalOnce = false;
let eqFilters = [];
let eqBands = [60, 170, 350, 1000, 3500, 10000];
let eqGains = Array(eqBands.length).fill(0);
let eqEnabled = false;
let eqPreset = "flat";

// ─── HELPERS ────────────────────────────────────────────────────────────────

function formatTime(seconds) {
  if (!seconds || isNaN(seconds)) return "0:00";
  const m = Math.floor(seconds / 60);
  const s = Math.floor(seconds % 60);
  return `${m}:${String(s).padStart(2, "0")}`;
}

/** Apply loop state to all UI elements without touching player */
function _applyLoopUI(isLoop) {
  const btnLoop = document.getElementById("btn-loop");
  const loopText = document.getElementById("loop-text");
  const miniLoopBtn = document.getElementById("mini-loop-btn");

  if (btnLoop) {
    btnLoop.classList.toggle("bg-gray-800", !isLoop);
    btnLoop.classList.toggle("text-gray-400", !isLoop);
    btnLoop.classList.toggle("bg-orange-500/10", isLoop);
    btnLoop.classList.toggle("text-orange-500", isLoop);
    btnLoop.classList.toggle("border", isLoop);
    btnLoop.classList.toggle("border-orange-500/30", isLoop);
  }
  if (loopText) loopText.innerText = isLoop ? "Loop On" : "Loop Off";
  if (miniLoopBtn) {
    miniLoopBtn.style.color = isLoop ? "#f97316" : "";
    miniLoopBtn.style.opacity = isLoop ? "1" : "0.5";
  }
}

function updateLoopUI() {
  const isLoop = player
    ? player.loop
    : localStorage.getItem("meel_global_loop") === "true";
  _applyLoopUI(isLoop);
}

function saveAudioState() {
  if (!window.MEEL_MUSIC_CONFIG) return;
  const cfg = window.MEEL_MUSIC_CONFIG;
  const plId = cfg.playlistId || 0;
  sessionStorage.setItem(
    "meel_audio_state",
    JSON.stringify({
      id: cfg.id,
      musicId: cfg.id,
      playlistId: plId,
      watchUrl: `watch.php?id=${cfg.id}`,
      currentTime: player ? player.currentTime : 0,
      isPlaying: player ? !player.paused : false,
      isLooping: player ? player.loop : false,
      title: cfg.title,
      artist: cfg.artist,
      thumbnail: cfg.thumbnail,
      thumbnailUrl: cfg.thumbnailUrl || "",
      filename: cfg.filename,
    }),
  );
  if (plId > 0) {
    localStorage.setItem("meel_last_playlist_id", String(plId));
  } else {
    localStorage.removeItem("meel_last_playlist_id");
  }
}

// ─── GLOBAL API ─────────────────────────────────────────────────────────────

window.toggleLoop = function () {
  const newLoop = !(localStorage.getItem("meel_global_loop") === "true");
  localStorage.setItem("meel_global_loop", String(newLoop));
  if (player) {
    player.loop = newLoop;
    saveAudioState();
  }
  updateLoopUI();
};

window.toggleVisualizer = function () {
  /* redefined in DOMContentLoaded */
};

function normalizeEqValue(value) {
  const num = Number(value);
  if (!Number.isFinite(num)) return 0;
  return Math.max(-12, Math.min(12, num));
}

function saveEqState() {
  try {
    localStorage.setItem(
      "meel_music_eq_state",
      JSON.stringify({
        enabled: eqEnabled,
        preset: eqPreset,
        gains: eqGains,
      }),
    );
  } catch (e) {
    console.warn("⚠️ Could not save EQ state:", e);
  }
}

function loadEqState() {
  try {
    const raw = localStorage.getItem("meel_music_eq_state");
    if (!raw) return;
    const state = JSON.parse(raw);
    if (state && Array.isArray(state.gains)) {
      eqGains = state.gains.map(normalizeEqValue);
    }
    if (typeof state?.enabled === "boolean") eqEnabled = state.enabled;
    if (typeof state?.preset === "string") eqPreset = state.preset;
  } catch (e) {
    console.warn("⚠️ Bad EQ state:", e);
  }
}

function applyEqToFilters() {
  if (!eqFilters.length) return;
  const gains = eqEnabled ? eqGains : Array(eqBands.length).fill(0);
  eqFilters.forEach((filter, index) => {
    filter.gain.value = normalizeEqValue(gains[index] ?? 0);
  });
}

function getRealtimeVbrValue(frequencyData) {
  if (!frequencyData || !frequencyData.length) return 160;
  const sum = frequencyData.reduce((acc, value) => acc + value, 0);
  const avg = sum / frequencyData.length;
  const smooth = Math.min(1, Math.max(0, avg / 255));
  const minKbps = 96;
  const maxKbps = 320;
  return Math.round(minKbps + smooth * (maxKbps - minKbps));
}

function updateBitrateLabel(value, element) {
  if (!element) return;
  element.innerText = `${value}`;
}

function updateBarColors(value, barElements) {
  if (!barElements || !barElements.length) return;
  const hue = Math.round(28 + ((value - 96) / 224) * 180);
  barElements.forEach((bar) => {
    bar.style.background = `linear-gradient(to top, hsl(${hue}, 96%, 50%), hsl(${Math.min(360, hue + 40)}, 96%, 72%))`;
  });
}

function updateEqUI() {
  const btnEq = document.getElementById("btn-eq");
  const eqText = document.getElementById("eq-text");
  const eqPanel = document.getElementById("eq-panel");
  const eqContainer = document.getElementById("eq-container");
  const presetSelect = document.getElementById("eq-preset");
  const presetButton = document.getElementById("eq-preset-button");
  const presetLabel = document.getElementById("eq-preset-label");
  const presetOptions = document.getElementById("eq-preset-options");

  if (btnEq) {
    btnEq.classList.toggle("bg-gray-800", !eqEnabled);
    btnEq.classList.toggle("text-gray-400", !eqEnabled);
    btnEq.classList.toggle("bg-orange-500/10", eqEnabled);
    btnEq.classList.toggle("text-orange-500", eqEnabled);
    btnEq.classList.toggle("border", eqEnabled);
    btnEq.classList.toggle("border-orange-500/30", eqEnabled);
  }
  if (eqText) eqText.innerText = eqEnabled ? "EQ On" : "EQ Off";
  if (eqPanel) {
    eqPanel.classList.toggle("hidden", !eqEnabled);
  }
  if (eqContainer) {
    eqContainer.classList.toggle("hidden", !eqEnabled);
  }
  if (presetSelect) presetSelect.value = eqPreset;
  if (presetLabel) presetLabel.innerText = eqPreset === 'flat' ? 'Flat' :
    eqPreset === 'bass' ? 'Bass Boost' :
    eqPreset === 'treble' ? 'Treble Boost' :
    eqPreset === 'vocal' ? 'Vocal Boost' :
    eqPreset === 'rock' ? 'Rock' : eqPreset;
  if (presetOptions) {
    presetOptions.querySelectorAll('button[data-preset]').forEach((button) => {
      const isActive = button.dataset.preset === eqPreset;
      button.classList.toggle('bg-white/[.06]', isActive);
      button.classList.toggle('text-orange-400', isActive);
    });
  }

  eqBands.forEach((_, index) => {
    const input = document.getElementById(`eq-band-${index}`);
    const valueEl = document.getElementById(`eq-band-value-${index}`);
    if (input) input.value = eqGains[index] ?? 0;
    if (valueEl) valueEl.innerText = `${normalizeEqValue(eqGains[index] ?? 0).toFixed(1)} dB`;
  });
}

window.toggleEqualizer = function () {
  /* redefined in DOMContentLoaded */
};

window.setEqBand = function (index, value) {
  eqGains[index] = normalizeEqValue(value);
  if (eqEnabled) applyEqToFilters();
  updateEqUI();
  saveEqState();
};

window.setEqPreset = function (preset) {
  const presets = {
    flat: [0, 0, 0, 0, 0, 0],
    bass: [3, 4, 4, 2, 1, 0],
    treble: [0, 1, 2, 2, 3, 4],
    vocal: [2, 2, 0, 1, 2, 2],
    rock: [3, 1, 0, -1, 2, 3],
  };
  const nextGains = (presets[preset] || presets.flat).map(normalizeEqValue);
  eqPreset = preset || "flat";
  eqGains = nextGains;
  if (eqEnabled) applyEqToFilters();
  updateEqUI();
  saveEqState();
};

window.toggleReply = function (id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.toggle("hidden");
  const input = el.querySelector('input[type="text"]');
  if (input && !el.classList.contains("hidden")) input.focus();
};

// ─── KEYBOARD SHORTCUTS (global, before player ready) ───────────────────────
document.addEventListener("keydown", (e) => {
  const tag = e.target.tagName.toLowerCase();
  if (tag === "input" || tag === "textarea") return;

  const key = e.key.toLowerCase();
  if (key === "l") {
    e.preventDefault();
    window.toggleLoop();
  }
  if (key === "v") window.toggleVisualizer?.();
  if (key === "i") window.toggleMiniPlayer?.();
});

// ========================================
// MAIN INITIALIZATION
// ========================================
document.addEventListener("DOMContentLoaded", () => {
  watchUrl = window.location.href;

  audio = document.getElementById("main-player");
  if (!audio) {
    console.error("❌ #main-player not found");
    return;
  }

  if (!window.MEEL_MUSIC_CONFIG?.id) {
    console.error("❌ MEEL_MUSIC_CONFIG missing");
    return;
  }

  storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id;

  if (typeof Plyr === "undefined") {
    console.error("❌ Plyr not loaded");
    return;
  }

  // ── 1. INIT PLYR ─────────────────────────────────────────────────────────
  try {
    player = new Plyr(audio, {
      iconUrl: "../assets/plyr.svg",
      controls: [
        "play",
        "progress",
        "current-time",
        "duration",
        "mute",
        "volume",
        "settings",
      ],
      settings: ["speed"],
      speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
      keyboard: { focused: true, global: true },
      tooltips: { controls: true, seek: true },
    });
    window.player = player;
  } catch (e) {
    console.error("❌ Plyr init error:", e);
    return;
  }
  if (!player) {
    console.error("❌ Plyr init failed");
    return;
  }

  // ── 2. DOM ELEMENTS ───────────────────────────────────────────────────────
  const container = document.getElementById("player-container");
  const bitrateDisplay = document.getElementById("realtime-bitrate");
  const cavaContainer = document.getElementById("cava-container");
  if (!container || !bitrateDisplay || !cavaContainer) {
    console.error("❌ Required containers missing");
    return;
  }

  // Apply persisted loop state immediately
  const savedLoop = localStorage.getItem("meel_global_loop") === "true";
  player.loop = savedLoop;
  updateLoopUI();
  loadEqState();
  updateEqUI();

  // ── 3. AUDIO STATE RESTORATION ───────────────────────────────────────────
  let shouldRestore = false;
  let restoreTime = 0;
  let restorePlay = false;
  let restoreLooping = savedLoop;

  const rawState = sessionStorage.getItem("meel_audio_state");
  if (rawState) {
    try {
      const state = JSON.parse(rawState);
      const matchedId = state.musicId ?? state.id;
      if (matchedId == window.MEEL_MUSIC_CONFIG.id) {
        shouldRestore = true;
        restoreTime = state.currentTime;
        restorePlay = state.isPlaying;
        if (state.isLooping !== undefined) {
          restoreLooping = state.isLooping;
          localStorage.setItem("meel_global_loop", String(state.isLooping));
        }
      }
    } catch (e) {
      console.warn("⚠️ Bad audio state:", e);
    }
  }

  const savedPos = localStorage.getItem(storageKeyMusic);
  const canResume = savedPos && !isFinished && !shouldRestore;

  // ── 4. VISUALIZER SETUP ───────────────────────────────────────────────────
  let isVisualizerEnabled = window.innerWidth >= 1024;
  const isMobile = window.innerWidth < 768;
  const numBars = isMobile ? 20 : 40;
  const bars = [];

  cavaContainer.innerHTML = "";
  for (let i = 0; i < numBars; i++) {
    const bar = document.createElement("div");
    bar.className =
      "flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75";
    bar.style.cssText = "height:4px;min-width:1px";
    cavaContainer.appendChild(bar);
    bars.push(bar);
  }

  const realFileSize = window.MEEL_MUSIC_CONFIG.fileSizeBytes;
  let baseBitrate = 160;
  let audioCtx,
    analyser,
    source,
    isInitialized = false;
  let animationId;
  let userInteracted = false;

  const markInteracted = () => {
    userInteracted = true;
  };
  document.addEventListener("click", markInteracted, { once: true });
  document.addEventListener("keydown", markInteracted, { once: true });

  // ── 5. AUDIO CONTEXT (Opus-optimised) ─────────────────────────────────────
  function initAudio() {
    if (!userInteracted) return false;
    if (audioCtx && audioCtx.state !== "closed") {
      if (audioCtx.state === "suspended") audioCtx.resume();
      return true;
    }
    try {
      audioCtx = new (window.AudioContext || window.webkitAudioContext)({
        latencyHint: "playback",
        sampleRate: 48000,
      });
      analyser = audioCtx.createAnalyser();
      source = audioCtx.createMediaElementSource(audio);

      eqFilters = [];
      let previousNode = source;
      eqBands.forEach((freq, index) => {
        const filter = audioCtx.createBiquadFilter();
        filter.type = "peaking";
        filter.frequency.value = freq;
        filter.Q.value = 1.0;
        filter.gain.value = normalizeEqValue(eqGains[index] ?? 0);
        previousNode.connect(filter);
        previousNode = filter;
        eqFilters.push(filter);
      });

      previousNode.connect(analyser);
      analyser.connect(audioCtx.destination);
      analyser.fftSize = 256;
      applyEqToFilters();
      isInitialized = true;
      return true;
    } catch (e) {
      console.error("❌ AudioContext error:", e);
      return false;
    }
  }

  window.toggleEqualizer = function () {
    eqEnabled = !eqEnabled;
    if (eqEnabled) {
      if (!isInitialized) {
        initAudio();
      } else {
        applyEqToFilters();
      }
    } else {
      applyEqToFilters();
    }
    updateEqUI();
    saveEqState();
  };

  function render() {
    if (!isVisualizerEnabled || !isInitialized || player.paused) {
      cancelAnimationFrame(animationId);
      return;
    }
    const data = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(data);

    let peak = 0;
    for (let i = 0; i < numBars; i++) {
      const idx = Math.floor(i * (data.length / numBars) * 0.7);
      const value = Math.max(4, (data[idx] / 255) * 100);
      bars[i].style.height = `${value}%`;
      peak = Math.max(peak, data[idx]);
    }
    if (bitrateDisplay) {
      const dynamicBitrate = getRealtimeVbrValue(data);
      updateBitrateLabel(dynamicBitrate, bitrateDisplay);
      updateBarColors(dynamicBitrate, bars);
    }
    animationId = requestAnimationFrame(render);
  }

  // ── 6. VISUALIZER TOGGLE ─────────────────────────────────────────────────
  function applyVisualizerUI() {
    const btnVis = document.getElementById("btn-vis");
    const visText = document.getElementById("vis-text");
    const on = isVisualizerEnabled;

    if (btnVis) {
      btnVis.classList.toggle("bg-gray-800", !on);
      btnVis.classList.toggle("text-gray-400", !on);
      btnVis.classList.toggle("bg-orange-500/10", on);
      btnVis.classList.toggle("text-orange-500", on);
      btnVis.classList.toggle("border", on);
      btnVis.classList.toggle("border-orange-500/30", on);
    }
    if (visText) visText.innerText = on ? "Vis On" : "Vis Off";
    cavaContainer.style.display = on ? "flex" : "none";
    if (!on) cavaContainer.classList.add("hidden");
    else cavaContainer.classList.remove("hidden");
  }

  window.toggleVisualizer = function () {
    isVisualizerEnabled = !isVisualizerEnabled;
    applyVisualizerUI();
    if (isVisualizerEnabled && !player.paused) {
      if (!isInitialized) {
        if (initAudio()) render();
      } else render();
    } else {
      cancelAnimationFrame(animationId);
    }
  };

  setTimeout(applyVisualizerUI, 100);

  // ── 7. RESUME MODAL ──────────────────────────────────────────────────────
  const modal = document.getElementById("resume-modal");
  const btnResume = document.getElementById("btn-resume");
  const btnRestart = document.getElementById("btn-restart");
  const displayTime = document.getElementById("resume-time");

  if (modal && btnResume && btnRestart && displayTime) {
    const countdownText = document.createElement("p");
    countdownText.className = "text-[9px] text-gray-500 italic mb-4";
    displayTime.parentNode.after(countdownText);

    let autoRestartTimer,
      countdownInterval,
      sessionHandled = false;

    function showResumeModal() {
      if (skipResumeModalOnce) {
        skipResumeModalOnce = false;
        return;
      }
      if (sessionStorage.getItem("skip_resume_once") === "true") {
        sessionStorage.removeItem("skip_resume_once");
        return;
      }
      if (shouldRestore) return;

      const pos = localStorage.getItem(storageKeyMusic);
      if (!pos || parseFloat(pos) <= 10) return;
      if (player.duration && parseFloat(pos) >= player.duration - 5) return;

      sessionHandled = false;
      clearInterval(countdownInterval);
      clearTimeout(autoRestartTimer);

      const secs = parseFloat(pos);
      const m = Math.floor(secs / 60),
        s = Math.floor(secs % 60);
      displayTime.innerText = `${m}:${String(s).padStart(2, "0")}`;
      audio.autoplay = player.autoplay = false;
      audio.currentTime = secs;
      modal.classList.remove("hidden");

      let timeLeft = 15;
      const tick = () => {
        countdownText.innerText =
          timeLeft >= 0
            ? `Otomatis putar dari awal dalam ${timeLeft--}s...`
            : "Otomatis putar dari awal...";
      };
      tick();
      countdownInterval = setInterval(tick, 1000);
      autoRestartTimer = setTimeout(() => {
        if (!sessionHandled && !modal.classList.contains("hidden")) {
          clearInterval(countdownInterval);
          btnRestart.click();
        }
      }, 15000);
    }

    player.on("ready", () => {
      if (realFileSize > 0 && player.duration > 0)
        baseBitrate = Math.round((realFileSize * 8) / (player.duration * 1000));

      const plyrEl = document.querySelector(".plyr");
      if (plyrEl) {
        plyrEl.tabIndex = 0;
        plyrEl.focus();
      }

      if (shouldRestore) {
        player.loop = restoreLooping;
        localStorage.setItem("meel_global_loop", String(restoreLooping));
        updateLoopUI();
        sessionStorage.removeItem("meel_audio_state");

        // Tunggu metadata audio siap sebelum seek agar benar-benar dipulihkan
        const seekToRestore = () => {
          player.currentTime = Math.max(0, restoreTime);
          if (restorePlay) {
            player.play().catch(() => {});
          }
        };
        if (audio.readyState >= HTMLMediaElement.HAVE_METADATA) {
          seekToRestore();
        } else {
          audio.addEventListener("loadedmetadata", seekToRestore, { once: true });
        }
      } else {
        const pos = localStorage.getItem(storageKeyMusic);
        if (
          pos &&
          parseFloat(pos) > 10 &&
          (!player.duration || parseFloat(pos) < player.duration - 5)
        )
          showResumeModal();
        else player.play().catch(() => {});
      }
    });

    audio.addEventListener("loadedmetadata", showResumeModal);

    btnResume.onclick = () => {
      sessionHandled = true;
      clearTimeout(autoRestartTimer);
      clearInterval(countdownInterval);
      player.currentTime = parseFloat(localStorage.getItem(storageKeyMusic));
      player.play();
      modal.classList.add("hidden");
    };

    btnRestart.onclick = () => {
      sessionHandled = true;
      clearTimeout(autoRestartTimer);
      clearInterval(countdownInterval);
      localStorage.removeItem(storageKeyMusic);
      player.currentTime = 0;
      player.play();
      modal.classList.add("hidden");
    };
  }

  // ── 8. PLYR EVENTS ───────────────────────────────────────────────────────
  const vinyl = () => document.querySelector(".vinyl-wrap .vinyl-spin");

  player.on("play", () => {
    if (window.meelHealthAlertActive) {
      player.pause();
      return;
    }
    isFinished = false;
    container.classList.add("playing");
    vinyl()?.classList.add("playing");
    if (isVisualizerEnabled) {
      if (!isInitialized) {
        if (initAudio()) render();
      } else render();
    }
    updateMiniPlayerUI();
  });

  player.on("pause", () => {
    container.classList.remove("playing");
    vinyl()?.classList.remove("playing");
    cancelAnimationFrame(animationId);
    updateMiniPlayerUI();
  });

  player.on("timeupdate", () => {
    if (
      !isFinished &&
      player.currentTime > 0 &&
      player.currentTime < player.duration - 1
    )
      localStorage.setItem(storageKeyMusic, player.currentTime);
    updateMiniPlayerUI();
  });

  player.on("loadedmetadata", updateMiniPlayerUI);

  player.on("ended", () => {
    const next = window.MEEL_MUSIC_CONFIG.nextSongUrl;
    if (next) {
      window.location.href = next;
    } else {
      localStorage.removeItem(storageKeyMusic);
      const rec = document.querySelector(".rekomendasi-item");
      if (rec) window.location.href = rec.href;
    }
  });

  // ── 9. MINI PLAYER (SPA-style) ───────────────────────────────────────────
  function updateMiniPlayerUI() {
    if (!isMiniPlayerActive) return;
    const miniPlayBtn = document.getElementById("mini-play-btn");
    const miniProgressFill = document.getElementById("mini-progress-fill");
    const miniCurrentTime = document.getElementById("mini-current-time");
    const miniDuration = document.getElementById("mini-duration");

    if (miniPlayBtn)
      miniPlayBtn.innerHTML = player.paused
        ? '<i data-lucide="play"  style="width:18px;height:18px;"></i>'
        : '<i data-lucide="pause" style="width:18px;height:18px;"></i>';

    const pct = player.duration
      ? (player.currentTime / player.duration) * 100
      : 0;
    if (miniProgressFill) miniProgressFill.style.width = pct + "%";
    if (miniCurrentTime)
      miniCurrentTime.textContent = formatTime(player.currentTime);
    if (miniDuration) miniDuration.textContent = formatTime(player.duration);

    if (typeof lucide !== "undefined") lucide.createIcons();
  }

  window.toggleMiniPlayer = async function () {
    const playerContainer = document.getElementById("player-container");
    const mainGrid = document.querySelector(
      'div[class*="grid-cols-1"][class*="lg:grid-cols-3"]',
    );
    const leftColumn = mainGrid?.querySelector('div[class*="lg:col-span-2"]');
    const rightSidebar = leftColumn?.nextElementSibling;

    if (!isMiniPlayerActive) {
      isMiniPlayerActive = true;
      skipResumeModalOnce = true;

      if (rightSidebar) rightSidebar.style.display = "none";
      if (playerContainer) {
        playerContainer.style.maxHeight = "120px";
        playerContainer.style.overflow = "hidden";
      }
      if (mainGrid) {
        mainGrid.classList.remove(
          "grid",
          "grid-cols-1",
          "lg:grid-cols-3",
          "gap-6",
          "lg:gap-8",
        );
        mainGrid.style.cssText = "display:flex;flex-direction:column";
      }

      let tempIndex = document.getElementById("temp-index-content");
      if (!tempIndex) {
        tempIndex = document.createElement("div");
        tempIndex.id = "temp-index-content";
        tempIndex.className = "w-full";
        mainGrid?.appendChild(tempIndex);
        try {
          const html = await (await fetch("index.php")).text();
          const doc = new DOMParser().parseFromString(html, "text/html");
          const indexMain = doc.querySelector("main");
          if (indexMain) {
            tempIndex.innerHTML = indexMain.innerHTML;
            window.history.pushState({ miniPlayer: true }, "", "index.php");
            if (window.lucide) lucide.createIcons();
            if (window.htmx) htmx.process(tempIndex);
          }
        } catch (err) {
          console.error("Failed to load index:", err);
        }
      } else {
        tempIndex.style.display = "block";
        window.history.pushState({ miniPlayer: true }, "", "index.php");
      }
    } else {
      isMiniPlayerActive = false;
      if (playerContainer) {
        playerContainer.style.maxHeight = "";
        playerContainer.style.overflow = "";
      }
      document
        .getElementById("temp-index-content")
        ?.style.setProperty("display", "none");
      if (mainGrid) {
        mainGrid.style.display = "grid";
        mainGrid.classList.add(
          "grid",
          "grid-cols-1",
          "lg:grid-cols-3",
          "gap-6",
          "lg:gap-8",
        );
      }
      leftColumn?.classList.add("lg:col-span-2", "space-y-5");
      if (rightSidebar) rightSidebar.style.display = "block";
      window.history.pushState({}, "", watchUrl);
    }
  };

  // Auto-save every 5 s while mini player active
  setInterval(() => {
    if (isMiniPlayerActive) saveAudioState();
  }, 5000);

  window.miniPlayPause = function () {
    if (!player) return;
    if (window.meelHealthAlertActive) return;
    player.paused ? player.play() : player.pause();
    updateMiniPlayerUI();
  };

  window.miniSeek = function (event) {
    if (!player) return;
    if (window.meelHealthAlertActive) return;
    const rect = event.currentTarget.getBoundingClientRect();
    player.currentTime =
      ((event.clientX - rect.left) / rect.width) * player.duration;
  };

  window.miniNext = function () {
    if (window.meelHealthAlertActive) return;
    const next = window.MEEL_MUSIC_CONFIG?.nextSongUrl;
    if (next) {
      saveAudioState();
      window.location.href = next;
    } else {
      const rec = document.querySelector(".rekomendasi-item");
      if (rec) window.location.href = rec.href;
    }
  };

  window.miniPrev = function () {
    if (!player) return;
    if (window.meelHealthAlertActive) return;
    if (player.currentTime > 3) {
      player.currentTime = 0;
      return;
    }
    window.history.length > 1
      ? window.history.back()
      : (player.currentTime = 0);
  };

  // ── 10. KEYBOARD SHORTCUT (in-page 'i' → back to library) ───────────────
  document.addEventListener("keydown", (e) => {
    const tag = e.target.tagName.toLowerCase();
    if (tag === "input" || tag === "textarea") return;
    if (e.key.toLowerCase() === "i" && !e.ctrlKey && !e.altKey && !e.metaKey) {
      e.preventDefault();
      window.goBackToLibrary();
    }
  });

  window.goBackToLibrary = function () {
    saveAudioState();
    var plId = window.MEEL_MUSIC_CONFIG?.playlistId;
    player?.destroy();
    if (plId && plId > 0) {
      window.location.href = "index.php?playlist_id=" + plId;
    } else {
      window.location.href = "index.php";
    }
  };

  // Mini-player container click while active
  document
    .getElementById("player-container")
    ?.addEventListener("click", (e) => {
      // If the click originates from Plyr controls, custom mini player controls, or any button, ignore the toggle.
      if (
        e.target.closest(".plyr__controls") ||
        e.target.closest(".mp-controls") ||
        e.target.closest("button")
      ) {
        return;
      }
      if (isMiniPlayerActive) {
        e.preventDefault();
        window.toggleMiniPlayer();
      }
    });

  window.addEventListener("popstate", () => {
    if (isMiniPlayerActive && window.location.href === watchUrl)
      window.toggleMiniPlayer();
  });

  // ── 11. INIT ICONS ───────────────────────────────────────────────────────
  if (typeof lucide !== "undefined") lucide.createIcons();
});
