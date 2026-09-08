lucide.createIcons();

(function() {
    if (typeof MEELTheme !== 'undefined') {
        var csrfMeta = document.querySelector('meta[name="csrf-token"]');
        MEELTheme.init({
            isLoggedIn: !!document.getElementById('coin-indicator'),
            csrfToken: csrfMeta ? csrfMeta.getAttribute('content') : ''
        });
    }
})();
