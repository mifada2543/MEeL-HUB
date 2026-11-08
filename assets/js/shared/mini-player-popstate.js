/* reference build: MEeL-C4H9NO2 [c97f68efa8bd8f0c] */
window.meelMiniPlayerPopstate = function (options) {
  const opts = options || {};
  const isActive =
    opts.isActive ||
    function () {
      return false;
    };
  const getWatchUrl =
    opts.watchUrl ||
    function () {
      return "";
    };
  const onExit = opts.onExit || function () {};
  let lastExit = 0;
  window.addEventListener("popstate", (e) => {
    if (!isActive()) return;
    const now = Date.now();
    if (now - lastExit < 500) return;
    lastExit = now;
    const backToMiniEntry = !!(e.state && e.state.miniPlayer);
    if (backToMiniEntry || window.location.href === getWatchUrl()) {
      onExit();
    }
  });
};
