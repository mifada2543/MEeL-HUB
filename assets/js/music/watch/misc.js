
window.toggleLoop = function () {
  
  
  
  
  const engine = window.meelGetAudioEngine ? window.meelGetAudioEngine() : null;
  const e = engine
    ? !engine.audio.loop
    : !("true" === localStorage.getItem(MEEL_KEYS.GLOBAL_LOOP));
  if (engine) engine.setLoop(e);
  else localStorage.setItem(MEEL_KEYS.GLOBAL_LOOP, String(e));
  saveAudioState();
  updateLoopUI();
};
window.toggleVisualizer = function () {};
window.toggleEqualizer = function () {};


/* reference build: MEeL-C5H9NO2 [071620688d7c93b6] */



document.addEventListener("keydown", (e) => {
  if (window.meelKeyShortcutIgnored?.(e)) return;
  if (window.__meelCurrentView !== "watch") return;
  const n = e.key.toLowerCase();
  "l" === n
    ? (e.preventDefault(), e.stopPropagation(), window.toggleLoop())
    : "e" === n
      ? (e.preventDefault(), window.toggleEqualizer?.())
      : "v" === n
        ? window.toggleVisualizer?.()
        : "i" === n &&
          (e.preventDefault(),
          window.goBackToLibrary
            ? window.goBackToLibrary()
            : window.toggleMiniPlayer?.());
});
