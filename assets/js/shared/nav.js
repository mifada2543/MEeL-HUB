(function() {
    var root = document.getElementById('nav-data');
    var isLoggedIn = root ? root.dataset.loggedIn === '1' : false;
    var csrfToken = root ? root.dataset.csrfToken : '';

    window.toggleNavDropdown = function() {
        var dd = document.getElementById('nav-dropdown');
        var ch = document.getElementById('nav-chevron');
        if (!dd) return;
        dd.classList.toggle('hidden');
        if (ch) ch.style.transform = dd.classList.contains('hidden') ? '' : 'rotate(180deg)';
    };

    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('nav-dropdown-wrap');
        if (wrap && !wrap.contains(e.target)) {
            var dd = document.getElementById('nav-dropdown');
            var ch = document.getElementById('nav-chevron');
            if (dd) dd.classList.add('hidden');
            if (ch) ch.style.transform = '';
        }
    });

    function closeDrawerOnMainClick(e) {
        e.preventDefault();
        e.stopPropagation();
        window.toggleNavDrawer();
    }

    window.toggleNavDrawer = function() {
        var drawer = document.getElementById('nav-drawer');
        var overlay = document.getElementById('nav-drawer-overlay');
        var mainContent = document.getElementById('app-content-grid') || document.querySelector('main');
        if (!drawer) return;
        var open = drawer.classList.contains('open');
        if (open) {
            drawer.style.transform = '';
            overlay.classList.add('hidden');
            drawer.classList.remove('open');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
            document.body.classList.remove('nav-drawer-open');
            if (mainContent) {
                mainContent.classList.remove('blur-md');
                mainContent.removeEventListener('click', closeDrawerOnMainClick);
            }
            setTimeout(function() {
                if (!drawer.classList.contains('open')) {
                    drawer.classList.add('hidden');
                    drawer.classList.remove('flex');
                }
            }, 300);
        } else {
            drawer.classList.remove('hidden');
            drawer.classList.add('flex');
            setTimeout(function() { drawer.style.transform = 'translateX(0)'; drawer.classList.add('open'); }, 10);
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            document.body.classList.add('nav-drawer-open');
            if (mainContent) {
                mainContent.classList.add('blur-md', 'transition-all', 'duration-300');
                mainContent.addEventListener('click', closeDrawerOnMainClick);
            }
        }
    };

    function closeGuestDrawerOnMainClick(e) {
        e.preventDefault();
        e.stopPropagation();
        window.toggleNavDrawerGuest();
    }

    window.toggleNavDrawerGuest = function() {
        var drawer = document.getElementById('nav-drawer-guest');
        var overlay = document.getElementById('nav-drawer-guest-overlay');
        var mainContent = document.getElementById('app-content-grid') || document.querySelector('main');
        if (!drawer) return;
        var open = drawer.classList.contains('open');
        if (open) {
            drawer.style.transform = '';
            overlay.classList.add('hidden');
            drawer.classList.remove('open');
            document.body.style.overflow = '';
            document.documentElement.style.overflow = '';
            if (mainContent) {
                mainContent.classList.remove('blur-md');
                mainContent.removeEventListener('click', closeGuestDrawerOnMainClick);
            }
            setTimeout(function() {
                if (!drawer.classList.contains('open')) {
                    drawer.classList.add('hidden');
                    drawer.classList.remove('flex');
                }
            }, 300);
        } else {
            drawer.classList.remove('hidden');
            drawer.classList.add('flex');
            setTimeout(function() { drawer.style.transform = 'translateX(0)'; drawer.classList.add('open'); }, 10);
            overlay.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
            if (mainContent) {
                mainContent.classList.add('blur-md', 'transition-all', 'duration-300');
                mainContent.addEventListener('click', closeGuestDrawerOnMainClick);
            }
        }
    };

    if (typeof MEELTheme !== 'undefined') {
        MEELTheme.init({ isLoggedIn: isLoggedIn, csrfToken: csrfToken });
    }
})();
