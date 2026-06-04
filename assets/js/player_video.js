// Validasi ketersediaan data dari PHP (Jembatan Data)
const config = window.playerConfig || {};

// 1. Deklarasi Variabel Utama
const videoElement = document.getElementById("main-video");
let videoSrc = config.videoSrc || "";
let isHls = config.isHls || false;
let vttSrc = config.vttSrc || "";
let videoId = config.id || "";
let storageKeyVideo = `video_pos_${videoId}`;

let player;
let hls;
const isTouchDevice = "ontouchstart" in window || navigator.maxTouchPoints > 0;

// Inisialisasi Icon
if (window.lucide) {
  lucide.createIcons();
}

// 2. Konfigurasi UI Plyr Dasar
const plyrOptions = {
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

// 3. Inisialisasi Engine Pemutar
if (isHls && window.Hls && Hls.isSupported()) {
  hls = new Hls({
    maxBufferLength: 30,
    enableWorker: true,
    backBufferLength: 60,
  });

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

// 4. Kumpulan Event & Fitur Player
function setupMeelPlayerEvents() {
  window.player = player;

  const modal = document.getElementById("resume-modal");
  const btnResume = document.getElementById("btn-resume");
  const btnRestart = document.getElementById("btn-restart");
  const displayTime = document.getElementById("resume-time");
  const countdownText = document.getElementById("resume-countdown");

  player.on("ready", (event) => {
    const savedPos = localStorage.getItem(storageKeyVideo);
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

  player.on("timeupdate", () => {
    if (player.currentTime > 0)
      localStorage.setItem(storageKeyVideo, player.currentTime);
  });

  player.on("ended", async () => {
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

      const swapElements = [
        "video-info",
        "comment-section",
        "recommendation-column",
      ];
      swapElements.forEach((id) => {
        const currentEl = document.getElementById(id);
        const newEl = doc.getElementById(id);
        if (currentEl && newEl) currentEl.innerHTML = newEl.innerHTML;
      });

      if (window.lucide) window.lucide.createIcons();
      if (window.htmx) htmx.process(document.body);

      player.poster = newPoster;

      if (newIsHls) {
        if (!hls && window.Hls && Hls.isSupported()) {
          hls = new Hls();
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

      // Update VTT Hover Sprite secara dinamis
      if (newVtt) {
        refreshVttSprites(newVtt);
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
const watchUrl = window.location.href;

window.toggleMiniPlayer = async function () {
  const videoWrapper = document.getElementById("main-video-wrapper");
  const detailsWrapper = document.getElementById("watch-details-wrapper");
  const recWrapper = document.getElementById("recommendation-wrapper");
  const appContentGrid = document.getElementById("app-content-grid");
  const leftColumn = document.getElementById("left-column");

  if (!isMiniPlayerActive) {
    isMiniPlayerActive = true;
    if (videoWrapper) videoWrapper.classList.add("mini-player-mode");

    if (detailsWrapper) detailsWrapper.style.display = "none";
    if (recWrapper) recWrapper.style.display = "none";

    if (appContentGrid)
      appContentGrid.classList.remove(
        "flex",
        "flex-col",
        "lg:flex-row",
        "gap-4",
      );
    if (leftColumn)
      leftColumn.classList.remove(
        "flex-1",
        "space-y-4",
        "sm:space-y-5",
      );

    let tempIndex = document.getElementById("temp-index-content");
    if (!tempIndex) {
      tempIndex = document.createElement("div");
      tempIndex.id = "temp-index-content";
      tempIndex.className = "w-full animate-fade-in";
      if (appContentGrid) appContentGrid.appendChild(tempIndex);

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
    if (videoWrapper) videoWrapper.classList.remove("mini-player-mode");

    const tempIndex = document.getElementById("temp-index-content");
    if (tempIndex) tempIndex.style.display = "none";

    if (appContentGrid)
      appContentGrid.classList.add(
        "flex",
        "flex-col",
        "lg:flex-row",
        "gap-4",
      );
    if (leftColumn)
      leftColumn.classList.add(
        "flex-1",
        "space-y-4",
        "sm:space-y-5",
      );

    if (detailsWrapper) detailsWrapper.style.display = "block";
    if (recWrapper) recWrapper.style.display = "block";

    window.history.pushState({}, "", watchUrl);
  }
};

window.addEventListener("keydown", (e) => {
  if (["INPUT", "TEXTAREA"].includes(document.activeElement.tagName)) return;
  if (e.key.toLowerCase() === "l") {
    setTimeout(updateLoopUI, 50);
  }
  if (e.key.toLowerCase() === "i") {
    toggleMiniPlayer();
  }
});

document.addEventListener("DOMContentLoaded", () => {
  const videoWrapper = document.getElementById("main-video-wrapper");
  if (videoWrapper) {
    videoWrapper.addEventListener("click", (e) => {
      if (isMiniPlayerActive) {
        e.preventDefault();
        toggleMiniPlayer();
      }
    });
  }
});

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
