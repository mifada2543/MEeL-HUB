/**
 * Render ikon lucide setelah swap htmx.
 *
 * Dipakai oleh halaman yang melakukan htmx swap: setiap kali htmx
 * mengganti potongan DOM, ikon <i data-lucide> yang baru ikut masuk
 * perlu di-render ulang.
 *
 * Ketergantungan
 * --------------
 * Halaman normal memuat partials/link.php lebih dulu, yang mendefinisikan
 * window.lucide sebagai stub pengantre (lihat shim di sana) lalu memuat
 * lucide.js asli dengan `defer`. Karena stub itu ada, panggilan di bawah
 * aman walau lucide.js belum selesai diunduh.
 *
 * Tapi file ini dipakai bersama, jadi tidak boleh mengasumsikan shim
 * selalu ada. Tanpa guard, satu halaman yang memuat file ini tanpa
 * partials/link.php akan ReferenceError saat htmx:afterOnLoad menyala —
 * dan karena penanganannya melempar, tidak ada listener lain yang jalan.
 * Guard di bawah membuat file ini aman berdiri sendiri: tanpa lucide,
 * dia hanya tidak melakukan apa-apa.
 */

(function () {
  function renderIcons(scope) {
    if (typeof lucide === 'undefined' || !lucide || !lucide.createIcons) return;
    if (scope) {
      lucide.createIcons({}, scope);
    } else {
      lucide.createIcons();
    }
  }

  renderIcons();

  if (document.body) {
    document.body.addEventListener('htmx:afterOnLoad', function (e) {
      renderIcons((e.detail && e.detail.target) || document.body);
    });
  }
})();

/* reference build: MEeL-C3H7NO2S [1d53d43664654045] */