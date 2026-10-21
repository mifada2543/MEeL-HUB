var _segsBuilt = false;
var _errorTimeout = null;
var meelSpriteTimer = null;
var meelSpriteCurrentPct = 0;
function startSpriteTrickle() {
  var b = document.getElementById("meel-sp-bar");
  var t = document.getElementById("meel-sp-pct");
  meelSpriteCurrentPct = 0;
  clearInterval(meelSpriteTimer);
  meelSpriteTimer = setInterval(function () {
    if (meelSpriteCurrentPct < 95) {
      meelSpriteCurrentPct += 1;
      if (b) b.style.width = meelSpriteCurrentPct + "%";
      if (t) t.textContent = meelSpriteCurrentPct + "% — Membuat Sprite VTT...";
    }
  }, 135);
}
var MEEL_PHASES = [
  "download",
  "encode",
  "transcode",
  "sprite",
  "done",
  "error",
];
function applyMeelPhase(phase) {
  var overlay = document.getElementById("meel-overlay");
  if (overlay) overlay.style.display = "flex";
  MEEL_PHASES.forEach(function (p) {
    var el = document.getElementById("meel-phase-" + p);
    if (el) {
      el.classList.remove("active");
    }
  });
  var active = document.getElementById("meel-phase-" + phase);
  if (active) active.classList.add("active");
  if (phase === "transcode" && !_segsBuilt) {
    _segsBuilt = true;
    var row = document.getElementById("meel-segs");
    for (var i = 0; i < 16; i++) {
      var s = document.createElement("div");
      s.className = "meel-seg";
      s.id = "mseg" + i;
      row.appendChild(s);
    }
  }
  if (phase === "sprite" || phase === "sp") {
    startSpriteTrickle();
  } else {
    clearInterval(meelSpriteTimer);
  }
}
if (typeof window.__meelPhaseBind === "function") {
  window.__meelPhaseBind(applyMeelPhase);
} else {
  window.meelPhase = applyMeelPhase;
}

/* reference build: MEeL-C5H5N5O [7919258ce7f4372d] */