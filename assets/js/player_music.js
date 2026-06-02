// ========================================
// MEeL MUSIC PLAYER - FIXED VERSION
// ========================================

// --- GLOBAL VARIABLES (Safe declarations) ---
let player;
let audio;
let storageKeyMusic;
let isFinished = false;
let canResume = false;
let isMiniPlayerActive = false;
let watchUrl;
let skipResumeModalOnce = false;

// --- GLOBAL UI FUNCTIONS (Safe for event listeners) ---

// Baca state loop global tanpa bergantung pada player object
function _applyLoopUIFromGlobal() {
  const isLoop = localStorage.getItem("meel_global_loop") === "true";
  const btnLoop = document.getElementById("btn-loop");
  const loopText = document.getElementById("loop-text");
  const miniLoopBtn = document.getElementById("mini-loop-btn");
  if (isLoop) {
    if (btnLoop) {
      btnLoop.classList.remove("bg-gray-800", "text-gray-400");
      btnLoop.classList.add(
        "bg-orange-500/10",
        "text-orange-500",
        "border",
        "border-orange-500/30",
      );
    }
    if (loopText) loopText.innerText = "Loop On";
    if (miniLoopBtn) {
      miniLoopBtn.style.color = "#f97316";
      miniLoopBtn.style.opacity = "1";
    }
  } else {
    if (btnLoop) {
      btnLoop.classList.add("bg-gray-800", "text-gray-400");
      btnLoop.classList.remove(
        "bg-orange-500/10",
        "text-orange-500",
        "border",
        "border-orange-500/30",
      );
    }
    if (loopText) loopText.innerText = "Loop Off";
    if (miniLoopBtn) {
      miniLoopBtn.style.color = "";
      miniLoopBtn.style.opacity = "0.5";
    }
  }
}

function updateLoopUI() {
  const btnLoop = document.getElementById("btn-loop");
  const loopText = document.getElementById("loop-text");
  const miniLoopBtn = document.getElementById("mini-loop-btn");

  if (!player) {
    _applyLoopUIFromGlobal();
    return;
  }

  if (player.loop) {
    if (btnLoop) {
      btnLoop.classList.remove("bg-gray-800", "text-gray-400");
      btnLoop.classList.add(
        "bg-orange-500/10",
        "text-orange-500",
        "border",
        "border-orange-500/30",
      );
    }
    if (loopText) loopText.innerText = "Loop On";
    if (miniLoopBtn) {
      miniLoopBtn.style.color = "#f97316";
      miniLoopBtn.style.opacity = "1";
    }
  } else {
    if (btnLoop) {
      btnLoop.classList.add("bg-gray-800", "text-gray-400");
      btnLoop.classList.remove(
        "bg-orange-500/10",
        "text-orange-500",
        "border",
        "border-orange-500/30",
      );
    }
    if (loopText) loopText.innerText = "Loop Off";
    if (miniLoopBtn) {
      miniLoopBtn.style.color = "";
      miniLoopBtn.style.opacity = "0.5";
    }
  }
}

window.toggleLoop = function () {
  // Bisa di-toggle bahkan sebelum player siap — baca state global dulu
  const currentLoop = localStorage.getItem("meel_global_loop") === "true";
  const newLoop = !currentLoop;

  // Simpan ke global key agar persisten lintas halaman (index ↔ watch)
  localStorage.setItem("meel_global_loop", String(newLoop));

  // Terapkan ke player jika sudah siap
  if (player) {
    player.loop = newLoop;
    updateLoopUI();

    // Sinkronisasi ke sessionStorage agar index.php juga membacanya
    const state = {
      musicId: window.MEEL_MUSIC_CONFIG ? window.MEEL_MUSIC_CONFIG.id : 0,
      currentTime: player.currentTime,
      isPlaying: !player.paused,
      isLooping: newLoop,
      title: window.MEEL_MUSIC_CONFIG ? window.MEEL_MUSIC_CONFIG.title : "",
      artist: window.MEEL_MUSIC_CONFIG ? window.MEEL_MUSIC_CONFIG.artist : "",
      thumbnail: window.MEEL_MUSIC_CONFIG
        ? window.MEEL_MUSIC_CONFIG.thumbnail
        : "",
      thumbnailUrl: window.MEEL_MUSIC_CONFIG
        ? window.MEEL_MUSIC_CONFIG.thumbnailUrl || ""
        : "",
      filename: window.MEEL_MUSIC_CONFIG
        ? window.MEEL_MUSIC_CONFIG.filename
        : "",
    };
    sessionStorage.setItem("meel_audio_state", JSON.stringify(state));
  } else {
    // Player belum siap: update UI tombol saja berdasarkan nilai baru
    _applyLoopUIFromGlobal();
  }
};

window.toggleVisualizer = function () {
  // Will be redefined inside DOMContentLoaded
};

window.toggleReply = function (id) {
  const element = document.getElementById(id);
  if (element) {
    element.classList.toggle("hidden");
    const input = element.querySelector('input[type="text"]');
    if (input && !element.classList.contains("hidden")) input.focus();
  }
};

// --- KEYBOARD SHORTCUTS (Safe globally) ---
document.addEventListener("keydown", (e) => {
  if (
    e.target.tagName.toLowerCase() === "input" ||
    e.target.tagName.toLowerCase() === "textarea"
  )
    return;

  const key = e.key.toLowerCase();
  if (key === "l") {
    e.preventDefault();
    window.toggleLoop();
  }
  if (key === "v") {
    if (typeof window.toggleVisualizer === "function") {
      window.toggleVisualizer();
    }
  }
  if (key === "i") {
    if (typeof window.toggleMiniPlayer === "function") {
      window.toggleMiniPlayer();
    }
  }
});

// ========================================
// MAIN INITIALIZATION (DOMContentLoaded)
// ========================================

document.addEventListener("DOMContentLoaded", () => {
  // --- Set watch URL for mini player toggle ---
  watchUrl = window.location.href;

  // --- Safety Check: Required elements & config ---
  audio = document.getElementById("main-player");
  if (!audio) {
    console.error("❌ Audio element #main-player tidak ditemukan");
    return;
  }

  if (!window.MEEL_MUSIC_CONFIG || !window.MEEL_MUSIC_CONFIG.id) {
    console.error("❌ MEEL_MUSIC_CONFIG belum diset dari watch.php");
    return;
  }

  storageKeyMusic = "music_pos_" + window.MEEL_MUSIC_CONFIG.id;

  // --- 1. INISIALISASI PLYR ---
  player = new Plyr("#main-player", {
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
    speed: {
      selected: 1,
      options: [0.5, 0.75, 1, 1.25, 1.5, 2],
    },
    keyboard: {
      focused: true,
      global: true,
    },
  });

  // --- 2. DOM ELEMENTS (Safe retrieval) ---
  const container = document.getElementById("player-container");
  const bitrateDisplay = document.getElementById("realtime-bitrate");
  const cavaContainer = document.getElementById("cava-container");

  if (!container || !bitrateDisplay || !cavaContainer) {
    console.error("❌ Container elements tidak ditemukan");
    return;
  }

  // Terapkan global loop state ke UI dan player segera saat halaman dimuat
  const _globalLoop = localStorage.getItem("meel_global_loop") === "true";
  if (player) player.loop = _globalLoop;
  updateLoopUI();

  // --- 3. AUDIO STATE RESTORATION (from mini-player) ---
  const audioState = sessionStorage.getItem("meel_audio_state");
  let shouldRestore = false;
  let restoreTime = 0;
  let restorePlayStatus = false;
  let restoreLooping = false;

  // Selalu baca global loop key dari localStorage (persists lintas halaman)
  restoreLooping = localStorage.getItem("meel_global_loop") === "true";

  if (audioState) {
    try {
      const state = JSON.parse(audioState);
      if (state.musicId == window.MEEL_MUSIC_CONFIG.id) {
        shouldRestore = true;
        restoreTime = state.currentTime;
        restorePlayStatus = state.isPlaying;
        // Prioritaskan global loop key, tapi juga baca isLooping dari state sebagai fallback
        if (state.isLooping !== undefined) {
          restoreLooping = state.isLooping;
          // Sinkronisasi balik ke global key jika state punya info lebih baru
          localStorage.setItem("meel_global_loop", String(state.isLooping));
        }
      }
    } catch (e) {
      console.log("⚠️ Error parsing audio state:", e);
    }
  }

  const savedTime = localStorage.getItem(storageKeyMusic);
  if (savedTime && !isFinished && !shouldRestore) {
    canResume = true;
  }

  // --- 4. VISUALIZER (CAVA) SETUP ---
  let isVisualizerEnabled = window.innerWidth >= 1024;
  const isMobile = window.innerWidth < 768;
  const numBars = isMobile ? 20 : 40;
  const bars = [];

  cavaContainer.innerHTML = "";
  for (let i = 0; i < numBars; i++) {
    const bar = document.createElement("div");
    bar.className =
      "flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75";
    bar.style.height = "4px";
    bar.style.minWidth = "1px";
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

  document.addEventListener("click", () => (userInteracted = true), {
    once: true,
  });
  document.addEventListener("keydown", () => (userInteracted = true), {
    once: true,
  });

  // --- 5. AUDIO CONTEXT & VISUALIZATION ---
  function initAudio() {
    try {
      if (!userInteracted) {
        console.warn("⚠️ Cava: Waiting for user gesture...");
        return false;
      }
      if (audioCtx && audioCtx.state !== "closed") {
        if (audioCtx.state === "suspended") {
          audioCtx.resume();
        }
        return true;
      }
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      analyser = audioCtx.createAnalyser();
      source = audioCtx.createMediaElementSource(audio);
      source.connect(analyser);
      analyser.connect(audioCtx.destination);
      analyser.fftSize = 256;
      isInitialized = true;
      return true;
    } catch (e) {
      console.error("❌ Cava init error:", e);
      return false;
    }
  }

  function render() {
    if (!isVisualizerEnabled || !isInitialized || player.paused) {
      cancelAnimationFrame(animationId);
      return;
    }
    const dataArray = new Uint8Array(analyser.frequencyBinCount);
    analyser.getByteFrequencyData(dataArray);

    let sum = 0;
    for (let i = 0; i < numBars; i++) {
      const idx = Math.floor(i * (dataArray.length / numBars) * 0.7);
      const val = dataArray[idx];
      bars[i].style.height = `${Math.max(4, (val / 255) * 100)}%`;
      sum += val;
    }
    const fluctuation = (sum / numBars / 128) * 0.25;
    if (isInitialized && bitrateDisplay) {
      bitrateDisplay.innerText = Math.round(baseBitrate * (0.85 + fluctuation));
    } else if (bitrateDisplay) {
      bitrateDisplay.innerText = Math.round(baseBitrate);
    }
    animationId = requestAnimationFrame(render);
  }

  // --- 6. VISUALIZER TOGGLE ---
  window.toggleVisualizer = function () {
    isVisualizerEnabled = !isVisualizerEnabled;
    const btnVis = document.getElementById("btn-vis");
    const visText = document.getElementById("vis-text");

    if (isVisualizerEnabled) {
      if (btnVis) {
        btnVis.classList.remove("bg-gray-800", "text-gray-400");
        btnVis.classList.add(
          "bg-orange-500/10",
          "text-orange-500",
          "border",
          "border-orange-500/30",
        );
      }
      if (visText) visText.innerText = "Vis On";
      cavaContainer.classList.remove("hidden");
      cavaContainer.style.display = "flex";
      if (!isInitialized && !player.paused) {
        if (initAudio()) render();
      } else if (!player.paused) {
        render();
      }
    } else {
      if (btnVis) {
        btnVis.classList.add("bg-gray-800", "text-gray-400");
        btnVis.classList.remove(
          "bg-orange-500/10",
          "text-orange-500",
          "border",
          "border-orange-500/30",
        );
      }
      if (visText) visText.innerText = "Vis Off";
      cavaContainer.style.display = "none";
      cancelAnimationFrame(animationId);
    }
  };

  // --- 7. VISUALIZER UI INIT ---
  setTimeout(() => {
    const btnVis = document.getElementById("btn-vis");
    const visText = document.getElementById("vis-text");
    if (isVisualizerEnabled) {
      if (btnVis) {
        btnVis.classList.add(
          "bg-orange-500/10",
          "text-orange-500",
          "border",
          "border-orange-500/30",
        );
        btnVis.classList.remove("bg-gray-800", "text-gray-400");
      }
      if (visText) visText.innerText = "Vis On";
      cavaContainer.classList.remove("hidden");
      cavaContainer.style.display = "flex";
    } else {
      if (btnVis) {
        btnVis.classList.add("bg-gray-800", "text-gray-400");
        btnVis.classList.remove(
          "bg-orange-500/10",
          "text-orange-500",
          "border",
          "border-orange-500/30",
        );
      }
      if (visText) visText.innerText = "Vis Off";
      cavaContainer.style.display = "none";
    }
  }, 100);

  // --- 8. RESUME MODAL LOGIC ---
  const modal = document.getElementById("resume-modal");
  const btnResume = document.getElementById("btn-resume");
  const btnRestart = document.getElementById("btn-restart");
  const displayTime = document.getElementById("resume-time");

  if (!modal || !btnResume || !btnRestart || !displayTime) {
    console.warn("⚠️ Resume modal elements belum lengkap");
  } else {
    let countdownText = document.createElement("p");
    countdownText.className = "text-[9px] text-gray-500 italic mb-4";
    displayTime.parentNode.after(countdownText);

    let autoRestartTimer;
    let countdownInterval;
    let sessionHandled = false;

    function showResumeModal() {
      // Skip modal jika toggle via 'i' key (mini player mode)
      if (skipResumeModalOnce) {
        skipResumeModalOnce = false;
        return;
      }

      // Skip modal jika navigasi berasal dari klik lagu di index.php
      if (sessionStorage.getItem("skip_resume_once") === "true") {
        sessionStorage.removeItem("skip_resume_once");
        return;
      }

      // Skip modal jika sedang memulihkan state dari mini-player
      if (shouldRestore) {
        return;
      }

      const savedPos = localStorage.getItem(storageKeyMusic);
      if (
        savedPos &&
        parseFloat(savedPos) > 10 &&
        (!player.duration || parseFloat(savedPos) < player.duration - 5)
      ) {
        sessionHandled = false;
        clearInterval(countdownInterval);
        clearTimeout(autoRestartTimer);
        let timeLeft = 15;
        const mins = Math.floor(savedPos / 60);
        const secs = Math.floor(savedPos % 60);
        displayTime.innerText = `${mins}:${secs.toString().padStart(2, "0")}`;
        audio.autoplay = false;
        player.autoplay = false;
        audio.currentTime = parseFloat(savedPos);
        modal.classList.remove("hidden");
        countdownText.innerText = `Otomatis putar dari awal dalam ${timeLeft}s...`;
        countdownInterval = setInterval(() => {
          timeLeft--;
          if (timeLeft >= 0) {
            countdownText.innerText = `Otomatis putar dari awal dalam ${timeLeft}s...`;
          } else {
            countdownText.innerText = `Otomatis putar dari awal...`;
            clearInterval(countdownInterval);
          }
        }, 1000);
        autoRestartTimer = setTimeout(() => {
          if (!sessionHandled && !modal.classList.contains("hidden")) {
            clearInterval(countdownInterval);
            btnRestart.click();
          }
        }, 15000);
      }
    }

    player.on("ready", () => {
      if (realFileSize > 0 && player.duration > 0) {
        baseBitrate = Math.round((realFileSize * 8) / (player.duration * 1000));
      }
      const plyrContainer = document.querySelector(".plyr");
      if (plyrContainer) {
        plyrContainer.tabIndex = 0;
        plyrContainer.focus();
      }

      if (shouldRestore) {
        player.currentTime = Math.max(0, restoreTime);
        player.loop = restoreLooping;
        // Sinkronisasi ke global key
        localStorage.setItem("meel_global_loop", String(restoreLooping));
        if (restorePlayStatus) {
          player.play().catch(() => console.log("Playback dimulai..."));
        } else {
          player.pause();
        }
        updateLoopUI();
        sessionStorage.removeItem("meel_audio_state");
      } else {
        const savedPos = localStorage.getItem(storageKeyMusic);
        if (
          savedPos &&
          parseFloat(savedPos) > 10 &&
          (!player.duration || parseFloat(savedPos) < player.duration - 5)
        ) {
          showResumeModal();
        } else {
          player.play().catch(() => console.log("Menunggu interaksi user..."));
        }
      }
    });

    audio.addEventListener("loadedmetadata", () => {
      showResumeModal();
    });

    btnResume.onclick = () => {
      sessionHandled = true;
      clearTimeout(autoRestartTimer);
      clearInterval(countdownInterval);
      const savedPos = localStorage.getItem(storageKeyMusic);
      player.currentTime = parseFloat(savedPos);
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

  // --- 9. PLYR EVENT LISTENERS ---
  player.on("play", () => {
    isFinished = false;
    container.classList.add("playing");
    const vinyl = document.querySelector(".vinyl-wrap .vinyl-spin");
    if (vinyl) vinyl.classList.add("playing");

    if (isVisualizerEnabled) {
      if (!isInitialized) {
        if (initAudio()) {
          render();
        }
      } else {
        render();
      }
    }
  });

  player.on("pause", () => {
    container.classList.remove("playing");
    const vinyl = document.querySelector(".vinyl-wrap .vinyl-spin");
    if (vinyl) vinyl.classList.remove("playing");
    cancelAnimationFrame(animationId);
  });

  player.on("timeupdate", () => {
    if (
      !isFinished &&
      player.currentTime > 0 &&
      player.currentTime < player.duration - 1
    ) {
      localStorage.setItem(storageKeyMusic, player.currentTime);
    }
  });

  player.on("ended", () => {
    const nextPlaylistTrack = window.MEEL_MUSIC_CONFIG.nextSongUrl;

    if (nextPlaylistTrack !== "") {
      window.location.href = nextPlaylistTrack;
    } else {
      localStorage.removeItem(storageKeyMusic);
      const nextTrack = document.querySelector(".rekomendasi-item");
      if (nextTrack) window.location.href = nextTrack.href;
    }
  });

  // --- 10. MINI PLAYER FUNCTIONALITY (SPA-style) ---
  window.toggleMiniPlayer = async function () {
    const playerContainer = document.getElementById("player-container");
    const mainGrid = document.querySelector(
      'div[class*="grid-cols-1"][class*="lg:grid-cols-3"]',
    );
    const leftColumn = mainGrid?.querySelector('div[class*="lg:col-span-2"]');
    const rightSidebar = leftColumn?.nextElementSibling;

    if (!isMiniPlayerActive) {
      isMiniPlayerActive = true;
      skipResumeModalOnce = true; // Abaikan resume modal saat toggle via 'i'

      // Hide sidebar (recommendations and playlist)
      if (rightSidebar) rightSidebar.style.display = "none";

      // Minimize player
      if (playerContainer) {
        playerContainer.style.maxHeight = "120px";
        playerContainer.style.overflow = "hidden";
      }

      // Adjust grid layout
      if (mainGrid) {
        mainGrid.classList.remove(
          "grid",
          "grid-cols-1",
          "lg:grid-cols-3",
          "gap-6",
          "lg:gap-8",
        );
        mainGrid.style.display = "flex";
        mainGrid.style.flexDirection = "column";
      }

      // Load index content into temp container
      let tempIndex = document.getElementById("temp-index-content");
      if (!tempIndex) {
        tempIndex = document.createElement("div");
        tempIndex.id = "temp-index-content";
        tempIndex.className = "w-full";
        if (mainGrid) mainGrid.appendChild(tempIndex);

        try {
          const response = await fetch("index.php");
          const html = await response.text();
          const doc = new DOMParser().parseFromString(html, "text/html");
          const indexMain = doc.querySelector("main");

          if (indexMain) {
            tempIndex.innerHTML = indexMain.innerHTML;
            window.history.pushState({ miniPlayer: true }, "", "index.php");

            if (window.lucide) window.lucide.createIcons();
            if (window.htmx) htmx.process(tempIndex);
          }
        } catch (err) {
          console.error("Gagal memuat index:", err);
        }
      } else {
        tempIndex.style.display = "block";
        window.history.pushState({ miniPlayer: true }, "", "index.php");
      }
    } else {
      isMiniPlayerActive = false;

      // Restore player
      if (playerContainer) {
        playerContainer.style.maxHeight = "";
        playerContainer.style.overflow = "";
      }

      const tempIndex = document.getElementById("temp-index-content");
      if (tempIndex) tempIndex.style.display = "none";

      // Restore grid layout
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

      if (leftColumn) leftColumn.classList.add("lg:col-span-2", "space-y-5");

      // Show sidebar
      if (rightSidebar) rightSidebar.style.display = "block";

      window.history.pushState({}, "", watchUrl);
    }
  };

  function saveAudioState() {
    const state = {
      musicId: window.MEEL_MUSIC_CONFIG.id,
      currentTime: player ? player.currentTime : 0,
      isPlaying: player ? !player.paused : false,
      isLooping: player ? player.loop : false,
      title: window.MEEL_MUSIC_CONFIG.title,
      artist: window.MEEL_MUSIC_CONFIG.artist,
      thumbnail: window.MEEL_MUSIC_CONFIG.thumbnail,
      thumbnailUrl: window.MEEL_MUSIC_CONFIG.thumbnailUrl || "",
      filename: window.MEEL_MUSIC_CONFIG.filename,
    };
    sessionStorage.setItem("meel_audio_state", JSON.stringify(state));
  }

  setInterval(() => {
    if (
      typeof window.isMiniPlayerActive !== "undefined" &&
      window.isMiniPlayerActive
    ) {
      saveAudioState();
    }
  }, 5000);

  window.miniPlayPause = function () {
    if (player) {
      if (player.paused) {
        player.play();
      } else {
        player.pause();
      }
      updateMiniPlayerUI();
    }
  };

  window.miniSeek = function (event) {
    if (!player) return;
    const bar = event.currentTarget;
    const rect = bar.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const percentage = clickX / rect.width;
    player.currentTime = percentage * player.duration;
  };

  function updateMiniPlayerUI() {
    if (
      typeof window.isMiniPlayerActive === "undefined" ||
      !window.isMiniPlayerActive
    )
      return;

    const miniPlayBtn = document.getElementById("mini-play-btn");
    const miniProgressFill = document.getElementById("mini-progress-fill");
    const miniCurrentTime = document.getElementById("mini-current-time");
    const miniDuration = document.getElementById("mini-duration");

    if (player.paused) {
      if (miniPlayBtn)
        miniPlayBtn.innerHTML =
          '<i data-lucide="play" style="width: 18px; height: 18px;"></i>';
    } else {
      if (miniPlayBtn)
        miniPlayBtn.innerHTML =
          '<i data-lucide="pause" style="width: 18px; height: 18px;"></i>';
    }

    const percentage = (player.currentTime / player.duration) * 100;
    if (miniProgressFill) miniProgressFill.style.width = percentage + "%";

    if (miniCurrentTime)
      miniCurrentTime.textContent = formatTime(player.currentTime);
    if (miniDuration) miniDuration.textContent = formatTime(player.duration);

    if (typeof lucide !== "undefined") lucide.createIcons();
  }

  function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return "0:00";
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, "0")}`;
  }

  player.on("play", () => {
    updateMiniPlayerUI();
  });

  player.on("pause", () => {
    updateMiniPlayerUI();
  });

  player.on("timeupdate", () => {
    updateMiniPlayerUI();
  });

  player.on("loadedmetadata", () => {
    updateMiniPlayerUI();
  });

  window.goBackToLibrary = function () {
    const config = window.MEEL_MUSIC_CONFIG || {};
    const state = {
      musicId: config.id || "",
      id: config.id || "",
      title: config.title || "",
      artist: config.artist || "",
      thumbnail: config.thumbnail || "",
      thumbnailUrl: config.thumbnailUrl || "",
      filename: config.filename || "",
      nextSongUrl: config.nextSongUrl || "",
      currentTime: player ? player.currentTime : 0,
      isPlaying: player ? !player.paused : false,
    };
    sessionStorage.setItem("meel_audio_state", JSON.stringify(state));
    if (player) {
      player.destroy();
    }
    window.location.href = "index.php";
  };

  // --- 11. KEYBOARD SHORTCUTS (In-page) ---
  document.addEventListener("keydown", (e) => {
    if (
      e.target.tagName.toLowerCase() === "input" ||
      e.target.tagName.toLowerCase() === "textarea"
    )
      return;

    if (e.key.toLowerCase() === "i" && !e.ctrlKey && !e.altKey && !e.metaKey) {
      e.preventDefault();
      window.goBackToLibrary();
    }
  });

  // --- 11. MINI PLAYER EVENT LISTENERS ---
  const playerContainer = document.getElementById("player-container");
  if (playerContainer) {
    playerContainer.addEventListener("click", (e) => {
      if (isMiniPlayerActive) {
        e.preventDefault();
        window.toggleMiniPlayer();
      }
    });
  }

  window.addEventListener("popstate", (e) => {
    if (isMiniPlayerActive && window.location.href === watchUrl) {
      window.toggleMiniPlayer();
    }
  });

  // --- 12. MINI PLAYER NEXT / PREV ---
  window.miniNext = function () {
    const nextUrl = window.MEEL_MUSIC_CONFIG?.nextSongUrl;
    if (nextUrl && nextUrl !== "") {
      // Simpan posisi terakhir ke sessionStorage sebelum pindah
      if (player) {
        const state = {
          musicId: window.MEEL_MUSIC_CONFIG.id,
          currentTime: player.currentTime,
          isPlaying: !player.paused,
          title: window.MEEL_MUSIC_CONFIG.title,
          artist: window.MEEL_MUSIC_CONFIG.artist,
          thumbnail: window.MEEL_MUSIC_CONFIG.thumbnail,
          thumbnailUrl: window.MEEL_MUSIC_CONFIG.thumbnailUrl || "",
          filename: window.MEEL_MUSIC_CONFIG.filename,
        };
        sessionStorage.setItem("meel_audio_state", JSON.stringify(state));
      }
      window.location.href = nextUrl;
    } else {
      // Fallback: ambil lagu pertama dari daftar rekomendasi
      const firstRec = document.querySelector(".rekomendasi-item");
      if (firstRec) window.location.href = firstRec.href;
    }
  };

  window.miniPrev = function () {
    if (!player) return;
    // Jika sudah > 3 detik, restart lagu yang sama
    if (player.currentTime > 3) {
      player.currentTime = 0;
      return;
    }
    // Kalau di awal, coba kembali ke halaman sebelumnya di history browser
    if (window.history.length > 1) {
      window.history.back();
    } else {
      player.currentTime = 0;
    }
  };

  // --- 13. ICONS INITIALIZATION ---
  if (typeof lucide !== "undefined") {
    lucide.createIcons();
  }
});
