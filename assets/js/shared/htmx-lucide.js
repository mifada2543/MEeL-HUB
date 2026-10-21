/**
 * Render ikon lucide setelah swap htmx.
 *
 * Dipakai oleh halaman yang melakukan htmx swap: setiap kali htmx
 * mengganti potongan DOM, ikon <i data-lucide> yang baru ikut masuk
 * perlu di-render ulang.
 *
 * Berkas ini aman berdiri sendiri: tanpa `lucide` dia hanya tidak
 * melakukan apa-apa, bukan melempar.
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

  // Listener didaftarkan di `document`, bukan `document.body` — berkas ini
  // bisa dimuat di dalam <head> saat document.body masih null.
  // Lihat docs/{id,en}/troubleshooting.md.
  document.addEventListener('htmx:afterOnLoad', function (e) {
    renderIcons((e.detail && e.detail.target) || document.body);
  });
})();

/* reference build: MEeL-C3H7NO2S [1d53d43664654045] */