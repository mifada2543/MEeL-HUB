"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");

const root = path.join(__dirname, "..", "..");
function generatedServiceWorker() {
  return fs
    .readFileSync(path.join(root, "sw.js.php"), "utf8")
    .replace(/<\?php[\s\S]*?\?>/g, "0");
}
const listeners = {};
const putCalls = [];
const logs = [];
let putRejects = false;
function makeHeaders(initial) {
  const values = Object.assign({}, initial || {});
  return {
    get(k) {
      return values[k] || "";
    },
    set(k, v) {
      values[k] = v;
    },
  };
}
class FakeResponse {
  constructor(body, options) {
    const opts = options || {};
    this.body = body;
    this.status = opts.status === undefined ? 200 : opts.status;
    this.statusText = opts.statusText || "";
    this.headers = makeHeaders(opts.headers);
  }
  get ok() {
    return this.status >= 200 && this.status < 300;
  }
  clone() {
    return new FakeResponse(this.body, { status: this.status, headers: this.headers });
  }
  blob() {
    return Promise.resolve(this.body);
  }
  static error() {
    return new FakeResponse("", { status: 0 });
  }
}
class FakeAbortController {
  constructor() {
    this.signal = {};
  }
  abort() {}
}
const cache = {
  match: async () => undefined,
  put: async (request, response) => {
    putCalls.push(request && request.url ? request.url : String(request));
    if (putRejects) throw new Error("NetworkError");
  },
  keys: async () => [],
  delete: async () => {},
};
const sandbox = {
  console: {
    log: (...a) => logs.push(a.join(" ")),
    warn: (...a) => logs.push("WARN:" + a.join(" ")),
  },
  URL,
  location: { origin: "http://localhost" },
  Headers: makeHeaders,
  Response: FakeResponse,
  caches: {
    match: async () => undefined,
    open: async () => cache,
    keys: async () => [],
    delete: async () => {},
  },
  fetch: async () => new FakeResponse("webp", { headers: { "content-type": "image/webp" } }),
  self: {
    registration: { scope: "http://localhost/MEeL/" },
    addEventListener: (type, fn) => {
      listeners[type] = fn;
    },
    skipWaiting: () => {},
    clients: { claim: () => {} },
  },
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(generatedServiceWorker(), sandbox, { filename: "sw.js.php (generated)" });
function requestFor(url, destination, accept) {
  return {
    url,
    method: "GET",
    destination: destination || "",
    headers: { get: (name) => (name.toLowerCase() === "accept" ? accept || "" : "") },
  };
}
async function dispatch(url, destination, accept) {
  let responded = false;
  let handlerPromise = null;
  listeners.fetch({
    request: requestFor(url, destination, accept),
    respondWith(promise) {
      responded = true;
      handlerPromise = promise;
    },
  });
  if (handlerPromise) await handlerPromise.catch(() => {});
  return { responded, url };
}
(async () => {
  const results = {};
  const manifest = await dispatch("http://localhost/MEeL/video/upload/video/clip/clip.m3u8", "");
  const segment = await dispatch("http://localhost/MEeL/video/upload/video/clip/clip_000.ts", "");
  const vtt = await dispatch("http://localhost/MEeL/video/upload/thumbnail/clip_thumbnails.vtt", "");
  const thumbnail = await dispatch("http://localhost/MEeL/video/upload/thumbnail/clip_thumb.webp", "image");
  const document = await dispatch("http://localhost/MEeL/video/watch?v=240", "document", "text/html");
  const script = await dispatch("http://localhost/MEeL/assets/js/app.js?v=1", "script");
  results.hlsManifestPassthrough = manifest.responded === false;
  results.hlsSegmentPassthrough = segment.responded === false;
  results.hlsVttPassthrough = vtt.responded === false;
  results.thumbnailCached = thumbnail.responded === true;
  results.documentCached = document.responded === true;
  results.staticCached = script.responded === true;
  results.noMediaInCache = !putCalls.some((url) => /\.m3u8|\.ts(\?|$)/.test(url));
  const before = putCalls.length;
  putRejects = true;
  const safe = await dispatch("http://localhost/MEeL/video/upload/thumbnail/safe.webp", "image");
  putRejects = false;
  results.safeCachePut = safe.responded === true && putCalls.length === before + 1;
  results.cacheFailureWarned = logs.some((line) => line.includes("Cache.put"));
  process.stdout.write(JSON.stringify(results) + "\n");
})().catch((err) => {
  process.stderr.write(String(err && err.stack || err) + "\n");
  process.exit(1);
});
