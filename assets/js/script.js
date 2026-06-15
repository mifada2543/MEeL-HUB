function meelAlert(options = {}) {
  const config = {
    title: options.title || "MEeL",
    text: options.text || "",
    icon: options.icon || "info",
    iconColor: options.iconColor || "#ef4444",
    background: "#141820",
    color: "#ffffff",
    confirmButtonText: options.confirmButtonText || "OKE",
    buttonsStyling: false,
    customClass: {
      popup:
        "border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "text-[11px] text-gray-400 uppercase tracking-wider",
      confirmButton:
        "bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 px-6 rounded-xl transition-all border-none cursor-pointer mt-2",
    },
  };

  if (typeof Swal === "undefined") {
    console.warn("SweetAlert2 belum ter-load.", config.text || config.title);
    return Promise.resolve();
  }

  return Swal.fire(config);
}

function meelAlertRedirect(options = {}) {
  meelAlert(options).then((result) => {
    if (result.isConfirmed && options.redirectUrl) {
      window.location.href = options.redirectUrl;
    }
  });
  return false;
}

window.meelAlert = meelAlert;
window.meelAlertRedirect = meelAlertRedirect;

function meelConfirm(options = {}) {
  const config = {
    title: options.title || "Konfirmasi",
    text: options.text || "Lanjutkan aksi ini?",
    icon: options.icon || "warning",
    iconColor: options.iconColor || "#ef4444",
    background: "#141820",
    color: "#ffffff",
    showCancelButton: true,
    confirmButtonText: options.confirmButtonText || "YA, LANJUTKAN",
    cancelButtonText: options.cancelButtonText || "BATAL",
    reverseButtons: true,
    buttonsStyling: false,
    customClass: {
      popup:
        "border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "text-[11px] text-gray-400 uppercase tracking-wider",
      actions: "flex gap-2 w-full mt-4 px-3",
      confirmButton:
        "flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer",
      cancelButton:
        "flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all",
    },
  };

  if (typeof Swal === "undefined") {
    console.warn("SweetAlert2 belum ter-load.", config.text);
    return Promise.resolve(false);
  }

  return Swal.fire(config).then((result) => result.isConfirmed);
}

function meelConfirmLink(event, options = {}) {
  if (event) {
    event.preventDefault();
  }

  const link = event?.currentTarget;
  const href = options.href || link?.getAttribute("href");

  meelConfirm(options).then((confirmed) => {
    if (confirmed && href) {
      window.location.href = href;
    }
  });

  return false;
}

function meelConfirmForm(event, options = {}) {
  if (event) {
    event.preventDefault();
  }

  const form = event?.currentTarget;
  const submitter = event?.submitter;

  meelConfirm(options).then((confirmed) => {
    if (confirmed && form) {
      if (submitter?.name) {
        const hiddenSubmitter = document.createElement("input");
        hiddenSubmitter.type = "hidden";
        hiddenSubmitter.name = submitter.name;
        hiddenSubmitter.value = submitter.value || "";
        form.appendChild(hiddenSubmitter);
      }

      form.submit();
    }
  });

  return false;
}

window.meelConfirm = meelConfirm;
window.meelConfirmLink = meelConfirmLink;
window.meelConfirmForm = meelConfirmForm;

function submitMeelConfirmedForm(form, submitter) {
  if (submitter?.name) {
    const hiddenSubmitter = document.createElement("input");
    hiddenSubmitter.type = "hidden";
    hiddenSubmitter.name = submitter.name;
    hiddenSubmitter.value = submitter.value || "";
    form.appendChild(hiddenSubmitter);
  }

  form.submit();
}

function getMeelConfirmOptions(element) {
  return {
    title: element.dataset.meelConfirmTitle,
    text: element.dataset.meelConfirmText,
    icon: element.dataset.meelConfirmIcon,
    confirmButtonText: element.dataset.meelConfirmButton,
    cancelButtonText: element.dataset.meelCancelButton,
  };
}

function initMeelConfirmHandlers() {
  if (window.meelConfirmHandlersReady) {
    return;
  }

  window.meelConfirmHandlersReady = true;
  document.addEventListener("click", (event) => {
    const link = event.target.closest("[data-meel-confirm-link]");
    if (!link) {
      return;
    }

    meelConfirmLink(event, {
      ...getMeelConfirmOptions(link),
      href: link.getAttribute("href"),
    });
  });

  document.addEventListener("submit", (event) => {
    const form = event.target.closest("[data-meel-confirm-form]");
    if (!form) {
      return;
    }

    event.preventDefault();
    meelConfirm(getMeelConfirmOptions(form)).then((confirmed) => {
      if (confirmed) {
        submitMeelConfirmedForm(form, event.submitter);
      }
    });
  });
}

// --- LOGIKA MODE SEHAT (PERSISTEN & BEBAS BUG) ---

// Konstanta durasi untuk memudahkan pengaturan (20 Menit)
const HEALTH_INTERVAL_MS = 20 * 60 * 1000;
let healthReminderTimer;

// Status Global Mode Sehat (Untuk memblokir keyboard & player)
window.meelHealthAlertActive = false;

// --- 1. PENCEGAH INTERAKSI KEYBOARD GLOBAL SAAT MODE SEHAT ---
document.addEventListener(
  "keydown",
  function (e) {
    if (window.meelHealthAlertActive) {
      // Blokir spasi, k (play/pause), j/l (rewind/forward), panah, m (mute), f (fullscreen)
      const blockedKeys = [
        " ",
        "k",
        "K",
        "j",
        "J",
        "l",
        "L",
        "ArrowLeft",
        "ArrowRight",
        "ArrowUp",
        "ArrowDown",
        "m",
        "M",
        "f",
        "F",
      ];
      if (blockedKeys.includes(e.key)) {
        e.preventDefault();
        e.stopPropagation();
      }
    }
  },
  true,
); // "true" membuat ini dieksekusi pada fase Capture (Mencegat sebelum sampai ke Plyr)

function toggleHealth() {
  const current = localStorage.getItem("health_reminder") === "true";
  const newState = !current;
  localStorage.setItem("health_reminder", newState);
  updateHealthToggleButton();

  if (newState) {
    scheduleNextHealthAlert();
  } else {
    clearTimeout(healthReminderTimer);
    localStorage.removeItem("health_target_time");
    window.meelHealthReminderStarted = false;
  }
}

function updateHealthToggleButton() {
  const btn = document.getElementById("healthToggle");
  if (btn) {
    btn.onclick = toggleHealth;
    const active = localStorage.getItem("health_reminder") === "true";
    btn.classList.remove(
      "bg-green-500/20",
      "text-green-500",
      "bg-red-500/20",
      "text-red-500",
      "text-gray-700",
    );
    if (active) {
      btn.classList.add("bg-green-500/20", "text-green-500");
      btn.innerText = "ON";
    } else {
      btn.classList.add("bg-red-500/20", "text-red-500");
      btn.innerText = "OFF";
    }
  }
}

// Fungsi menetapkan target waktu baru (Dipanggil setelah alert ditutup / Mode Sehat dinyalakan)
function scheduleNextHealthAlert() {
  const targetTime = Date.now() + HEALTH_INTERVAL_MS;
  localStorage.setItem("health_target_time", targetTime.toString());
  startHealthCountdown();
}

// Logika pemantau timer (Persisten melintasi refresh halaman)
function startHealthCountdown() {
  clearTimeout(healthReminderTimer);

  const targetTimeStr = localStorage.getItem("health_target_time");
  if (!targetTimeStr) {
    // Jika belum ada target, buat target baru
    scheduleNextHealthAlert();
    return;
  }

  const targetTime = parseInt(targetTimeStr, 10);
  const timeLeft = targetTime - Date.now();

  if (timeLeft <= 0) {
    // Waktu sudah habis (misal user refresh tepat saat 20 menit)
    triggerPremiumHealthAlert();
  } else {
    // Hitung mundur sisa waktu
    healthReminderTimer = setTimeout(() => {
      triggerPremiumHealthAlert();
    }, timeLeft);
  }
}

function startHealthReminder() {
  if (window.meelHealthReminderStarted) {
    return;
  }

  const isEnabled = localStorage.getItem("health_reminder") === "true";
  if (isEnabled) {
    window.meelHealthReminderStarted = true;
    startHealthCountdown(); // Gunakan penghitung yang persisten
  }
}

function isPlayerActive() {
  // Cek Plyr instance (mini player maupun full player)
  if (window.player && !window.player.paused) {
    return true;
  }

  // Cek elemen media HTML langsung
  const mediaEl =
    document.getElementById("main-video") ||
    document.getElementById("main-player") ||
    (document.fullscreenElement &&
      document.fullscreenElement.querySelector("video, audio"));

  if (mediaEl && !mediaEl.paused) {
    return true;
  }

  // Cek semua elemen video/audio di halaman (misal mini player)
  const allMedia = document.querySelectorAll("video, audio");
  for (const m of allMedia) {
    if (!m.paused) return true;
  }

  return false;
}

function triggerPremiumHealthAlert() {
  if (typeof Swal === "undefined") {
    console.warn("SweetAlert2 belum ter-load.");
    return;
  }

  // Jangan tampilkan alert jika tidak ada player yang sedang aktif/playing
  if (!isPlayerActive()) {
    // Reschedule: pantau terus, cek lagi setelah 30 detik sampai player aktif
    healthReminderTimer = setTimeout(() => {
      triggerPremiumHealthAlert();
    }, 30 * 1000);
    return;
  }

  let mediaElement =
    document.getElementById("main-video") ||
    document.getElementById("main-player");

  if (!mediaElement && document.fullscreenElement) {
    mediaElement = document.fullscreenElement.querySelector("video, audio");
  }

  // Fallback: ambil media element pertama yang sedang playing
  if (!mediaElement) {
    const allMedia = document.querySelectorAll("video, audio");
    for (const m of allMedia) {
      if (!m.paused) {
        mediaElement = m;
        break;
      }
    }
  }

  // --- 2. KUNCI PEMUTARAN ---
  window.meelHealthAlertActive = true;

  // Handler paksa pause jika media mencoba berputar kembali
  const forcePauseHandler = function () {
    if (window.meelHealthAlertActive) {
      if (window.player) {
        window.player.pause();
      } else if (mediaElement) {
        mediaElement.pause();
      }
    }
  };

  // Daftarkan interceptor pencegah putar ulang
  if (window.player) {
    window.player.on("play", forcePauseHandler);
  } else if (mediaElement) {
    mediaElement.addEventListener("play", forcePauseHandler);
  }

  // Hentikan pemutaran yang sedang berjalan saat ini
  let wasPlaying = false;
  if (window.player) {
    if (!window.player.paused) {
      window.player.pause();
      wasPlaying = true;
    }
  } else if (mediaElement && !mediaElement.paused) {
    mediaElement.pause();
    wasPlaying = true;
  }

  // Helper function untuk melanjutkan video/audio DAN membuka kunci pengaman
  const cleanupAndResume = () => {
    window.meelHealthAlertActive = false;
    if (window.player) {
      window.player.off("play", forcePauseHandler);
    } else if (mediaElement) {
      mediaElement.removeEventListener("play", forcePauseHandler);
    }

    if (wasPlaying) {
      if (window.player) {
        window.player.play().catch(() => {});
      } else if (mediaElement) {
        mediaElement.play().catch(() => {});
      }
    }
  };

  const wasFullscreen =
    !!document.fullscreenElement ||
    (window.player &&
      window.player.fullscreen &&
      window.player.fullscreen.active);

  Swal.fire({
    title: "WAKTUNYA ISTIRAHATKAN MATA!",
    html: `
            <div class="text-center space-y-4">
                <p class="text-[10px] text-gray-500 uppercase tracking-widest leading-relaxed">
                    Anda telah menonton selama <span class="text-red-400 font-bold font-mono">20 Menit</span>. 
                    Mari cegah mata lelah dengan metode <span class="text-green-500 font-bold">20-20-20</span>.
                </p>
                <div class="bg-black/40 border border-white/[.04] p-3 rounded-xl text-left space-y-1.5">
                    <div class="flex items-center gap-2 text-xs text-gray-300 font-bold">
                        <i data-lucide="eye-off" class="w-4 h-4 text-red-500"></i>
                        <span>Langkah Istirahat:</span>
                    </div>
                    <ol class="list-decimal list-inside text-[11px] text-gray-400 space-y-1 pl-1">
                        <li>Hentikan tatapan ke layar perangkat.</li>
                        <li>Pandang objek sejauh minimal 6 meter (20 kaki).</li>
                        <li>Fokuskan mata Anda di sana selama 20 detik.</li>
                    </ol>
                </div>
            </div>
        `,
    icon: "warning",
    iconColor: "#dc2626",
    background: "#141820",
    color: "#ffffff",
    showCancelButton: true,
    confirmButtonText: "SAYA MAU JEDA",
    cancelButtonText: "LANJUT NONTON",
    reverseButtons: true,
    buttonsStyling: false,
    allowOutsideClick: false,
    timer: 300000, // Hilang otomatis dalam 5 Menit
    customClass: {
      popup:
        "border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl shadow-2xl",
      title: "text-sm font-black uppercase tracking-wider pt-4 text-red-500",
      htmlContainer: "mt-1 mb-4",
      actions: "flex gap-2 w-full mt-4 px-3",
      confirmButton:
        "flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer",
      cancelButton:
        "flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all",
    },
    didOpen: () => {
      if (typeof lucide !== "undefined") {
        lucide.createIcons();
      }
      // Exit fullscreen saat modal muncul agar user bisa interact
      if (wasFullscreen) {
        if (
          window.player &&
          window.player.fullscreen &&
          window.player.fullscreen.active
        ) {
          window.player.fullscreen.exit();
        } else if (document.fullscreenElement) {
          document.exitFullscreen().catch(() => {});
        }
      }
    },
  }).then((result) => {
    if (result.isConfirmed) {
      // --- LOGIKA 1: PENGGUNA KLIK "SAYA MAU JEDA" ---
      let timerInterval;
      Swal.fire({
        title: "RELAKSASI DIMULAI",
        html: `
                    <div class="text-center space-y-3 py-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-widest">Arahkan pandangan Anda ke kejauhan sekarang!</p>
                        <div class="text-4xl font-mono font-black text-green-500 tracking-wider">
                            <span id="countdown-sec">20</span>s
                        </div>
                        <div class="w-full bg-white/[0.04] h-1.5 rounded-full overflow-hidden">
                            <div id="countdown-bar" class="bg-green-500 h-full w-full transition-all duration-1000 ease-linear"></div>
                        </div>
                    </div>
                `,
        background: "#141820",
        color: "#ffffff",
        timer: 20000, // Timer relaksasi 20 detik
        timerProgressBar: false,
        showConfirmButton: false,
        allowOutsideClick: false,
        customClass: {
          popup:
            "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl",
          title:
            "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
        },
        didOpen: () => {
          let secondsLeft = 20;
          const secSpan = document.getElementById("countdown-sec");
          const progressBar = document.getElementById("countdown-bar");

          timerInterval = setInterval(() => {
            secondsLeft--;
            if (secSpan) secSpan.innerText = secondsLeft;
            if (progressBar) {
              const percent = (secondsLeft / 20) * 100;
              progressBar.style.width = percent + "%";
            }
          }, 1000);
        },
        willClose: () => {
          clearInterval(timerInterval);
        },
      }).then(() => {
        // Notifikasi Selesai - Otomatis tertutup dalam 2 detik
        Swal.fire({
          title: "SELESAI!",
          text: "Mata Anda kembali bugar. Selamat menonton kembali!",
          icon: "success",
          iconColor: "#10b981",
          background: "#141820",
          color: "#ffffff",
          timer: 2000,
          timerProgressBar: true,
          showConfirmButton: false,
          buttonsStyling: false,
          customClass: {
            popup:
              "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl w-auto",
            title:
              "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
            htmlContainer: "text-[11px] text-gray-400 uppercase tracking-wider",
          },
        }).then(() => {
          cleanupAndResume(); // Buka Kunci & Resume Playback
          // Re-enter fullscreen jika sebelumnya fullscreen
          if (wasFullscreen) {
            if (window.player && window.player.fullscreen) {
              window.player.fullscreen.enter();
            } else if (mediaElement && mediaElement.requestFullscreen) {
              mediaElement
                .requestFullscreen()
                .catch((err) => console.log("Fullscreen re-entry:", err));
            }
          }
          scheduleNextHealthAlert();
        });
      });
    } else if (result.dismiss === Swal.DismissReason.cancel) {
      // PENGGUNA KLIK "LANJUT NONTON"
      cleanupAndResume(); // Buka Kunci & Resume Playback
      // Re-enter fullscreen jika sebelumnya fullscreen
      if (wasFullscreen) {
        if (window.player && window.player.fullscreen) {
          window.player.fullscreen.enter();
        } else if (mediaElement && mediaElement.requestFullscreen) {
          mediaElement
            .requestFullscreen()
            .catch((err) => console.log("Fullscreen re-entry:", err));
        }
      }
      scheduleNextHealthAlert();
    } else if (result.dismiss === Swal.DismissReason.timer) {
      // DIDIAMKAN SELAMA 5 MENIT
      cleanupAndResume(); // Buka Kunci & Resume Playback
      // Re-enter fullscreen jika sebelumnya fullscreen
      if (wasFullscreen) {
        if (window.player && window.player.fullscreen) {
          window.player.fullscreen.enter();
        } else if (mediaElement && mediaElement.requestFullscreen) {
          mediaElement
            .requestFullscreen()
            .catch((err) => console.log("Fullscreen re-entry:", err));
        }
      }
    }
  });
}

document.addEventListener("DOMContentLoaded", () => {
  if (typeof initMeelConfirmHandlers === "function") {
    initMeelConfirmHandlers();
  }
  if (typeof startHealthReminder === "function") {
    startHealthReminder();
  }

  // Ini kunci agar tombol mendeteksi klik sejak awal
  if (typeof updateHealthToggleButton === "function") {
    updateHealthToggleButton();
  } else {
    console.error("Fungsi updateHealthToggleButton tidak ditemukan!");
  }
});
