
(function () {
    var _segsBuilt = false;
    window.meelPhase = function (phase) {
        var phases = ['download', 'transcode', 'sprite', 'done', 'error'];
        phases.forEach(function (p) {
            var el = document.getElementById('meel-phase-' + p);
            if (el) { el.classList.remove('active'); }
        });
        var active = document.getElementById('meel-phase-' + phase);
        if (active) active.classList.add('active');
        if (phase === 'transcode' && !_segsBuilt) {
            _segsBuilt = true;
            var row = document.getElementById('meel-segs');
            for (var i = 0; i < 16; i++) {
                var s = document.createElement('div');
                s.className = 'meel-seg';
                s.id = 'mseg' + i;
                row.appendChild(s);
            }
        }
    };
    window.meelDlPct = function (pct, eta) {
        var b = document.getElementById('meel-dl-bar');
        var t = document.getElementById('meel-dl-pct');
        var e = document.getElementById('meel-dl-eta');
        if (b) b.style.width = pct + '%';
        if (t) t.textContent = pct + '%';
        if (e && eta) e.textContent = eta;
    };
    window.meelTcPct = function (pct, label) {
        var b = document.getElementById('meel-tc-bar');
        var t = document.getElementById('meel-tc-pct');
        if (b) b.style.width = pct + '%';
        if (t) t.textContent = (label || pct + '% — segmen TS');
        var done = Math.floor(pct / 6.5);
        for (var i = 0; i < done && i < 16; i++) {
            var s = document.getElementById('mseg' + i);
            if (s) s.classList.add('done');
        }
    };
    window.meelSpPct = function (pct, label) {
        var b = document.getElementById('meel-sp-bar');
        var t = document.getElementById('meel-sp-pct');
        if (b) b.style.width = pct + '%';
        if (t && label) t.textContent = label;
    };
    window.meelDone = function (title, homeUrl) {
        meelPhase('done');
        var el = document.getElementById('meel-done-title');
        if (el && title) el.textContent = title;
        if (homeUrl) {
            var btn = document.getElementById('meel-btn-home');
            if (btn) btn.href = homeUrl;
        }
    };
    window.meelError = function (log) {
        meelPhase('error');
        var el = document.getElementById('meel-error-log');
        if (el) el.textContent = log;
    };
})();
window.meelDoneTranscode = function (title, downloadUrl) {
    // 1. Ganti state ke 'done'
    meelPhase('done');

    // 2. Set Judul
    var titleEl = document.getElementById('meel-done-title');
    if (titleEl) titleEl.textContent = title;

    // 3. Modifikasi Tombol "Download Lagi" agar tidak ke upload_advanced.php
    // Kita cari tombol berdasarkan teks atau urutan (tombol kedua di meel-nav-btns)
    var navBtns = document.getElementById('meel-nav-btns');
    if (navBtns) {
        var btns = navBtns.getElementsByTagName('a');
        if (btns.length >= 2) {
            // Tombol "Download Lagi" (Indeks 1)
            btns[1].innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Simpan File';
            btns[1].href = downloadUrl;
            btns[1].setAttribute('download', title); // Trigger download otomatis
            btns[1].style.color = '#3b82f6'; // Beri warna biru agar beda
            btns[1].style.borderColor = 'rgba(59,130,246,0.3)';
        }
    }

    // 4. (Opsional) Berikan tombol untuk "Tutup Overlay" agar UI utama terlihat
    var closeBtn = document.createElement('a');
    closeBtn.className = 'meel-nav-btn';
    closeBtn.style.cssText = 'color:rgba(255,255,255,0.5); border-color:rgba(255,255,255,0.1); background:transparent;';
    closeBtn.innerHTML = 'Tutup';
    closeBtn.onclick = function () { document.getElementById('meel-overlay').style.display = 'none'; };
    navBtns.appendChild(closeBtn);
};