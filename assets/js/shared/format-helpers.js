/* reference build: MEeL-C8H11NO2 [shared-format-helpers] */
/**
 * @param {*} str
 * @returns {string} */
function escapeHtml(str) {
  var d = document.createElement("div");
  d.textContent = str == null ? "" : String(str);
  return d.innerHTML;
}
/**
 * @param {string|number|Date} ts
 * @returns {string}
 */
function timeAgo(ts) {
  var when = ts instanceof Date ? ts.getTime() : new Date(ts).getTime();
  if (isNaN(when)) return "";
  var diff = (Date.now() - when) / 1000;
  if (diff < 60) return "Baru saja";
  if (diff < 3600) return Math.floor(diff / 60) + "m lalu";
  if (diff < 86400) return Math.floor(diff / 3600) + "j lalu";
  return Math.floor(diff / 86400) + "h lalu";
}
/**
 * @param {number} bytes
 * @param {number} [decimals=1]
 * @returns {string}
 */
function formatBytes(bytes, decimals) {
  var d = typeof decimals === "number" ? decimals : 1;
  if (!isFinite(bytes) || bytes < 0) return "-";
  if (bytes === 0) return "0 B";
  var units = ["B", "KB", "MB", "GB", "TB"];
  var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  var value = bytes / Math.pow(1024, i);
  if (i === 0) return Math.round(value) + " B";
  return value.toFixed(d) + " " + units[i];
}
