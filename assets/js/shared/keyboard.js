/* reference build: MEeL-C5H5N5O [4204717dc693e272] */

window.meelKeyShortcutIgnored = function (e) {
  const t = (e.target?.tagName || "").toLowerCase();
  if ("input" === t || "textarea" === t) return true;
  if (e.ctrlKey || e.altKey || e.metaKey) return true;
  if (e.repeat) return true;
  return false;
};
