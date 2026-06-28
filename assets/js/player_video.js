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

// Throttle localStorage: simpan posisi maksimal 1x per 5 detik
let lastLocalStorageSave = 0;
const LOCAL_STORAGE_THROTTLE_MS = 5000;

// Detektor stuck
let lastPlayTime = -1;
let lastTimeUpdateTimestamp = Date.now();
let stuckCheckInterval = null;

// Guard untuk transisi "next video" (mencegah race condition saat tab di-background)
let isTransitioningNext = false;
let nextVideoTransitionId = 0;

const isTouchDevice = "ontouchstart" in window || navigator.maxTouchPoints > 0;

// ── Glow ambient — state di-hoist ke module scope agar mini-player bisa akses ──
let glowSampleInterval = null;
let glowLerpInterval = null;
const GLOW_W = 8;
const GLOW_H = 6;
let glowTargetData = new Float32Array(GLOW_W * GLOW_H * 4);
let glowCurData = new Float32Array(GLOW_W * GLOW_H * 4);
let glowStartFn = null; // referensi ke startGlow(), di-assign saat init
let glowStopFn = null; // referensi ke stopGlow(), di-assign saat init
let glowEnabled = localStorage.getItem("meel_glow_enabled") !== "false";
let glowNavbar = null; // referensi ke <nav>, di-assign saat init

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
  // Beritahu Plyr untuk tidak downgrade preload attribute yang sudah di-set di HTML
  mediaMetadata: {},
};

const HLS_CONFIG = {
  maxBufferLength: 45,
  maxMaxBufferLength: 90,
  maxBufferHole: 0.5,
  nudgeMaxRetry: 5,
  nudgeOffset: 0.1,
  enableWorker: true,
  backBufferLength: 10,
  lowLatencyMode: false,
  // Mulai dari level terendah agar buffer awal cepat penuh di WiFi lambat,
  // ABR akan naik otomatis setelah buffer aman
  startLevel: -1,
  abrEwmaDefaultEstimate: 500000, // asumsi awal 500kbps (konservatif untuk WiFi lambat)
  fragLoadingTimeOut: 20000,
  manifestLoadingTimeOut: 10000,
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
  if (isRecovering || isCheckingStatus || isTransitioningNext) return;

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
    // 2 detik sudah cukup untuk deteksi stuck 3 detik
    if (!player || player.paused || isRecovering || isTransitioningNext) return;

    // Lewati pengecekan saat tab di-background: decoding frame video sering
    // di-throttle oleh browser di tab tersembunyi sehingga currentTime tampak
    // "macet" padahal sebenarnya tidak — ini memicu recovery palsu.
    if (document.hidden) return;

    const currentVideoTime = player.currentTime;
    const now = Date.now();

    if (currentVideoTime === lastPlayTime) {
      const secondsStuck = (now - lastTimeUpdateTimestamp) / 1000;
      // Threshold 6 dtk: jeda pendek (0.1-0.5 dtk) di mobile LAN adalah normal
      // segment-boundary micro-stall, bukan hang — recovery palsu justru memperberat lag
      if (secondsStuck >= 6) {
        triggerPlayerRecovery();
      }
    } else {
      lastPlayTime = currentVideoTime;
      lastTimeUpdateTimestamp = now;
    }
  }, 2000);
}

// Saat tab kembali aktif, reset baseline waktu agar tidak langsung
// dianggap "stuck" akibat throttling timer ketika di-background.
document.addEventListener("visibilitychange", () => {
  if (!document.hidden) {
    lastTimeUpdateTimestamp = Date.now();
    if (player) lastPlayTime = player.currentTime;
  }
});

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

  // Saat mini-player aktif, hasil pencarian/"Muat Lebih Banyak" yang baru
  // di-swap ke dalam #temp-index-content (mis. #video-container) berisi
  // kartu video baru — listener intercept klik perlu dipasang ulang supaya
  // klik tetap mengganti video di mini-player, bukan navigasi penuh.
  if (isMiniPlayerActive) {
    const tempIndex = document.getElementById("temp-index-content");
    if (tempIndex && tempIndex.contains(event.detail.target)) {
      attachMiniPlayerVideoCardListeners(event.detail.target);
    }
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
    if (typeof window.appendCustomSettings === "function") {
      setTimeout(window.appendCustomSettings, 0);
    }
    // Paksa preload agresif — Plyr kadang reset ini ke "metadata"
    if (videoElement && !isHls) {
      videoElement.preload = "auto";
    }
    applyNativeAspectRatio();
    if (vttSrc) {
      setTimeout(() => refreshVttSprites(vttSrc), 300);
    } else {
      player.config.previewThumbnails.enabled = false;
      const thumbEl = document.querySelector(".plyr__preview-thumb");
      if (thumbEl) thumbEl.style.display = "none";
    }
    const savedPos = localStorage.getItem(storageKeyVideo);

    // Aliran auto-recovery: langsung play tanpa buffer gate
    if (isAutoRecovering && savedPos) {
      isAutoRecovering = false;
      player.currentTime = parseFloat(savedPos);
      player.play().catch(() => { });
      startStuckDetector();
      return;
    }

    // ── Buffer Gate ──────────────────────────────────────────────────────────
    // Tunda autoplay sampai ada ≥ 15 detik konten terbuffer. Di WiFi lambat,
    // HLS.js mulai play setelah ~1 segment tersedia (~2-6 dtk), lalu stutter
    // saat segment ke-3/4 belum siap. Gate ini menghilangkan jeda awal tsb.
    // Timeout 8 detik: jika buffer tidak terpenuhi (mis. koneksi sangat lambat),
    // play tetap dimulai agar user tidak menunggu tanpa batas.
    const MIN_BUFFER_SEC = 15;
    const GATE_TIMEOUT_MS = 8000;

    function doPlayAfterReady() {
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
    }

    if (isHls && hls) {
      let gateCleared = false;
      const gateTimeout = setTimeout(() => {
        if (!gateCleared) {
          gateCleared = true;
          doPlayAfterReady();
        }
      }, GATE_TIMEOUT_MS);

      hls.on(Hls.Events.FRAG_BUFFERED, function checkBuffer() {
        if (gateCleared) {
          hls.off(Hls.Events.FRAG_BUFFERED, checkBuffer);
          return;
        }
        try {
          const buf = videoElement.buffered;
          if (buf.length > 0) {
            const bufferedAhead = buf.end(0) - (videoElement.currentTime || 0);
            if (bufferedAhead >= MIN_BUFFER_SEC) {
              clearTimeout(gateTimeout);
              gateCleared = true;
              hls.off(Hls.Events.FRAG_BUFFERED, checkBuffer);
              doPlayAfterReady();
            }
          }
        } catch (e) { }
      });
    } else {
      doPlayAfterReady();
    }
  });

  player.on("controlsshown", () => {
    // appendCustomSettings dipanggil hanya via settings button listener, bukan setiap controlsshown
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
    // Flush posisi segera saat pause agar tidak kehilangan progress walau throttled
    if (player.currentTime > 0) {
      localStorage.setItem(storageKeyVideo, player.currentTime);
      lastLocalStorageSave = Date.now();
    }
  });

  player.on("seeking", () => {
    const now = Date.now();
    lastTimeUpdateTimestamp = now;
    lastLocalStorageSave = now; // flush saat seek juga
    if (player) {
      localStorage.setItem(storageKeyVideo, player.currentTime);
      lastPlayTime = player.currentTime;
    }
  });

  player.on("seeked", () => {
    lastTimeUpdateTimestamp = Date.now();
    if (player) lastPlayTime = player.currentTime;
  });

  player.on("timeupdate", () => {
    if (player.currentTime > 0) {
      const now = Date.now();
      lastPlayTime = player.currentTime;
      lastTimeUpdateTimestamp = now;

      // Throttle localStorage writes: max 1x per 5 detik agar tidak blocking tiap frame
      if (now - lastLocalStorageSave >= LOCAL_STORAGE_THROTTLE_MS) {
        localStorage.setItem(storageKeyVideo, player.currentTime);
        lastLocalStorageSave = now;
      }

      // Jika berhasil memutar tanpa masalah selama 5 detik, reset delay pemulihan ke default
      if (playbackStartTimestamp > 0 && now - playbackStartTimestamp > 5000) {
        recoveryDelay = 5000;
        playbackStartTimestamp = 0;
      }
    }
  });

  player.on("ended", async () => {
    stopStuckDetector();
    if (player.loop) return;

    // Cegah dua transisi "next video" berjalan bersamaan. Tanpa guard ini,
    // saat tab di-background, eksekusi JS bisa di-throttle/dijeda oleh browser
    // di tengah fetch/parsing — kalau event "ended" lain sempat menyusul
    // (mis. video pendek berikutnya langsung habis juga) atau recovery system
    // ikut jalan, kedua proses akan saling menimpa variabel global yang sama
    // (videoTitle, videoUploader, videoId, hls, dst), sehingga title/uploader/
    // description yang tampil jadi tidak sinkron dengan video yang sedang main.
    if (isTransitioningNext) return;
    isTransitioningNext = true;
    isRecovering = true; // blokir stuck-detector/recovery selama transisi berlangsung
    const myTransitionId = ++nextVideoTransitionId;

    localStorage.removeItem(storageKeyVideo);
    const nextVideoLink = document.querySelector(".rekomendasi-item");
    if (!nextVideoLink) {
      isTransitioningNext = false;
      isRecovering = false;
      return;
    }

    const wasFullscreen =
      player.fullscreen.active || !!document.fullscreenElement;

    try {
      const response = await fetch(nextVideoLink.href);
      const html = await response.text();
      if (myTransitionId !== nextVideoTransitionId) return;

      const parser = new DOMParser();
      const doc = parser.parseFromString(html, "text/html");
      watchUrl = nextVideoLink.href;
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
          } catch (e) { }
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
    } finally {
      // Hanya reset guard jika ini masih transisi yang aktif (bukan yang sudah
      // ditimpa oleh transisi lebih baru), supaya transisi terbaru tidak
      // dimatikan secara prematur oleh transisi lama yang baru selesai belakangan.
      if (myTransitionId === nextVideoTransitionId) {
        isTransitioningNext = false;
        isRecovering = false;
        startStuckDetector();
      }
    }
  });

  player.on("enterfullscreen", () => {
    if (screen.orientation?.lock) {
      screen.orientation.lock("landscape").catch(() => { });
    }
    // Re-apply VTT sprites saat masuk fullscreen (handle mobile cloning)
    if (vttSrc) {
      setTimeout(() => refreshVttSprites(vttSrc), 300);
    }

    // ── Glow fullscreen: inject canvas ke .plyr__video-wrapper ──
    // .plyr__video-wrapper punya background:#000 — canvas harus DI DALAM
    // wrapper tersebut, bukan di .plyr (parent), agar tidak tertutup.
    const plyrEl = player.elements.container;
    const fsWrap = plyrEl ? plyrEl.querySelector(".plyr__video-wrapper") : null;
    if (plyrEl && fsWrap && videoElement) {
      // Guard: hapus jika sudah ada
      const oldFs = fsWrap.querySelector("#video-glow-canvas-fs");
      if (oldFs) oldFs.remove();

      // Paksa video element selalu di atas canvas glow
      videoElement.style.position = "relative";
      videoElement.style.zIndex = "2";

      const fsCanvas = document.createElement("canvas");
      fsCanvas.id = "video-glow-canvas-fs";
      fsCanvas.width = GLOW_W;
      fsCanvas.height = GLOW_H;
      fsCanvas.style.cssText = [
        "position:absolute",
        "top:50%",
        "left:50%",
        // Scale 1.4 → canvas lebih besar dari video, glow meluber ke letterbox/pillarbox
        "transform:translate(-50%,-50%) scale(1.4)",
        "width:100%",
        "height:100%",
        "pointer-events:none",
        "z-index:1", // di bawah video (z-index:2)
        "filter:blur(40px)",
        "opacity:0",
        "transition:opacity 0.6s ease",
      ].join(";");
      fsWrap.insertBefore(fsCanvas, fsWrap.firstChild);

      const fsCtx = fsCanvas.getContext("2d");

      const fsSample = document.createElement("canvas");
      fsSample.width = GLOW_W;
      fsSample.height = GLOW_H;
      const fsSampleCtx = fsSample.getContext("2d", {
        willReadFrequently: true,
      });

      const fsTargetData = new Float32Array(GLOW_W * GLOW_H * 4);
      const fsCurData = new Float32Array(GLOW_W * GLOW_H * 4);
      let fsSampleInt = null,
        fsLerpInt = null;

      const fsSampleColor = () => {
        if (videoElement.readyState < 2 || document.hidden) return;
        try {
          fsSampleCtx.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
          const data = fsSampleCtx.getImageData(0, 0, GLOW_W, GLOW_H).data;
          fsTargetData.set(data);
        } catch (e) { }
      };

      const fsLerpDraw = () => {
        for (let i = 0; i < fsCurData.length; i++) {
          fsCurData[i] += (fsTargetData[i] - fsCurData[i]) * 0.018;
        }
        const imgData = fsCtx.createImageData(GLOW_W, GLOW_H);
        for (let i = 0; i < fsCurData.length; i++) {
          imgData.data[i] = Math.round(fsCurData[i]);
        }
        fsCtx.putImageData(imgData, 0, 0);
      };

      const startFs = () => {
        if (!glowEnabled) return;
        if (fsSampleInt) return;
        fsCanvas.style.opacity = "0.6";
        fsSampleColor();
        fsSampleInt = setInterval(fsSampleColor, 300);
        fsLerpInt = setInterval(fsLerpDraw, 30);
      };

      const stopFs = () => {
        if (fsSampleInt) {
          clearInterval(fsSampleInt);
          fsSampleInt = null;
        }
        if (fsLerpInt) {
          clearInterval(fsLerpInt);
          fsLerpInt = null;
        }
        fsCanvas.style.opacity = "0";
        fsTargetData.fill(0);
        fsCurData.fill(0);
        fsCtx.clearRect(0, 0, GLOW_W, GLOW_H);
      };

      const pauseFs = () => {
        if (fsSampleInt) {
          clearInterval(fsSampleInt);
          fsSampleInt = null;
        }
        if (fsLerpInt) {
          clearInterval(fsLerpInt);
          fsLerpInt = null;
        }
        // opacity TIDAK di-nol-kan — warna terakhir freeze
      };

      // Simpan referensi handler agar bisa dilepas saat exitfullscreen
      plyrEl._fsGlowStart = startFs;
      plyrEl._fsGlowStop = stopFs;
      plyrEl._fsGlowPause = pauseFs;

      player.on("play", startFs);
      player.on("playing", startFs);
      player.on("pause", pauseFs);
      player.on("ended", stopFs);

      // Langsung aktifkan jika video sedang berjalan
      if (!videoElement.paused && !videoElement.ended) startFs();
    }
  });

  player.on("exitfullscreen", () => {
    if (screen.orientation?.unlock) {
      screen.orientation.unlock();
    }

    // ── Bersihkan canvas glow fullscreen ──
    const plyrEl = player.elements.container;
    if (plyrEl) {
      if (plyrEl._fsGlowStop) plyrEl._fsGlowStop();
      if (plyrEl._fsGlowStart) player.off("play", plyrEl._fsGlowStart);
      if (plyrEl._fsGlowStart) player.off("playing", plyrEl._fsGlowStart);
      if (plyrEl._fsGlowPause) player.off("pause", plyrEl._fsGlowPause);
      if (plyrEl._fsGlowStop) player.off("ended", plyrEl._fsGlowStop);
      delete plyrEl._fsGlowStart;
      delete plyrEl._fsGlowStop;
      delete plyrEl._fsGlowPause;

      const fsWrapEl = plyrEl.querySelector(".plyr__video-wrapper");
      const fsCanvas = fsWrapEl
        ? fsWrapEl.querySelector("#video-glow-canvas-fs")
        : null;
      if (fsCanvas) fsCanvas.remove();

      // Reset z-index video element yang dipaksa saat enterfullscreen
      if (videoElement) {
        videoElement.style.position = "";
        videoElement.style.zIndex = "";
      }
    }
  });

  // ─── FITUR CAHAYA SINEMATIK (AMBIENT MODE) ───
  // State di module scope (glowSampleInterval, glowLerpInterval, dll)
  // agar mini-player bisa clear interval tanpa scope leak.
  const glowCanvas = document.getElementById("video-glow-canvas");
  if (glowCanvas && videoElement) {
    const sampleCanvas = document.createElement("canvas");
    sampleCanvas.width = GLOW_W;
    sampleCanvas.height = GLOW_H;
    const sampleCtx = sampleCanvas.getContext("2d", {
      willReadFrequently: true,
    });

    glowCanvas.width = GLOW_W;
    glowCanvas.height = GLOW_H;
    const ctx = glowCanvas.getContext("2d");

    glowNavbar = document.querySelector("nav");

    const sampleColor = () => {
      if (videoElement.readyState < 2 || document.hidden) return;
      try {
        sampleCtx.drawImage(videoElement, 0, 0, GLOW_W, GLOW_H);
        const data = sampleCtx.getImageData(0, 0, GLOW_W, GLOW_H).data;
        glowTargetData.set(data);
      } catch (e) { }
    };

    const LERP = 0.018;
    const lerpAndDraw = () => {
      for (let i = 0; i < glowCurData.length; i++) {
        glowCurData[i] += (glowTargetData[i] - glowCurData[i]) * LERP;
      }
      const imgData = ctx.createImageData(GLOW_W, GLOW_H);
      for (let i = 0; i < glowCurData.length; i++) {
        imgData.data[i] = Math.round(glowCurData[i]);
      }
      ctx.putImageData(imgData, 0, 0);

      if (glowNavbar) {
        // Average the top edge of the video (row 0 of the GLOW_W x GLOW_H grid)
        let r = 0, g = 0, b = 0;
        for (let col = 0; col < GLOW_W; col++) {
          const idx = col * 4;
          r += glowCurData[idx];
          g += glowCurData[idx + 1];
          b += glowCurData[idx + 2];
        }
        const navR = Math.round(r / GLOW_W);
        const navG = Math.round(g / GLOW_W);
        const navB = Math.round(b / GLOW_W);
        glowNavbar.style.setProperty("--navbar-glow-color", `${navR},${navG},${navB}`);
      }
    };

    const startGlow = () => {
      if (!glowEnabled) return;
      if (glowSampleInterval) return; // Cegah double-start
      glowCanvas.classList.add("glow-active");
      sampleColor();
      glowSampleInterval = setInterval(sampleColor, 300);
      glowLerpInterval = setInterval(lerpAndDraw, 30);
    };

    const stopGlow = (clearColor = false) => {
      if (glowSampleInterval) {
        clearInterval(glowSampleInterval);
        glowSampleInterval = null;
      }
      if (glowLerpInterval) {
        clearInterval(glowLerpInterval);
        glowLerpInterval = null;
      }
      glowCanvas.classList.remove("glow-active");
      if (glowNavbar)
        glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0");
      if (clearColor) {
        glowTargetData.fill(0);
        glowCurData.fill(0);
        ctx.clearRect(0, 0, GLOW_W, GLOW_H);
      }
    };

    const pauseGlow = () => {
      if (glowSampleInterval) {
        clearInterval(glowSampleInterval);
        glowSampleInterval = null;
      }
      if (glowLerpInterval) {
        clearInterval(glowLerpInterval);
        glowLerpInterval = null;
      }
    };

    // Expose ke module scope agar mini-player restore bisa restart glow
    glowStartFn = startGlow;
    glowStopFn = stopGlow;

    const makeValueHTML = (on) =>
      `${on ? "On" : "Off"} <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:${on ? "inline-block" : "none"};vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>`;

    const updateGlowMenuUI = () => {
      const el = document.getElementById("plyr-setting-glow");
      if (!el) return;
      el.setAttribute("aria-checked", glowEnabled ? "true" : "false");
      const val = el.querySelector(".plyr__menu__value");
      if (val) val.innerHTML = makeValueHTML(glowEnabled);
    };

    const updateLoopMenuUI = () => {
      const el = document.getElementById("plyr-setting-loop");
      if (!el) return;
      const isLoop = player ? player.loop : false;
      el.setAttribute("aria-checked", isLoop ? "true" : "false");
      const val = el.querySelector(".plyr__menu__value");
      if (val) val.innerHTML = makeValueHTML(isLoop);
    };

    window.updateLoopMenuUI = updateLoopMenuUI;
    window.updateGlowMenuUI = updateGlowMenuUI;

    // Cari panel home Plyr — panel berisi daftar opsi di root settings menu
    const getSettingsHomePanel = () => {
      if (!player?.elements?.container) return null;
      // Plyr 3.x: player.elements.settings.panels.home
      const directPanel = player.elements?.settings?.panels?.home;
      if (directPanel) return directPanel.querySelector('[role="menu"]') || directPanel;
      // Fallback DOM search
      const c = player.elements.container;
      return (
        c.querySelector('.plyr__menu__container [id$="-home"] [role="menu"]') ||
        c.querySelector('.plyr__menu__container [id$="-home"]') ||
        c.querySelector('.plyr__menu__container [role="menu"]') ||
        c.querySelector('.plyr__menu [role="menu"]')
      );
    };

    let settingsListenerAttached = false;

    // Inject items ke panel — dipanggil tiap kali settings menu dibuka
    const injectSettingsItems = () => {
      const panel = getSettingsHomePanel();
      if (!panel) return;
      panel.querySelector("#plyr-setting-glow")?.remove();
      panel.querySelector("#plyr-setting-loop")?.remove();

      const glowItem = document.createElement("button");
      glowItem.type = "button";
      glowItem.className = "plyr__control";
      glowItem.setAttribute("role", "menuitemcheckbox");
      glowItem.id = "plyr-setting-glow";
      glowItem.innerHTML = `<span>Ambient Glow</span><span class="plyr__menu__value"></span>`;
      glowItem.addEventListener("click", (e) => { e.stopPropagation(); window.toggleGlow(); });

      const loopItem = document.createElement("button");
      loopItem.type = "button";
      loopItem.className = "plyr__control";
      loopItem.setAttribute("role", "menuitemcheckbox");
      loopItem.id = "plyr-setting-loop";
      loopItem.innerHTML = `<span>Loop Playback</span><span class="plyr__menu__value"></span>`;
      loopItem.addEventListener("click", (e) => { e.stopPropagation(); window.toggleLoop(); });

      panel.appendChild(glowItem);
      panel.appendChild(loopItem);
      updateGlowMenuUI();
      updateLoopMenuUI();
    };

    // Setup listener pada settings button — dipanggil sekali di ready
    window.appendCustomSettings = () => {
      if (settingsListenerAttached) return;
      if (!player?.elements?.container) return;
      settingsListenerAttached = true;

      const settingsBtn = player.elements.container.querySelector('[data-plyr="settings"]');
      const onOpen = () => setTimeout(injectSettingsItems, 0);
      if (settingsBtn) {
        settingsBtn.addEventListener("click", onOpen);
        settingsBtn.addEventListener("touchend", onOpen, { passive: true });
      }
    };

    // ── Toast notifikasi toggle ──
    let _toastTimeout = null;
    const showToggleToast = (icon, label) => {
      const container = player?.elements?.container;
      if (!container) return;

      // Hapus toast lama agar animasi restart
      const old = container.querySelector(".meel-toggle-toast");
      if (old) old.remove();
      if (_toastTimeout) { clearTimeout(_toastTimeout); _toastTimeout = null; }

      const toast = document.createElement("div");
      toast.className = "meel-toggle-toast";
      toast.innerHTML = `${icon}<span>${label}</span>`;
      container.appendChild(toast);

      _toastTimeout = setTimeout(() => toast.remove(), 1900);
    };

    window.toggleGlow = () => {
      glowEnabled = !glowEnabled;
      localStorage.setItem("meel_glow_enabled", glowEnabled ? "true" : "false");
      updateGlowMenuUI();

      const icon = glowEnabled
        ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></svg>`
        : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><path d="M9.58 4.18A8 8 0 0 1 20 12c0 1.49-.41 2.88-1.12 4.08M6.51 6.51A8 8 0 0 0 4 12c0 4.42 3.58 8 8 8a8 8 0 0 0 5.49-2.18"/></svg>`;
      showToggleToast(icon, glowEnabled ? "Ambient Glow On" : "Ambient Glow Off");

      if (glowEnabled) {
        if (videoElement && !videoElement.paused && !videoElement.ended && glowStartFn) {
          glowStartFn();
        }
        const plyrEl = player?.elements?.container;
        if (plyrEl && plyrEl._fsGlowStart && !videoElement.paused && !videoElement.ended) {
          plyrEl._fsGlowStart();
        }
      } else {
        if (glowStopFn) {
          glowStopFn(true);
        }
        const plyrEl = player?.elements?.container;
        if (plyrEl && plyrEl._fsGlowStop) {
          plyrEl._fsGlowStop();
        }
      }
    };

    window.toggleLoop = () => {
      if (player) {
        player.loop = !player.loop;
        updateLoopMenuUI();
        const isLoop = player.loop;
        const icon = isLoop
          ? `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>`
          : `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="2" y1="2" x2="22" y2="22"/><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h11"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>`;
        showToggleToast(icon, isLoop ? "Loop On" : "Loop Off");
      }
    };

    player.on("play", startGlow);
    player.on("playing", startGlow);
    player.on("pause", pauseGlow);
    player.on("ended", () => stopGlow(true));

    if (!videoElement.paused && !videoElement.ended) startGlow();
  }
  // ─────────────────────────────────────────────

  setupMobileGestures();
}

// 5. Fungsi Penunjang UI
// toggleLoop & updateLoopMenuUI didefinisikan di dalam setupMeelPlayerEvents (blok glowCanvas)

// --- FITUR MINI PLAYER SPA ---
let isMiniPlayerActive = false;
let watchUrl = window.location.href;
let savedWatchScrollY = 0;

let miniShell = null;

// Saat mini-player aktif, search bar di navbar watch.php (#v-search-watch /
// #v-search-mobile) seharusnya mencari di library index (#video-container di
// dalam #temp-index-content), bukan ke #recommendation-column yang sedang
// disembunyikan — sebelumnya hasil pencarian "hilang" karena nyemplung ke
// elemen tersembunyi tersebut.
function setNavbarSearchTarget(targetSelector) {
  ["v-search-watch", "v-search-mobile"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) el.setAttribute("hx-target", targetSelector);
  });
  document
    .querySelectorAll('button[hx-include="#v-search-watch"]')
    .forEach((btn) => btn.setAttribute("hx-target", targetSelector));
}

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
        updateSearchExcludeId(newId);
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
        watchUrl = targetUrl;
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
    setNavbarSearchTarget("#video-container");

    // Simpan posisi scroll halaman watch saat ini, supaya bisa dikembalikan
    // persis seperti semula saat user kembali dari browsing index.
    savedWatchScrollY = window.scrollY;
    // Mulai tampilan index dari atas, bukan dari posisi scroll watch sebelumnya.
    window.scrollTo({ top: 0, left: 0, behavior: "instant" });

    // Reset inline style dari halaman normal sebelum masuk mini-player
    if (videoWrapper) {
      videoWrapper.style.removeProperty("aspect-ratio");
      videoWrapper.style.removeProperty("height");
    }

    miniShell = buildMiniShell(videoWrapper);
    videoWrapper.classList.add("mini-player-mode");
    // CSS handles hiding non-progress controls via .mini-player-mode .plyr__controls > *:not(.plyr__progress__container)

    // Sembunyikan canvas glow & hentikan interval (pakai module-scope vars)
    const glowCanvasEl = document.getElementById("video-glow-canvas");
    if (glowCanvasEl) {
      glowCanvasEl.style.display = "none";
      glowCanvasEl.classList.remove("glow-active");
    }
    if (glowSampleInterval) {
      clearInterval(glowSampleInterval);
      glowSampleInterval = null;
    }
    if (glowLerpInterval) {
      clearInterval(glowLerpInterval);
      glowLerpInterval = null;
    }
    if (glowNavbar)
      glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0");

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
    setNavbarSearchTarget("#recommendation-column");

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

      // Kembalikan wrapper ke posisi semula di dalam glow-container
      const glowContainer = document.getElementById("video-glow-container");
      if (glowContainer) {
        // Sisipkan sebelum elemen pertama (canvas), atau append jika kosong
        const canvas = glowContainer.querySelector("canvas");
        if (canvas) {
          glowContainer.insertBefore(videoWrap, canvas.nextSibling);
        } else {
          glowContainer.appendChild(videoWrap);
        }
      } else if (leftColumn) {
        // Fallback: kembalikan ke left-column jika glow-container tidak ada
        leftColumn.insertBefore(videoWrap, leftColumn.firstChild);
      }

      // Tampilkan kembali canvas glow & restart interval bersih
      const glowCanvasEl = document.getElementById("video-glow-canvas");
      if (glowCanvasEl) {
        glowCanvasEl.style.removeProperty("display");
        // Reset warna lerp agar tidak ada lompatan warna dari state lama
        glowTargetData.fill(0);
        glowCurData.fill(0);
        if (glowNavbar)
          glowNavbar.style.setProperty("--navbar-glow-color", "0,0,0");
        // Restart glow hanya jika video sedang berjalan
        if (!videoElement?.paused && !videoElement?.ended && glowStartFn) {
          glowStartFn();
        }
      }
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

      // Kembalikan scroll ke posisi watch sebelum masuk mini-player, supaya
      // tidak "nyangkut" di posisi scroll terakhir saat browsing index tadi.
      window.scrollTo({ top: savedWatchScrollY, left: 0, behavior: "instant" });
    });

    window.history.pushState({}, "", watchUrl);
  }
};

window.addEventListener(
  "keydown",
  (e) => {
    // 1. CEK INPUT: Izinkan semua ketikan jika user sedang berada di kolom teks/pencarian
    if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;

    // 2. CEGAH FULLSCREEN: Baru blokir tombol "f" jika bukan sedang mengetik
    if (isMiniPlayerActive && e.key.toLowerCase() === "f") {
      e.preventDefault();
      e.stopPropagation();
      return;
    }

    // Shortcut lainnya
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

  const container = document.querySelector(".plyr");
  if (!container) return;

  // ── State ──────────────────────────────────────────────────────────────────
  // isStandby: true = controls sedang terlihat (mode standby), false = tersembunyi
  let isStandby = false;
  let standbyTimeout = null; // auto-hide timer
  let lastTap = 0; // timestamp tap terakhir (untuk double-tap)
  let lastTouchTime = 0; // timestamp touchstart (untuk blokir dblclick)
  let tapCancelToken = null; // setTimeout untuk defer single-tap
  let pendingDoubleTap = false; // flag: ada tap pertama yg menunggu konfirmasi double

  // Zona berdasarkan posisi X relatif terhadap lebar container
  // [0 – 40%] = kiri (rewind), [40% – 60%] = tengah, [60% – 100%] = kanan (skip)
  function getZone(touchX, width) {
    if (touchX < width * 0.4) return "left";
    if (touchX > width * 0.6) return "right";
    return "center";
  }

  // ── Standby (controls visibility) ─────────────────────────────────────────
  function enterStandby() {
    isStandby = true;
    // Munculkan controls Plyr secara paksa via class yang sudah dihandle Plyr
    container.classList.add("plyr--hide-controls");
    container.classList.remove("plyr--hide-controls");
    // Trigger Plyr internal show controls
    if (player && player.elements && player.elements.controls) {
      player.elements.controls.style.opacity = "";
      player.elements.controls.style.pointerEvents = "";
    }
    // Tampilkan play-large jika ada
    const playLarge = container.querySelector(".plyr__control--overlaid");
    if (playLarge) playLarge.style.opacity = "";
    scheduleAutoHide();
  }

  function exitStandby() {
    isStandby = false;
    clearTimeout(standbyTimeout);
    if (player && player.elements && player.elements.controls) {
      player.elements.controls.style.opacity = "0";
      player.elements.controls.style.pointerEvents = "none";
    }
    const playLarge = container.querySelector(".plyr__control--overlaid");
    if (playLarge) playLarge.style.opacity = "0";
  }

  function toggleStandby() {
    if (isStandby) {
      exitStandby();
    } else {
      enterStandby();
    }
  }

  function scheduleAutoHide() {
    clearTimeout(standbyTimeout);
    standbyTimeout = setTimeout(() => {
      if (isStandby) exitStandby();
    }, 3000);
  }

  // ── Double-tap handler via touchstart ──────────────────────────────────────
  container.addEventListener(
    "touchstart",
    (e) => {
      const now = Date.now();
      lastTouchTime = now;

      // Guard: abaikan jika sentuhan berasal dari dalam controls Plyr
      // (progress bar, tombol play/mute/fullscreen, volume, settings, dll)
      const touchTarget = e.target;
      if (
        touchTarget.closest(".plyr__controls") ||
        touchTarget.closest(".plyr__control--overlaid") ||
        touchTarget.closest(".plyr__menu") ||
        touchTarget.closest(".plyr__volume") ||
        touchTarget.closest(".plyr__progress")
      ) {
        // Tetap reset auto-hide agar controls tidak langsung hilang saat dipakai
        if (isStandby) scheduleAutoHide();
        return;
      }

      const rect = container.getBoundingClientRect();
      const touch = e.touches[0] || e.changedTouches[0];
      if (!touch) return;

      const touchX = touch.clientX - rect.left;
      const zone = getZone(touchX, rect.width);

      // Deteksi double-tap (interval < 300ms)
      if (now - lastTap < 300 && pendingDoubleTap) {
        // Batalkan defer single-tap
        clearTimeout(tapCancelToken);
        pendingDoubleTap = false;

        // Cegah event bawaan (fullscreen, dll)
        e.preventDefault();
        e.stopPropagation();

        if (zone === "left") {
          if (player) player.rewind(10);
          tampilkanSisiIndikator("rewind", "-10s");
        } else if (zone === "right") {
          if (player) player.forward(10);
          tampilkanSisiIndikator("forward", "+10s");
        }
        // Double-tap tengah: tidak ada aksi (single-tap sudah handle pause)

        lastTap = 0; // reset agar triple-tap tidak trigger double lagi
        return;
      }

      lastTap = now;
      pendingDoubleTap = true;

      // Defer single-tap selama 300ms untuk menunggu kemungkinan double-tap
      clearTimeout(tapCancelToken);
      tapCancelToken = setTimeout(() => {
        pendingDoubleTap = false;
        handleSingleTap(zone);
      }, 300);
    },
    { passive: false },
  );

  // ── Single-tap logic ───────────────────────────────────────────────────────
  function handleSingleTap(zone) {
    if (zone === "left" || zone === "right") {
      // Di sisi kiri/kanan: toggle standby (show/hide controls)
      toggleStandby();
    } else {
      // Zona tengah
      if (!isStandby) {
        // Tidak standby → masuk standby (tampilkan controls)
        enterStandby();
      } else {
        // Sudah standby: cek apakah tap tepat di 20% tengah
        // Zona "pause valid" = 40%–60% dari lebar (sudah diketahui zone === "center")
        // Di sini kita pause/play, lalu tetap standby sementara
        if (player) {
          if (player.paused) {
            player.play();
          } else {
            player.pause();
          }
          scheduleAutoHide();
        }
      }
    }
  }

  // ── Blokir dblclick simulated dari browser (mencegah fullscreen) ───────────
  container.addEventListener(
    "dblclick",
    (e) => {
      if (Date.now() - lastTouchTime < 1000) {
        e.preventDefault();
        e.stopPropagation();
      }
    },
    true,
  );

  // ── Fix Volume Slider Mobile ───────────────────────────────────────────────
  // Slider volume di-rotate -90deg via CSS sehingga terlihat vertikal,
  // tapi browser tetap membaca drag kiri-kanan sebagai perubahan nilai.
  // Di sini kita intercept touchmove dan konversi delta-Y → perubahan volume.
  (function setupVolumeTouch() {
    // Slider baru muncul saat hover (CSS), jadi pakai event delegation via document
    let volStartY = null;
    let volStartValue = null;
    let activeSlider = null;

    document.addEventListener(
      "touchstart",
      (e) => {
        const slider = e.target.closest(".plyr__volume input[type='range']");
        if (!slider) return;
        // Tolak jika controls sedang tidak terlihat (isStandby false = hidden)
        if (!isStandby) return;
        volStartY = e.touches[0].clientY;
        volStartValue = parseFloat(slider.value);
        activeSlider = slider;
        // Tahan auto-hide selama drag berlangsung
        clearTimeout(standbyTimeout);
        e.preventDefault();
      },
      { passive: false },
    );

    document.addEventListener(
      "touchmove",
      (e) => {
        if (!activeSlider || volStartY === null) return;
        e.preventDefault();

        const deltaY = volStartY - e.touches[0].clientY;
        const range =
          parseFloat(activeSlider.max) - parseFloat(activeSlider.min);
        const delta = (deltaY / 120) * range;
        const newVal = Math.min(
          parseFloat(activeSlider.max),
          Math.max(parseFloat(activeSlider.min), volStartValue + delta),
        );

        activeSlider.value = newVal;
        activeSlider.dispatchEvent(new Event("input", { bubbles: true }));
        if (player) player.volume = newVal;
      },
      { passive: false },
    );

    document.addEventListener("touchend", () => {
      if (activeSlider) {
        // Baru jadwalkan auto-hide setelah jari diangkat
        scheduleAutoHide();
      }
      volStartY = null;
      volStartValue = null;
      activeSlider = null;
    });
  })();

  // ── Sinkronisasi state standby saat player play/pause ─────────────────────
  // Saat video mulai play → exit standby (hide controls) setelah delay
  // Saat video pause → enter standby (tampilkan controls)
  if (player) {
    player.on("play", () => {
      scheduleAutoHide();
    });
    player.on("pause", () => {
      clearTimeout(standbyTimeout);
      isStandby = true;
      if (player.elements && player.elements.controls) {
        player.elements.controls.style.opacity = "";
        player.elements.controls.style.pointerEvents = "";
      }
    });
  }
}

// Cache sprite URL per VTT agar tidak fetch ulang setiap fullscreen/transition
const _vttSpriteCache = {};

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
    if (typeof player.previewThumbnails.load === "function") {
      player.previewThumbnails.load();
    }
  }

  // 3. Update elemen sprite — gunakan cache jika sudah pernah fetch VTT ini
  const applySprite = (spriteUrl) => {
    document
      .querySelectorAll(".plyr__preview-thumb__image-container")
      .forEach((c) => {
        c.style.backgroundImage = `url("${spriteUrl}")`;
      });
    document
      .querySelectorAll(
        ".plyr__preview-thumb__image-container img, .plyr__preview-scrubbing img",
      )
      .forEach((img) => {
        img.src = spriteUrl;
      });
  };

  if (_vttSpriteCache[vttUrl]) {
    applySprite(_vttSpriteCache[vttUrl]);
    return;
  }

  fetch(vttUrl)
    .then((res) => res.text())
    .then((text) => {
      const match = text.match(/([\w-]+\.(jpg|png|webp|jpeg))/i);
      if (match) {
        const baseUrl = vttUrl.substring(0, vttUrl.lastIndexOf("/") + 1);
        const spriteUrl = baseUrl + match[1];
        _vttSpriteCache[vttUrl] = spriteUrl; // simpan ke cache
        applySprite(spriteUrl);
      }
    })
    .catch((e) => console.error("Gagal refresh VTT sprites:", e));
}

// ── Indikator Sisi (Rewind / Forward) — gaya YouTube ──────────────────────
// Reuse dua elemen: satu untuk kiri (rewind), satu untuk kanan (forward)
const _sisiIndicators = { rewind: null, forward: null };
const _sisiHideTimeouts = { rewind: null, forward: null };
const _sisiRippleCounts = { rewind: 0, forward: 0 }; // akumulasi detik per burst

/**
 * tampilkanSisiIndikator(sisi, label)
 * sisi  : "rewind" | "forward"
 * label : string detik, mis. "-10s" atau "+10s"
 */
function tampilkanSisiIndikator(sisi, label) {
  const container = document.querySelector(".plyr");
  if (!container) return;

  // Buat elemen sekali per sisi, reuse selanjutnya
  if (!_sisiIndicators[sisi] || !_sisiIndicators[sisi].parentNode) {
    const el = document.createElement("div");
    el.className = `meel-seek-indicator meel-seek-${sisi}`;
    // Posisi: kiri untuk rewind, kanan untuk forward
    // Animasi & style dihandle CSS (lihat video.css)
    container.appendChild(el);
    _sisiIndicators[sisi] = el;
  }

  const el = _sisiIndicators[sisi];

  // Ripple icon — chevron ganda (<<  atau >>)
  const icon =
    sisi === "rewind"
      ? `<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M11 18V6l-8.5 6 8.5 6zm.5-6l8.5 6V6l-8.5 6z"/></svg>`
      : `<svg class="meel-seek-icon" viewBox="0 0 24 24"><path d="M4 18l8.5-6L4 6v12zm9-12v12l8.5-6L13 6z"/></svg>`;

  el.innerHTML = `${icon}<span class="meel-seek-label">${label}</span>`;

  // Trigger animasi ulang (force reflow)
  el.classList.remove("meel-seek-active");
  void el.offsetWidth;
  el.classList.add("meel-seek-active");

  // Auto-hide setelah animasi selesai
  clearTimeout(_sisiHideTimeouts[sisi]);
  _sisiHideTimeouts[sisi] = setTimeout(() => {
    el.classList.remove("meel-seek-active");
  }, 800);
}

// Kompatibilitas mundur — tidak dipakai lagi tapi jaga agar tidak error
function tampilkanIndikator(teks) {
  // no-op: digantikan oleh tampilkanSisiIndikator
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
function updateSearchExcludeId(newId) {
  // Update input text
  ["v-search-watch", "v-search-mobile"].forEach((id) => {
    const el = document.getElementById(id);
    if (el) {
      el.setAttribute("hx-get", `search_video.php?exclude=${newId}`);
      if (window.htmx) htmx.process(el); // Inisialisasi ulang HTMX
    }
  });

  // Update tombol cari
  document
    .querySelectorAll('button[hx-include="#v-search-watch"]')
    .forEach((btn) => {
      btn.setAttribute("hx-get", `search_video.php?exclude=${newId}`);
      if (window.htmx) htmx.process(btn);
    });
}