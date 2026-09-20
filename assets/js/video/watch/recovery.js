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
function destroyPlayer() {
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
  ((isCheckingStatus = !0),
    showReconnectingIndicator(),
    console.log(`Mengecek ketersediaan file media di: ${videoSrc}`));
  const e = new AbortController(),
    t = setTimeout(() => {
      e.abort();
    }, 3e3);
  fetch(videoSrc, { method: "HEAD", signal: e.signal })
    .then((e) => {
      clearTimeout(t);
      const n = e.headers.get("content-type") || "";
      if (e.ok && !n.includes("text/html")) {
        (console.log("Media terdeteksi online! Memulai pemulihan via HTMX..."),
          (recoveryRetryCount = 0),
          (lastSuccessfulRecovery = Date.now()));
        const e = player ? player.currentTime : 0;
        e > 0 && localStorage.setItem(storageKeyVideo, e);
        window._videoReconnectOverlay && window._videoReconnectOverlay.hide(),
          (isRecovering = !0),
          (isAutoRecovering = !0),
          window.htmx
            ? htmx.ajax("GET", window.location.href, {
                target: "#main-video-wrapper",
                select: "#main-video-wrapper",
                swap: "outerHTML",
              })
            : window.location.reload(),
          (isCheckingStatus = !1);
      } else
        (console.log(
          "Media masih offline (kembalian server bukan file media). Menguji ulang dalam 3 detik...",
        ),
          setTimeout(() => {
            ((isCheckingStatus = !1), checkMediaAndRecover());
          }, 3e3));
    })
    .catch((e) => {
      (clearTimeout(t),
        console.log(
          "Koneksi media gagal/offline atau timeout. Menguji ulang dalam 3 detik...",
        ),
        setTimeout(() => {
          ((isCheckingStatus = !1), checkMediaAndRecover());
        }, 3e3));
    });
}
function triggerPlayerRecovery() {
  if (isRecovering || isCheckingStatus || isTransitioningNext) return;
  if (!document.getElementById("main-video-wrapper")) return;
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
    if ([2, 3, 4].includes(mediaError.code)) triggerPlayerRecovery();
  });
  videoEl.addEventListener("stalled", () => {
    console.warn("Video stalled, memulai waiting timeout...");
    startWaitingTimeout();
  });
}
function startWaitingTimeout() {
  if (!window._videoWaitingTimeout) {
    window._videoWaitingTimeout = meelCreateWaitingTimeout({
      timeout: 10000,
      onTimeout: function () {
        console.warn("Video menunggu data terlalu lama (>10 detik), trigger recovery");
        triggerPlayerRecovery();
      },
    });
  }
  window._videoWaitingTimeout.start();
}
function stopWaitingTimeout() {
  window._videoWaitingTimeout && window._videoWaitingTimeout.stop();
}
