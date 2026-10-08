/* reference build: MEeL-C3H7NO2S [20fcfdefc875d5c3] */

(function () {
  "use strict";
  if (document.readyState !== "loading") return;
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName("script");
      return s[s.length - 1] ? s[s.length - 1].src : "";
    })();
  var base = src.substring(0, src.lastIndexOf("/") + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? "?v=" + encodeURIComponent(m[1]) : "";
  var files = ["init.js", "url-preview.js", "submit.js"];
  for (var i = 0; i < files.length; i++) {
    var s = document.createElement("script");
    s.src = base + files[i] + qs;
    s.async = false;
    document.head.appendChild(s);
  }
})();
