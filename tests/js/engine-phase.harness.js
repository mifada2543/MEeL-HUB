"use strict";
/* reference build: MEeL-C10H15N [engine-phase-harness] */
const fs = require("fs");
const path = require("path");
const vm = require("vm");
const root = path.join(__dirname, "..", "..");
const scenario = process.argv[2] || "race";
const PHASES = ["download", "encode", "transcode", "sprite", "done", "error"];
function makeElement(id) {
  return {
    id: id,
    style: {},
    _classes: new Set(),
    textContent: "",
    classList: {
      add(c) {
        this._owner._classes.add(c);
      },
      remove(c) {
        this._owner._classes.delete(c);
      },
      contains(c) {
        return this._owner._classes.has(c);
      },
    },
  };
}
const elements = new Map();
["meel-overlay"].concat(PHASES.map((p) => "meel-phase-" + p)).forEach((id) => {
  const e = makeElement(id);
  e.classList._owner = e;
  elements.set(id, e);
});
function el(id) {
  return elements.get(id) || null;
}
const appended = [];
const sandbox = {
  console: console,
  setInterval: () => 0,
  clearInterval: () => {},
  document: {
    readyState: "loading",
    currentScript: { src: "http://localhost/assets/js/engine/main.js" },
    head: {
      appendChild(node) {
        appended.push(node.src);
        node.__appended = true;
      },
    },
    createElement: () => ({ src: "", async: false }),
    getElementById: (id) => (elements.has(id) ? elements.get(id) : null),
    getElementsByTagName: () => [],
  },
};
sandbox.window = sandbox;
vm.createContext(sandbox);
function activePhases() {
  return PHASES.filter((p) => {
    const e = el("meel-phase-" + p);
    return e.classList.contains("active");
  });
}
function overlayVisible() {
  return el("meel-overlay").style.display === "flex";
}
vm.runInContext(
  fs.readFileSync(path.join(root, "assets/js/engine/main.js"), "utf8"),
  sandbox,
  {
    filename: "main.js",
  },
);
const result = { scenario: scenario };
if (scenario === "race") {
  sandbox.meelPhase("download");
  result.queuedBeforeEngineReady = sandbox.__meelPhaseQ.length;
  vm.runInContext(
    fs.readFileSync(path.join(root, "assets/js/engine/core.js"), "utf8"),
    sandbox,
    {
      filename: "core.js",
    },
  );
  result.queuedAfterBind = sandbox.__meelPhaseQ.length;
  result.overlayVisible = overlayVisible();
  result.activePhases = activePhases();
  result.overlayDisplay = el("meel-overlay").style.display;
} else if (scenario === "encode") {
  vm.runInContext(
    fs.readFileSync(path.join(root, "assets/js/engine/core.js"), "utf8"),
    sandbox,
    {
      filename: "core.js",
    },
  );
  sandbox.meelPhase("download");
  const duringDownload = activePhases();
  sandbox.meelPhase("encode");
  const duringEncode = activePhases();
  result.overlayVisible = overlayVisible();
  result.duringDownload = duringDownload;
  result.duringEncode = duringEncode;
  result.hasEncodeElement = el("meel-phase-encode") !== null;
} else if (scenario === "order") {
  sandbox.meelPhase("download");
  sandbox.meelPhase("encode");
  sandbox.meelPhase("done");
  vm.runInContext(
    fs.readFileSync(path.join(root, "assets/js/engine/core.js"), "utf8"),
    sandbox,
    {
      filename: "core.js",
    },
  );

  result.queuedAfterBind = sandbox.__meelPhaseQ.length;
  result.activePhases = activePhases();
}
result.engineScriptsRequested = appended;
result.shimInstalledAtBoot = typeof sandbox.meelPhase === "function";
result.meelPhaseIsFunction = typeof sandbox.meelPhase === "function";
process.stdout.write(JSON.stringify(result) + "\n");
