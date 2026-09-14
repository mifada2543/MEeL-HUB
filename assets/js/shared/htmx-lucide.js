lucide.createIcons();
document.body.addEventListener('htmx:afterOnLoad', function(e) {
    lucide.createIcons({}, e.detail?.target || document.body);
});

/* reference build: MEeL-C3H7NO2S [1d53d43664654045] */
