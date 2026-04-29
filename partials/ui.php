<?php
// Ambil isi CSS secara internal agar langsung di-render browser
$css_content = file_get_contents(__DIR__ . '/../assets/css/overlay.css');
?>
<style>
<?php echo $css_content; ?>
</style>

<script>
<?php echo file_get_contents(__DIR__ . '/../assets/js/overlay.js'); ?>
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
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="2"><rect x="3" y="3" width="18" height="14" rx="2"/><path d="M3 9h18"/></svg>
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
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="meel-label" style="color:#22c55e">Selesai</div>
      <div id="meel-done-title" style="font-size:11px;color:rgba(255,255,255,.4);letter-spacing:.05em;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></div>
      <!-- Tombol navigasi setelah selesai -->
      <div style="display:flex;gap:10px;margin-top:6px;flex-wrap:wrap;justify-content:center" id="meel-nav-btns">
        <a id="meel-btn-home" href="index.php" class="meel-nav-btn" style="color:#22c55e;border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.08)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M3 12L12 3l9 9"/><path d="M9 21V12h6v9"/></svg>
          Beranda
        </a>
        <a href="upload_advanced.php" class="meel-nav-btn" style="color:rgba(255,255,255,.45);border-color:rgba(255,255,255,.12);background:rgba(255,255,255,.04)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Download Lagi
        </a>
      </div>
    </div>

    <!-- ── FASE: ERROR ── -->
    <div class="meel-phase" id="meel-phase-error">
      <div class="meel-icon-wrap" style="background:rgba(239,68,68,.09);border:0.5px solid rgba(239,68,68,.28)">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
      </div>
      <div class="meel-label" style="color:#ef4444">Proses Gagal</div>
      <div id="meel-error-log" style="width:100%;background:rgba(239,68,68,.06);border:0.5px solid rgba(239,68,68,.18);
           border-radius:6px;padding:10px 12px;max-height:80px;overflow:auto;
           font-size:9px;color:rgba(239,68,68,.65);text-align:left;line-height:1.65;
           white-space:pre-wrap;word-break:break-all"></div>
      <div style="display:flex;gap:10px;margin-top:2px;flex-wrap:wrap;justify-content:center">
        <a href="upload_advanced.php" class="meel-nav-btn" style="color:#ef4444;border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.07)">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.28"/></svg>
          Coba Lagi
        </a>
        <a href="index.php" class="meel-nav-btn" style="color:rgba(255,255,255,.4);border-color:rgba(255,255,255,.1);background:rgba(255,255,255,.04)">
          Beranda
        </a>
      </div>
    </div>
  </div>
</div>
