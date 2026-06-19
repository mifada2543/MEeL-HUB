// Validasi ketersediaan data dari PHP (Jembatan Data)
const config = window.playerConfig || {};

// 1. Deklarasi Variabel Utama
let videoElement;
let videoSrc = config.videoSrc || "";
let isHls = config.isHls || false;
let vttSrc = config.vttSrc || "";
let videoId = config.id || "";
let videoTitle = config.title || "";
let videoUploader = config.uploader || "";
let storageKeyVideo = `video_pos_${videoId}`;

let player;
let hls;
let isAutoRecovering = false;
let isRecovering = false;
let isCheckingStatus = false;

// Variabel Kontrol Pemulihan & Cooldown
let recoveryDelay = 10000; // Mulai dari 10 detik
let lastRecoveryTime = 0;
let recoveryTimeoutId = null;
let playbackStartTimestamp = 0;

// Detektor stuck
let lastPlayTime = -1;
let lastTimeUpdateTimestamp = Date.now();
let stuckCheckInterval = null;

const isTouchDevice = "ontouchstart" in window || navigator.maxTouchPoints > 0;

// Inisialisasi Icon
if (window.lucide) {
  lucide.createIcons();
}

// 2. Konfigurasi UI Plyr Dasar
const plyrOptions = {
  iconUrl: "../assets/plyr.svg",
  controls: [
    "play-large",
    "play",
    "progress",
    "current-time",
    "duration",
    "mute",
    "volume",
    "captions",
    "settings",
    "pip",
    "airplay",
    "fullscreen",
  ],
  settings: ["quality", "speed"],
  speed: {
    selected: 1,
    options: [0.5, 0.75, 1, 1.25, 1.5, 2],
  },
  tooltips: {
    controls: true,
    seek: true,
  },
  clickToPlay: !isTouchDevice,
  keyboard: {
    focused: true,
    global: true,
  },
  previewThumbnails: {
    enabled: vttSrc !== "",
    src: vttSrc,
  },
};

const HLS_CONFIG = {
  maxBufferLength: 30,
  enableWorker: true,
  backBufferLength: 60,
};

function destroyPlayer() {
  stopStuckDetector();
  if (player) {
    try {
      player.destroy();
    } catch (e) {
      console.error("Gagal destroy player:", e);
    }
    player = null;
  }
  if (hls) {
    try {
      hls.destroy();
    } catch (e) {
      console.error("Gagal destroy hls:", e);
    }
    hls = null;
  }
}

function showReconnectingIndicator() {
  const container = document.getElementById("main-video-wrapper");
  if (!container) return;

  const existing = document.getElementById("meel-reconnect-indicator");
  if (existing) existing.remove();

  const indicator = document.createElement("div");
  indicator.id = "meel-reconnect-indicator";
  indicator.className =
    "absolute inset-0 bg-[#080a0f]/95 flex flex-col items-center justify-center z-[100] text-white gap-3 p-4 text-center rounded-none sm:rounded-none";
  indicator.innerHTML = `
    <div class="animate-spin h-8 w-8 border-4 border-red-600 border-t-transparent rounded-full"></div>
    <div class="text-sm font-bold uppercase tracking-wider text-white">Sambungan Media Terputus</div>
    <div class="text-xs text-gray-500">Mencoba menghubungkan kembali secara otomatis...</div>
  `;
  container.appendChild(indicator);
}

function checkMediaAndRecover() {
  if (isCheckingStatus) return;
  isCheckingStatus = true;

  showReconnectingIndicator();

  console.log(`Mengecek ketersediaan file media di: ${videoSrc}`);

  // Gunakan AbortController untuk membatasi waktu tunggu fetch maksimal 3 detik
  // Guna mengantisipasi OS hang yang lama saat mengakses mountpoint yang terlepas
  const controller = new AbortController();
  const timeoutId = setTimeout(() => {
    controller.abort();
  }, 3000);

  fetch(videoSrc, { method: "HEAD", signal: controller.signal })
    .then((response) => {
      clearTimeout(timeoutId);
      const contentType = response.headers.get("content-type") || "";

      // Pastikan response ok (status 2xx) DAN tipe konten bukan halaman HTML (biasanya custom error page 200 OK dari server)
      if (response.ok && !contentType.includes("text/html")) {
        console.log("Media terdeteksi online! Memulai pemulihan via HTMX...");

        // Simpan posisi detik terakhir sebelum swap
        const recoveryTime = player ? player.currentTime : 0;
        if (recoveryTime > 0) {
          localStorage.setItem(storageKeyVideo, recoveryTime);
        }

        isRecovering = true;
        isAutoRecovering = true;

        if (window.htmx) {
          htmx.ajax("GET", window.location.href, {
            target: "#main-video-wrapper",
            select: "#main-video-wrapper",
            swap: "outerHTML",
          });
        } else {
          window.location.reload();
        }
        isCheckingStatus = false;
      } else {
        console.log(
          "Media masih offline (kembalian server bukan file media). Menguji ulang dalam 3 detik...",
        );
        setTimeout(() => {
          isCheckingStatus = false;
          checkMediaAndRecover();
        }, 3000);
      }
    })
    .catch((error) => {
      clearTimeout(timeoutId);
      console.log(
        "Koneksi media gagal/offline atau timeout. Menguji ulang dalam 3 detik...",
      );
      setTimeout(() => {
        isCheckingStatus = false;
        checkMediaAndRecover();
      }, 3000);
    });
}

function triggerPlayerRecovery() {
  if (isRecovering || isCheckingStatus) return;

  const now = Date.now();
  if (now - lastRecoveryTime < recoveryDelay) {
    console.log("Menunda pemulihan: masih dalam masa cooldown.");
    return;
  }

  lastRecoveryTime = now;
  stopStuckDetector();
  checkMediaAndRecover();
}

function startStuckDetector() {
  stopStuckDetector();
  stuckCheckInterval = setInterval(() => {
    if (!player || player.paused || isRecovering) return;

    const currentVideoTime = player.currentTime;
    const now = Date.now();

    if (currentVideoTime === lastPlayTime) {
      const secondsStuck = (now - lastTimeUpdateTimestamp) / 1000;
      if (secondsStuck >= 3) {
        triggerPlayerRecovery();
      }
    } else {
      lastPlayTime = currentVideoTime;
      lastTimeUpdateTimestamp = now;
    }
  }, 1000);
}

function stopStuckDetector() {
  if (stuckCheckInterval) {
    clearInterval(stuckCheckInterval);
    stuckCheckInterval = null;
  }
}

function registerHlsErrorListener(hlsInstance) {
  hlsInstance.on(Hls.Events.ERROR, function (event, data) {
    if (data.fatal) {
      console.warn("Fatal HLS error encountered:", data.type);
      switch (data.type) {
        case Hls.ErrorTypes.NETWORK_ERROR:
          triggerPlayerRecovery();
          break;
        case Hls.ErrorTypes.MEDIA_ERROR:
          hlsInstance.recoverMediaError();
          break;
        default:
          triggerPlayerRecovery();
          break;
      }
    }
  });
}

// 3. Inisialisasi Engine Pemutar
function initPlayer() {
  videoElement = document.getElementById("main-video");
  if (!videoElement) return;

  if (isHls && window.Hls && Hls.isSupported()) {
    hls = new Hls(HLS_CONFIG);

    registerHlsErrorListener(hls);
    hls.loadSource(videoSrc);
    hls.attachMedia(videoElement);

    hls.on(Hls.Events.MANIFEST_PARSED, function () {
      const availableQualities = hls.levels.map((l) => l.bitrate);

      if (availableQualities.length > 1) {
        plyrOptions.quality = {
          default: availableQualities[0],
          options: availableQualities,
          forced: true,
          onChange: (newBitrate) => {
            const levelIndex = hls.levels.findIndex(
              (l) => l.bitrate === newBitrate,
            );
            hls.currentLevel = levelIndex;
          },
        };

        plyrOptions.i18n = {
          qualityLabel: {},
        };

        hls.levels.forEach((level) => {
          const label = level.name
            ? level.name
            : `${level.height}p (${Math.round(level.bitrate / 1000)}kbps)`;
          plyrOptions.i18n.qualityLabel[level.bitrate] = label;
        });
      }

      if (!player) {
        player = new Plyr(videoElement, plyrOptions);
        setupMeelPlayerEvents();
      }
    });
  } else {
    player = new Plyr(videoElement, plyrOptions);
    if (isHls) videoElement.src = videoSrc;
    setupMeelPlayerEvents();
  }
}

// Jalankan inisialisasi awal
document.addEventListener("DOMContentLoaded", () => {
  initPlayer();
});

// Listener untuk HTMX setelah swap player
document.addEventListener("htmx:afterSwap", function (event) {
  if (event.detail.target.id === "main-video-wrapper") {
    destroyPlayer();
    isRecovering = false;
    initPlayer();
  }
});

// 4. Kumpulan Event & Fitur Player
function setupMeelPlayerEvents() {
  window.player = player;

  const modal = document.getElementById("resume-modal");
  const btnResume = document.getElementById("btn-resume");
  const btnRestart = document.getElementById("btn-restart");
  const displayTime = document.getElementById("resume-time");
  const countdownText = document.getElementById("resume-countdown");

  // Deteksi & terapkan aspect ratio asli video ke wrapper (dan mini-player shell)
  function applyNativeAspectRatio() {
    const wrapper = document.getElementById("main-video-wrapper");
    const vid = videoElement;
    if (!wrapper || !vid || !vid.videoWidth || !vid.videoHeight) return;

    const vw = vid.videoWidth;
    const vh = vid.videoHeight;
    const gcd = (a, b) => (b === 0 ? a : gcd(b, a % b));
    const g = gcd(vw, vh);
    console.log(`[MEeL] Aspect ratio video: ${vw / g}:${vh / g} (${vw}x${vh})`);

    // Saat mini-player aktif: jangan set aspect-ratio/height pada wrapper,
    // biarkan CSS shell yang mengatur dimensi via width: 100%
    if (!isMiniPlayerActive) {
      wrapper.style.aspectRatio = `${vw} / ${vh}`;
    }
  }

  // Apply segera jika metadata sudah ada, atau tunggu event
  if (videoElement.readyState >= 1 && videoElement.videoWidth) {
    applyNativeAspectRatio();
  } else {
    videoElement.addEventListener("loadedmetadata", applyNativeAspectRatio, {
      once: true,
    });
  }

  player.on("ready", (event) => {
    applyNativeAspectRatio();
    if (vttSrc) {
      setTimeout(() => refreshVttSprites(vttSrc), 300);
    } else {
      player.config.previewThumbnails.enabled = false;
      const thumbEl = document.querySelector(".plyr__preview-thumb");
      if (thumbEl) thumbEl.style.display = "none";
    }
    const savedPos = localStorage.getItem(storageKeyVideo);

    // Aliran auto-recovery
    if (isAutoRecovering && savedPos) {
      isAutoRecovering = false;
      player.currentTime = parseFloat(savedPos);
      player.play().catch(() => {});
      startStuckDetector();
      return;
    }

    if (savedPos && parseFloat(savedPos) > 10) {
      const mins = Math.floor(savedPos / 60);
      const secs = Math.floor(savedPos % 60);
      if (displayTime)
        displayTime.innerText = `${mins}:${secs.toString().padStart(2, "0")}`;
      if (modal) modal.classList.remove("hidden");

      let timeLeft = 15;
      const countdownInterval = setInterval(() => {
        timeLeft--;
        if (timeLeft > 0) {
          if (countdownText)
            countdownText.innerText = `Otomatis ulang dari awal dalam ${timeLeft}s...`;
        } else {
          clearInterval(countdownInterval);
        }
      }, 1000);

      const autoRestartTimer = setTimeout(() => {
        if (btnRestart) btnRestart.click();
      }, 15000);

      if (btnResume) {
        btnResume.onclick = () => {
          clearTimeout(autoRestartTimer);
          clearInterval(countdownInterval);
          player.currentTime = parseFloat(savedPos);
          player.play();
          modal.classList.add("hidden");
        };
      }

      if (btnRestart) {
        btnRestart.onclick = () => {
          clearTimeout(autoRestartTimer);
          clearInterval(countdownInterval);
          localStorage.removeItem(storageKeyVideo);
          player.currentTime = 0;
          player.play();
          modal.classList.add("hidden");
        };
      }
    } else {
      player.play().catch(() => console.log("Menunggu interaksi user..."));
    }
  });

  const onPlaybackStart = () => {
    playbackStartTimestamp = Date.now();
    lastTimeUpdateTimestamp = Date.now();
    if (player) lastPlayTime = player.currentTime;
    startStuckDetector();
  };
  player.on("play", onPlaybackStart);
  player.on("playing", onPlaybackStart);

  player.on("pause", () => {
    stopStuckDetector();
  });

  player.on("seeking", () => {
    lastTimeUpdateTimestamp = Date.now();
  });

  player.on("seeked", () => {
    lastTimeUpdateTimestamp = Date.now();
  });

  player.on("timeupdate", () => {
    if (player.currentTime > 0) {
      localStorage.setItem(storageKeyVideo, player.currentTime);
      lastPlayTime = player.currentTime;
      lastTimeUpdateTimestamp = Date.now();

      // Jika berhasil memutar tanpa masalah selama 5 detik, reset delay pemulihan ke default
      if (
        playbackStartTimestamp > 0 &&
        Date.now() - playbackStartTimestamp > 5000
      ) {
        recoveryDelay = 5000;
        playbackStartTimestamp = 0;
      }
    }
  });

  player.on("ended", async () => {
    stopStuckDetector();
    if (player.loop) return;
    localStorage.removeItem(storageKeyVideo);
    const nextVideoLink = document.querySelector(".rekomendasi-item");
    if (!nextVideoLink) return;

    const wasFullscreen =
      player.fullscreen.active || !!document.fullscreenElement;

    try {
      const response = await fetch(nextVideoLink.href);
      const html = await response.text();
      const parser = new DOMParser();
      const doc = parser.parseFromString(html, "text/html");

      window.history.pushState({}, "", nextVideoLink.href);
      document.title = doc.title;

      const newVideoEl = doc.getElementById("main-video");
      if (!newVideoEl) throw new Error("Video elemen tidak ditemukan");

      const newSrc = newVideoEl.getAttribute("data-src");
      const newIsHls = newVideoEl.getAttribute("data-ishls") === "true";
      const newPoster = newVideoEl.getAttribute("data-poster");
      const newVtt = newVideoEl.getAttribute("data-vtt");

      // Update variabel global agar video selanjutnya dapat memproses localstorage dan antrean dengan benar
      videoId =
        new URL(nextVideoLink.href, window.location.href).searchParams.get(
          "id",
        ) || videoId;
      storageKeyVideo = `video_pos_${videoId}`;
      vttSrc = newVtt;

      // ─── ISI PERBAIKAN: AMBIL TITLE & UPLOADER BARU DI SINI ───
      let fetchedConfig = {};
      doc.querySelectorAll("script:not([src])").forEach((s) => {
        const m = s.textContent.match(
          /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
        );
        if (m) {
          try {
            fetchedConfig = new Function("return " + m[1])();
          } catch (e) {}
        }
      });

      // Update nilai variabel global judul & uploader
      videoTitle = fetchedConfig.title || "";
      videoUploader = fetchedConfig.uploader || "";

      // Sinkronkan objek playerConfig global
      window.playerConfig = {
        videoSrc: newSrc,
        isHls: newIsHls,
        vttSrc: newVtt,
        id: videoId,
        title: videoTitle,
        uploader: videoUploader,
      };

      // Jika sedang berada di mode mini-player, langsung perbarui teks UI-nya
      if (isMiniPlayerActive) {
        updateMiniPlayerInfo(videoTitle, videoUploader);
      }
      // ─────────────────────────────────────────────────────────

      // Ganti array swapElements lama kamu dengan ini
      const swapElements = [
        "watch-details-wrapper", // Mengganti video-info dan comment-section
        "recommendation-column",
      ];
      swapElements.forEach((id) => {
        const currentEl = document.getElementById(id);
        const newEl = doc.getElementById(id);
        if (currentEl && newEl) currentEl.innerHTML = newEl.innerHTML;
      });

      if (window.lucide) window.lucide.createIcons();
      if (window.htmx) htmx.process(document.body);

      // Jalankan kalkulasi tombol Read More deskripsi baru (khusus mode full player)
      if (!isMiniPlayerActive) {
        requestAnimationFrame(() => {
          const d = document.getElementById("desc-text");
          const b = document.getElementById("btn-read-more");
          if (d && b && d.scrollHeight > d.clientHeight)
            b.classList.remove("hidden");
        });
      }

      player.poster = newPoster;

      if (newIsHls) {
        if (!hls && window.Hls && Hls.isSupported()) {
          hls = new Hls(HLS_CONFIG);
          registerHlsErrorListener(hls);
          hls.attachMedia(player.media);
        } else if (hls && hls.media !== player.media) {
          hls.detachMedia();
          hls.attachMedia(player.media);
        }
        hls.loadSource(newSrc);
      } else {
        if (hls) {
          hls.destroy();
          hls = null;
        }
        player.media.src = newSrc;
        player.media.load();
      }

      const playPromise = player.play();
      if (playPromise !== undefined) {
        playPromise.catch((e) => {
          console.error("Autoplay dicegah oleh browser:", e);
        });
      }

      if (newVtt) {
        setTimeout(() => refreshVttSprites(newVtt), 300);
      } else {
        player.config.previewThumbnails.enabled = false;
        const thumbEl = document.querySelector(".plyr__preview-thumb");
        if (thumbEl) thumbEl.style.display = "none";
      }

      // Re-enter fullscreen if it was active before transition
      if (wasFullscreen && !player.fullscreen.active) {
        player.fullscreen.toggle();
        // Setelah fullscreen re-entry, refresh VTT lagi karena browser mobile
        // bisa me-recreate/clone elemen thumbnail
        if (newVtt) {
          setTimeout(() => refreshVttSprites(newVtt), 500);
          setTimeout(() => refreshVttSprites(newVtt), 1500);
        }
      }
    } catch (err) {
      console.error("Gagal transisi seamless, fallback ke reload:", err);
      window.location.href = nextVideoLink.href;
    }
  });

  player.on("enterfullscreen", () => {
    if (screen.orientation?.lock) {
      screen.orientation.lock("landscape").catch(() => {});
    }
    // Re-apply VTT sprites saat masuk fullscreen (handle mobile cloning)
    if (vttSrc) {
      setTimeout(() => refreshVttSprites(vttSrc), 300);
    }
  });

  player.on("exitfullscreen", () => {
    if (screen.orientation?.unlock) {
      screen.orientation.unlock();
    }
  });

  setupMobileGestures();
}

// 5. Fungsi Penunjang UI
function updateLoopUI() {
  const btnLoop = document.getElementById("btn-loop");
  const loopText = document.getElementById("loop-text");
  if (!btnLoop || !loopText) return;

  if (player.loop) {
    btnLoop.classList.remove("bg-gray-800", "text-gray-400");
    btnLoop.classList.add("bg-red-500/10", "text-red-400", "border-red-600/30");
    loopText.innerText = "Loop On";
  } else {
    btnLoop.classList.add("bg-gray-800", "text-gray-400");
    btnLoop.classList.remove(
      "bg-red-500/10",
      "text-red-400",
      "border-red-600/30",
    );
    loopText.innerText = "Loop Off";
  }
}

window.toggleLoop = function () {
  if (player) {
    player.loop = !player.loop;
    updateLoopUI();
  }
};

// --- FITUR MINI PLAYER SPA ---
let isMiniPlayerActive = false;
let watchUrl = window.location.href;

let miniShell = null;

function buildMiniShell(videoWrapper) {
  const shell = document.createElement("div");
  shell.id = "mini-player-shell";

  // Tombol expand (kiri atas)
  const expandBtn = document.createElement("button");
  expandBtn.id = "mini-expand-btn";
  expandBtn.title = "Perlebar player";
  expandBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M3 3h7v2H5v5H3V3zm11 0h7v7h-2V5h-5V3zM3 14h2v5h5v2H3v-7zm16 5h-5v2h7v-7h-2v5z"/></svg>`;
  expandBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    toggleMiniPlayer();
  });

  // Tombol close (kanan atas)
  const closeBtn = document.createElement("button");
  closeBtn.id = "mini-close-btn";
  closeBtn.title = "Tutup mini player";
  closeBtn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>`;
  closeBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    closeMiniPlayer();
  });

  shell.appendChild(videoWrapper);
  shell.appendChild(expandBtn);
  shell.appendChild(closeBtn);

  // Info panel (klik → kembali ke watch)
  const titleText = videoTitle;
  const uploaderText = videoUploader;
  const infoPanel = document.createElement("div");
  infoPanel.id = "mini-player-info";
  infoPanel.title = "Kembali ke video";
  infoPanel.innerHTML = `
    <div style="flex:1;min-width:0;">
      <div id="mini-info-title">${titleText}</div>
      <div id="mini-info-uploader">${uploaderText}</div>
    </div>
  `;
  infoPanel.addEventListener("click", () => toggleMiniPlayer());
  shell.appendChild(infoPanel);

  return shell;
}

function closeMiniPlayer() {
  if (!isMiniPlayerActive) return;
  if (player) player.pause();
  window.location.href = "index.php";
}

// Update teks title & uploader di info panel mini-player
function updateMiniPlayerInfo(title, uploader) {
  const titleEl = document.getElementById("mini-info-title");
  const uploaderEl = document.getElementById("mini-info-uploader");
  if (titleEl) titleEl.textContent = title || "";
  if (uploaderEl) uploaderEl.textContent = uploader || "";
}

// Intercept klik video card saat mini-player aktif:
// fetch watch.php baru → update src video + title tanpa full reload
function attachMiniPlayerVideoCardListeners(container) {
  if (!container) return;

  container.querySelectorAll('a[href*="watch.php"]').forEach((link) => {
    if (link.dataset.miniIntercepted) return;
    link.dataset.miniIntercepted = "1";

    link.addEventListener("click", async (e) => {
      if (!isMiniPlayerActive) return;
      e.preventDefault();

      const targetUrl = link.href;

      try {
        const res = await fetch(targetUrl);
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, "text/html");

        // Parse playerConfig dari script tag halaman yang di-fetch
        let fetchedConfig = {};
        doc.querySelectorAll("script:not([src])").forEach((s) => {
          const m = s.textContent.match(
            /window\.playerConfig\s*=\s*(\{[\s\S]*?\});/,
          );
          if (m) {
            try {
              // Evaluasi format JavaScript Object Literal (bukan strict JSON)
              fetchedConfig = new Function("return " + m[1])();
            } catch (e) {
              console.error("Gagal parse playerConfig:", e);
            }
          }
        });

        const newVideoEl = doc.getElementById("main-video");
        const newTitle = fetchedConfig.title || "";
        const newUploader = fetchedConfig.uploader || "";
        const newSrc = fetchedConfig.videoSrc || newVideoEl?.dataset?.src || "";
        const newIsHls =
          fetchedConfig.isHls === true || fetchedConfig.isHls === "true";
        const newPoster = newVideoEl?.dataset?.poster || "";
        const newId =
          fetchedConfig.id || new URL(targetUrl).searchParams.get("id") || "";
        const newVttSrc = fetchedConfig.vttSrc || "";

        if (!newSrc) {
          window.location.href = targetUrl;
          return;
        }

        // Update variabel global
        storageKeyVideo = `video_pos_${newId}`;
        videoSrc = newSrc;
        isHls = newIsHls;
        vttSrc = newVttSrc;
        videoId = newId;

        destroyPlayer();

        const videoEl = document.getElementById("main-video");
        if (videoEl) {
          videoEl.innerHTML = "";
          videoEl.dataset.src = newSrc;
          videoEl.dataset.ishls = newIsHls ? "true" : "false";
          videoEl.dataset.poster = newPoster;
          videoEl.poster = newPoster;
          if (!newIsHls) {
            videoEl.src = newSrc;
          } else {
            videoEl.removeAttribute("src");
          }

          videoEl.load();
        }
        videoTitle = newTitle;
        videoUploader = newUploader;
        window.playerConfig = {
          videoSrc: newSrc,
          isHls: newIsHls,
          vttSrc: newVttSrc,
          id: newId,
          title: newTitle,
          uploader: newUploader,
        };
        initPlayer();
        updateMiniPlayerInfo(newTitle, newUploader);
        document.title = doc.title;
        const swapElements = ["watch-details-wrapper", "recommendation-column"];
        swapElements.forEach((id) => {
          const currentEl = document.getElementById(id);
          const newEl = doc.getElementById(id);
          if (currentEl && newEl) currentEl.innerHTML = newEl.innerHTML;
        });
        if (window.lucide) window.lucide.createIcons();
        if (window.htmx) htmx.process(document.body);
        window.history.pushState({ miniPlayer: true }, "", targetUrl);
        const tempIndex = document.getElementById("temp-index-content");
        if (tempIndex) attachMiniPlayerVideoCardListeners(tempIndex);
      } catch (err) {
        console.error("Gagal ganti video di mini-player:", err);
        window.location.href = targetUrl;
      }
    });
  });
}

window.toggleMiniPlayer = async function () {
  const videoWrapper = document.getElementById("main-video-wrapper");
  const detailsWrapper = document.getElementById("watch-details-wrapper");
  const recWrapper = document.getElementById("recommendation-wrapper");
  const appContentGrid = document.getElementById("app-content-grid");
  const leftColumn = document.getElementById("left-column");

  if (!isMiniPlayerActive) {
    isMiniPlayerActive = true;

    // Reset inline style dari halaman normal sebelum masuk mini-player
    if (videoWrapper) {
      videoWrapper.style.removeProperty("aspect-ratio");
      videoWrapper.style.removeProperty("height");
    }

    miniShell = buildMiniShell(videoWrapper);
    videoWrapper.classList.add("mini-player-mode");
    // CSS handles hiding non-progress controls via .mini-player-mode .plyr__controls > *:not(.plyr__progress__container)

    document.body.appendChild(miniShell);
    document.body.style.paddingBottom = "120px";

    if (detailsWrapper) detailsWrapper.style.display = "none";
    if (recWrapper) recWrapper.style.display = "none";
    if (appContentGrid) appContentGrid.style.display = "none";

    let tempIndex = document.getElementById("temp-index-content");
    if (!tempIndex) {
      tempIndex = document.createElement("div");
      tempIndex.id = "temp-index-content";
      tempIndex.className = "w-full";
      const footer =
        document.querySelector("footer") ?? document.body.lastElementChild;
      document.body.insertBefore(tempIndex, footer);

      try {
        const response = await fetch("index.php");
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, "text/html");
        const indexMain = doc.querySelector("main");
        if (indexMain) {
          tempIndex.innerHTML = indexMain.outerHTML;
          window.history.pushState({ miniPlayer: true }, "", "index.php");
          if (window.lucide) window.lucide.createIcons();
          if (window.htmx) htmx.process(tempIndex);
          attachMiniPlayerVideoCardListeners(tempIndex);
        }
      } catch (err) {
        console.error("Gagal memuat index:", err);
      }
    } else {
      tempIndex.style.display = "block";
      window.history.pushState({ miniPlayer: true }, "", "index.php");
      attachMiniPlayerVideoCardListeners(tempIndex);
    }
  } else {
    isMiniPlayerActive = false;

    const videoWrap = document.getElementById("main-video-wrapper");
    if (videoWrap) {
      videoWrap.classList.remove("mini-player-mode");

      // Reset semua inline style yang diterapkan selama mode mini-player
      videoWrap.style.removeProperty("aspect-ratio");
      videoWrap.style.removeProperty("height");
      videoWrap.style.removeProperty("width");
      videoWrap.style.removeProperty("position");

      // Kembalikan aspect-ratio default watch page (16/9)
      videoWrap.style.aspectRatio = "16 / 9";

      // Reset tampilan control Plyr
      if (player?.elements?.controls) {
        Array.from(player.elements.controls.children).forEach(
          (el) => (el.style.display = ""),
        );
      }

      // Kembalikan wrapper ke posisi semula di left-column
      if (leftColumn) leftColumn.insertBefore(videoWrap, leftColumn.firstChild);
    }

    if (miniShell) {
      miniShell.remove();
      miniShell = null;
    }
    document.body.style.paddingBottom = "";

    const tempIndex = document.getElementById("temp-index-content");
    if (tempIndex) tempIndex.style.display = "none";

    if (appContentGrid) appContentGrid.style.display = "";
    if (detailsWrapper) detailsWrapper.style.display = "block";
    if (recWrapper) recWrapper.style.display = "block";
    requestAnimationFrame(() => {
      if (videoElement && videoElement.videoWidth && videoElement.videoHeight) {
        const vw = videoElement.videoWidth;
        const vh = videoElement.videoHeight;
        if (videoWrap) videoWrap.style.aspectRatio = `${vw} / ${vh}`;
      }
      const d = document.getElementById("desc-text");
      const b = document.getElementById("btn-read-more");
      if (d && b) {
        b.classList.add("hidden"); // Reset state awal
        if (d.scrollHeight > d.clientHeight) {
          b.classList.remove("hidden");
        }
      }
    });

    window.history.pushState({}, "", watchUrl);
  }
};

window.addEventListener(
  "keydown",
  (e) => {
    // CEGAH FULLSCREEN: Jika mini-player aktif dan tombol "f" ditekan, blokir sepenuhnya!
    if (isMiniPlayerActive && e.key.toLowerCase() === "f") {
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;

    if (e.key.toLowerCase() === "l") {
      setTimeout(updateLoopUI, 50);
    }
    if (e.key.toLowerCase() === "i") {
      toggleMiniPlayer();
    }
  },
  true,
);
// Cegah fullscreen dari klik ganda (double click) saat di mode mini-player
window.addEventListener(
  "dblclick",
  (e) => {
    if (isMiniPlayerActive) {
      const videoWrapper = e.target.closest("#main-video-wrapper");
      if (videoWrapper) {
        e.preventDefault();
        e.stopPropagation();
      }
    }
  },
  true,
);
window.addEventListener("popstate", (e) => {
  if (isMiniPlayerActive && window.location.href === watchUrl) {
    toggleMiniPlayer();
  }
});

function setupMobileGestures() {
  // Hanya aktif di perangkat sentuh (HP/tablet), tidak di desktop
  if (!isTouchDevice) return;

  let lastTap = 0;
  let lastTouchTime = 0;
  const container = document.querySelector(".plyr");
  if (!container) return;

  // Lacak waktu touch terakhir dan handle double-tap untuk rewind/forward/play-pause
  container.addEventListener(
    "touchstart",
    (e) => {
      const now = Date.now();
      lastTouchTime = now;

      if (now - lastTap < 300) {
        const rect = container.getBoundingClientRect();
        const touch = e.touches[0] || e.changedTouches[0];
        if (touch) {
          const touchX = touch.clientX - rect.left;
          const width = rect.width;

          // Batalkan event bawaan agar tidak memicu fullscreen
          e.preventDefault();
          e.stopPropagation();

          if (touchX < width * 0.4) {
            // Sisi kiri: Rewind
            if (player) player.rewind(5);
            tampilkanIndikator("⏪ -5s");
          } else if (touchX > width * 0.6) {
            // Sisi kanan: Forward
            if (player) player.forward(5);
            tampilkanIndikator("+5s ⏩");
          } else {
            // Bagian tengah: Tidak lakukan apa‑apa pada double‑tap,
            // biarkan tap pertama menangani play/pause secara default.
          }
        }
      }
      lastTap = now;
    },
    { passive: false },
  );

  // Tap tunggal di tengah untuk toggle play/pause
  container.addEventListener("click", (e) => {
    const rect = container.getBoundingClientRect();
    const touchX = e.clientX - rect.left;
    const width = rect.width;

    if (touchX >= width * 0.4 && touchX <= width * 0.6) {
      if (player) {
        if (player.paused) {
          player.play();
        } else {
          player.pause();
        }
        // Optional indikator
        tampilkanIndikator(player.paused ? "⏸️ Pause" : "▶️ Play");
      }
    }
  });

  // Cegah event dblclick buatan (simulated) dari interaksi sentuh agar tidak memicu fullscreen
  container.addEventListener(
    "dblclick",
    (e) => {
      if (Date.now() - lastTouchTime < 1000) {
        e.preventDefault();
        e.stopPropagation();
      }
    },
    true,
  ); // Gunakan fase capture agar dijalankan sebelum event listener bawaan Plyr
}

// Helper: Refresh VTT sprite images — reset cache internal Plyr dan update seluruh document
function refreshVttSprites(vttUrl) {
  if (!player) return;

  // 1. Update config Plyr
  player.config.previewThumbnails.src = vttUrl;
  player.config.previewThumbnails.enabled = true;

  // 2. Reset cache internal Plyr agar tidak pakai sprite lama
  if (player.previewThumbnails) {
    player.previewThumbnails.thumbnails = [];
    player.previewThumbnails.loaded = false;
    // Paksa Plyr reload VTT dari URL baru
    if (typeof player.previewThumbnails.load === "function") {
      player.previewThumbnails.load();
    }
  }

  // 3. Juga update elemen sprite secara manual di SELURUH document
  //    (bukan hanya player.elements.container, karena mobile fullscreen
  //    bisa me-render di luar container Plyr)
  fetch(vttUrl)
    .then((res) => res.text())
    .then((text) => {
      const match = text.match(/([\w-]+\.(jpg|png|webp|jpeg))/i);
      if (match) {
        const baseUrl = vttUrl.substring(0, vttUrl.lastIndexOf("/") + 1);
        const spriteUrl = baseUrl + match[1];

        // Ganti background-image di semua container thumbnail di seluruh halaman
        document
          .querySelectorAll(".plyr__preview-thumb__image-container")
          .forEach((c) => {
            c.style.backgroundImage = `url("${spriteUrl}")`;
          });
        // Ganti src semua tag img thumbnail di seluruh halaman
        document
          .querySelectorAll(".plyr__preview-thumb__image-container img")
          .forEach((img) => {
            img.src = spriteUrl;
          });
        // Juga handle scrubbing images (preview saat drag progress bar)
        document
          .querySelectorAll(".plyr__preview-scrubbing img")
          .forEach((img) => {
            img.src = spriteUrl;
          });
      }
    })
    .catch((e) => console.error("Gagal refresh VTT sprites:", e));
}

function tampilkanIndikator(teks) {
  const container = document.querySelector(".plyr");
  if (!container) return;
  const ind = document.createElement("div");
  ind.innerText = teks;
  ind.className =
    "absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-black/60 text-white font-black py-2 px-4 rounded-full pointer-events-none z-50 transition-opacity duration-500";
  container.appendChild(ind);
  setTimeout(() => {
    ind.style.opacity = "0";
    setTimeout(() => ind.remove(), 500);
  }, 500);
}

// 6. Fungsi Deskripsi — didefinisikan di sini agar bisa dipanggil dari watch.php inline script
window.toggleDescription = function () {
  const descText = document.getElementById("desc-text");
  const btn = document.getElementById("btn-read-more");
  if (!descText || !btn) return;
  const collapsed = descText.classList.toggle("line-clamp-5");
  btn.textContent = collapsed ? "Selengkapnya" : "Lebih Sedikit";
};

window.toggleReply = function (id) {
  const element = document.getElementById(id);
  if (element) {
    element.classList.toggle("hidden");
    if (!element.classList.contains("hidden")) {
      const input = element.querySelector('input[type="text"]');
      if (input) input.focus();
    }
  }
};
