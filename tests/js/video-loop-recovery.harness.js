"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const root = path.join(__dirname, "..", "..");
const scenario = process.argv[2] || "selfheal-success";
let CLOCK = 1000000;
const timers = [];
function fakeSetTimeout(fn, ms) {
  const id = timers.length + 1;
  timers.push({ id: id, fn: fn, at: CLOCK + (ms || 0) });
  return id;
}
function fakeClearTimeout(id) {
  const i = timers.findIndex((t) => t.id === id);
  if (i >= 0) timers.splice(i, 1);
}
function advance(ms) {
  CLOCK += ms;
  for (;;) {
    const due = timers
      .filter((t) => t.at <= CLOCK)
      .sort((a, b) => a.at - b.at || a.id - b.id);
    if (!due.length) break;
    const t = due[0];
    timers.splice(timers.indexOf(t), 1);
    t.fn();
  }
}
const logs = { warn: [], log: [] };
const overlayCalls = [];
const docListeners = {};
const winListeners = {};
let abortCount = 0;
let reloadCount = 0;
let destroyCount = 0;
let miniShellVisible = false;
function makeVideoEl() {
  const el = {
    dataset: {},
    _listeners: {},
    error: null,
    removeAttribute() {},
    addEventListener(type, fn) {
      (this._listeners[type] = this._listeners[type] || []).push(fn);
    },
    dispatch(type) {
      (this._listeners[type] || []).forEach((fn) => fn());
    },
  };
  return el;
}
function makePlayer() {
  return {
    _paused: false,
    currentTime: 0,
    duration: 240,
    error: null,
    loop: false,
    loopStart: 0,
    get paused() {
      return this._paused;
    },
    set paused(v) {
      this._paused = !!v;
    },
    play() {
      this._paused = false;
      return Promise.resolve();
    },
    on() {},
    destroy() {
      destroyCount++;
    },
  };
}
let probeResponse = {
  ok: true,
  status: 200,
  headers: { get: () => "application/x-mpegURL" },
  text: async () => "#EXTM3U\n#EXT-X-VERSION:3\nclip_000.ts\n",
};
let probeCalls = 0;
let fetchCount = 0;
function fakeFetch() {
  probeCalls++;
  fetchCount++;
  return Promise.resolve(probeResponse);
}
const videoElement = makeVideoEl();
const player = makePlayer();
const hlsCalls = [];
const sandbox = {
  console: {
    log: (...a) => logs.log.push(a.join(" ")),
    warn: (...a) => logs.warn.push(a.join(" ")),
    error: () => {},
  },
  setTimeout: fakeSetTimeout,
  clearTimeout: fakeClearTimeout,
  Date: { now: () => CLOCK },
  Math: Math,
  Promise: Promise,
  JSON: JSON,
  localStorage: {
    _m: new Map(),
    getItem(k) {
      return this._m.has(k) ? this._m.get(k) : null;
    },
    setItem(k, v) {
      this._m.set(k, String(v));
    },
    removeItem(k) {
      this._m.delete(k);
    },
  },
  sessionStorage: {
    _m: new Map(),
    getItem(k) { return this._m.has(k) ? this._m.get(k) : null; },
    setItem(k, v) { this._m.set(k, String(v)); },
    removeItem(k) { this._m.delete(k); },
  },
  document: {
    hidden: false,
    readyState: "complete",
    getElementById: (id) => {
      if (id === "main-video-wrapper") return {};
      if (id === "mini-player-shell" && miniShellVisible) return { style: { display: "block" } };
      return null;
    },
    addEventListener: (type, fn) => {
      (docListeners[type] = docListeners[type] || []).push(fn);
    },
  },
  addEventListener: (type, fn) => {
    (winListeners[type] = winListeners[type] || []).push(fn);
  },
  name: "",
  location: { reload: () => { reloadCount++; } },
  fetch: fakeFetch,
  AbortController: class {
    constructor() {
      this.signal = { aborted: false };
    }
    abort() {
      abortCount++;
      this.signal.aborted = true;
    }
  },
  videoElement: videoElement,
  player: player,
  isHls: true,
  hasEverPlayed: true,
  isRecovering: false,
  isCheckingStatus: false,
  isTransitioningNext: false,
  pendingRecoveryOnVisible: false,
  recoveryRetryCount: 0,
  MAX_RECOVERY_RETRIES: 20,
  recoveryDelay: 0,
  lastRecoveryTime: 0,
  lastSuccessfulRecovery: 0,
  POST_RECOVERY_COOLDOWN_MS: 5000,
  storageKeyVideo: "video_pos_test",
  videoSrc: "upload/video/test/test.m3u8",
  selfHealUntil: 0,
  selfHealTimer: null,
  selfHealFrom: -1,
  MEDIA_ERROR_GRACE_MS: 8000,
  glowRAF: null,
  playbackStartTimeout: null,
  hls: null,
  HLS_CONFIG: {},
  Hls: Object.assign(class {
    static isSupported() { return true; }
    constructor(config) { this.config = config; }
    on() {}
    loadSource() { hlsCalls.push("loadSource"); }
    attachMedia() { hlsCalls.push("attachMedia"); }
    destroy() { hlsCalls.push("destroy"); }
  }, { Events: { MANIFEST_PARSED: "manifestParsed" } }),
  meelCreateStuckDetector: () => ({ start() {}, stop() {} }),
  meelCreateWaitingTimeout: () => ({ start() {}, stop() {} }),
  meelCreateReconnectOverlay: () => ({
    show: () => overlayCalls.push("show"),
    hide: () => overlayCalls.push("hide"),
    showFailed: () => overlayCalls.push("showFailed"),
  }),
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
sandbox.useHls = function () {
  sandbox.hls = {
    recoverMediaError() {
      hlsCalls.push("recoverMediaError");
    },
    startLoad(pos) {
      hlsCalls.push("startLoad:" + pos);
    },
    destroy() { hlsCalls.push("destroy"); },
  };
};
sandbox.useHls();

vm.createContext(sandbox);
vm.runInContext(
  fs.readFileSync(path.join(root, "assets/js/video/watch/recovery.js"), "utf8"),
  sandbox,
  { filename: "recovery.js" },
);
sandbox.registerVideoErrorListener(videoElement);
const recovery = {
  get fetchCalls() {
    return fetchCount;
  },
};
sandbox.fetch = fakeFetch;

const R = {};
function raiseDecodeError() {
  videoElement.error = { code: 3, message: "PIPELINE_ERROR_DECODE: video decode error! code: 3" };
  videoElement.dispatch("error");
}
if (scenario === "selfheal-success") {
  raiseDecodeError();
  R.recoverCalledImmediately = hlsCalls.includes("recoverMediaError");
  R.recoveryDuringGrace = recovery.fetchCalls > 0;
  sandbox.triggerPlayerRecovery();
  R.stuckBlockedDuringGrace = recovery.fetchCalls === 0;
  advance(2000);
  player.currentTime = 120;
  advance(6000);
  R.recoveryAfterSuccess = recovery.fetchCalls > 0;
  R.selfHealedLogged = logs.log.some((l) => l.includes("self-healed"));
}
if (scenario === "selfheal-escalate") {
  raiseDecodeError();
  R.recoveryDuringGrace = recovery.fetchCalls > 0;
  advance(8000);
  R.escalatedAfterGrace = recovery.fetchCalls > 0;
  R.escalationLogged = logs.warn.some((l) => l.includes("tidak pulih"));
}
if (scenario === "selfheal-double-error") {
  raiseDecodeError();
  const first = hlsCalls.length;
  raiseDecodeError();
  R.singleHealAttempt = hlsCalls.length === first;
}
if (scenario === "grace-blocks-stuck") {
  raiseDecodeError();
  sandbox.triggerPlayerRecovery();
  sandbox.triggerPlayerRecovery();
  R.recoveryBlocked = recovery.fetchCalls === 0;
  R.graceLogPresent = logs.log.some((l) => l.includes("grace period self-heal"));
}
if (scenario === "unsupported-src-still-recovers") {
  videoElement.error = { code: 4, message: "SRC_NOT_SUPPORTED" };
  videoElement.dispatch("error");
  R.recoveryForCode4 = recovery.fetchCalls > 0;
  R.noSelfHealForCode4 = !hlsCalls.includes("recoverMediaError");
}
if (scenario === "network-error-heals") {
  videoElement.error = { code: 2, message: "NETWORK" };
  videoElement.dispatch("error");
  R.usedStartLoad = hlsCalls.some((c) => c.startsWith("startLoad:"));
  R.noRecoverForCode2 = !hlsCalls.includes("recoverMediaError");
}
function probeResponseFor(ok, status, contentType, body) {
  return {
    ok: !!ok,
    status: status || 0,
    headers: { get: () => contentType },
    text: async () => body,
  };
}
(async () => {
  const flush = () => new Promise((resolve) => setImmediate(resolve));
  const authKey = "meel_stream_auth_reload:" + sandbox.videoSrc;

  if (scenario === "probe-stale-text-rejected") {
    probeResponse = probeResponseFor(true, 200, "text/plain", "storage unavailable");
    sandbox.recoveryRetryCount = 7;
    sandbox.triggerPlayerRecovery();
    await flush();
    R.noOnlineLog = !logs.log.some((l) => l.includes("terdeteksi online"));
    R.notResetRetry = sandbox.recoveryRetryCount >= 7;
    R.retryScheduled = timers.length > 0;
  }
  if (scenario === "probe-503-not-online") {
    probeResponse = probeResponseFor(false, 503, "text/plain", "");
    sandbox.recoveryRetryCount = 7;
    sandbox.triggerPlayerRecovery();
    await flush();
    R.noOnlineLog = !logs.log.some((l) => l.includes("terdeteksi online"));
    R.notResetRetry = sandbox.recoveryRetryCount >= 7;
    R.statusLogged = logs.log.some((l) => l.includes("503"));
  }
  if (scenario === "probe-valid-manifest") {
    probeResponse = probeResponseFor(true, 200, "application/vnd.apple.mpegurl", "#EXTM3U\nclip_000.ts\n");
    sandbox.recoveryRetryCount = 7;
    sandbox.triggerPlayerRecovery();
    await flush();
    R.onlineLog = logs.log.some((l) => l.includes("terdeteksi online"));
    R.retryReset = sandbox.recoveryRetryCount === 0;
  }
  if (scenario === "probe-aborted-on-swap") {
    let resolveFetch = null;
    sandbox.fetch = () => {
      fetchCount++;
      return new Promise((resolve) => { resolveFetch = resolve; });
    };
    sandbox.triggerPlayerRecovery();
    const before = abortCount;
    (docListeners["htmx:beforeSwap"] || []).forEach((fn) =>
      fn({ detail: { target: { id: "main-video-wrapper" } } })
    );
    R.abortOnSwap = abortCount > before;
    R.timersCleared = timers.length === 0;
    if (resolveFetch) resolveFetch(probeResponseFor(true, 200, "text/plain", "stale"));
    await flush();
  }
  if (scenario === "probe-hidden-no-retry") {
    probeResponse = probeResponseFor(false, 503, "text/plain", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    const before = fetchCount;
    sandbox.document.hidden = true;
    advance(60000);
    R.noRetryWhileHidden = fetchCount === before;
  }
  if (scenario === "probe-slow-body-cancel") {
    let resolveBody = null;
    probeResponse = {
      ok: true,
      status: 200,
      headers: { get: () => "application/vnd.apple.mpegurl" },
      text: () => new Promise((resolve) => { resolveBody = resolve; }),
    };
    sandbox.triggerPlayerRecovery();
    const before = abortCount;
    sandbox.cancelMediaProbe();
    R.abortDuringBody = abortCount > before;
    R.noRetryAfterCancel = timers.length === 0;
    if (resolveBody) resolveBody("#EXTM3U\n");
    await flush();
    R.staleResultIgnored = !logs.log.some((l) => l.includes("terdeteksi online"));
  }
  if (scenario === "probe-stale-rejection") {
    const bodies = [];
    sandbox.fetch = () => {
      fetchCount++;
      return Promise.resolve({
        ok: true,
        status: 200,
        headers: { get: () => "application/vnd.apple.mpegurl" },
        text: () => new Promise((_resolve, reject) => { bodies.push(reject); }),
      });
    };
    sandbox.triggerPlayerRecovery();
    await flush();
    sandbox.cancelMediaProbe();
    sandbox.recoveryRetryCount = 0;
    sandbox.triggerPlayerRecovery();
    await flush();
    if (bodies[0]) bodies[0](new Error("aborted"));
    await flush();
    const before = abortCount;
    sandbox.cancelMediaProbe();
    R.newProbeAborted = abortCount > before;
  }
  if (scenario === "probe-mp4-video-online") {
    sandbox.isHls = false;
    probeResponse = probeResponseFor(true, 200, "video/mp4", "binary");
    sandbox.recoveryRetryCount = 7;
    sandbox.triggerPlayerRecovery();
    await flush();
    R.onlineLog = logs.log.some((l) => l.includes("terdeteksi online"));
    R.retryReset = sandbox.recoveryRetryCount === 0;
  }
  if (scenario === "bfcache-mini-rebuild") {
    miniShellVisible = true;
    sandbox.hls = { destroy: () => hlsCalls.push("destroy") };
    sandbox.document.readyState = "loading";
    vm.runInContext(
      fs.readFileSync(path.join(root, "assets/js/video/watch/lifecycle.js"), "utf8"),
      sandbox,
      { filename: "lifecycle.js" }
    );
    (winListeners["pageshow"] || []).forEach((fn) => fn({ persisted: true }));
    R.hlsRebuilt = hlsCalls.includes("destroy") && hlsCalls.includes("loadSource") && hlsCalls.includes("attachMedia");
    R.playerKept = destroyCount === 0;
    R.noReload = reloadCount === 0;
  }
  if (scenario === "bfcache-teardown") {
    sandbox.document.readyState = "loading";
    vm.runInContext(
      fs.readFileSync(path.join(root, "assets/js/video/watch/lifecycle.js"), "utf8"),
      sandbox,
      { filename: "lifecycle.js" }
    );
    const before = destroyCount;
    (winListeners["pageshow"] || []).forEach((fn) => fn({ persisted: true }));
    R.playerDestroyed = destroyCount > before;
    R.locationReloaded = reloadCount === 1;
  }
  if (scenario === "probe-403-auth") {
    probeResponse = probeResponseFor(false, 403, "text/html; charset=utf-8", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.reloadedOnce = reloadCount === 1;
    R.noRetryTimer = timers.length === 0;
    R.overlayHidden = overlayCalls.includes("hide");
    R.staleHlsDestroyed = hlsCalls.includes("destroy");
    R.markerSet = sandbox.sessionStorage.getItem(authKey) === "1" || sandbox.name.includes(authKey);
  }
  if (scenario === "probe-403-repeat") {
    sandbox.sessionStorage.setItem(authKey, "1");
    probeResponse = probeResponseFor(false, 403, "text/html; charset=utf-8", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.noSecondReload = reloadCount === 0;
    R.failedShown = overlayCalls.includes("showFailed");
  }
  if (scenario === "probe-403-cleared") {
    sandbox.sessionStorage.setItem(authKey, "1");
    sandbox.name = authKey;
    probeResponse = probeResponseFor(true, 200, "application/vnd.apple.mpegurl", "#EXTM3U\nclip.ts\n");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.markerCleared = sandbox.sessionStorage.getItem(authKey) === null && !sandbox.name.includes(authKey);
  }
  if (scenario === "probe-403-storage-blocked") {
    sandbox.sessionStorage = {
      getItem() { throw new Error("blocked"); },
      setItem() { throw new Error("blocked"); },
      removeItem() { throw new Error("blocked"); },
    };
    probeResponse = probeResponseFor(false, 403, "text/html; charset=utf-8", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.firstReload = reloadCount === 1;
    R.nameMarkerSet = sandbox.name.includes(authKey);
  }
  if (scenario === "probe-403-name-marker") {
    sandbox.name = authKey;
    probeResponse = probeResponseFor(false, 403, "text/html; charset=utf-8", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.noSecondReload = reloadCount === 0;
    R.failedShown = overlayCalls.includes("showFailed");
  }
  if (scenario === "probe-403-key-follows-video") {
    const nextSrc = "upload/video/lain/lain.m3u8";
    sandbox.sessionStorage.setItem("meel_stream_auth_reload:" + nextSrc, "1");
    sandbox.videoSrc = nextSrc;
    probeResponse = probeResponseFor(false, 403, "text/html; charset=utf-8", "");
    sandbox.triggerPlayerRecovery();
    await flush();
    R.noSecondReload = reloadCount === 0;
    R.failedShown = overlayCalls.includes("showFailed");
  }
  if (scenario === "clear-marker-call") {
    sandbox.sessionStorage.setItem(authKey, "1");
    sandbox.name = "other|" + authKey;
    sandbox.__meelClearAuthReloadMarker();
    R.storageCleared = sandbox.sessionStorage.getItem(authKey) === null;
    R.nameCleared = !sandbox.name.includes(authKey) && sandbox.name === "other";
  }
  R.scenario = scenario;
  process.stdout.write(JSON.stringify(R) + "\n");
})().catch((err) => {
  process.stderr.write(String((err && err.stack) || err) + "\n");
  process.exit(1);
});