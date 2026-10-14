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
 * Waktu relatif dalam Bahasa Indonesia, singkat.
 *   < 1 menit  → "Baru saja"
 *   < 1 jam    → "Nm lalu"
 *   < 1 hari   → "Nj lalu"   (j = jam)
 *   >= 1 hari  → "Nh lalu"   (h = hari)
 *
 * PERHATIKAN: label "j" = jam dan "h" = hari (bukan sebaliknya). Ini
 * yang sudah dipakai kedua salinan aslinya; jangan ditukar tanpa alasan.
 *
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
 * Ukuran file/byte dalam satuan biner.
 *
 * Dua implementasi lama beda KONTEKS, dan itu disengaja:
 *   - drive/upload.js (file milik user) → 1 desimal ("1.5 KB")
 *   - admin/index.js (monitoring disk/RAM) → 2 desimal ("4.66 GB")
 * Menyatukan tanpa parameter akan memaksa salah satu format berubah di
 * halaman yang tidak boleh berubah, jadi `decimals` jadi parameter.
 *
 * @param {number} bytes
 * @param {number} [decimals=1] jumlah angka desimal (0-3)
 * @returns {string}
 */
function formatBytes(bytes, decimals) {
  var d = typeof decimals === "number" ? decimals : 1;
  if (!isFinite(bytes) || bytes < 0) return "-";
  if (bytes === 0) return "0 B";

  var units = ["B", "KB", "MB", "GB", "TB"];
  var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
  var value = bytes / Math.pow(1024, i);
  // Byte bulat tidak perlu desimal: "512 B", bukan "512.0 B".
  if (i === 0) return Math.round(value) + " B";

  return value.toFixed(d) + " " + units[i];
}