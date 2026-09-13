(function() {
    var el = document.getElementById('coin-indicator');
    if (!el) return;

    var countdownEl = document.getElementById('coin-countdown');
    var currentEl = document.getElementById('coin-current');
    var maxEl = document.getElementById('coin-max');
    var role = el.getAttribute('data-role');
    if (role === 'admin') return;

    var remaining = parseInt(el.getAttribute('data-countdown'), 10) || 0;
    var maxCoins = parseInt(el.getAttribute('data-max'), 10) || 0;
    var refillHours = parseInt(el.getAttribute('data-refill-hours'), 10) || 5;
    var userId = parseInt(el.getAttribute('data-user-id'), 10) || 0;
    var apiBase = el.getAttribute('data-api-base') || '../api/meelcoin';
    var interval = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }

    function formatTime(s) {
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        if (h > 0) return pad(h) + ':' + pad(m) + ':' + pad(sec);
        return pad(m) + ':' + pad(sec);
    }

    function tick() {
        if (remaining <= 0) {
            remaining = refillHours * 3600;
            if (countdownEl) countdownEl.textContent = formatTime(remaining);
            return;
        }
        remaining--;
        if (countdownEl) countdownEl.textContent = formatTime(remaining);
    }

    function fetchBalance() {
        fetch(apiBase + '?user_id=' + userId)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.enabled && !data.is_admin) {
                    if (currentEl) currentEl.textContent = data.balance;
                    if (maxEl) maxEl.textContent = data.max;

                    if (data.balance >= data.max) {
                        el.classList.add('coin-maxed');
                    } else {
                        el.classList.remove('coin-maxed');
                    }

                    remaining = data.countdown || refillHours * 3600;
                    if (countdownEl) countdownEl.textContent = formatTime(remaining);
                    if (!interval) {
                        interval = setInterval(tick, 1000);
                    }
                }
            })
            .catch(function() {});
    }

    if (remaining > 0 && countdownEl) {
        countdownEl.textContent = formatTime(remaining);
        interval = setInterval(tick, 1000);
    } else {
        remaining = refillHours * 3600;
        if (countdownEl) countdownEl.textContent = formatTime(remaining);
        interval = setInterval(tick, 1000);
    }
})();
