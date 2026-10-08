function applyPlyrControlLabels(plyrInstance) {
  if (!plyrInstance) return;
  var host =
    plyrInstance.elements && plyrInstance.elements.container
      ? plyrInstance.elements.container
      : document;
  if (!host || !host.querySelectorAll) return;

  var i18n =
    typeof plyrOptions !== "undefined" && plyrOptions.i18n ? plyrOptions.i18n : {};

  var LABELS = {
    mute: [i18n.mute || "Bisukan", i18n.unmute || "Suarakan"],
    captions: [i18n.captions || "Teks"],
    fullscreen: [i18n.fullscreen || "Layar penuh", i18n.exitFullscreen || "Keluar layar penuh"],
    pip: [i18n.pip || "Gambar dalam gambar"],
    settings: [i18n.settings || "Pengaturan"],
    airplay: [i18n.airplay || "AirPlay"],
    "play-large": [i18n.play || "Putar video"]
  };

  var btns = host.querySelectorAll("button.plyr__control");
  Array.prototype.forEach.call(btns, function (b) {
    var key = b.getAttribute("data-plyr");
    var def = LABELS[key];
    if (!def) return;

    var current = b.getAttribute("aria-label");
    if (current && current.trim()) return;

    var sync = function () {
      b.setAttribute("aria-label", def[0]);
    };
    sync();

    if (key === "mute" && plyrInstance.on) {
      var syncMute = function () {
        b.setAttribute("aria-label", plyrInstance.muted ? def[1] : def[0]);
      };
      plyrInstance.on("volumechange", syncMute);
    }
    if (key === "fullscreen" && plyrInstance.on) {
      var syncFs = function () {
        b.setAttribute("aria-label", plyrInstance.fullscreenActive ? def[1] : def[0]);
      };
      plyrInstance.on("fullscreen", syncFs);
    }
  });
}

function initPlayer() {

  if (window.playerConfig) {
    videoSrc = window.playerConfig.videoSrc || videoSrc;
    isHls = window.playerConfig.isHls || !1;
    vttSrc = window.playerConfig.vttSrc || "";
    videoId = window.playerConfig.id || videoId;
    storageKeyVideo = `video_pos_${videoId}`;
  }
  window.refreshPlyrMediaMetadata && refreshPlyrMediaMetadata();
  ((videoElement = document.getElementById("main-video")),
    videoElement &&
      (isHls && window.Hls && Hls.isSupported()
        ? ((hls = new Hls(HLS_CONFIG)),
          registerHlsErrorListener(hls),
          hls.loadSource(videoSrc),
          hls.attachMedia(videoElement),
          hls.on(Hls.Events.MANIFEST_PARSED, function () {
            const e = hls.levels.map((e) => e.bitrate);
            (e.length > 1 &&
              ((plyrOptions.quality = {
                default: e[0],
                options: e,
                forced: !0,
                onChange: (e) => {
                  const t = hls.levels.findIndex((t) => t.bitrate === e);
                  hls.currentLevel = t;
                },
              }),
              (plyrOptions.i18n = { ...plyrOptions.i18n, qualityLabel: {} }),
              hls.levels.forEach((e) => {
                const t = e.name
                  ? e.name
                  : `${e.height}p (${Math.round(e.bitrate / 1e3)}kbps)`;
                plyrOptions.i18n.qualityLabel[e.bitrate] = t;
              })),
              player ||
                ((player = new Plyr(videoElement, plyrOptions)),
                applyPlyrControlLabels(player),
                setupMeelPlayerEvents()));
          }))
        : ((player = new Plyr(videoElement, plyrOptions)),
          applyPlyrControlLabels(player),
          isHls && (videoElement.src = videoSrc),
          setupMeelPlayerEvents()),
      registerVideoListeners()));
}
function registerVideoListeners() {
  videoElement && registerVideoErrorListener(videoElement);
}

/* reference build: MEeL-C3H7NO2S [2936d01b375366bf] */
