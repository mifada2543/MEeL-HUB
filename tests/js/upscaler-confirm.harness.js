"use strict";
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const root = path.join(__dirname, "..", "..");
const src = fs.readFileSync(
  path.join(root, "assets/js/video/watch/upscaler.js"),
  "utf8",
);
const storage = {
  getItem: () => null,
  setItem: () => {},
  removeItem: () => {},
};
const sandbox = {
  console: console,
  setTimeout: setTimeout,
  clearTimeout: clearTimeout,
  setInterval: () => 0,
  clearInterval: () => {},
  navigator: {},
  localStorage: storage,
  sessionStorage: storage,
  location: { search: "" },
  document: {
    currentScript: {
      src: "http://localhost/MEeL/assets/js/video/watch/upscaler.js",
    },
    addEventListener: () => {},
    removeEventListener: () => {},
    createElement: () => ({}),
    getElementById: () => null,
  },
  MEEL_KEYS: {
    UPSCALE_ENABLED: "meel_upscale_enabled",
    UPSCALE_MODEL: "meel_upscale_model",
    UPSCALE_MODE: "meel_upscale_mode",
    UPSCALE_SCALE: "meel_upscale_scale",
  },
};
sandbox.window = sandbox;
sandbox.HTMLVideoElement = function () {};
sandbox.HTMLVideoElement.prototype = {};
vm.createContext(sandbox);
vm.runInContext(src, sandbox, { filename: "upscaler.js" });
const api = sandbox.window.MEEL_UPSCALER;
if (!api || !api.rules) {
  process.stderr.write("MEEL_UPSCALER.rules tidak tersedia\n");
  process.exit(1);
}
const r = api.rules;
process.stdout.write(
  JSON.stringify({
    enable: {
      on: r.needsEnableConfirm(true),
      off: r.needsEnableConfirm(false),
    },
    model: {
      switch: r.needsModelConfirm("meelsharp", "anime4k"),
      same: r.needsModelConfirm("anime4k", "anime4k"),
      empty: r.needsModelConfirm("anime4k", ""),
    },
    scale: {
      autoTo15: r.needsScaleConfirm("auto", "1.5"),
      autoTo2: r.needsScaleConfirm("auto", "2"),
      up: r.needsScaleConfirm("1.5", "2"),
      down: r.needsScaleConfirm("2", "1.5"),
      toAuto: r.needsScaleConfirm("2", "auto"),
      same: r.needsScaleConfirm("1.5", "1.5"),
    },
    gpuLoad: r.gpuLoad,
  }),
);
