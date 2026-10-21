/* reference build: MEeL-C6H9N3O3 [d1b5f7073b4b4292] */
(function () {
  if (window.__meelResumeKeyGuardBound) return;
  window.__meelResumeKeyGuardBound = true;

  window.meelResumeModalActive = false;

  function focusSafe(el) {
    try {
      el.focus({ preventScroll: true });
    } catch (_) {
      try {
        el.focus();
      } catch (__) {}
    }
  }

  function getButtons() {
    return {
      resume: document.getElementById("btn-resume"),
      restart: document.getElementById("btn-restart"),
    };
  }

  window.addEventListener(
    "keydown",
    function (e) {
      if (!window.meelResumeModalActive) return;
      // Modifier tetap bebas: reload, tab baru, devtools, dll.
      if (e.ctrlKey || e.metaKey || e.altKey) return;

      var btn = getButtons();
      if (!btn.resume || !btn.restart) return;
      var onResume = document.activeElement === btn.resume;
      var onRestart = document.activeElement === btn.restart;

      var swallow = function () {
        e.preventDefault();
        e.stopImmediatePropagation();
      };

      switch (e.key) {
        /* Space mengaktifkan pilihan terfokus, bukan play/pause. */
        case " ":
        case "Spacebar":
          swallow();
          (onRestart ? btn.restart : btn.resume).click();
          return;

        /* Navigasi pilihan mengikuti posisi visual tombol:
           Lanjut di kiri, Ulang di kanan. */
        case "ArrowLeft":
          swallow();
          focusSafe(btn.resume);
          return;
        case "ArrowRight":
          swallow();
          focusSafe(btn.restart);
          return;
        /* Home/End tetap ke pilihan pertama/terakhir. */
        case "Home":
          swallow();
          focusSafe(btn.resume);
          return;
        case "End":
          swallow();
          focusSafe(btn.restart);
          return;

        /* Enter dibiarkan native: <button> yang terfokus akan fire click. */
        case "Enter":
          if (!onResume && !onRestart) {
            swallow();
            focusSafe(btn.resume);
          }
          return;

        /* Tab tetap siklus fokus, Escape tetap keluar dari fullscreen. */
        case "Tab":
        case "Escape":
          return;

        /* Sisanya (ArrowUp/ArrowDown = volume, k, l, f, m, c, i, n, 0-9, ...)
           diblokir supaya tidak sampai ke player. */
        default:
          swallow();
      }
    },
    true,
  );
})();

window.meelResumeModal = function (options) {
  const o = options || {};
  const modal = document.getElementById("resume-modal"),
    btnResume = document.getElementById("btn-resume"),
    btnRestart = document.getElementById("btn-restart"),
    timeEl = document.getElementById("resume-time");
  if (!modal || !btnResume || !btnRestart || !timeEl) return false;
  if (o.skipOnce && o.skipOnce()) return false;
  const saved = localStorage.getItem(o.storageKey);
  if (!saved || parseFloat(saved) <= 10) return false;
  if (
    window.player &&
    window.player.duration &&
    parseFloat(saved) >= window.player.duration - (o.durationMargin ?? 5)
  )
    return false;

  // Panggil instance sebelumnya (mis. pindah track di halaman musik).
  if (typeof window.__meelResumeDismiss === "function") window.__meelResumeDismiss();

  let countdownEl = document.getElementById("resume-countdown");
  if (!countdownEl) {
    countdownEl = document.createElement("p");
    countdownEl.id = "resume-countdown";
    countdownEl.className = "text-[9px] text-gray-500 italic mb-4";
    const anchor = timeEl.parentNode || modal;
    anchor.after(countdownEl);
  }
  timeEl.innerText = formatTime(parseFloat(saved));
  modal.classList.remove("hidden");
  window.meelResumeModalActive = true;
  o.onShow && o.onShow();

  let userChose = false;
  const prefix = o.countdownPrefix || "Otomatis putar dari awal dalam",
    doneText = o.countdownDoneText || null;
  let sec = 15;
  countdownEl.innerText = `${prefix} ${sec}s...`;
  const countdownTimer = setInterval(() => {
    sec--;
    if (sec > 0) countdownEl.innerText = `${prefix} ${sec}s...`;
    else if (doneText) {
      countdownEl.innerText = doneText;
      clearInterval(countdownTimer);
    } else clearInterval(countdownTimer);
  }, 1e3);
  const autoRestartTimer = setTimeout(() => {
    if (userChose) return;
    close(false);
    o.onRestart && o.onRestart();
  }, 15e3);

  function playerFocusTarget() {
    return (
      o.focusTarget ||
      document.getElementById("main-video-wrapper") ||
      document.getElementById("player-container") ||
      document.querySelector(".plyr")
    );
  }

  function restoreFocus() {
    if (o.restoreFocus === false) return;
    if (document.activeElement !== btnResume && document.activeElement !== btnRestart)
      return;
    const target = playerFocusTarget();
    if (!target) return;
    if (
      !target.hasAttribute("tabindex") &&
      !target.matches("a[href],button,input,select,textarea")
    ) {
      try {
        target.setAttribute("tabindex", "-1");
      } catch (_) {}
    }
    try {
      target.focus({ preventScroll: true });
    } catch (_) {
      try {
        target.focus();
      } catch (__) {}
    }
  }

  function close(restore) {
    if (userChose) return;
    userChose = true;
    clearInterval(countdownTimer);
    clearTimeout(autoRestartTimer);
    modal.classList.add("hidden");
    window.meelResumeModalActive = false;
    window.__meelResumeDismiss = null;
    if (restore !== false) restoreFocus();
  }
  window.__meelResumeDismiss = () => close(true);

  btnResume.onclick = () => {
    close(true);
    o.onResume && o.onResume(parseFloat(saved));
  };
  btnRestart.onclick = () => {
    close(true);
    o.onRestart && o.onRestart();
  };

  // Default ke "Lanjut": pilihan yang tidak menghapus progres.
  try {
    btnResume.focus({ preventScroll: true });
  } catch (_) {}

  return true;
};
