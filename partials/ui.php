<style>
  #meel-overlay * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
  }

  #meel-overlay {
    position: fixed;
    inset: 0;
    background: rgba(5, 7, 12, 0.96);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: ui-monospace, 'Cascadia Code', 'SF Mono', monospace;
    /* Tambahan agar animasi lancar saat streaming */
    backface-visibility: hidden;
    perspective: 1000px;
    backdrop-filter: blur(2px);
  }

  #meel-overlay.error-state {
    background: rgba(5, 7, 12, 0.98);
  }

  #meel-card {
    width: 340px;
    text-align: center;
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
  }

  .meel-track {
    width: 100%;
    height: 3px;
    background: rgba(255, 255, 255, .07);
    border-radius: 99px;
    overflow: hidden;
    position: relative;
  }

  .meel-bar {
    height: 100%;
    width: 0%;
    border-radius: 99px;
    transition: width .35s cubic-bezier(.4, 0, .2, 1);
  }

  .meel-pct {
    font-size: 10px;
    letter-spacing: .12em;
    color: rgba(255, 255, 255, .3);
    width: 100%;
    display: flex;
    justify-content: space-between;
  }

  .meel-label {
    font-size: 9px;
    letter-spacing: .22em;
    text-transform: uppercase;
  }

  /* Animasi Scan */
  .meel-scan {
    position: absolute;
    top: 0;
    left: 0;
    width: 30%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .18), transparent);
    animation: meel-scan 1.4s ease-in-out infinite;
    will-change: transform;
  }

  @keyframes meel-scan {
    0% {
      transform: translateX(-100%);
    }

    100% {
      transform: translateX(450%);
    }
  }

  @keyframes meel-spin {
    to {
      transform: rotate(360deg);
    }
  }

  @keyframes meel-eq1 {

    0%,
    100% {
      transform: scaleY(.35);
    }

    50% {
      transform: scaleY(1);
    }
  }

  @keyframes meel-eq2 {

    0%,
    100% {
      transform: scaleY(1);
    }

    50% {
      transform: scaleY(.3);
    }
  }

  .meel-spinner {
    width: 13px;
    height: 13px;
    border: 1.5px solid rgba(59, 130, 246, .2);
    border-top-color: #3b82f6;
    border-radius: 50%;
    animation: meel-spin .85s linear infinite;
    flex-shrink: 0;
    transform: translateZ(0);
    /* Hardware Acceleration */
  }

  .meel-eq {
    display: flex;
    gap: 3px;
    align-items: flex-end;
    height: 13px;
  }

  .meel-eq span {
    width: 3px;
    border-radius: 2px;
    background: #f97316;
    transform: translateZ(0);
  }

  .meel-eq span:nth-child(1) {
    animation: meel-eq1 .75s ease-in-out infinite;
    height: 6px;
  }

  .meel-eq span:nth-child(2) {
    animation: meel-eq2 .75s ease-in-out infinite .12s;
    height: 10px;
  }

  .meel-eq span:nth-child(3) {
    animation: meel-eq1 .75s ease-in-out infinite .25s;
    height: 13px;
  }

  .meel-eq span:nth-child(4) {
    animation: meel-eq2 .75s ease-in-out infinite .38s;
    height: 8px;
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
    transition: opacity .2s;
  }

  .meel-nav-btn:hover {
    opacity: .75;
  }
</style>

<script>
  (function() {
    var _segsBuilt = false;
    var _errorTimeout = null;
    
    window.meelPhase = function(phase) {
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
    };
    
    window.meelDlPct = function(pct, eta) {
      var b = document.getElementById('meel-dl-bar');
      var t = document.getElementById('meel-dl-pct');
      var e = document.getElementById('meel-dl-eta');
      if (b) b.style.width = pct + '%';
      if (t) t.textContent = pct + '%';
      if (e && eta) e.textContent = eta;
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
    
    window.meelSpPct = function(pct, label) {
      var b = document.getElementById('meel-sp-bar');
      var t = document.getElementById('meel-sp-pct');
      if (b) b.style.width = pct + '%';
      if (t && label) t.textContent = label;
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
      // Clear any existing error timeout
      if (_errorTimeout) clearTimeout(_errorTimeout);
      
      // Ganti fase ke error
      meelPhase('error');
      
      // Set error state untuk dark background
      var overlay = document.getElementById('meel-overlay');
      if (overlay) {
        overlay.classList.add('error-state');
      }
      
      // Set error message
      var el = document.getElementById('meel-error-log');
      if (el) el.textContent = log;
      
      console.error('MEeL Error:', log);
    };
    
    // Safety: Catch global errors dan tampilkan di overlay
    window.addEventListener('error', function(event) {
      console.error('Global JavaScript Error:', event.error);
      meelError('Kesalahan sistem: ' + (event.error?.message || 'Unknown error'));
    });
    
    // Safety: Catch unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
      console.error('Unhandled Promise:', event.reason);
      meelError('Kesalahan sistem: ' + (event.reason?.message || String(event.reason)));
    });
  })();
  
  window.meelDoneTranscode = function(title, downloadUrl) {
    // 1. Ganti state ke 'done'
    meelPhase('done');

    // 2. Set Judul
    var titleEl = document.getElementById('meel-done-title');
    if (titleEl) titleEl.textContent = title;

    // 3. Modifikasi Tombol "Download Lagi" agar tidak ke upload_advanced.php
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

    // 4. Tombol tutup overlay
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

<div id="meel-overlay">
  <div id="meel-card">

    <!-- LOGO / WORDMARK -->
    <div style="font-size:11px;letter-spacing:.35em;color:rgba(255,255,255,.18);text-transform:uppercase;margin-bottom:28px">MEeL Engine</div>

    <!-- ── FASE: DOWNLOAD ── -->
    <div class="meel-phase" id="meel-phase-download">
      <div style="display:flex;align-items:center;gap:8px">
        <div class="meel-spinner"></div>
        <span class="meel-label" style="color:#3b82f6">Mengunduh</span>
      </div>
      <div class="meel-track">
        <div class="meel-bar" id="meel-dl-bar" style="background:#3b82f6"></div>
        <div class="meel-scan"></div>
      </div>
      <div class="meel-pct">
        <span id="meel-dl-pct">0%</span>
        <span id="meel-dl-eta" style="color:rgba(255,255,255,.15)"></span>
      </div>
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