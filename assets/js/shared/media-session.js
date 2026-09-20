(function () {
  "use strict";

  window.meelUpdateMediaSession = function (meta) {
    if (!("mediaSession" in navigator) || !meta) return;
    var artwork = [];
    if (meta.artwork) {
      var absUrl = meta.artwork;
      try {
        absUrl = new URL(meta.artwork, document.baseURI).href;
      } catch (e) {}
      artwork.push({ src: absUrl, sizes: "512x512", type: "image/jpeg" });
    }
    try {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: meta.title || "",
        artist: meta.artist || "",
        artwork: artwork,
      });
    } catch (e) {
      console.warn("[MEeL] mediaSession error:", e);
    }
  };
})();
