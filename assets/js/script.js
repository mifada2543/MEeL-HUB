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

// Menjalankan pemantau kesehatan mata di latar belakang jika Mode Sehat aktif
function startHealthReminder() {
  const isEnabled = localStorage.getItem("health_reminder") === "true";
  if (isEnabled) {
    // Berjalan setiap 20 menit sekali
    setInterval(
      () => {
        triggerPremiumHealthAlert();
      },
      20 * 60 * 1000,
    );
  }
}

// FUNGSI UTAMA: Memicu SweetAlert2 Premium (Metode 20-20-20)
function triggerPremiumHealthAlert() {
  if (typeof Swal === "undefined") {
    // Fallback jika library SweetAlert2 gagal dimuat
    console.warn("SweetAlert2 belum ter-load. Menggunakan fallback alert.");
    alert(
      "MEeL Health Check: Waktunya mengistirahatkan mata Anda (Aturan 20-20-20)!",
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
                        <li>Hentikan tatapan ke arah video player.</li>
                        <li>Pandang objek sejauh minimal 6 meter (20 kaki).</li>
                        <li>Fokuskan mata Anda di sana selama 20 detik.</li>
                    </ol>
                </div>
            </div>
        `,
    icon: "warning",
    iconColor: "#dc2626", // Warna merah tema MEeL
    background: "#141820", // Warna background modal gelap premium
    color: "#ffffff",
    showCancelButton: true,
    confirmButtonText: "SAYA MAU JEDA",
    cancelButtonText: "LANJUT NONTON",
    reverseButtons: true, // Tombol aksi utama di sebelah kanan
    buttonsStyling: false, // Matikan style default SWAL agar kelas Tailwind bekerja
    allowOutsideClick: false, // Paksa pengguna merespon
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
      // Render ulang ikon Lucide agar muncul di dalam modal SweetAlert
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
          // Resume video kembali jika sebelumnya sedang berputar sebelum alert dipicu
          if (wasPlaying && mainVideo) {
            mainVideo.play();
          }
        });
      });
    } else {
      // Jika memilih lanjut menonton, mainkan kembali video yang tadi ter-pause
      if (wasPlaying && mainVideo) {
        mainVideo.play();
      }
    }
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", startHealthReminder);
} else {
  startHealthReminder();
}
