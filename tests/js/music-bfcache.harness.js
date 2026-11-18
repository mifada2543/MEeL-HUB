"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const root = path.join(__dirname, "..", "..");
const scenario = process.argv[2] || "pagehide";
const winListeners = {};
const events = [];
const overlay = {
  hide: () => events.push("overlay.hide"),
  show: () => events.push("overlay.show"),
  showFailed: () => events.push("overlay.showFailed"),
};
let stuckOpts = null;
const detector = {
  start: () => events.push("detector.start"),
  stop: () => events.push("detector.stop"),
};
const waiting = { start() {}, stop() {} };
const audioListeners = {};
let playCount = 0;
const audio = {
  paused: false,
  ended: false,
  currentTime: 12,
  readyState: 0,
  error: null,
  addEventListener(type, fn) {
    (audioListeners[type] = audioListeners[type] || []).push(fn);
  },
  removeEventListener(type, fn) {
    audioListeners[type] = (audioListeners[type] || []).filter((f) => f !== fn);
  },
  dispatch(type) {
    (audioListeners[type] || []).slice().forEach((fn) => fn());
  },
  play: () => { playCount++; return Promise.resolve(); },
  pause() {},
  load() {},
  set src(v) { events.push("audio.src"); },
};
const playerHandlers = {};
const player = {
  paused: false,
  ended: false,
  currentTime: 12,
  on(type, fn) {
    (playerHandlers[type] = playerHandlers[type] || []).push(fn);
  },
  dispatch(type) {
    (playerHandlers[type] || []).slice().forEach((fn) => fn());
  },
  destroy() {},
  elements: { container: {} },
};
const engine = {
  audio,
  player,
  mount() {},
  __syncPlayingVisualState() {},
  getAnalyser: () => null,
  ensureAudioContext() {},
};

const storage = () => ({
  getItem: () => null,
  setItem() {},
  removeItem() {},
});
const sandbox = {
  console: { log() {}, warn() {}, error() {} },
  document: {
    readyState: "loading",
    hidden: false,
    getElementById: (id) => (id === "player-audio-slot" ? {} : null),
    querySelector: () => ({
      classList: { add() {}, remove() {}, toggle() {} },
      style: {},
      querySelectorAll: () => [],
    }),
    addEventListener(type, fn) {
      (winListeners["doc:" + type] = winListeners["doc:" + type] || []).push(fn);
    },
  },
  addEventListener(type, fn) {
    (winListeners[type] = winListeners[type] || []).push(fn);
  },
  location: { href: "http://localhost/MEeL/music/watch?v=5" },
  setTimeout: () => 1,
  clearTimeout() {},
  setInterval: () => 1,
  clearInterval() {},
  localStorage: storage(),
  sessionStorage: storage(),
  Date,
  Math,
  JSON,
  Promise,
  HTMLMediaElement: { HAVE_METADATA: 1 },
  Plyr: function () {},
  MEEL_KEYS: {
    AUDIO_STATE: "audio_state",
    GLOBAL_LOOP: "global_loop",
    AUTONAV: "autonav",
    VIDEO_LOOP: "video_loop",
    GLOW_ENABLED: "glow_enabled",
  },
  MEEL_MUSIC_CONFIG: { id: 5, streamUrl: "stream?id=5", filename: "lagu.mp3" },
  saveAudioState() {},
  meelGetAudioEngine: () => engine,
  meelCreateReconnectOverlay: () => overlay,
  meelCreateStuckDetector: (opts) => {
    stuckOpts = opts;
    return detector;
  },
  meelCreateWaitingTimeout: () => waiting,
};
sandbox.window = sandbox;
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(
  fs.readFileSync(path.join(root, "assets/js/music/watch/state.js"), "utf8"),
  sandbox,
  { filename: "state.js" }
);
vm.runInContext(
  fs.readFileSync(path.join(root, "assets/js/music/watch/player-core.js"), "utf8"),
  sandbox,
  { filename: "player-core.js" }
);
try {
  sandbox.meelInitWatchPlayer();
} catch (err) {
  events.push("init.incomplete");
}
const R = {};
if (scenario === "pagehide") {
  (winListeners["pagehide"] || []).forEach((fn) => fn());
  R.detectorStopped = events.includes("detector.stop");
  R.overlayHidden = events.includes("overlay.hide");
}
if (scenario === "bfcache-persisted") {
  events.length = 0;
  (winListeners["pageshow"] || []).forEach((fn) => fn({ persisted: true }));
  R.detectorStopped = events.includes("detector.stop");
  R.overlayHidden = events.includes("overlay.hide");
  R.detectorRestarted = events.includes("detector.start");
}
if (scenario === "pending-recovery-cancelled") {
  player.dispatch("play");
  events.length = 0;
  if (stuckOpts) stuckOpts.onStuck();
  R.recoveryStarted = events.includes("audio.src");
  const before = playCount;
  const listenersBefore = (audioListeners["loadedmetadata"] || []).length;
  (winListeners["pagehide"] || []).forEach((fn) => fn());
  const listenersAfter = (audioListeners["loadedmetadata"] || []).length;
  audio.dispatch("loadedmetadata");
  R.listenerRemoved = listenersAfter === listenersBefore - 1;
  R.noLatePlay = playCount === before;
}
if (scenario === "bfcache-normal") {
  events.length = 0;
  (winListeners["pageshow"] || []).forEach((fn) => fn({ persisted: false }));
  R.noSideEffect = events.length === 0;
}
R.scenario = scenario;
process.stdout.write(JSON.stringify(R) + "\n");
