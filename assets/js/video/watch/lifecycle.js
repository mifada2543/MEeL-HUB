(window.addEventListener("pageshow", (e) => {
  if (!e.persisted) return;
  const resumeAt = player ? player.currentTime : 0;
  cancelMediaProbe();
  stopSelfHeal();
  stopStuckDetector();
  stopWaitingTimeout();
  stopPlaybackStartTimeout();
  const miniShell = document.getElementById("mini-player-shell");
  if (miniShell && miniShell.style.display !== "none") {
    if (hls) {
      try {
        hls.destroy();
      } catch (err) {}
      hls = null;
    }
    if (isHls && window.Hls && Hls.isSupported() && videoElement) {
      hls = new Hls(HLS_CONFIG);
      registerHlsErrorListener(hls);
      hls.loadSource(videoSrc);
      hls.attachMedia(videoElement);
      const resume = function () {
        try {
          if (resumeAt > 0) videoElement.currentTime = resumeAt;
          if (videoElement.paused && !videoElement.ended) {
            const p = videoElement.play();
            p && p.catch && p.catch(function () {});
          }
        } catch (err) {}
        videoElement.removeEventListener("loadedmetadata", resume);
      };
      if (videoElement.readyState >= 1) resume();
      else videoElement.addEventListener("loadedmetadata", resume);
    }
    lastTimeUpdateTimestamp = Date.now();
    lastPlayTime = resumeAt;
    return;
  }
  destroyPlayer();
  window.location.reload();
}),
  window.addEventListener("pagehide", function () {
    cancelMediaProbe();
    stopStuckDetector();
    stopWaitingTimeout();
    stopPlaybackStartTimeout();
  }),
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) {
      if (glowRAF) {
        cancelAnimationFrame(glowRAF);
        glowRAF = null;
      }
      const e = player?.elements?.container;
      if (e && e._fsGlowPause) e._fsGlowPause();
    } else {
      const miniShell = document.getElementById("mini-player-shell");
      const miniActive = !!(miniShell && miniShell.style.display !== "none");
      if (
        !miniActive &&
        glowEnabled &&
        videoElement &&
        !videoElement.paused &&
        !videoElement.ended &&
        !glowRAF &&
        glowStartFn
      ) {
        glowStartFn();
      }
      const e = player?.elements?.container;
      if (
        e &&
        e._fsGlowStart &&
        videoElement &&
        !videoElement.paused &&
        !videoElement.ended
      ) {
        e._fsGlowStart();
      }
      lastTimeUpdateTimestamp = Date.now();
      player && (lastPlayTime = player.currentTime);
    }
  }),
  document.readyState === "loading"
    ? document.addEventListener("DOMContentLoaded", () => {
        initPlayer();
      })
    : initPlayer(),
  document.addEventListener("htmx:beforeSwap", function (e) {
    if ("main-video-wrapper" === e.detail.target.id && isRecovering) {
      const t = e.detail.xhr;
      t &&
        t.status >= 400 &&
        (e.preventDefault(),
        console.warn(
          "HTMX recovery swap gagal (status " +
            t.status +
            "), fallback reload.",
        ),
        window.location.reload());
    }
  }),
  document.addEventListener("htmx:afterSwap", function (e) {
    if ("main-video-wrapper" === e.detail.target.id) {
      (destroyPlayer(), (isRecovering = !1));
      const n = document.getElementById("meel-reconnect-indicator");
      (n && n.remove(), initPlayer());
      if (isMiniPlayerActive) {
        const o = document.getElementById("main-video-wrapper");
        o && o.classList.add("mini-player-mode");
        const l = document.getElementById("mini-player-shell");
        if (l && o && o.parentNode !== l) {
          const a = l.querySelector("#mini-expand-btn");
          a ? l.insertBefore(o, a) : l.prepend(o);
        }
      }
    }
    if (isMiniPlayerActive) {
      const t = document.getElementById("temp-index-content");
      t && attachMiniPlayerVideoCardListeners(t);
    }
  }),

  document.addEventListener("htmx:afterSettle", function (e) {
    if ("main-video-wrapper" !== e.detail.target.id || !isMiniPlayerActive)
      return;
    const o = document.getElementById("main-video-wrapper");
    if (!o) return;
    o.classList.add("mini-player-mode");
    const l = document.getElementById("mini-player-shell");
    if (l && o.parentNode !== l) {
      const a = l.querySelector("#mini-expand-btn");
      a ? l.insertBefore(o, a) : l.prepend(o);
    }
    syncMiniPlayerBodyPadding();
  }));

/* reference build: MEeL-C10H15N [a3a01d2e61b17c6b] */