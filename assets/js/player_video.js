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
      player.play().catch(() => {});
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
        } catch (e) {}
      });
    } else {
      doPlayAfterReady();
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
      screen.orientation.lock("landscape").catch(() => {});
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

      const fsCanvas = document.createElement("canvas");
      fsCanvas.id = "video-glow-canvas-fs";
      fsCanvas.width = 64;
      fsCanvas.height = 36;
      fsCanvas.style.cssText = [
        "position:absolute",
        "inset:0",
        "width:100%",
        "height:100%",
        "pointer-events:none",
        "z-index:0",
        "filter:blur(60px)",
        "opacity:0",
        "transform:scale(1.08)",
        "transition:opacity 0.6s ease",
        "border-radius:50%",
      ].join(";");
      // Sisipkan sebelum firstChild (video) — video di atasnya via normal stacking
      fsWrap.insertBefore(fsCanvas, fsWrap.firstChild);

      const fsCtx = fsCanvas.getContext("2d", { willReadFrequently: false });
      let fsAnimId = null;
      let fsRunning = false;

      const drawFs = () => {
        if (!fsRunning) return;
        if (!videoElement.paused && !videoElement.ended && !document.hidden) {
          try {
            fsCtx.drawImage(videoElement, 0, 0, 64, 36);
          } catch (e) {}
        }
        fsAnimId = requestAnimationFrame(drawFs);
      };

      const startFs = () => {
        if (fsRunning) return;
        fsRunning = true;
        fsCanvas.style.opacity = "0.5";
        drawFs();
      };

      const stopFs = () => {
        fsRunning = false;
        if (fsAnimId !== null) {
          cancelAnimationFrame(fsAnimId);
          fsAnimId = null;
        }
        fsCanvas.style.opacity = "0";
      };

      // Simpan referensi handler agar bisa dilepas saat exitfullscreen
      plyrEl._fsGlowStart = startFs;
      plyrEl._fsGlowStop = stopFs;

      player.on("play", startFs);
      player.on("playing", startFs);
      player.on("pause", stopFs);
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
      if (plyrEl._fsGlowStop) player.off("pause", plyrEl._fsGlowStop);
      if (plyrEl._fsGlowStop) player.off("ended", plyrEl._fsGlowStop);
      delete plyrEl._fsGlowStart;
      delete plyrEl._fsGlowStop;

      const fsWrapEl = plyrEl.querySelector(".plyr__video-wrapper");
      const fsCanvas = fsWrapEl
        ? fsWrapEl.querySelector("#video-glow-canvas-fs")
        : null;
      if (fsCanvas) fsCanvas.remove();
    }
  });

  // ─── FITUR CAHAYA SINEMATIK (AMBIENT MODE) ───
  const glowCanvas = document.getElementById("video-glow-canvas");
  if (glowCanvas && videoElement) {
    const ctx = glowCanvas.getContext("2d", { willReadFrequently: false });

    // Resolusi internal canvas sekecil mungkin — CSS blur akan menghaluskannya
    glowCanvas.width = 64;
    glowCanvas.height = 36;

    let glowAnimationId = null;
    let glowRunning = false;

    const drawGlowFrame = () => {
      if (!glowRunning) return;
      if (!videoElement.paused && !videoElement.ended && !document.hidden) {
        try {
          ctx.drawImage(
            videoElement,
            0,
            0,
            glowCanvas.width,
            glowCanvas.height,
          );
        } catch (e) {}
      }
      glowAnimationId = requestAnimationFrame(drawGlowFrame);
    };

    const startGlow = () => {
      if (glowRunning) return; // Cegah double-loop
      glowRunning = true;
      glowCanvas.classList.add("glow-active");
      drawGlowFrame();
    };

    const stopGlow = (clearFrame = false) => {
      glowRunning = false;
      if (glowAnimationId !== null) {
        cancelAnimationFrame(glowAnimationId);
        glowAnimationId = null;
      }
      glowCanvas.classList.remove("glow-active");
      if (clearFrame) {
        ctx.clearRect(0, 0, glowCanvas.width, glowCanvas.height);
      }
    };

    player.on("play", startGlow);
    player.on("playing", startGlow); // HLS resume setelah buffering
    player.on("pause", () => stopGlow(false));
    player.on("ended", () => stopGlow(true));

    // Jika video sudah berjalan saat JS diinisialisasi (mis. auto-recovery)
    if (!videoElement.paused && !videoElement.ended) {
      startGlow();
    }
  }
  // ─────────────────────────────────────────────

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

    // Sembunyikan canvas glow — video sudah keluar dari glow-container
    const glowCanvasEl = document.getElementById("video-glow-canvas");
    if (glowCanvasEl) glowCanvasEl.style.display = "none";

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

      // Tampilkan kembali canvas glow (reset ke kondisi semula — visible di sm+)
      const glowCanvasEl = document.getElementById("video-glow-canvas");
      if (glowCanvasEl) glowCanvasEl.style.removeProperty("display");
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

// Reuse satu elemen indikator (tidak buat DOM baru setiap gesture)
let _indikatorEl = null;
let _indikatorHideTimeout = null;
let _indikatorRemoveTimeout = null;

function tampilkanIndikator(teks) {
  const container = document.querySelector(".plyr");
  if (!container) return;

  // Buat elemen sekali, reuse selanjutnya
  if (!_indikatorEl || !_indikatorEl.parentNode) {
    _indikatorEl = document.createElement("div");
    _indikatorEl.className =
      "absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-black/60 text-white font-black py-2 px-4 rounded-full pointer-events-none z-50 transition-opacity duration-500";
    container.appendChild(_indikatorEl);
  }

  // Reset timeout sebelumnya jika masih aktif
  clearTimeout(_indikatorHideTimeout);
  clearTimeout(_indikatorRemoveTimeout);

  _indikatorEl.innerText = teks;
  _indikatorEl.style.opacity = "1";

  _indikatorHideTimeout = setTimeout(() => {
    if (_indikatorEl) _indikatorEl.style.opacity = "0";
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
