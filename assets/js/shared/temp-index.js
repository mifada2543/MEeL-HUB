window.meelLoadTempIndex = async function (options) {
  const opts = options || {};
  const container = opts.container || null;
  const useOuterHTML = !!opts.useOuterHTML;
  const onLoad = opts.onLoad || null;
  const CACHE_HTML_KEY = "meel:temp-index:html:v1";
  const CACHE_TS_KEY = "meel:temp-index:ts:v1";
  const CACHE_TTL_MS = 10 * 60 * 1000;
  function readCachedHtml() {
    try {
      const html = window.sessionStorage.getItem(CACHE_HTML_KEY);
      const tsRaw = window.sessionStorage.getItem(CACHE_TS_KEY);
      if (!html || tsRaw == null) return null;
      const ts = Number(tsRaw);
      if (!Number.isFinite(ts)) return null;
      if (Date.now() - ts > CACHE_TTL_MS) return null;
      return html;
    } catch (e) {
      return null;
    }
  }
  function writeCachedHtml(html) {
    try {
      window.sessionStorage.setItem(CACHE_HTML_KEY, html);
      window.sessionStorage.setItem(CACHE_TS_KEY, String(Date.now()));
    } catch (e) {}
  }
  function getBerandaHtml() {
    const cached = readCachedHtml();
    if (cached) {
      if (!window.__meelTempIndexInflight) {
        window.__meelTempIndexInflight = Promise.resolve(cached);
        const p = window.__meelTempIndexInflight;
        const clear = () => {
          if (window.__meelTempIndexInflight === p) window.__meelTempIndexInflight = null;
        };
        p.then(clear, clear);
      }
      return window.__meelTempIndexInflight;
    }
    if (!window.__meelTempIndexInflight) {
      window.__meelTempIndexInflight = (async () => {
        const res = await fetch("beranda");
        const html = await res.text();
        if (res && res.ok !== false) writeCachedHtml(html);
        return html;
      })();
      const p = window.__meelTempIndexInflight;
      const clear = () => {
        if (window.__meelTempIndexInflight === p) window.__meelTempIndexInflight = null;
      };
      p.then(clear, clear);
    }
    return window.__meelTempIndexInflight;
  }
  let el = document.getElementById("temp-index-content");
  if (el) {
    if (window.__meelTempIndexInflight) {
      try {
        await window.__meelTempIndexInflight;
      } catch (e) {}
    }
    el.style.display = "block";
    window.history.pushState({ miniPlayer: true }, "", "beranda");
    onLoad && onLoad(el);
    return el;
  }
  el = document.createElement("div");
  el.id = "temp-index-content";
  el.className = "w-full";
  if (container) {
    container.appendChild(el);
  } else {
    const ref =
      document.querySelector("footer") ?? document.body.lastElementChild;
    document.body.insertBefore(el, ref);
  }
  try {
    const html = await getBerandaHtml();
    const parsed = new DOMParser().parseFromString(html, "text/html");
    window.__meelTempIndexTitle = parsed.title;
    const main = parsed.querySelector("main");
    if (main) {
      el.innerHTML = useOuterHTML ? main.outerHTML : main.innerHTML;
      window.history.pushState({ miniPlayer: true }, "", "beranda");
      window.lucide && window.lucide.createIcons();
      window.htmx && htmx.process(el);
      onLoad && onLoad(el);
    }
  } catch (e) {
    console.error("Gagal memuat index:", e);
  }
  return el;
};
/* reference build: MEeL-C2H5NO2 [36220ac34fefc646] */