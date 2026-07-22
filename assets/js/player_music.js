let player,
  audio,
  storageKeyMusic,
  watchUrl,
  isFinished = !1,
  isMiniPlayerActive = !1,
  skipResumeModalOnce = !1,
  eqFilters = [],
  eqBands = [60, 170, 350, 1e3, 3500, 1e4],
  eqGains = Array(eqBands.length).fill(0),
  eqEnabled = !1,
  eqPreset = "flat";
const ZERO_GAINS = Array(eqBands.length).fill(0),
  EQ_PRESET_LABELS = {
    flat: "Flat",
    bass: "Bass Boost",
    treble: "Treble Boost",
    vocal: "Vocal Boost",
    rock: "Rock",
    classical: "Classical",
    pop: "Pop",
    jazz: "Jazz",
    electronic: "Electronic",
    acoustic: "Acoustic",
    gaming: "Gaming",
    podcast: "Podcast",
  },
  EQ_PRESETS = {
    flat: [0, 0, 0, 0, 0, 0],
    bass: [3, 4, 4, 2, 1, 0],
    treble: [0, 1, 2, 2, 3, 4],
    vocal: [2, 2, 0, 1, 2, 2],
    rock: [3, 1, 0, -1, 2, 3],
    classical: [2, 0, -1, -1, 2, 3],
    pop: [1, 2, 2, 1, 2, 1],
    jazz: [2, 3, 1, 0, 1, 2],
    electronic: [4, 2, -1, -1, 2, 4],
    acoustic: [2, 2, 1, 0, 1, 2],
    gaming: [3, 2, -1, 1, 3, 2],
    podcast: [0, -1, 2, 3, 1, -1],
  };
let miniEls = null;
function formatTime(e) {
  if (!e || isNaN(e)) return "0:00";
  const t = Math.floor(e / 60),
    n = Math.floor(e % 60);
  return `${t}:${String(n).padStart(2, "0")}`;
}
function _setTogglePillUI(e, t) {
  e &&
    (e.classList.toggle("bg-gray-800", !t),
    e.classList.toggle("text-gray-400", !t),
    e.classList.toggle("bg-orange-500/10", t),
    e.classList.toggle("text-orange-500", t),
    e.classList.toggle("border", t),
    e.classList.toggle("border-orange-500/30", t));
}
function _applyLoopUI(e) {
  _setTogglePillUI(document.getElementById("btn-loop"), e);
  const t = document.getElementById("loop-text"),
    n = document.getElementById("mini-loop-btn");
  (t && (t.innerText = e ? "Loop On" : "Loop Off"),
    n &&
      ((n.style.color = e ? "#f97316" : ""),
      (n.style.opacity = e ? "1" : "0.5")));
}
function updateLoopUI() {
  _applyLoopUI(
    player ? player.loop : "true" === localStorage.getItem("meel_global_loop"),
  );
}
function saveAudioState() {
  if (!window.MEEL_MUSIC_CONFIG) return;
  const e = window.MEEL_MUSIC_CONFIG,
    t = e.playlistId || 0;
  (sessionStorage.setItem(
    "meel_audio_state",
    JSON.stringify({
      id: e.id,
      musicId: e.id,
      playlistId: t,
      watchUrl: `watch.php?id=${e.id}`,
      currentTime: player ? player.currentTime : 0,
      isPlaying: !!player && !player.paused,
      isLooping: !!player && player.loop,
      title: e.title,
      artist: e.artist,
      thumbnail: e.thumbnail,
      thumbnailUrl: e.thumbnailUrl || "",
      filename: e.filename,
    }),
  ),
    t > 0
      ? localStorage.setItem("meel_last_playlist_id", String(t))
      : localStorage.removeItem("meel_last_playlist_id"));
}
function normalizeEqValue(e) {
  const t = Number(e);
  return Number.isFinite(t) ? Math.max(-12, Math.min(12, t)) : 0;
}
function saveEqState() {
  try {
    localStorage.setItem(
      "meel_music_eq_state",
      JSON.stringify({ enabled: eqEnabled, preset: eqPreset, gains: eqGains }),
    );
  } catch (e) {
    console.warn("⚠️ Could not save EQ state:", e);
  }
}
function loadEqState() {
  try {
    const e = localStorage.getItem("meel_music_eq_state");
    if (!e) return;
    const t = JSON.parse(e);
    (t && Array.isArray(t.gains) && (eqGains = t.gains.map(normalizeEqValue)),
      "boolean" == typeof t?.enabled && (eqEnabled = t.enabled),
      "string" == typeof t?.preset && (eqPreset = t.preset));
  } catch (e) {
    console.warn("⚠️ Bad EQ state:", e);
  }
}
function applyEqToFilters() {
  if (!eqFilters.length) return;
  const e = eqEnabled ? eqGains : ZERO_GAINS;
  for (let t = 0; t < eqFilters.length; t++)
    eqFilters[t].gain.value = normalizeEqValue(e[t] ?? 0);
}
function getRealtimeVbrValue(e) {
  if (!e || !e.length) return 160;
  const t = e.reduce((e, t) => e + t, 0) / e.length,
    n = Math.min(1, Math.max(0, t / 255));
  return Math.round(96 + 224 * n);
}
function updateBitrateLabel(e, t) {
  t && (t.innerText = `${e}`);
}
function updateBarColors(e, t) {
  if (!t || !t.length) return;
  const n = Math.round(28 + ((e - 96) / 224) * 180);
  t.forEach((e) => {
    e.style.background = `linear-gradient(to top, hsl(${n}, 96%, 50%), hsl(${Math.min(360, n + 40)}, 96%, 72%))`;
  });
}
function updateEqUI() {
  const e = document.getElementById("btn-eq"),
    t = document.getElementById("eq-text"),
    n = document.getElementById("eq-panel"),
    a = document.getElementById("eq-container"),
    o = document.getElementById("eq-preset"),
    i =
      (document.getElementById("eq-preset-button"),
      document.getElementById("eq-preset-label")),
    l = document.getElementById("eq-preset-options");
  (_setTogglePillUI(e, eqEnabled),
    t && (t.innerText = eqEnabled ? "EQ On" : "EQ Off"),
    n && n.classList.toggle("hidden", !eqEnabled),
    a && a.classList.toggle("hidden", !eqEnabled),
    o && (o.value = eqPreset),
    i && (i.innerText = EQ_PRESET_LABELS[eqPreset] || eqPreset),
    l &&
      l.querySelectorAll("button[data-preset]").forEach((e) => {
        const t = e.dataset.preset === eqPreset;
        (e.classList.toggle("bg-white/[.06]", t),
          e.classList.toggle("text-orange-400", t));
      }),
    eqBands.forEach((e, t) => {
      const n = document.getElementById(`eq-band-${t}`),
        a = document.getElementById(`eq-band-value-${t}`);
      (n && (n.value = eqGains[t] ?? 0),
        a &&
          (a.innerText = `${normalizeEqValue(eqGains[t] ?? 0).toFixed(1)} dB`));
    }));
}
((window.toggleLoop = function () {
  const e = !("true" === localStorage.getItem("meel_global_loop"));
  (localStorage.setItem("meel_global_loop", String(e)),
    player && ((player.loop = e), saveAudioState()),
    updateLoopUI());
}),
  (window.toggleVisualizer = function () {}),
  (window.toggleEqualizer = function () {}),
  (window.setEqBand = function (e, t) {
    ((eqGains[e] = normalizeEqValue(t)),
      eqEnabled && applyEqToFilters(),
      updateEqUI(),
      saveEqState());
  }),
  (window.setEqPreset = function (e) {
    const t = (EQ_PRESETS[e] || EQ_PRESETS.flat).map(normalizeEqValue);
    ((eqPreset = e || "flat"),
      (eqGains = t),
      eqEnabled && applyEqToFilters(),
      updateEqUI(),
      saveEqState());
  }),
  (window.toggleReply = function (e) {
    const t = document.getElementById(e);
    if (!t) return;
    t.classList.toggle("hidden");
    const n = t.querySelector('input[type="text"]');
    n && !t.classList.contains("hidden") && n.focus();
  }),
  document.addEventListener("keydown", (e) => {
    const t = e.target.tagName.toLowerCase();
    if ("input" === t || "textarea" === t) return;
    if (e.ctrlKey || e.altKey || e.metaKey) return;
    const n = e.key.toLowerCase();
    "l" === n
      ? (e.preventDefault(), window.toggleLoop())
      : "e" === n
        ? (e.preventDefault(), window.toggleEqualizer?.())
        : "v" === n
          ? window.toggleVisualizer?.()
          : "i" === n &&
            (e.preventDefault(),
            window.goBackToLibrary
              ? window.goBackToLibrary()
              : window.toggleMiniPlayer?.());
  }),
  document.addEventListener("DOMContentLoaded", () => {
    if (
      ((watchUrl = window.location.href),
      (audio = document.getElementById("main-player")),
      !audio)
    )
      return void console.error("❌ #main-player not found");
    if (!window.MEEL_MUSIC_CONFIG?.id)
      return void console.error("❌ MEEL_MUSIC_CONFIG missing");
    if (
      ((storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id),
      "undefined" == typeof Plyr)
    )
      return void console.error("❌ Plyr not loaded");

    // ── Deteksi FLAC & tambahkan event handler error/timeout ──
    const isFlac = audio.querySelector('source[type="audio/flac"]') !== null
      || window.MEEL_MUSIC_CONFIG?.filename?.toLowerCase().endsWith('.flac');

    // Loading state indicator untuk file besar
    let loadingTimeout = null;
    let secondaryTimeout = null;
    let metadataLoaded = false;
    let loadRetried = false;
    const LOADING_TIMEOUT_MS = isFlac ? 20000 : 10000; // 20s untuk FLAC, 10s untuk lainnya

    // Fungsi untuk membersihkan semua timeout
    function clearAllTimeouts() {
      if (loadingTimeout) { clearTimeout(loadingTimeout); loadingTimeout = null; }
      if (secondaryTimeout) { clearTimeout(secondaryTimeout); secondaryTimeout = null; }
    }

    // Fungsi untuk menampilkan/tutup loading overlay
    function showLoadingOverlay(msg) {
      let overlay = document.getElementById('flac-loading-overlay');
      if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'flac-loading-overlay';
        overlay.style.cssText = 'position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.85);z-index:50;border-radius:inherit;gap:12px;padding:20px;text-align:center;';
        const spinner = document.createElement('div');
        spinner.className = 'animate-spin h-8 w-8 border-2 border-orange-500 border-t-transparent rounded-full';
        const text = document.createElement('p');
        text.id = 'flac-loading-text';
        text.style.cssText = 'color:#9ca3af;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;';
        overlay.appendChild(spinner);
        overlay.appendChild(text);
        const container = document.getElementById('player-container');
        if (container) {
          container.style.position = 'relative';
          container.appendChild(overlay);
        }
      }
      overlay.style.display = 'flex';
      const txt = document.getElementById('flac-loading-text');
      if (txt) txt.textContent = msg || 'Memuat audio...';
    }

    function hideLoadingOverlay() {
      const overlay = document.getElementById('flac-loading-overlay');
      if (overlay) overlay.style.display = 'none';
    }

    // ── Flag untuk mencegah redirect loop ──
    let audioEndedNaturally = false;  // di-set true hanya jika playback mencapai akhir
    let isNavigating = false;         // cegah navigasi ganda

    // Handler error audio — tandai bahwa audio GAGAL (bukan selesai natural)
    let errorHandled = false;
    function onAudioError(e) {
      if (errorHandled) return;
      errorHandled = true;
      audioEndedNaturally = false; // pastikan ended handler TIDAK redirect
      clearAllTimeouts();
      hideLoadingOverlay();
      const errCode = audio.error ? audio.error.code : '?';
      const errMsg = audio.error ? audio.error.message : 'Gagal memuat audio';
      console.error('❌ Audio error [' + errCode + ']:', errMsg);
      if (isFlac) {
        showLoadingOverlay('⚠️ FLAC tidak dapat dimuat. Coba refresh halaman atau gunakan format lain.');
      }
    }

    // Handler loadedmetadata — batalkan timeout
    function onLoadedMetadata() {
      metadataLoaded = true;
      clearAllTimeouts();
      hideLoadingOverlay();
    }

    // Pasang event listeners
    audio.addEventListener('error', onAudioError);
    audio.addEventListener('loadedmetadata', onLoadedMetadata, { once: true });

    // Timeout: jika loadedmetadata tidak kunjung tiba, reset state
    loadingTimeout = setTimeout(() => {
      if (!metadataLoaded && !errorHandled) {
        console.warn('⚠️ loadedmetadata timeout setelah ' + (LOADING_TIMEOUT_MS/1000) + 's.');
        showLoadingOverlay('Memuat file besar... (' + (isFlac ? 'FLAC' : 'audio') + ')');
        // Coba reload source sekali saja — jika masih gagal, tampilkan error final
        if (isFlac && audio && !loadRetried) {
          loadRetried = true;
          audio.load();
        }
        // Tambahan timeout kedua: jika masih belum juga, beri pesan error
        secondaryTimeout = setTimeout(() => {
          if (!metadataLoaded && !errorHandled) {
            hideLoadingOverlay();
            showLoadingOverlay('⚠️ Waktu muat habis. Silakan refresh halaman atau coba format lain.');
          }
        }, LOADING_TIMEOUT_MS);
      }
    }, LOADING_TIMEOUT_MS);

    // Cleanup pada page unload / player destroy
    function cleanupAudioListeners() {
      clearAllTimeouts();
      if (audio) {
        audio.removeEventListener('error', onAudioError);
        audio.removeEventListener('loadedmetadata', onLoadedMetadata);
      }
      hideLoadingOverlay();
    }
    window.addEventListener('beforeunload', cleanupAudioListeners);

    try {
      ((player = new Plyr(audio, {
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
        keyboard: { focused: !0, global: !0 },
        tooltips: { controls: !0, seek: !0 },
      })),
        (window.player = player));
    } catch (U) {
      return void console.error("❌ Plyr init error:", U);
    }
    if (!player) return void console.error("❌ Plyr init failed");
    const e = document.getElementById("player-container"),
      t = document.getElementById("realtime-bitrate"),
      n = document.getElementById("cava-container");
    if (!e || !t || !n)
      return void console.error("❌ Required containers missing");
    const a = "true" === localStorage.getItem("meel_global_loop");
    ((player.loop = a), updateLoopUI(), loadEqState(), updateEqUI());
    let o = !1,
      i = 0,
      l = !1,
      r = a;
    const s = sessionStorage.getItem("meel_audio_state");
    if (s)
      try {
        const k = JSON.parse(s);
        (k.musicId ?? k.id) == window.MEEL_MUSIC_CONFIG.id &&
          ((o = !0),
          (i = k.currentTime),
          (l = k.isPlaying),
          void 0 !== k.isLooping &&
            ((r = k.isLooping),
            localStorage.setItem("meel_global_loop", String(k.isLooping))));
      } catch (O) {
        console.warn("⚠️ Bad audio state:", O);
      }
    localStorage.getItem(storageKeyMusic);
    let d,
      c = window.innerWidth >= 1024,
      u = [];
    function p() {
      let e = n.clientWidth;
      return (
        e <= 0 &&
          (e =
            window.innerWidth >= 1024
              ? 0.32 * window.innerWidth
              : window.innerWidth - 32),
        e < 180 ? 12 : e < 280 ? 18 : e < 400 ? 24 : e < 600 ? 32 : 40
      );
    }
    function m() {
      const e = p();
      if (u.length === e) return;
      ((u = []), (n.innerHTML = ""));
      const t = document.createDocumentFragment();
      for (let n = 0; n < e; n++) {
        const e = document.createElement("div");
        ((e.className =
          "flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75"),
          (e.style.cssText = "height:4px;min-width:1px"),
          t.appendChild(e),
          u.push(e));
      }
      n.appendChild(t);
    }
    (m(),
      window.addEventListener("resize", function () {
        (clearTimeout(d),
          (d = setTimeout(() => {
            u.length !== p() &&
              (m(), c && I && !player.paused && (cancelAnimationFrame(E), _()));
          }, 200)));
      }));
    const y = window.MEEL_MUSIC_CONFIG.fileSizeBytes;
    let g,
      w,
      f,
      E,
      h = 160,
      I = !1,
      q = !1;
    const S = () => {
      q = !0;
    };
    function v() {
      if (!q) return !1;
      if (g && "closed" !== g.state)
        return ("suspended" === g.state && g.resume(), !0);
      try {
        ((g = new (window.AudioContext || window.webkitAudioContext)({
          latencyHint: "playback",
          sampleRate: 48e3,
        })),
          (w = g.createAnalyser()),
          (f = g.createMediaElementSource(audio)),
          (eqFilters = []));
        let e = f;
        return (
          eqBands.forEach((t, n) => {
            const a = g.createBiquadFilter();
            ((a.type = "peaking"),
              (a.frequency.value = t),
              (a.Q.value = 1),
              (a.gain.value = normalizeEqValue(eqGains[n] ?? 0)),
              e.connect(a),
              (e = a),
              eqFilters.push(a));
          }),
          e.connect(w),
          w.connect(g.destination),
          (w.fftSize = 256),
          applyEqToFilters(),
          (I = !0),
          !0
        );
      } catch (e) {
        return (console.error("❌ AudioContext error:", e), !1);
      }
    }
    function _() {
      if (!c || !I || player.paused) return void cancelAnimationFrame(E);
      const e = new Uint8Array(w.frequencyBinCount);
      w.getByteFrequencyData(e);
      const n = u.length;
      if (0 === n) return void (E = requestAnimationFrame(_));
      let a = 0;
      for (let t = 0; t < n; t++) {
        const o = e[Math.floor(t * (e.length / n) * 0.7)],
          i = Math.max(4, (o / 255) * 100);
        u[t].style.height = `${i}%`;
        const l = o / 255;
        let r = "#9ca3af";
        (l > 0.75
          ? (r = "#22c55e")
          : l > 0.5
            ? (r = "#FB923C")
            : l > 0.25 && (r = "#eab308"),
          (u[t].style.background = r),
          (a = Math.max(a, o)));
      }
      (t && updateBitrateLabel(getRealtimeVbrValue(e), t),
        (E = requestAnimationFrame(_)));
    }
    function b() {
      const e = document.getElementById("btn-vis"),
        t = document.getElementById("vis-text"),
        a = c;
      (_setTogglePillUI(e, a),
        t && (t.innerText = a ? "Vis On" : "Vis Off"),
        (n.style.display = a ? "flex" : "none"),
        n.classList.toggle("hidden", !a));
    }
    (document.addEventListener("click", S, { once: !0 }),
      document.addEventListener("keydown", S, { once: !0 }),
      (window.toggleEqualizer = function () {
        ((eqEnabled = !eqEnabled),
          eqEnabled ? (I ? applyEqToFilters() : v()) : applyEqToFilters(),
          updateEqUI(),
          saveEqState());
      }),
      (window.toggleVisualizer = function () {
        ((c = !c),
          b(),
          c && !player.paused ? (I || v()) && _() : cancelAnimationFrame(E));
      }),
      setTimeout(b, 100));
    const L = document.getElementById("resume-modal"),
      M = document.getElementById("btn-resume"),
      T = document.getElementById("btn-restart"),
      x = document.getElementById("resume-time");
    if (L && M && T && x) {
      const N = document.createElement("p");
      ((N.className = "text-[9px] text-gray-500 italic mb-4"),
        x.parentNode.after(N));
      let z,
        G,
        R = !1;
      // Bersihkan flag sisa dari navigasi index agar tidak stale
      sessionStorage.removeItem("skip_resume_once");
      function B() {
        if (skipResumeModalOnce) return void (skipResumeModalOnce = !1);
        const e = localStorage.getItem(storageKeyMusic);
        if (!e || parseFloat(e) <= 10) return;
        if (player.duration && parseFloat(e) >= player.duration - 5) return;
        ((R = !1), clearInterval(G), clearTimeout(z));
        const t = parseFloat(e),
          n = Math.floor(t / 60),
          a = Math.floor(t % 60);
        ((x.innerText = `${n}:${String(a).padStart(2, "0")}`),
          (audio.autoplay = player.autoplay = !1),
          (audio.currentTime = t),
          L.classList.remove("hidden"));
        let i = 15;
        const l = () => {
          N.innerText =
            i >= 0
              ? `Otomatis putar dari awal dalam ${i--}s...`
              : "Otomatis putar dari awal...";
        };
        (l(),
          (G = setInterval(l, 1e3)),
          (z = setTimeout(() => {
            R ||
              L.classList.contains("hidden") ||
              (clearInterval(G), T.click());
          }, 15e3)));
      }
      (player.on("ready", () => {
        y > 0 &&
          player.duration > 0 &&
          (h = Math.round((8 * y) / (1e3 * player.duration)));
        const e = document.querySelector(".plyr");
        if ((e && ((e.tabIndex = 0), e.focus()), o)) {
          ((player.loop = r),
            localStorage.setItem("meel_global_loop", String(r)),
            updateLoopUI(),
            sessionStorage.removeItem("meel_audio_state"));

          // 🔥 FIX: Setelah restore dari audio state, tetap cek localStorage
          // untuk resume modal (karena B() tidak pernah dipanggil dari cabang o=true)
          const _savedPos = localStorage.getItem(storageKeyMusic);
          if (
            _savedPos &&
            parseFloat(_savedPos) > 10 &&
            (!player.duration || parseFloat(_savedPos) < player.duration - 5)
          ) {
            B();
          }

          // Jika B() tidak menampilkan modal (modal masih hidden), play normal
          if (L && L.classList.contains("hidden")) {
            const e = () => {
              ((player.currentTime = Math.max(0, i)),
                l && player.play().catch(() => {}));
            };
            audio.readyState >= HTMLMediaElement.HAVE_METADATA
              ? e()
              : audio.addEventListener("loadedmetadata", e, { once: !0 });
          }
        } else {
          const e = localStorage.getItem(storageKeyMusic);
          e &&
          parseFloat(e) > 10 &&
          (!player.duration || parseFloat(e) < player.duration - 5)
            ? B()
            : player.play().catch(() => {});
        }
      }),

        (M.onclick = () => {
          ((R = !0),
            clearTimeout(z),
            clearInterval(G),
            (player.currentTime = parseFloat(
              localStorage.getItem(storageKeyMusic),
            )),
            player.play(),
            L.classList.add("hidden"));
        }),
        (T.onclick = () => {
          ((R = !0),
            clearTimeout(z),
            clearInterval(G),
            localStorage.removeItem(storageKeyMusic),
            // ⚡ Gunakan audio.currentTime langsung (bukan player.currentTime)
            // karena Plyr ignore seek jika !duration — yang sering terjadi
            // untuk FLAC dengan preload="none" (metadata belum termuat).
            (audio.currentTime = 0),
            player.play(),
            L.classList.add("hidden"));
        }));
    }
    const A = () => document.querySelector(".vinyl-wrap .vinyl-spin");
    (player.on("play", () => {
      window.meelHealthAlertActive
        ? player.pause()
        : ((isFinished = !1),
          e.classList.add("playing"),
          A()?.classList.add("playing"),
          c && (I || v()) && _(),
          C());
    }),
      player.on("pause", () => {
        (e.classList.remove("playing"),
          A()?.classList.remove("playing"),
          cancelAnimationFrame(E),
          C());
      }));
    let F = -1;
    (player.on("timeupdate", () => {
      if (
        !isFinished &&
        player.currentTime > 0 &&
        player.currentTime < player.duration - 1
      ) {
        const e = Math.floor(player.currentTime);
        e !== F &&
          ((F = e), localStorage.setItem(storageKeyMusic, player.currentTime));
      }
      C();
    }),
      player.on("loadedmetadata", C),
      player.on("ended", () => {
        // 🛡️ Cegah redirect loop: hanya lanjut jika audio benar-benar selesai
        // diputar sampai akhir (currentTime mendekati duration), BUKAN karena error.
        const isGenuineEnd = audioEndedNaturally
          || (player.duration > 0 && Math.abs(player.currentTime - player.duration) < 1.5)
          || (player.currentTime > 0 && !audio.error && audio.ended === true);

        if (!isGenuineEnd) {
          console.warn('⚠️ ended fired tapi bukan natural end — skip redirect. err=', !!audio.error);
          return;
        }
        if (isNavigating) return;
        isNavigating = true;

        const e = window.MEEL_MUSIC_CONFIG.nextSongUrl;
        if (e) window.location.href = e;
        else {
          localStorage.removeItem(storageKeyMusic);
          const e = document.querySelector(".rekomendasi-item");
          if (e) window.location.href = e.href;
          else isNavigating = false; // reset jika tidak ada tujuan
        }
      }),
      // Tandai natural end saat currentTime mendekati durasi & audio sedang diputar
      // (jangan set flag jika user cuma seek ke akhir lalu pause)
      player.on("timeupdate", () => {
        if (player.duration > 0 && !player.paused && player.currentTime >= player.duration - 0.5) {
          audioEndedNaturally = true;
        }
      }));
    let P = null;
    function C() {
      if (!isMiniPlayerActive) return;
      miniEls ||
        (miniEls = {
          playBtn: document.getElementById("mini-play-btn"),
          progressFill: document.getElementById("mini-progress-fill"),
          currentTime: document.getElementById("mini-current-time"),
          duration: document.getElementById("mini-duration"),
        });
      const {
        playBtn: e,
        progressFill: t,
        currentTime: n,
        duration: a,
      } = miniEls;
      e &&
        player.paused !== P &&
        ((P = player.paused),
        (e.innerHTML = player.paused
          ? '<i data-lucide="play"  style="width:18px;height:18px;"></i>'
          : '<i data-lucide="pause" style="width:18px;height:18px;"></i>'),
        "undefined" != typeof lucide && lucide.createIcons());
      const o = player.duration
        ? (player.currentTime / player.duration) * 100
        : 0;
      (t && (t.style.width = o + "%"),
        n && (n.textContent = formatTime(player.currentTime)),
        a && (a.textContent = formatTime(player.duration)));
    }
    ((window.toggleMiniPlayer = async function () {
      const e = document.getElementById("player-container"),
        t = document.querySelector(
          'div[class*="grid-cols-1"][class*="lg:grid-cols-3"]',
        ),
        n = t?.querySelector('div[class*="lg:col-span-2"]'),
        a = n?.nextElementSibling;
      if (isMiniPlayerActive)
        ((isMiniPlayerActive = !1),
          e && ((e.style.maxHeight = ""), (e.style.overflow = "")),
          document
            .getElementById("temp-index-content")
            ?.style.setProperty("display", "none"),
          t &&
            ((t.style.display = "grid"),
            t.classList.add(
              "grid",
              "grid-cols-1",
              "lg:grid-cols-3",
              "gap-6",
              "lg:gap-8",
            )),
          n?.classList.add("lg:col-span-2", "space-y-5"),
          a && (a.style.display = "block"),
          window.history.pushState({}, "", watchUrl));
      else {
        ((isMiniPlayerActive = !0),
          (skipResumeModalOnce = !0),
          a && (a.style.display = "none"),
          e && ((e.style.maxHeight = "120px"), (e.style.overflow = "hidden")),
          t &&
            (t.classList.remove(
              "grid",
              "grid-cols-1",
              "lg:grid-cols-3",
              "gap-6",
              "lg:gap-8",
            ),
            (t.style.cssText = "display:flex;flex-direction:column")));
        let n = document.getElementById("temp-index-content");
        if (n)
          ((n.style.display = "block"),
            window.history.pushState({ miniPlayer: !0 }, "", "index.php"));
        else {
          ((n = document.createElement("div")),
            (n.id = "temp-index-content"),
            (n.className = "w-full"),
            t?.appendChild(n));
          try {
            const e = await (await fetch("index.php")).text(),
              t = new DOMParser()
                .parseFromString(e, "text/html")
                .querySelector("main");
            t &&
              ((n.innerHTML = t.innerHTML),
              window.history.pushState({ miniPlayer: !0 }, "", "index.php"),
              window.lucide && lucide.createIcons(),
              window.htmx && htmx.process(n));
          } catch (e) {
            console.error("Failed to load index:", e);
          }
        }
      }
    }),
      setInterval(() => {
        isMiniPlayerActive && saveAudioState();
      }, 5e3),
      (window.miniPlayPause = function () {
        player &&
          (window.meelHealthAlertActive ||
            (player.paused ? player.play() : player.pause(), C()));
      }),
      (window.miniSeek = function (e) {
        if (!player) return;
        if (window.meelHealthAlertActive) return;
        const t = e.currentTarget.getBoundingClientRect();
        player.currentTime = ((e.clientX - t.left) / t.width) * player.duration;
      }),
      (window.miniNext = function () {
        if (window.meelHealthAlertActive || isNavigating) return;
        isNavigating = true;
        const e = window.MEEL_MUSIC_CONFIG?.nextSongUrl;
        if (e) (saveAudioState(), (window.location.href = e));
        else {
          const e = document.querySelector(".rekomendasi-item");
          if (e) window.location.href = e.href;
          else isNavigating = false; // reset jika tidak ada tujuan
        }
      }),
      (window.miniPrev = function () {
        player &&
          (window.meelHealthAlertActive ||
            (player.currentTime > 3
              ? (player.currentTime = 0)
              : window.history.length > 1
                ? window.history.back()
                : (player.currentTime = 0)));
      }),
      (window.goBackToLibrary = function () {
        saveAudioState();
        var e = window.MEEL_MUSIC_CONFIG?.playlistId;
        (player?.destroy(),
          (window.location.href =
            e && e > 0 ? "index.php?playlist_id=" + e : "index.php"));
      }),
      document
        .getElementById("player-container")
        ?.addEventListener("click", (e) => {
          e.target.closest(".plyr__controls") ||
            e.target.closest(".mp-controls") ||
            e.target.closest("button") ||
            (isMiniPlayerActive &&
              (e.preventDefault(), window.toggleMiniPlayer()));
        }),
      window.addEventListener("popstate", () => {
        isMiniPlayerActive &&
          window.location.href === watchUrl &&
          window.toggleMiniPlayer();
      }),
      "undefined" != typeof lucide && lucide.createIcons());
  }));
