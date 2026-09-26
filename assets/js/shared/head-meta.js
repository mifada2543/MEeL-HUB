(function () {
  "use strict";

  var META_MAP = [
    { sel: 'meta[name="description"]', attr: "content" },
    { sel: 'meta[property="og:title"]', attr: "content" },
    { sel: 'meta[property="og:description"]', attr: "content" },
    { sel: 'meta[property="og:image"]', attr: "content" },
    { sel: 'meta[property="og:image:width"]', attr: "content" },
    { sel: 'meta[property="og:image:height"]', attr: "content" },
    { sel: 'meta[property="og:url"]', attr: "content" },
    { sel: 'meta[property="og:type"]', attr: "content" },
    { sel: 'meta[name="twitter:title"]', attr: "content" },
    { sel: 'meta[name="twitter:description"]', attr: "content" },
    { sel: 'meta[name="twitter:image"]', attr: "content" },
  ];

  /**
   * Sinkronkan tag <head> (og:/twitter:/description/canonical/title) dari
   * dokumen hasil fetch ke halaman yang sedang berjalan. Dipakai navigasi
   * client-side mini-player & skipToNextVideo supaya og:image ikut berganti.
   * @param {Document} sourceDoc dokumen hasil DOMParser
   */
  window.meelUpdateHeadMeta = function (sourceDoc) {
    if (!sourceDoc || !sourceDoc.querySelector) return;

    META_MAP.forEach(function (m) {
      var src = sourceDoc.querySelector(m.sel);
      if (!src) return;
      var value = src.getAttribute(m.attr);
      if (value === null || value === "") return;

      var targets = document.head.querySelectorAll(m.sel);
      if (targets.length === 0) {
        var created = document.createElement("meta");
        var name = m.sel.match(/^(?:meta\[)(?:name|property)="([^"]+)"/);
        if (name) {
          if (m.sel.indexOf("name=") !== -1) created.setAttribute("name", name[1]);
          else created.setAttribute("property", name[1]);
        }
        created.setAttribute(m.attr, value);
        document.head.appendChild(created);
      } else {
        targets.forEach(function (t) {
          t.setAttribute(m.attr, value);
        });
      }
    });

    var srcCanonical = sourceDoc.querySelector('link[rel="canonical"]');
    var canonicalValue = srcCanonical && srcCanonical.getAttribute("href");
    if (canonicalValue) {
      var canonicals = document.head.querySelectorAll('link[rel="canonical"]');
      if (canonicals.length === 0) {
        var link = document.createElement("link");
        link.setAttribute("rel", "canonical");
        link.setAttribute("href", canonicalValue);
        document.head.appendChild(link);
      } else {
        canonicals.forEach(function (c) {
          c.setAttribute("href", canonicalValue);
        });
      }
    }

    if (sourceDoc.title) document.title = sourceDoc.title;
  };
})();

/* reference build: MEeL-C9H11NO2 [665d94826324dced] */
