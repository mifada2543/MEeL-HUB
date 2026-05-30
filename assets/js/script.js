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
  meelAlert(options).then(() => {
    if (options.redirectUrl) {
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

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", initMeelConfirmHandlers);
} else {
  initMeelConfirmHandlers();
}

// Toggle HealthReminder (Mode Sehat) logic, auto-initialize jika tombol ada
function toggleHealth() {
  const current = localStorage.getItem("health_reminder") === "true";
  localStorage.setItem("health_reminder", !current);
  location.reload();
}

function updateHealthToggleButton() {
  const btn = document.getElementById("healthToggle");
  if (btn) {
    btn.onclick = toggleHealth;
    const active = localStorage.getItem("health_reminder") === "true";
    btn.className = btn.className
      .replace(/bg-(green|red)-500\/20|text-(green|red)-500/g, "")
      .trim();
    if (active) {
      btn.classList.add("bg-green-500/20", "text-green-500");
      btn.innerText = "ON";
    } else {
      btn.classList.add("bg-red-500/20", "text-red-500");
      btn.innerText = "OFF";
    }
  }
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", updateHealthToggleButton);
} else {
  updateHealthToggleButton();
}
// Variabel global untuk menyimpan timer
let healthReminderTimer;

// Fungsi untuk mereset dan menjadwalkan ulang alert 20 menit ke depan
function scheduleNextHealthAlert() {
  clearTimeout(healthReminderTimer);
  healthReminderTimer = setTimeout(
    () => {
      triggerPremiumHealthAlert();
    },
    20 * 60 * 1000, // 20 Menit
  );
}

// Menjalankan pemantau kesehatan mata di latar belakang jika Mode Sehat aktif
function startHealthReminder() {
  if (window.meelHealthReminderStarted) {
    return;
  }

  const isEnabled = localStorage.getItem("health_reminder") === "true";
  if (isEnabled) {
    window.meelHealthReminderStarted = true;
    scheduleNextHealthAlert(); // Mulai perhitungan waktu
  }
}

// FUNGSI UTAMA: Memicu SweetAlert2 Premium (Metode 20-20-20)
function triggerPremiumHealthAlert() {
  if (typeof Swal === "undefined") {
    console.warn(
      "SweetAlert2 belum ter-load. MEeL Health Check: Waktunya mengistirahatkan mata Anda (Aturan 20-20-20)!",
    );
    return;
  }

  // Jeda paksa/pause video player utama jika sedang berputar agar tidak mengganggu relaksasi
  const mainVideo = document.getElementById("main-video");
  let wasPlaying = false;
  if (mainVideo && !mainVideo.paused) {
    mainVideo.pause();
    wasPlaying = true;
  }

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
    },
  }).then((result) => {
    if (result.isConfirmed) {
      // Memulai hitung mundur interaktif 20 detik untuk merelaksasikan mata
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
        timer: 20000,
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
        // Notifikasi relaksasi selesai dilakukan
        Swal.fire({
          title: "SELESAI!",
          text: "Mata Anda kembali bugar. Selamat menonton kembali!",
          icon: "success",
          iconColor: "#10b981",
          background: "#141820",
          color: "#ffffff",
          confirmButtonText: "OKE",
          buttonsStyling: false,
          customClass: {
            popup:
              "border border-green-600/25 border-t-2 border-t-green-500 rounded-2xl shadow-2xl w-auto",
            title:
              "text-xs font-black uppercase tracking-widest pt-4 text-green-400",
            htmlContainer: "text-[11px] text-gray-400 uppercase tracking-wider",
            confirmButton:
              "bg-green-600 hover:bg-green-500 text-white text-xs font-black uppercase tracking-wider py-2 px-6 rounded-xl transition-all border-none cursor-pointer mt-2",
          },
        }).then(() => {
          // Resume video & Mulai ulang perhitungan 20 menit
          if (wasPlaying && mainVideo) {
            mainVideo.play();
          }
          scheduleNextHealthAlert();
        });
      });
    } else {
      // Jika menolak jeda: Resume video & Mulai ulang perhitungan 20 menit
      if (wasPlaying && mainVideo) {
        mainVideo.play();
      }
      scheduleNextHealthAlert();
    }
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", startHealthReminder);
} else {
  startHealthReminder();
}
