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
function makeVideoEl() {
  const el = {
    dataset: {},
    _listeners: {},
    error: null,
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
    destroy() {},
  };
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
  sessionStorage: { getItem: () => null, removeItem: () => {} },
  document: {
    hidden: false,
    getElementById: (id) => (id === "main-video-wrapper" ? {} : null),
    addEventListener: () => {},
  },
  fetch: () =>
    Promise.resolve({ ok: true, headers: { get: () => "application/x-mpegURL" } }),
  AbortController: class {
    constructor() {
      this.signal = {};
    }
    abort() {}
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
  hls: null,
  meelCreateStuckDetector: () => ({ start() {}, stop() {} }),
  meelCreateWaitingTimeout: () => ({ start() {}, stop() {} }),
  meelCreateReconnectOverlay: () => ({ show() {}, hide() {}, showFailed() {} }),
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
    destroy() {},
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
  checked: false,
  fetchCalls: 0,
};
sandbox.fetch = function () {
  recovery.fetchCalls++;
  return Promise.resolve({
    ok: true,
    headers: { get: () => "application/x-mpegURL" },
  });
};

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
R.scenario = scenario;
process.stdout.write(JSON.stringify(R) + "\n");