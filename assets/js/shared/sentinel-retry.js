/* reference build: MEeL-C6H9N3O3 [d1b5f7073b4b4292] */

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
