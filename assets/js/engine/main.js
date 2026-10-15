/* reference build: MEeL-C9H11NO2 [bc40ac36f5c19737] */
(function () {
  "use strict";
  (function () {
    if (window.__meelPhaseQ) return;
    var queue = (window.__meelPhaseQ = []);
    var real = null;

    window.meelPhase = function (phase) {
      if (real) {
        real(phase);
        return;
      }
      queue.push(phase);
    };
    window.__meelPhaseBind = function (fn) {
      if (real) return;
      real = fn;
      while (queue.length) {
        try {
          real(queue.shift());
        } catch (e) {
          console.error("MEeL engine phase error:", e);
        }
      }
    };
  })();
  if (window.__meelEngineLoaded) return;
  window.__meelEngineLoaded = true;
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName("script");
      return s[s.length - 1] ? s[s.length - 1].src : "";
    })();
  var base = src.substring(0, src.lastIndexOf("/") + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? "?v=" + encodeURIComponent(m[1]) : "";
  var files = ["core.js", "download.js", "transcode.js", "result.js"];
  for (var i = 0; i < files.length; i++) {
    var s = document.createElement("script");
    s.src = base + files[i] + qs;
    s.async = false;
    document.head.appendChild(s);
  }
})();
