/* reference build: MEeL-C5H5N5O [77b86112497d612e] */
function stopPlaybackStartTimeout() {
  playbackStartTimeout &&
    (clearTimeout(playbackStartTimeout), (playbackStartTimeout = null));
}
function startPlaybackStartTimeout() {
  (stopPlaybackStartTimeout(),
    (playbackStartTimeout = setTimeout(() => {
      hasEverPlayed ||
        (console.warn(
          "Video tidak kunjung mulai putar (>" +
            PLAYBACK_START_TIMEOUT_MS / 1e3 +
            " detik), trigger recovery.",
        ),
        triggerPlayerRecovery());
    }, PLAYBACK_START_TIMEOUT_MS)));
}
function stopSelfHeal() {
  selfHealTimer && (clearTimeout(selfHealTimer), (selfHealTimer = null));
  selfHealUntil = 0;
  selfHealFrom = -1;
}
function trySelfHealMediaError(code) {
  if (selfHealTimer) return true;
  if (!videoElement) return false;
  stopWaitingTimeout();
  try {
    if (isHls && hls) {
      3 === code
        ? hls.recoverMediaError()
        : hls.startLoad(Math.max(0, (player ? player.currentTime : 0) - 1));
    }
    if (player && !player.paused) {
      const e = player.play();
      e && e.catch && e.catch(function () {});
    }
  } catch (e) {
    console.warn("[MEeL] Self-heal error media gagal:", e);
    stopSelfHeal();
    return false;
  }
  selfHealFrom = player ? player.currentTime : 0;
  selfHealUntil = Date.now() + MEDIA_ERROR_GRACE_MS;
  selfHealTimer = setTimeout(function () {
    selfHealTimer = null;
    selfHealUntil = 0;
    const e = selfHealFrom;
    selfHealFrom = -1;
    if (!player) return;
    if (player.currentTime > e + 0.1 && !player.error) {
      console.log("[MEeL] Error media self-healed, playback lanjut normal.");
      return;
    }
    console.warn(
      "[MEeL] Error media tidak pulih dalam " +
        MEDIA_ERROR_GRACE_MS / 1e3 +
        " detik, lanjut ke recovery.",
    );
    triggerPlayerRecovery();
  }, MEDIA_ERROR_GRACE_MS);
  return true;
}
function destroyPlayer() {
  stopSelfHeal();
  if (glowRAF) {
    cancelAnimationFrame(glowRAF);
    glowRAF = null;
  }
  if (
    (stopStuckDetector(),
    stopPlaybackStartTimeout(),
    stopWaitingTimeout(),
    player)
  ) {
    try {
      player.destroy();
    } catch (e) {
      console.error("Gagal destroy player:", e);
    }
    player = null;

    videoElement && videoElement.removeAttribute("controls");
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
  if (!window._videoReconnectOverlay) {
    window._videoReconnectOverlay = meelCreateReconnectOverlay({
      containerId: "main-video-wrapper",
      color: "red",
    });
  }
  window._videoReconnectOverlay.show();
}
let probeController = null;
let probeRetryTimer = null;
let probeTimeout = null;
let probeGeneration = 0;
function cancelMediaProbe() {
  probeGeneration++;
  if (probeController) {
    try {
      probeController.abort();
    } catch (err) {}
    probeController = null;
  }
  if (probeRetryTimer) {
    clearTimeout(probeRetryTimer);
    probeRetryTimer = null;
  }
  if (probeTimeout) {
    clearTimeout(probeTimeout);
    probeTimeout = null;
  }
  isCheckingStatus = !1;
}
function scheduleProbeRetry(status) {
  const serverError = status >= 500;
  const step = serverError ? 1e4 : 3e3;
  const cap = serverError ? 6e4 : 15e3;
  probeRetryTimer = setTimeout(() => {
    probeRetryTimer = null;
    if (document.hidden) {
      isCheckingStatus = !1;
      return;
    }
    isCheckingStatus = !1;
    checkMediaAndRecover();
  }, Math.min(step * (recoveryRetryCount + 1), cap));
}
function probeOnline() {
  console.log("Media terdeteksi online! Memulai pemulihan...");
  recoveryRetryCount = 0;
  lastSuccessfulRecovery = Date.now();
  const e = player ? player.currentTime : 0;
  (e > 0 && localStorage.setItem(storageKeyVideo, e),
    window._videoReconnectOverlay && window._videoReconnectOverlay.hide(),
    (isCheckingStatus = !1));
  if (typeof isMiniPlayerActive !== "undefined" && isMiniPlayerActive) {
    try {
      hls && hls.startLoad(Math.max(0, (player ? player.currentTime : 0) - 1));
    } catch (err) {}
    try {
      if (player && player.paused && !player.ended) {
        const p = player.play();
        p && p.catch && p.catch(function () {});
      }
    } catch (err) {}
  } else {
    ((isRecovering = !0),
      (isAutoRecovering = !0),
      window.htmx
        ? htmx.ajax("GET", window.__meelWatchUrl || window.location.href, {
            target: "#main-video-wrapper",
            select: "#main-video-wrapper",
            swap: "outerHTML",
          })
        : window.location.reload());
  }
}
function checkMediaAndRecover() {
  if (isCheckingStatus) return;

  if (!document.getElementById("main-video-wrapper")) {
    isCheckingStatus = !1;
    isRecovering = !1;
    return;
  }
  if ((recoveryRetryCount++, recoveryRetryCount > MAX_RECOVERY_RETRIES)) {
    (console.warn("Batas percobaan pemulihan tercapai, berhenti mencoba."),
      (isCheckingStatus = !1),
      (recoveryRetryCount = 0));
    const e = document.getElementById("meel-reconnect-overlay");
    return void (
      e && window._videoReconnectOverlay &&
      window._videoReconnectOverlay.showFailed()
    );
  }
  if (player && player.paused && hasEverPlayed) {
    console.log("Video sedang di-paused, skip recovery check.");
    isCheckingStatus = !1;
    return;
  }
  ((isCheckingStatus = !0),
    showReconnectingIndicator(),
    console.log(`Mengecek ketersediaan file media di: ${videoSrc}`));
  const generation = ++probeGeneration;
  const controller = new AbortController();
  probeController = controller;
  const timeout = setTimeout(() => {
    controller.abort();
  }, 3e3);
  probeTimeout = timeout;
  fetch(videoSrc, {
    method: "GET",
    cache: "no-store",
    signal: controller.signal,
  })
    .then(async (response) => {
      if (generation !== probeGeneration) return;
      const contentType = (response.headers.get("content-type") || "").toLowerCase();
      if (!response.ok) {
        clearTimeout(timeout);
        probeTimeout = null;
        probeController = null;
        console.log(
          "Media belum siap (status " + response.status + ", tipe " + contentType + "). Menguji ulang...",
        );
        scheduleProbeRetry(response.status);
        return;
      }
      let head = "";
      try {
        head = (await response.text()).slice(0, 256);
      } catch (err) {}
      if (generation !== probeGeneration) return;
      clearTimeout(timeout);
      probeTimeout = null;
      probeController = null;
      const mediaOk = isHls
        ? head.trimStart().startsWith("#EXTM3U")
        : contentType.startsWith("video/") || contentType.startsWith("audio/");
      if (!mediaOk) {
        console.log(
          "Media masih offline (kembalian server bukan file media). Menguji ulang...",
        );
        scheduleProbeRetry(response.status);
        return;
      }
      probeOnline();
    })
    .catch((err) => {
      if (generation !== probeGeneration) return;
      clearTimeout(timeout);
      probeTimeout = null;
      probeController = null;
      console.log("Koneksi media gagal/offline atau timeout. Menguji ulang...");
      scheduleProbeRetry(0);
    });
}
function triggerPlayerRecovery() {
  if (isRecovering || isCheckingStatus || isTransitioningNext) return;
  if (selfHealUntil > Date.now()) {
    console.log("Masih dalam grace period self-heal, skip recovery.");
    return;
  }
  if (!document.getElementById("main-video-wrapper")) return;
  if (document.hidden) {
    if (!pendingRecoveryOnVisible) {
      pendingRecoveryOnVisible = !0;
      console.log("Tab di-background, pemulihan ditunda sampai tab aktif kembali.");
    }
    return;
  }
  pendingRecoveryOnVisible = !1;
  if (player && player.paused && hasEverPlayed)
    return void console.log("Video sedang di-paused, skip recovery.");
  const e = Date.now();
  lastSuccessfulRecovery > 0 &&
  e - lastSuccessfulRecovery < POST_RECOVERY_COOLDOWN_MS
    ? console.log(
        "Masih dalam masa cooldown pasca-recovery (" +
          Math.round(
            (POST_RECOVERY_COOLDOWN_MS - (e - lastSuccessfulRecovery)) / 1e3,
          ) +
          "s lagi), skip recovery.",
      )
    : e - lastRecoveryTime < recoveryDelay
      ? console.log("Menunda pemulihan: masih dalam masa cooldown.")
      : ((lastRecoveryTime = e), stopStuckDetector(), checkMediaAndRecover());
}
function startStuckDetector() {
  if (!window._videoStuckDetector) {
    window._videoStuckDetector = meelCreateStuckDetector({
      interval: 2000,
      threshold: 6,
      isPaused: function () { return hasEverPlayed && player && player.paused; },
      getCurrentTime: function () { return player ? player.currentTime : 0; },
      onStuck: function () { triggerPlayerRecovery(); },
    });
  }
  window._videoStuckDetector.start();
}
function stopStuckDetector() {
  window._videoStuckDetector && window._videoStuckDetector.stop();
}
function registerHlsErrorListener(hlsInstance) {
  hlsInstance.on(Hls.Events.ERROR, function (_event, data) {
    if (!data.fatal) return;
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
    }
  });
}
function registerVideoErrorListener(videoEl) {
  if (!videoEl || videoEl.dataset.meelErrorRegistered) return;
  videoEl.dataset.meelErrorRegistered = "1";
  videoEl.addEventListener("error", () => {
    const mediaError = videoEl.error;
    if (!mediaError) return;
    console.warn(
      "HTML5 video error:",
      mediaError.message || "Unknown",
      "code:",
      mediaError.code,
    );
    if ([2, 3].includes(mediaError.code) && trySelfHealMediaError(mediaError.code)) return;
    if ([2, 3, 4].includes(mediaError.code)) triggerPlayerRecovery();
  });
  videoEl.addEventListener("stalled", () => {
    console.warn("Video stalled, memulai waiting timeout...");
    startWaitingTimeout();
  });
}
function softResumePlayer() {
  try {
    if (hls) hls.startLoad(Math.max(0, (player ? player.currentTime : 0) - 1));
    if (player && player.paused && !player.ended) {
      const p = player.play();
      p && p.catch && p.catch(function () {});
    }
    return true;
  } catch (e) {
    return false;
  }
}
function startWaitingTimeout() {
  if (!window._videoWaitingTimeout) {
    window._videoWaitingTimeout = meelCreateWaitingTimeout({
      timeout: 10000,
      onTimeout: function () {
        console.warn("Video menunggu data terlalu lama (>10 detik)");
        if (softResumePlayer()) {
          setTimeout(function () {
            if (player && !player.ended && player.readyState < 2) {
              console.warn("Soft-resume gagal pulih, lanjut recovery penuh.");
              triggerPlayerRecovery();
            }
          }, 5000);
          return;
        }
        triggerPlayerRecovery();
      },
    });
  }
  window._videoWaitingTimeout.start();
}
function stopWaitingTimeout() {
  window._videoWaitingTimeout && window._videoWaitingTimeout.stop();
}
if (!window._meelRecoveryVisBound) {
  window._meelRecoveryVisBound = !0;
  document.addEventListener("visibilitychange", function () {
    if (document.hidden || !pendingRecoveryOnVisible) return;
    pendingRecoveryOnVisible = !1;
    console.log("Tab aktif kembali, menjalankan pemulihan yang tertunda.");
    triggerPlayerRecovery();
  });
  document.addEventListener("htmx:beforeSwap", function (event) {
    const detail = event && event.detail;
    const target = detail && detail.target;
    if (target && target.id === "main-video-wrapper") cancelMediaProbe();
  });
  window.addEventListener("pagehide", cancelMediaProbe);
}
