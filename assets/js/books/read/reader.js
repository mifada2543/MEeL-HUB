(function() {
    var el = document.getElementById('reader-data');
    if (!el) return;
    var bookId = parseInt(el.dataset.bookId) || 0;
    var bookTitle = el.dataset.bookTitle || '';
    var bookType = el.dataset.bookType || '';
    var currentChapter = el.dataset.chapter || '';
    var totalPages = parseInt(el.dataset.totalPages) || 0;
    var baseUrl = el.dataset.baseUrl || '';

    var lazyImages = document.querySelectorAll('img.manga-img.lazy');
    if (lazyImages.length) {
        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (!entry.isIntersecting) return;
                var img = entry.target;
                var src = img.dataset.src;
                if (!src) return;
                img.src = src;
                img.onload = function() {
                    img.classList.add('loaded');
                    img.classList.remove('lazy');
                };
                img.onerror = function() {
                    img.classList.add('loaded');
                    img.classList.remove('lazy');
                    img.style.background = document.documentElement.getAttribute('data-theme') === 'light' ? '#f4f4f5' : '#0f1318';
                    img.style.minHeight = '100px';
                };
                observer.unobserve(img);
            });
        }, { rootMargin: '400px 0px', threshold: 0 });
        lazyImages.forEach(function(img) { observer.observe(img); });
    }

    var pageDisplay = document.getElementById('current-page-display');
    var navPage = document.getElementById('nav-current-page');
    var scrollTopBtn = document.getElementById('scroll-top-btn');
    var navbar = document.getElementById('reader-navbar');
    var scrollEl = document.getElementById('scroll-container');
    var images = document.querySelectorAll('img.manga-img');
    var ticking = false;

    function getScrollState() {
        if (scrollEl && scrollEl.scrollHeight > scrollEl.clientHeight) {
            return { scrollTop: scrollEl.scrollTop, clientHeight: scrollEl.clientHeight };
        }
        return { scrollTop: window.scrollY || document.documentElement.scrollTop, clientHeight: window.innerHeight };
    }

    function animatePop(el) {
        if (!el) return;
        el.classList.remove('pop');
        void el.offsetWidth;
        el.classList.add('pop');
    }

    function updateScrollState() {
        var s = getScrollState();
        if (navbar) navbar.classList.toggle('scrolled', s.scrollTop > 10);
        if (scrollTopBtn) scrollTopBtn.classList.toggle('visible', s.scrollTop > s.clientHeight * 0.5);
        if (images.length > 0 && pageDisplay) {
            var currentPage = 1, minDist = Infinity;
            images.forEach(function(img) {
                var rect = img.getBoundingClientRect();
                var dist = Math.abs(rect.top - 56);
                if (dist < minDist) {
                    minDist = dist;
                    var p = parseInt(img.dataset.page);
                    if (p) currentPage = p;
                }
            });
            if (pageDisplay.textContent !== String(currentPage)) {
                pageDisplay.textContent = currentPage;
                animatePop(pageDisplay);
            }
            if (navPage && navPage.textContent !== String(currentPage)) {
                navPage.textContent = currentPage;
                animatePop(navPage);
            }
        }
        ticking = false;
    }

    function onScroll() {
        if (!ticking) { requestAnimationFrame(updateScrollState); ticking = true; }
    }

    if (scrollEl) scrollEl.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('scroll', onScroll, { passive: true });
    images.forEach(function(img) {
        img.addEventListener('load', function() {
            if (!ticking) { requestAnimationFrame(updateScrollState); ticking = true; }
        });
    });
    setTimeout(updateScrollState, 300);

    window.scrollToTop = function() {
        var el = document.getElementById('scroll-container');
        if (el && el.scrollHeight > el.clientHeight) el.scrollTo({ top: 0, behavior: 'smooth' });
        else window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    var activeDropdown = null;
    window.toggleChDropdown = function(which) {
        var options = document.getElementById('ch-options-' + which);
        var isHidden = options.classList.contains('hidden');
        document.querySelectorAll('.ch-options').forEach(function(el) { el.classList.add('hidden'); });
        document.body.classList.remove('ch-dropdown-open');
        if (isHidden) {
            options.classList.remove('hidden');
            document.body.classList.add('ch-dropdown-open');
            activeDropdown = which;
            var active = options.querySelector('.ch-option.active');
            if (active) setTimeout(function() { active.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }, 50);
        } else {
            activeDropdown = null;
        }
    };

    window.goToChapter = function(ch) {
        var url = '?id=' + bookId;
        if (ch) url += '&ch=' + encodeURIComponent(ch);
        window.navigateChapter(url);
    };

    document.addEventListener('click', function(e) {
        if (!activeDropdown) return;
        var dropdown = document.getElementById('ch-dropdown-' + activeDropdown);
        if (dropdown && !dropdown.contains(e.target)) {
            document.querySelectorAll('.ch-options').forEach(function(el) { el.classList.add('hidden'); });
            document.body.classList.remove('ch-dropdown-open');
            activeDropdown = null;
        }
    });

    var isTransitioning = false;
    window.navigateChapter = function(url) {
        if (isTransitioning) return;
        isTransitioning = true;
        var container = document.getElementById('manga-container');
        if (container) { container.classList.remove('chapter-visible'); container.classList.add('chapter-exit'); }
        setTimeout(function() { window.location.href = url; }, 250);
    };

    var mangaContainer = document.getElementById('manga-container');
    if (mangaContainer) requestAnimationFrame(function() { mangaContainer.classList.add('chapter-visible'); });

    document.addEventListener('click', function(e) {
        var link = e.target.closest('a');
        if (!link) return;
        var href = link.getAttribute('href');
        if (!href || !href.includes('ch=')) return;
        var text = link.textContent.trim();
        if (!text.includes('Selanjutnya') && !text.includes('Sebelumnya')) return;
        e.preventDefault();
        window.navigateChapter(link.href);
    });

    var _saveTimer = null;
    function saveProgress(extra) {
        if (_saveTimer) clearTimeout(_saveTimer);
        _saveTimer = setTimeout(function() {
            try {
                var pageEl = document.getElementById('current-page-display');
                var data = {
                    id: bookId, title: bookTitle, type: bookType, ch: currentChapter,
                    page: pageEl ? parseInt(pageEl.textContent) || 1 : 1,
                    total: totalPages, timestamp: Date.now()
                };
                if (extra) Object.assign(data, extra);
                localStorage.setItem('meel_book_progress', JSON.stringify(data));
            } catch(e) {}
        }, 3000);
    }
    saveProgress();
    window.addEventListener('beforeunload', function() {
        if (_saveTimer) clearTimeout(_saveTimer);
        try {
            var pageEl = document.getElementById('current-page-display');
            var data = {
                id: bookId, title: bookTitle, type: bookType, ch: currentChapter,
                page: pageEl ? parseInt(pageEl.textContent) || 1 : 1,
                total: totalPages, timestamp: Date.now()
            };
            localStorage.setItem('meel_book_progress', JSON.stringify(data));
        } catch(e) {}
    });

    (function() {
        try {
            var raw = localStorage.getItem('meel_book_progress');
            if (!raw) return;
            var saved = JSON.parse(raw);
            if (!saved || saved.id != bookId) return;
            if (!saved.page || saved.page < 2) return;
            if (saved.ch !== currentChapter) return;
            var retries = 0, maxRetries = 20, targetPage = saved.page;
            function tryScroll() {
                if (retries >= maxRetries) return;
                retries++;
                var img = document.querySelector('img.manga-img[data-page="' + targetPage + '"]');
                if (!img) { setTimeout(tryScroll, 500); return; }
                var scrollEl = document.getElementById('scroll-container');
                var top = img.offsetTop - 56;
                if (scrollEl && scrollEl.scrollHeight > scrollEl.clientHeight) scrollEl.scrollTo({ top: top, behavior: 'smooth' });
                else window.scrollTo({ top: top, behavior: 'smooth' });
            }
            setTimeout(tryScroll, 600);
        } catch(e) {}
    })();

    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
        var key = e.key.toLowerCase();
        if (key === 'arrowleft' || key === 'a') {
            var prevLink = document.querySelector('a[href*="ch="]:first-child');
            if (prevLink && prevLink.textContent.includes('Sebelumnya')) { e.preventDefault(); window.navigateChapter(prevLink.href); }
        }
        if (key === 'arrowright' || key === 'd') {
            var links = document.querySelectorAll('a[href*="ch="]');
            var nextLink = Array.from(links).find(function(el) { return el.textContent.includes('Selanjutnya'); });
            if (nextLink) { e.preventDefault(); window.navigateChapter(nextLink.href); }
        }
        if (key === 'escape') window.location.href = '..';
    });

    document.body.addEventListener('htmx:afterOnLoad', function() { lucide.createIcons(); });
})();
