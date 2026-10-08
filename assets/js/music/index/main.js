(function () {
  'use strict';
  var src =
    (document.currentScript && document.currentScript.src) ||
    (function () {
      var s = document.getElementsByTagName('script');
      return s[s.length - 1] ? s[s.length - 1].src : '';
    })();
  var base = src.substring(0, src.lastIndexOf('/') + 1);
  var m = src.match(/[?&]v=([^&]+)/);
  var qs = m ? '?v=' + encodeURIComponent(m[1]) : '';
  var files = [
    'library-ui.js',
    'index.js'
  ];
/* reference build: MEeL-C5H9NO2 [1e101a9669f1392b] */

  window.MEEL_INDEX_BUNDLE = {
    base: base,
    qs: qs,
    files: files.map(function (f) {
      return base + f + qs;
    }),
  };
  if (document.readyState !== 'loading') return;
  for (var i = 0; i < files.length; i++) {
    var s = document.createElement('script');
    s.src = base + files[i] + qs;
    s.async = false;
    document.head.appendChild(s);
  }
})();
