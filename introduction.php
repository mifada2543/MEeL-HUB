<?php
include 'auth/config.php';
$back_url = 'index.php';

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref  = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path        = parse_url($ref, PHP_URL_PATH);
        $excluded_pages  = ['profile_edit.php', 'index.php'];
        $should_exclude  = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) $back_url = $ref;
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Panduan Penggunaan</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/lucide.js"></script>
    <style>
        @import url('assets/css/font.css');

        :root {
            --bg: #080a0f;
            --surface: #0d1017;
            --panel: #0f1219;
            --border: rgba(255, 255, 255, .06);
            --border-md: rgba(255, 255, 255, .10);
            --text: #c8cdd8;
            --muted: #445060;
            --white: #f0f2f7;
            --red: #ef4444;
            --orange: #f97316;
            --blue: #3b82f6;
            --purple: #a78bfa;
            --green: #22c55e;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            height: 100%;
            overflow: hidden;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: 'DM Sans', sans-serif;
            display: flex;
            flex-direction: row;
        }

        /* noise grain */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        /* scanline top accent — konsisten dengan up.css */
        body::after {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--orange), transparent);
            z-index: 100;
            animation: scanline-glow 4s ease-in-out infinite;
        }

        @keyframes scanline-glow {

            0%,
            100% {
                opacity: .25;
            }

            50% {
                opacity: .8;
            }
        }

        /* ── SIDEBAR ── */
        .sidebar {
            width: 256px;
            flex-shrink: 0;
            height: 100vh;
            background: var(--panel);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            position: relative;
            z-index: 10;
        }

        .sidebar-header {
            padding: 22px 18px;
            border-bottom: 1px solid var(--border);
            flex-shrink: 0;
        }

        .back-link {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .back-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .04);
            border: 1px solid var(--border-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background .2s, border-color .2s;
        }

        .back-link:hover .back-icon {
            background: rgba(59, 130, 246, .12);
            border-color: rgba(59, 130, 246, .28);
        }

        .brand-title {
            font-family: 'Syne', sans-serif;
            font-size: 15px;
            font-weight: 800;
            color: var(--white);
            letter-spacing: .04em;
            line-height: 1.1;
        }

        .brand-title span {
            color: var(--blue);
        }

        .brand-sub {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .2em;
            color: var(--muted);
            margin-top: 3px;
        }

        /* nav */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .sidebar-nav::-webkit-scrollbar {
            display: none;
        }

        .nav-section-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .2em;
            color: var(--muted);
            padding: 8px 10px 4px;
        }

        .nav-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 12px;
            border: none;
            background: transparent;
            cursor: pointer;
            text-align: left;
            transition: background .18s;
            position: relative;
            overflow: hidden;
        }

        .nav-btn-icon {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: background .18s;
        }

        .nav-btn-label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--muted);
            transition: color .18s;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, .035);
        }

        .nav-btn:hover .nav-btn-label {
            color: var(--white);
        }

        /* active pill line */
        .nav-btn.active-video::before,
        .nav-btn.active-music::before {
            content: '';
            position: absolute;
            left: 0;
            top: 18%;
            bottom: 18%;
            width: 3px;
            border-radius: 0 3px 3px 0;
        }

        /* Video active */
        .nav-btn.active-video {
            background: rgba(239, 68, 68, .07);
        }

        .nav-btn.active-video .nav-btn-icon {
            background: rgba(239, 68, 68, .14);
        }

        .nav-btn.active-video .nav-btn-label {
            color: var(--red);
        }

        .nav-btn.active-video::before {
            background: var(--red);
        }

        /* Music active */
        .nav-btn.active-music {
            background: rgba(249, 115, 22, .07);
        }

        .nav-btn.active-music .nav-btn-icon {
            background: rgba(249, 115, 22, .14);
        }

        .nav-btn.active-music .nav-btn-label {
            color: var(--orange);
        }

        .nav-btn.active-music::before {
            background: var(--orange);
        }

        /* sidebar footer */
        .sidebar-footer {
            padding: 14px 10px;
            border-top: 1px solid var(--border);
            flex-shrink: 0;
        }

        .version-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: var(--muted);
        }

        /* ── MAIN ── */
        .main {
            flex: 1;
            height: 100vh;
            overflow-y: auto;
            background: rgba(0, 0, 0, .12);
            position: relative;
            z-index: 1;
        }

        .main::-webkit-scrollbar {
            width: 3px;
        }

        .main::-webkit-scrollbar-track {
            background: transparent;
        }

        .main::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, .07);
            border-radius: 4px;
        }

        .main-inner {
            max-width: 840px;
            margin: 0 auto;
            padding: 44px 32px 64px;
        }

        /* guide sections */
        .guide-section {
            display: none;
        }

        .guide-section.active {
            display: block;
            animation: sectionIn .35s cubic-bezier(.22, 1, .36, 1);
        }

        @keyframes sectionIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* section header */
        .guide-header {
            margin-bottom: 32px;
        }

        .guide-eyebrow {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .24em;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .guide-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(22px, 4vw, 32px);
            font-weight: 800;
            color: var(--white);
            line-height: 1.1;
        }

        .guide-title span.red {
            color: var(--red);
        }

        .guide-title span.orange {
            color: var(--orange);
        }

        .guide-desc {
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
            line-height: 1.65;
        }

        /* ── CONTENT CARD ── */
        .content-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            margin-bottom: 18px;
            transition: border-color .25s, transform .25s;
            animation: cardIn .4s cubic-bezier(.22, 1, .36, 1) both;
        }

        .content-card:hover {
            border-color: rgba(255, 255, 255, .1);
            transform: translateY(-1px);
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .content-card:nth-child(2) {
            animation-delay: .05s;
        }

        .content-card:nth-child(3) {
            animation-delay: .10s;
        }

        .content-card:nth-child(4) {
            animation-delay: .15s;
        }

        .content-card-header {
            padding: 14px 18px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header-icon {
            width: 30px;
            height: 30px;
            border-radius: 9px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-header-title {
            font-family: 'Syne', sans-serif;
            font-size: 12px;
            font-weight: 800;
            color: var(--white);
            letter-spacing: .04em;
        }

        .card-header-sub {
            font-size: 10px;
            color: var(--muted);
            margin-top: 1px;
        }

        /* screenshot */
        .screenshot-wrap {
            padding: 14px;
            background: rgba(0, 0, 0, .18);
        }

        .screenshot-img {
            width: 100%;
            border-radius: 10px;
            display: block;
            border: 1px solid var(--border);
            cursor: zoom-in;
            transition: opacity .2s, transform .25s;
        }

        .screenshot-img:hover {
            opacity: .88;
            transform: scale(1.004);
        }

        /* annotation list */
        .annotation-list {
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .annotation-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 8px 12px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .02);
            border: 1px solid rgba(255, 255, 255, .04);
            transition: background .15s, border-color .15s;
        }

        .annotation-item:hover {
            background: rgba(255, 255, 255, .04);
            border-color: rgba(255, 255, 255, .08);
        }

        .annotation-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            flex-shrink: 0;
            margin-top: 4px;
            animation: dot-pulse 2.4s ease-in-out infinite;
        }

        @keyframes dot-pulse {

            0%,
            100% {
                opacity: 1;
                transform: scale(1);
            }

            50% {
                opacity: .5;
                transform: scale(.75);
            }
        }

        /* stagger pulse so dots don't all blink together */
        .annotation-item:nth-child(2) .annotation-dot {
            animation-delay: .3s;
        }

        .annotation-item:nth-child(3) .annotation-dot {
            animation-delay: .6s;
        }

        .annotation-item:nth-child(4) .annotation-dot {
            animation-delay: .9s;
        }

        .annotation-item:nth-child(5) .annotation-dot {
            animation-delay: 1.2s;
        }

        .annotation-key {
            font-size: 11px;
            font-weight: 700;
            color: var(--white);
            min-width: 130px;
            flex-shrink: 0;
        }

        .annotation-val {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.5;
        }

        /* ── KEYBOARD SHORTCUTS ── */
        .shortcuts-grid {
            padding: 14px 18px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 7px;
        }

        @media (max-width: 600px) {
            .shortcuts-grid {
                grid-template-columns: 1fr;
            }
        }

        .shortcut-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 7px 10px;
            border-radius: 9px;
            background: rgba(255, 255, 255, .02);
            border: 1px solid rgba(255, 255, 255, .04);
            transition: background .15s;
        }

        .shortcut-item:hover {
            background: rgba(255, 255, 255, .04);
        }

        .kbd {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 28px;
            height: 22px;
            padding: 0 6px;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .10);
            border-bottom-width: 2px;
            border-radius: 5px;
            font-size: 10px;
            font-weight: 700;
            color: var(--white);
            font-family: 'DM Sans', sans-serif;
            flex-shrink: 0;
            transition: background .15s, border-color .15s;
        }

        .shortcut-item:hover .kbd {
            background: rgba(255, 255, 255, .09);
            border-color: rgba(255, 255, 255, .18);
        }

        .shortcut-desc {
            font-size: 11px;
            color: var(--muted);
            line-height: 1.4;
        }

        /* ── LIGHTBOX ── */
        #lightbox {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 999;
            background: rgba(0, 0, 0, .9);
            backdrop-filter: blur(14px);
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        #lightbox.open {
            display: flex;
            animation: overlayIn .2s ease;
        }

        @keyframes overlayIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        #lightbox-img {
            max-width: 100%;
            max-height: 90vh;
            border-radius: 14px;
            border: 1px solid var(--border-md);
            animation: imgIn .28s cubic-bezier(.34, 1.56, .64, 1);
        }

        @keyframes imgIn {
            from {
                transform: scale(.9);
                opacity: 0;
            }

            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        #lightbox-close {
            position: fixed;
            top: 18px;
            right: 18px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .07);
            border: 1px solid var(--border-md);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .2s;
        }

        #lightbox-close:hover {
            background: rgba(255, 255, 255, .14);
        }

        /* ── MOBILE ── */
        @media (max-width: 768px) {
            body {
                flex-direction: column;
                overflow: auto;
            }

            html,
            body {
                height: auto;
            }

            .sidebar {
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid var(--border);
            }

            .sidebar-nav {
                flex-direction: row;
                overflow-x: auto;
                padding: 10px 12px;
            }

            .nav-btn {
                min-width: 110px;
            }

            .main {
                height: auto;
                overflow-y: visible;
            }

            .main-inner {
                padding: 24px 16px 40px;
            }

            .shortcuts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

    <!-- ── SIDEBAR ── -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <a href="<?= htmlspecialchars($back_url) ?>" class="back-link">
                <div class="back-icon">
                    <i data-lucide="home" style="width:16px;height:16px;color:#6b7280;"></i>
                </div>
                <div>
                    <div class="brand-title">MEeL <span>Guide</span></div>
                    <div class="brand-sub">Pusat Bantuan</div>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section-label">Panduan</div>

            <button class="nav-btn active-video" id="nav-video" onclick="showGuide('video', this)">
                <div class="nav-btn-icon">
                    <i data-lucide="play-square" style="width:15px;height:15px;color:#ef4444;"></i>
                </div>
                <span class="nav-btn-label">Video</span>
            </button>

            <button class="nav-btn" id="nav-music" onclick="showGuide('music', this)">
                <div class="nav-btn-icon">
                    <i data-lucide="music-2" style="width:15px;height:15px;color:#f97316;"></i>
                </div>
                <span class="nav-btn-label">Musik</span>
            </button>
        </nav>

        <div class="sidebar-footer">
            <div class="version-chip">
                <i data-lucide="book-open" style="width:10px;height:10px;"></i>
                MEeL Docs
            </div>
        </div>
    </aside>

    <!-- ── MAIN ── -->
    <main class="main">
        <div class="main-inner">

            <!-- ══ VIDEO GUIDE ══ -->
            <div id="guide-video" class="guide-section active">
                <div class="guide-header">
                    <div class="guide-eyebrow">Dokumentasi · Fitur</div>
                    <h1 class="guide-title">Panduan <span class="red">Video</span></h1>
                    <p class="guide-desc">Kenali cara bernavigasi dan menggunakan fitur pemutar video MEeL.</p>
                </div>

                <!-- Halaman Index -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);">
                            <i data-lucide="layout-grid" style="width:14px;height:14px;color:#ef4444;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Index</div>
                            <div class="card-header-sub">Tampilan daftar & navigasi utama</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/video0.png" alt="Index Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $video_index = [
                            ['Menu HUB',           'Kembali ke halaman utama MEeL',                       '#ef4444'],
                            ['Search',             'Cari video berdasarkan judul atau kata kunci',         '#f97316'],
                            ['Navbar',             'Berpindah antar halaman — Video, Books, FikaAI',       '#3b82f6'],
                            ['Daftar Video',       'Grid video yang tersedia di library',                  '#a78bfa'],
                            ['Muat Lebih Banyak',  'Memuat batch video berikutnya secara lazy',            '#22c55e'],
                        ];
                        foreach ($video_index as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Halaman Watch -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.2);">
                            <i data-lucide="play-circle" style="width:14px;height:14px;color:#ef4444;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Watch</div>
                            <div class="card-header-sub">Pemutar video dengan Plyr HLS</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/video1.png" alt="Watch Video" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $video_watch = [
                            ['Kembali ke Index',   'Tombol navigasi ke halaman daftar video',    '#ef4444'],
                            ['Video Player',       'Pemutar video HLS adaptif berbasis Plyr',    '#f97316'],
                            ['Search',             'Cari video lain tanpa keluar dari halaman',  '#3b82f6'],
                        ];
                        foreach ($video_watch as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Keyboard shortcuts -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);">
                            <i data-lucide="keyboard" style="width:14px;height:14px;color:#60a5fa;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Kontrol Keyboard</div>
                            <div class="card-header-sub">Shortcut untuk pemutar video</div>
                        </div>
                    </div>
                    <div class="shortcuts-grid">
                        <?php
                        $shortcuts_video = [
                            ['0–9',       'Loncat ke 0–90% durasi'],
                            ['Space / K', 'Play / Pause'],
                            ['←',         'Mundur (seekTime)'],
                            ['→',         'Maju (seekTime)'],
                            ['↑',         'Volume naik'],
                            ['↓',         'Volume turun'],
                            ['M',         'Mute / Unmute'],
                            ['F',         'Layar penuh'],
                            ['C',         'Toggle caption'],
                            ['L',         'Toggle loop'],
                        ];
                        foreach ($shortcuts_video as $s): ?>
                            <div class="shortcut-item">
                                <span class="kbd"><?= $s[0] ?></span>
                                <span class="shortcut-desc"><?= $s[1] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- ══ MUSIC GUIDE ══ -->
            <div id="guide-music" class="guide-section">
                <div class="guide-header">
                    <div class="guide-eyebrow">Dokumentasi · Fitur</div>
                    <h1 class="guide-title">Panduan <span class="orange">Musik</span></h1>
                    <p class="guide-desc">Kenali cara bernavigasi dan menggunakan fitur pemutar musik MEeL.</p>
                </div>

                <!-- Halaman Index -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.2);">
                            <i data-lucide="layout-grid" style="width:14px;height:14px;color:#f97316;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Index</div>
                            <div class="card-header-sub">Tampilan library & navigasi musik</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/music0.png" alt="Index Musik" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $music_index = [
                            ['Menu HUB',        'Kembali ke halaman utama MEeL',                       '#f97316'],
                            ['Search',          'Cari lagu berdasarkan judul, artis, atau album',      '#ef4444'],
                            ['Navbar',          'Berpindah antar halaman — Video, Books, FikaAI',      '#3b82f6'],
                            ['Daftar Musik',    'Grid lagu yang tersedia di music library',            '#a78bfa'],
                        ];
                        foreach ($music_index as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Halaman Watch -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(249,115,22,.12);border:1px solid rgba(249,115,22,.2);">
                            <i data-lucide="headphones" style="width:14px;height:14px;color:#f97316;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Halaman Watch</div>
                            <div class="card-header-sub">Pemutar musik dengan Plyr</div>
                        </div>
                    </div>
                    <div class="screenshot-wrap">
                        <img src="assets/img/music1.png" alt="Watch Musik" class="screenshot-img"
                            onclick="openLightbox(this.src)" loading="lazy">
                    </div>
                    <div class="annotation-list">
                        <?php
                        $music_watch = [
                            ['Kembali ke Index',  'Tombol navigasi ke halaman library musik',    '#f97316'],
                            ['Music Player',      'Pemutar audio Opus berbasis Plyr',             '#ef4444'],
                            ['Search',            'Cari lagu lain tanpa keluar dari halaman',     '#3b82f6'],
                        ];
                        foreach ($music_watch as $a): ?>
                            <div class="annotation-item">
                                <div class="annotation-dot" style="background:<?= $a[2] ?>;box-shadow:0 0 5px <?= $a[2] ?>;"></div>
                                <div class="annotation-key"><?= $a[0] ?></div>
                                <div class="annotation-val"><?= $a[1] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Keyboard shortcuts -->
                <div class="content-card">
                    <div class="content-card-header">
                        <div class="card-header-icon" style="background:rgba(59,130,246,.1);border:1px solid rgba(59,130,246,.2);">
                            <i data-lucide="keyboard" style="width:14px;height:14px;color:#60a5fa;"></i>
                        </div>
                        <div>
                            <div class="card-header-title">Kontrol Keyboard</div>
                            <div class="card-header-sub">Shortcut untuk pemutar musik</div>
                        </div>
                    </div>
                    <div class="shortcuts-grid">
                        <?php
                        $shortcuts_music = [
                            ['0–9',       'Loncat ke 0–90% durasi'],
                            ['Space / K', 'Play / Pause'],
                            ['←',         'Mundur (seekTime)'],
                            ['→',         'Maju (seekTime)'],
                            ['↑',         'Volume naik'],
                            ['↓',         'Volume turun'],
                            ['M',         'Mute / Unmute'],
                            ['L',         'Toggle loop'],
                        ];
                        foreach ($shortcuts_music as $s): ?>
                            <div class="shortcut-item">
                                <span class="kbd"><?= $s[0] ?></span>
                                <span class="shortcut-desc"><?= $s[1] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </div><!-- /main-inner -->
    </main>

    <!-- ── LIGHTBOX ── -->
    <div id="lightbox" onclick="closeLightbox()">
        <div id="lightbox-close" onclick="closeLightbox()">
            <i data-lucide="x" style="width:14px;height:14px;color:#9ca3af;"></i>
        </div>
        <img id="lightbox-img" src="" alt="Preview" onclick="event.stopPropagation()">
    </div>

    <script>
        lucide.createIcons();

        function showGuide(id, btn) {
            document.querySelectorAll('.guide-section').forEach(s => s.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => {
                b.className = b.className.replace(/\bactive-\S+/g, '').trim() || 'nav-btn';
                if (!b.classList.contains('nav-btn')) b.classList.add('nav-btn');
            });
            document.getElementById('guide-' + id).classList.add('active');
            btn.classList.add('active-' + id);
        }

        function openLightbox(src) {
            const lb = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');
            img.src = src;
            lb.classList.add('open');
        }

        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('open');
        }

        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') closeLightbox();
        });
    </script>
</body>

</html>