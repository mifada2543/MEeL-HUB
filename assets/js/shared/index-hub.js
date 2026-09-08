(function() {
    var root = document.getElementById('index-data');
    if (!root) return;
    var isLoggedIn = root.dataset.loggedIn === '1';
    var csrfToken = root.dataset.csrfToken;

    lucide.createIcons();

    if (typeof MEELTheme !== 'undefined') {
        MEELTheme.init({ isLoggedIn: isLoggedIn, csrfToken: csrfToken });
    }

    (function() {
        var banner = document.getElementById('demoBanner');
        var closeBtn = document.getElementById('demoBannerClose');
        if (banner) {
            banner.style.visibility = 'hidden';
            banner.style.display = 'block';
            var h = banner.scrollHeight;
            document.body.style.setProperty('--demo-banner-h', h + 'px');
            banner.style.visibility = '';
            banner.style.display = '';
            document.body.classList.add('demo-banner-active');
            requestAnimationFrame(function() { banner.classList.add('demo-banner-visible'); });
        }
        if (closeBtn && banner) {
            closeBtn.addEventListener('click', function() {
                document.body.classList.remove('demo-banner-active');
                banner.classList.remove('demo-banner-visible');
                banner.classList.add('demo-banner-hiding');
                setTimeout(function() { banner.style.display = 'none'; }, 400);
            });
        }
    })();

    (function() {
        if (sessionStorage.getItem('meelDemoAlertShown')) return;
        sessionStorage.setItem('meelDemoAlertShown', '1');
        setTimeout(function() {
            Swal.fire({
                icon: 'warning',
                iconHtml: '<div style="font-size:1.8rem">\u26a0\ufe0f</div>',
                title: '<span style="font-size:0.9rem;font-weight:800;letter-spacing:0.08em;color:#fbbf24">\u26a0\ufe0f INI WEBSITE DEMO</span>',
                html: '<div style="text-align:center;font-size:0.8rem;color:#94a3b8;line-height:1.6">'
                    + '<strong style="color:#f97316;font-size:0.95rem">MEeL Hub</strong><br>'
                    + 'adalah <strong>demo project</strong> pribadi.<br><br>'
                    + 'Konten &amp; data di sini <strong style="color:#f87171" title="diperuntukan untuk penggunaan pribadi">tidak nyata</strong>.<br>'
                    + 'Hanya untuk <em style="color:#fde68a">showcase &amp; uji coba</em>.</div>',
                confirmButtonText: 'Saya Mengerti',
                confirmButtonColor: '#f97316',
                timer: 120000,
                timerProgressBar: true,
                background: '#0f172a',
                color: '#e2e8f0',
                backdrop: 'rgba(5, 7, 12, 0.7)',
                customClass: { popup: 'demo-modal-popup' },
                didOpen: function(modal) {
                    modal.addEventListener('mouseenter', function() { Swal.stopTimer(); });
                    modal.addEventListener('mouseleave', function() { Swal.resumeTimer(); });
                }
            });
        }, 800);
    })();
})();
