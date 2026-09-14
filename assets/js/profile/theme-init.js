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
/* reference build: MEeL-C8H11NO2 [ff03c236bdba23ea] */
