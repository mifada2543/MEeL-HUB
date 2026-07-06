<style>
  #meel-overlay * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }

  #meel-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 7, 12, 0.90);
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    font-family: ui-monospace, 'Cascadia Code', 'SF Mono', monospace;
    /* Efek Blur yang lebih kuat untuk fokus yang lebih jelas */
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    /* Animasi masuk overlay yang mulus */
    animation: meel-fade-in 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
  }

  @keyframes meel-fade-in {
    from {
      opacity: 0;
      backdrop-filter: blur(0px);
    }

    to {
      opacity: 1;
      backdrop-filter: blur(8px);
    }
  }

  #meel-overlay.error-state {
    background: rgba(5, 7, 12, 0.98);
  }

  #meel-card {
    width: 420px;
    text-align: center;
  }

  /* ── Download phase ── */
  #meel-phase-download .dl-icon-wrap {
    width: 72px;
    height: 72px;
    border-radius: 20px;
    background: rgba(59, 130, 246, .08);
    border: 1px solid rgba(59, 130, 246, .18);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    margin: 0 auto;
    /* Hardware acceleration untuk kontainer icon */
    transform: translateZ(0);
  }

  #meel-phase-download .dl-icon-wrap::after {
    content: '';
    position: absolute;
    inset: -1px;
    border-radius: 20px;
    border: 1px solid rgba(59, 130, 246, .35);
    animation: meel-ping 2s cubic-bezier(0, 0, 0.2, 1) infinite;
    will-change: transform, opacity;
  }

  @keyframes meel-ping {
    0% {
      transform: scale(1);
      opacity: .8;
    }

    80%,
    100% {
      transform: scale(1.15);
      opacity: 0;
    }
  }

  .dl-track {
    width: 100%;
    height: 4px;
    background: rgba(255, 255, 255, .06);
    border-radius: 99px;
    overflow: hidden;
    position: relative;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2);
  }

  .dl-stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 8px;
    width: 100%;
  }

  .dl-stat {
    background: rgba(255, 255, 255, .03);
    border: 1px solid rgba(255, 255, 255, .07);
    border-radius: 10px;
    padding: 10px 8px;
    text-align: center;
  }

  .dl-stat-label {
    font-size: 9px;
    letter-spacing: .16em;
    color: rgba(255, 255, 255, .3);
    text-transform: uppercase;
    margin-bottom: 5px;
  }

  .dl-stat-val {
    font-size: 13px;
    color: rgba(255, 255, 255, .8);
    font-weight: 600;
  }

  .meel-phase {
    display: none;
    flex-direction: column;
    align-items: center;
    gap: 14px;
    width: 100%;
  }

  .meel-phase.active {
    display: flex;
    animation: meel-slide-up 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards;
  }

  @keyframes meel-slide-up {
    from {
      opacity: 0;
      transform: translateY(10px);
    }

    to {
      opacity: 1;
      transform: translateY(0);
    }
  }

  .meel-track {
    width: 100%;
    height: 3px;
    background: rgba(255, 255, 255, .07);
    border-radius: 99px;
    overflow: hidden;
    position: relative;
  }

  /* Progress Bar Glow & Mulus */
  .meel-bar {
    height: 100%;
    width: 0%;
    border-radius: 99px;
    /* Transisi diperlambat sedikit agar input log PHP yang tidak stabil tetap terlihat halus */
    transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    will-change: width;
  }

  #meel-dl-bar {
    box-shadow: 0 0 10px rgba(59, 130, 246, 0.6);
  }

  #meel-tc-bar {
    box-shadow: 0 0 10px rgba(249, 115, 22, 0.6);
  }

  #meel-sp-bar {
    box-shadow: 0 0 10px rgba(167, 139, 250, 0.6);
  }

  .meel-pct {
    font-size: 10px;
    letter-spacing: .12em;
    color: rgba(255, 255, 255, .4);
    width: 100%;
    display: flex;
    justify-content: space-between;
  }

  .meel-label {
    font-size: 9px;
    letter-spacing: .22em;
    text-transform: uppercase;
    font-weight: 600;
  }

  /* Animasi Scan GPU Accelerated */
  .meel-scan {
    position: absolute;
    top: 0;
    left: 0;
    width: 30%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .3), transparent);
    animation: meel-scan 1.5s cubic-bezier(0.4, 0, 0.2, 1) infinite;
    /* Paksa render menggunakan GPU */
    will-change: transform;
    transform: translate3d(0, 0, 0);
  }

  @keyframes meel-scan {
    0% {
      transform: translate3d(-100%, 0, 0);
    }

    100% {
      transform: translate3d(400%, 0, 0);
    }
  }

  @keyframes meel-spin {
    to {
      transform: rotate(360deg);
    }
  }

  /* Equalizer Animation Fix */
  .meel-eq {
    display: flex;
    gap: 4px;
    align-items: flex-end;
    height: 14px;
  }

  .meel-eq span {
    width: 3px;
    border-radius: 2px;
    background: #f97316;
    /* PENTING: scale animasi dari bawah, bukan dari tengah */
    transform-origin: bottom;
    will-change: transform;
    box-shadow: 0 0 5px rgba(249, 115, 22, 0.4);
  }

  .meel-eq span:nth-child(1) {
    animation: meel-eq-bounce 0.8s ease-in-out infinite;
    height: 14px;
  }

  .meel-eq span:nth-child(2) {
    animation: meel-eq-bounce 0.8s ease-in-out infinite 0.2s;
    height: 14px;
  }

  .meel-eq span:nth-child(3) {
    animation: meel-eq-bounce 0.8s ease-in-out infinite 0.4s;
    height: 14px;
  }

  .meel-eq span:nth-child(4) {
    animation: meel-eq-bounce 0.8s ease-in-out infinite 0.6s;
    height: 14px;
  }

  @keyframes meel-eq-bounce {

    0%,
    100% {
      transform: scaleY(0.3);
    }

    50% {
      transform: scaleY(1);
    }
  }

  .meel-nav-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 20px;
    border-radius: 8px;
    font-size: 9px;
    letter-spacing: .18em;
    text-transform: uppercase;
    cursor: pointer;
    text-decoration: none;
    border: 0.5px solid;
    transition: all .2s cubic-bezier(0.4, 0, 0.2, 1);
  }

  .meel-nav-btn:hover {
    opacity: 1;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
  }

  /* Icon wrap untuk fase Done & Error */
  .meel-icon-wrap {
    width: 52px;
    height: 52px;
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* Segmen HLS — blok kecil di bawah progress bar transcode */
  .meel-segs {
    display: flex;
    gap: 3px;
    flex-wrap: wrap;
    justify-content: center;
    width: 100%;
    min-height: 6px;
  }

  .meel-seg {
    width: 14px;
    height: 6px;
    border-radius: 2px;
    background: rgba(249, 115, 22, .18);
    border: 0.5px solid rgba(249, 115, 22, .25);
    transition: background 0.3s ease, box-shadow 0.3s ease;
  }

  .meel-seg.done {
    background: #f97316;
    box-shadow: 0 0 5px rgba(249, 115, 22, 0.5);
  }
</style>

<script>
  (function() {
    var _segsBuilt = false;
    var _errorTimeout = null;

    // ─── KONTROL ANIMASI MERAYAP (TRICKLE EFFECT) FOR SPRITE ───
    var meelSpriteTimer = null;
    var meelSpriteCurrentPct = 0;

    function startSpriteTrickle() {
      var b = document.getElementById('meel-sp-bar');
      var t = document.getElementById('meel-sp-pct');

      meelSpriteCurrentPct = 0;
      clearInterval(meelSpriteTimer);

      // Bergerak otomatis ke 95% dalam durasi ~13 detik (135ms x 95 langkah)
      meelSpriteTimer = setInterval(function() {
        if (meelSpriteCurrentPct < 95) {
          meelSpriteCurrentPct += 1;
          if (b) b.style.width = meelSpriteCurrentPct + '%';
          if (t) t.textContent = meelSpriteCurrentPct + '% — Membuat Sprite VTT...';
        }
      }, 135);
    }

    // ─── FASE UTAMA SCRIPT ───
    window.meelPhase = function(phase) {
      var overlay = document.getElementById('meel-overlay');
      if (overlay) overlay.style.display = 'flex';
      var phases = ['download', 'transcode', 'sprite', 'done', 'error'];
      phases.forEach(function(p) {
        var el = document.getElementById('meel-phase-' + p);
        if (el) {
          el.classList.remove('active');
        }
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

      // INTEGRASI: Picu animasi merayap saat masuk fase sprite
      if (phase === 'sprite' || phase === 'sp') {
        startSpriteTrickle();
      } else {
        clearInterval(meelSpriteTimer);
      }
    };

    window.meelDlPct = function(pct, eta, speed, size, frag) {
      var b = document.getElementById('meel-dl-bar');
      var t = document.getElementById('meel-dl-pct');
      var e = document.getElementById('meel-dl-eta');
      var sp = document.getElementById('meel-dl-speed');
      var sz = document.getElementById('meel-dl-size');
      var fr = document.getElementById('meel-dl-frag');
      if (b) b.style.width = pct + '%';
      if (t) t.textContent = pct + '%';
      if (e && eta) e.textContent = eta;
      if (sp && speed) sp.textContent = speed;
      if (sz && size) sz.textContent = size;
      if (fr && frag) fr.textContent = frag;
    };

    window.meelDlInfo = function(url) {
      var el = document.getElementById('meel-dl-url');
      if (el && url) {
        try {
          var u = new URL(url);
          el.textContent = u.hostname + u.pathname.slice(0, 50);
        } catch (e) {
          el.textContent = url.slice(0, 60);
        }
      }
    };

    window.meelTcPct = function(pct, label) {
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

    // INTEGRASI: Tangani progress sprite asli dari PHP dengan trickle effect
    window.meelSpPct = function(pct, label) {
      var b = document.getElementById('meel-sp-bar');
      var t = document.getElementById('meel-sp-pct');
      var numericPct = parseInt(pct);

      if (numericPct === 100) {
        clearInterval(meelSpriteTimer);
        if (b) b.style.width = '100%';
        if (t) t.textContent = label || '100% — Selesai';
      } else if (numericPct > meelSpriteCurrentPct) {
        // Jika karena suatu hal backend mengirim progres lebih tinggi dari animasi palsu kita
        meelSpriteCurrentPct = numericPct;
        if (b) b.style.width = meelSpriteCurrentPct + '%';
        if (t) t.textContent = label || meelSpriteCurrentPct + '%';
      }
    };

    window.meelDone = function(title, homeUrl) {
      meelPhase('done');
      var el = document.getElementById('meel-done-title');
      if (el && title) el.textContent = title;
      if (homeUrl) {
        var btn = document.getElementById('meel-btn-home');
        if (btn) btn.href = homeUrl;
      }
    };

    window.meelError = function(log) {
      if (_errorTimeout) clearTimeout(_errorTimeout);
      meelPhase('error');
      var overlay = document.getElementById('meel-overlay');
      if (overlay) {
        overlay.classList.add('error-state');
      }
      var el = document.getElementById('meel-error-log');
      if (el) el.textContent = log;
      console.error('MEeL Error:', log);
    };

    window.addEventListener('error', function(event) {
      console.error('Global JavaScript Error:', event.error);
      meelError('Kesalahan sistem: ' + (event.error?.message || 'Unknown error'));
    });

    window.addEventListener('unhandledrejection', function(event) {
      console.error('Unhandled Promise:', event.reason);
      meelError('Kesalahan sistem: ' + (event.reason?.message || String(event.reason)));
    });
  })();

  window.meelDoneTranscode = function(title, downloadUrl) {
    meelPhase('done');
    var titleEl = document.getElementById('meel-done-title');
    if (titleEl) titleEl.textContent = title;

    var navBtns = document.getElementById('meel-nav-btns');
    if (navBtns) {
      var btns = navBtns.getElementsByTagName('a');
      if (btns.length >= 2) {
        btns[1].innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Simpan File';
        btns[1].href = downloadUrl;
        btns[1].setAttribute('download', title);
        btns[1].style.color = '#3b82f6';
        btns[1].style.borderColor = 'rgba(59,130,246,0.3)';
      }
    }

    var closeBtn = document.createElement('a');
    closeBtn.className = 'meel-nav-btn';
    closeBtn.style.cssText = 'color:rgba(255,255,255,0.5); border-color:rgba(255,255,255,0.1); background:transparent;';
    closeBtn.innerHTML = 'Tutup';
    closeBtn.onclick = function() {
      document.getElementById('meel-overlay').style.display = 'none';
    };
    navBtns.appendChild(closeBtn);
  };
</script>
<link rel="manifest" href="<?= asset_url('assets/manifest.json') ?>">
<link rel="icon" type="image/png" href="<?= asset_url('assets/MEeL.png') ?>">
<div id="meel-overlay">
  <div id="meel-card">

    <!-- LOGO / WORDMARK -->
    <div style="font-size:11px;letter-spacing:.35em;color:rgba(255,255,255,.18);text-transform:uppercase;margin-bottom:28px">MEeL Engine</div>

    <!-- ── FASE: DOWNLOAD ── -->
    <div class="meel-phase" id="meel-phase-download">
      <div class="dl-icon-wrap">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="1.5" stroke-linecap="round">
          <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
          <polyline points="7 10 12 15 17 10" />
          <line x1="12" y1="15" x2="12" y2="3" />
        </svg>
      </div>
      <div>
        <div class="meel-label" style="color:#3b82f6;margin-bottom:6px;">Mengunduh</div>
        <div id="meel-dl-url" style="font-size:11px;color:rgba(255,255,255,.28);letter-spacing:.04em;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"></div>
      </div>
      <div style="width:100%;">
        <div class="dl-track" style="margin-bottom:10px;">
          <div class="meel-bar" id="meel-dl-bar" style="background:#3b82f6"></div>
          <div class="meel-scan"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:10px;letter-spacing:.1em;color:rgba(255,255,255,.25);">
          <span id="meel-dl-pct" style="color:rgba(255,255,255,.5);">0%</span>
          <span id="meel-dl-eta"></span>
        </div>
      </div>
      <div class="dl-stats-grid">
        <div class="dl-stat">
          <div class="dl-stat-label">Ukuran</div>
          <div class="dl-stat-val" id="meel-dl-size">—</div>
        </div>
        <div class="dl-stat">
          <div class="dl-stat-label">Kecepatan</div>
          <div class="dl-stat-val" id="meel-dl-speed">—</div>
        </div>
        <div class="dl-stat">
          <div class="dl-stat-label">Fragmen</div>
          <div class="dl-stat-val" id="meel-dl-frag">—</div>
        </div>
      </div>
      <div style="font-size:10px;color:rgba(255,255,255,.15);letter-spacing:.08em;font-style:italic;">Jangan tutup tab ini selama proses berlangsung</div>
    </div>

    <!-- ── FASE: TRANSCODE HLS ── -->
    <div class="meel-phase" id="meel-phase-transcode">
      <div style="display:flex;align-items:center;gap:8px">
        <div class="meel-eq"><span></span><span></span><span></span><span></span></div>
        <span class="meel-label" style="color:#f97316">Transcode HLS</span>
      </div>
      <div class="meel-track">
        <div class="meel-bar" id="meel-tc-bar" style="background:#f97316"></div>
      </div>
      <div class="meel-pct">
        <span id="meel-tc-pct">0% — segmen TS</span>
        <span id="meel-tc-eta" style="color:rgba(255,255,255,.15)"></span>
      </div>
      <div class="meel-segs" id="meel-segs"></div>
    </div>

    <!-- ── FASE: SPRITE / VTT ── -->
    <div class="meel-phase" id="meel-phase-sprite">
      <div style="display:flex;align-items:center;gap:8px">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2">
          <rect x="3" y="3" width="18" height="14" rx="2" />
          <path d="M3 9h18" />
        </svg>
        <span class="meel-label" style="color:#a78bfa">Preview Sprite &amp; VTT</span>
      </div>
      <div class="meel-track">
        <div class="meel-bar" id="meel-sp-bar" style="background:#a78bfa"></div>
        <div class="meel-scan" style="background:linear-gradient(90deg,transparent,rgba(167,139,250,.25),transparent)"></div>
      </div>
      <div class="meel-pct"><span id="meel-sp-pct" style="width:100%;text-align:center">Membuat thumbnail.vtt...</span></div>
    </div>

    <!-- ── FASE: SELESAI ── -->
    <div class="meel-phase" id="meel-phase-done">
      <div class="meel-icon-wrap" style="background:rgba(34,197,94,.1);border:0.5px solid rgba(34,197,94,.3)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round">
          <polyline points="20 6 9 17 4 12" />
        </svg>
      </div>
      <div class="meel-label" style="color:#22c55e">Selesai</div>
      <div id="meel-done-title" style="font-size:11px;color:rgba(255,255,255,.4);letter-spacing:.05em;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
      <!-- Tombol navigasi setelah selesai -->
      <div style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap;justify-content:center" id="meel-nav-btns">
        <a id="meel-btn-home" href="index.php" class="meel-nav-btn" style="color:#22c55e;border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M3 12L12 3l9 9" />
            <path d="M9 21V12h6v9" />
          </svg>
          Beranda
        </a>
        <a href="upload_advanced.php" class="meel-nav-btn" style="color:rgba(255,255,255,.45);border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.04)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4" />
            <polyline points="17 8 12 3 7 8" />
            <line x1="12" y1="3" x2="12" y2="15" />
          </svg>
          Download Lagi
        </a>
      </div>
    </div>

    <!-- ── FASE: ERROR ── -->
    <div class="meel-phase" id="meel-phase-error">
      <div class="meel-icon-wrap" style="background:rgba(239,68,68,.09);border:0.5px solid rgba(239,68,68,.28)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round">
          <line x1="18" y1="6" x2="6" y2="18" />
          <line x1="6" y1="6" x2="18" y2="18" />
        </svg>
      </div>
      <div class="meel-label" style="color:#ef4444">Proses Gagal</div>
      <div id="meel-error-log" style="width:100%;background:rgba(239,68,68,.06);border:0.5px solid rgba(239,68,68,.18);
           border-radius:6px;padding:10px 12px;max-height:80px;overflow:auto;
           font-size:9px;color:rgba(239,68,68,.65);text-align:left;line-height:1.65;
           white-space:pre-wrap;word-break:break-all"></div>
      <div style="display:flex;gap:10px;margin-top:2px;flex-wrap:wrap;justify-content:center">
        <a href="upload_advanced.php" class="meel-nav-btn" style="color:#ef4444;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.07)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <polyline points="1 4 1 10 7 10" />
            <path d="M3.51 15a9 9 0 1 0 .49-3.28" />
          </svg>
          Coba Lagi
        </a>
        <a href="index.php" class="meel-nav-btn" style="color:rgba(255,255,255,.4);border-color:rgba(255,255,255,.1);background:rgba(255,255,255,.04)">
          Beranda
        </a>
      </div>
    </div>
  </div>
</div>