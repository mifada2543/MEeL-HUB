/**
 * Fallback retry untuk sentinel infinite-scroll (semua elemen hx-trigger="revealed":
 * #load-more-area, #load-more-music, #load-more-music-search, .rec-sentinel, dst).
 *
 * Trigger `revealed` pada htmx hanya menandai elemen sekali (data-hx-revealed),
 * sehingga saat request gagal rantai auto-scroll bisa mati permanen.
 * - Saat gagal (htmx:responseError / htmx:sendError / afterRequest !successful):
 *   hapus data-hx-revealed supaya scroll berikutnya memicu ulang otomatis.
 * - Saat sukses: sembunyikan sentinel sidebar (.rec-sentinel) lama karena konten
 *   baru sudah menyusulnya. Sentinel outerHTML (#load-more-*) sudah tergantikan
 *   oleh respons, jadi tidak perlu disentuh.
 */
(function () {
    var SENTINEL_SEL = '[hx-trigger="revealed"], [data-hx-trigger="revealed"]';

    function sentinelOf(e) {
        var t = e.target;
        if (!t || typeof t.closest !== 'function') return null;
        return t.closest(SENTINEL_SEL);
    }

    function rearm(e) {
        var s = sentinelOf(e);
        if (s) s.removeAttribute('data-hx-revealed');
    }

    document.addEventListener('htmx:responseError', rearm);
    document.addEventListener('htmx:sendError', rearm);

    document.addEventListener('htmx:afterRequest', function (e) {
        var s = sentinelOf(e);
        if (!s) return;
        if (e.detail && e.detail.successful) {
            if (s.classList.contains('rec-sentinel')) {
                s.style.display = 'none';
            }
        } else {
            s.removeAttribute('data-hx-revealed');
        }
    });
})();
