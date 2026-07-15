<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL Anime — Coming Soon. Platform Anime streaming akan segera hadir.">
    <meta property="og:title" content="MEeL Anime | Coming Soon">
    <meta property="og:description" content="MEeL Anime — Coming Soon. Platform Anime streaming akan segera hadir.">
    <title>MEeL Anime | Coming Soon</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link rel="manifest" href="../assets/manifest.json">
    <?php include_once '../partials/link.php'; ?>
    <style>
        /* ── Root ── */
        :root {
            --anime-pink: #ec4899;
            --anime-purple: #a855f7;
            --anime-blue: #3b82f6;
            --bg: #080a0f;
            --surface: rgba(13, 16, 23, 0.85);
            --border: rgba(255, 255, 255, 0.06);
            --font-display: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
            --font: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }

        * { box-sizing: border-box; }

        body {
            background-color: var(--bg);
            font-family: var(--font);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
            color: #9ca3af;
        }        /* ── Mini Nav Logo ── */
        .mini-nav {
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .5rem .75rem;
            background: rgba(13, 16, 23, 0.7);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .04);
            border-radius: 12px;
            text-decoration: none;
            transition: all .25s ease;
        }
        .mini-nav:hover {
            border-color: rgba(236, 72, 153, .15);
            background: rgba(13, 16, 23, 0.9);
            transform: translateY(-1px);
        }
        .mini-nav img {
            width: 22px;
            height: 22px;
            object-fit: contain;
            opacity: .7;
            transition: opacity .2s;
        }
        .mini-nav:hover img { opacity: 1; }
        .mini-nav span {
            font-size: .55rem;
            letter-spacing: .22em;
            text-transform: uppercase;
            font-weight: 800;
            color: #6b7280;
            transition: color .2s;
        }
        .mini-nav:hover span { color: #d1d5db; }

        @media (max-width: 480px) {
            .mini-nav { top: .75rem; left: .75rem; padding: .35rem .6rem; }
            .mini-nav img { width: 18px; height: 18px; }
            .mini-nav span { display: none; }
        }

        /* ── Scanline Grid ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .018) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .018) 1px, transparent 1px);
            background-size: 56px 56px;
            pointer-events: none;
            z-index: 0;
        }

        /* ── Animated Gradient Orbs ── */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            z-index: 0;
            animation: orbFloat 12s ease-in-out infinite alternate;
        }
        .orb-1 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(236, 72, 153, .15) 0%, transparent 70%);
            top: -100px;
            left: -100px;
            animation-delay: 0s;
        }
        .orb-2 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(168, 85, 247, .12) 0%, transparent 70%);
            bottom: -80px;
            right: -80px;
            animation-delay: -4s;
        }
        .orb-3 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(59, 130, 246, .08) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation-delay: -8s;
        }

        @keyframes orbFloat {
            0% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(40px, -30px) scale(1.1); }
            66% { transform: translate(-20px, 40px) scale(0.9); }
            100% { transform: translate(30px, -10px) scale(1.05); }
        }

        /* ── Floating Particles ── */
        .particles {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 0;
            overflow: hidden;
        }
        .particle {
            position: absolute;
            width: 4px;
            height: 4px;
            background: rgba(255, 255, 255, .15);
            border-radius: 50%;
            animation: particleDrift linear infinite;
        }
        @keyframes particleDrift {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% {
                transform: translateY(-20vh) rotate(720deg);
                opacity: 0;
            }
        }

        /* ── Glass Card ── */
        .glass-card {
            position: relative;
            z-index: 1;
            background: var(--surface);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 2rem;
            padding: 3.5rem 3rem;
            max-width: 540px;
            width: 100%;
            margin: 2rem 1.5rem;
            text-align: center;
            box-shadow:
                0 24px 64px rgba(0, 0, 0, .6),
                0 0 0 1px rgba(236, 72, 153, .06);
            transition: border-color .3s ease, box-shadow .3s ease;
        }
        .glass-card:hover {
            border-color: rgba(236, 72, 153, .15);
            box-shadow:
                0 32px 80px rgba(0, 0, 0, .7),
                0 0 0 1px rgba(236, 72, 153, .12);
        }

        /* ── Animated Border Gradient ── */
        .glass-card::before {
            content: '';
            position: absolute;
            inset: -1px;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(
                135deg,
                rgba(236, 72, 153, .3),
                rgba(168, 85, 247, .3),
                rgba(59, 130, 246, .2),
                rgba(236, 72, 153, .3)
            );
            background-size: 300% 300%;
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            animation: borderShimmer 4s ease-in-out infinite alternate;
        }
        @keyframes borderShimmer {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* ── Icon Container ── */
        .icon-ring {
            width: 88px;
            height: 88px;
            margin: 0 auto 1.75rem;
            border-radius: 50%;
            background: rgba(236, 72, 153, .06);
            border: 1px solid rgba(236, 72, 153, .12);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            animation: iconPulse 3s ease-in-out infinite;
        }
        .icon-ring svg {
            width: 36px;
            height: 36px;
            color: #ec4899;
            position: relative;
            z-index: 1;
        }
        .icon-ring::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 1px solid rgba(236, 72, 153, .08);
            animation: ringExpand 3s ease-in-out infinite;
        }
        @keyframes iconPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: .85; }
        }
        @keyframes ringExpand {
            0%, 100% { transform: scale(1); opacity: .5; }
            50% { transform: scale(1.15); opacity: 0; }
        }

        /* ── Status Badge ── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .35rem 1rem;
            border-radius: 999px;
            font-size: .55rem;
            font-weight: 800;
            letter-spacing: .25em;
            text-transform: uppercase;
            background: rgba(236, 72, 153, .08);
            border: 1px solid rgba(236, 72, 153, .15);
            color: #f9a8d4;
            margin-bottom: 1.5rem;
        }
        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #ec4899;
            animation: dotPulse 1.5s ease-in-out infinite;
        }
        @keyframes dotPulse {
            0%, 100% { opacity: 1; box-shadow: 0 0 0 0 rgba(236, 72, 153, .4); }
            50% { opacity: .5; box-shadow: 0 0 0 6px rgba(236, 72, 153, 0); }
        }

        /* ── Typography ── */
        .coming-title {
            font-family: var(--font-display);
            font-size: clamp(2.2rem, 12vw, 4rem);
            letter-spacing: .04em;
            line-height: 1;
            background: linear-gradient(135deg, #f9a8d4, #c084fc, #93c5fd, #f9a8d4);
            background-size: 300% 300%;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: gradientShift 6s ease-in-out infinite alternate;
            margin-bottom: .75rem;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .coming-sub {
            font-family: var(--font);
            font-size: .65rem;
            letter-spacing: .35em;
            text-transform: uppercase;
            color: #6b7280;
            margin-bottom: 1.5rem;
        }

        .coming-desc {
            font-size: .78rem;
            line-height: 1.8;
            color: #6b7280;
            max-width: 360px;
            margin: 0 auto 2rem;
        }
        .coming-desc strong {
            color: #d1d5db;
        }

        /* ── Progress Bar ── */
        .progress-wrap {
            max-width: 320px;
            margin: 0 auto 2.5rem;
        }
        .progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: .5rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: #4b5563;
            margin-bottom: .5rem;
        }
        .progress-track {
            height: 4px;
            background: rgba(255, 255, 255, .04);
            border-radius: 99px;
            overflow: hidden;
            position: relative;
        }
        .progress-fill {
            height: 100%;
            width: 0%;
            border-radius: 99px;
            background: linear-gradient(90deg, #ec4899, #a855f7, #3b82f6);
            background-size: 200% 100%;
            animation: fillProgress 2.5s ease-out forwards, shimmer 2s ease-in-out infinite alternate;
        }
        @keyframes fillProgress {
            0% { width: 0%; }
            100% { width: 68%; }
        }
        @keyframes shimmer {
            0% { background-position: 0% 0%; }
            100% { background-position: 100% 0%; }
        }
        .progress-pct {
            font-size: .5rem;
            letter-spacing: .2em;
            color: #6b7280;
            margin-top: .4rem;
            display: block;
            text-align: right;
        }

        /* ── Feature Teasers ── */
        .teaser-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: .6rem;
            margin-bottom: 2.5rem;
        }
        .teaser-item {
            background: rgba(255, 255, 255, .025);
            border: 1px solid rgba(255, 255, 255, .04);
            border-radius: 1rem;
            padding: .85rem .5rem;
            transition: all .25s ease;
            cursor: default;
        }
        .teaser-item:hover {
            background: rgba(236, 72, 153, .04);
            border-color: rgba(236, 72, 153, .12);
            transform: translateY(-2px);
        }
        .teaser-item svg {
            width: 18px;
            height: 18px;
            margin: 0 auto .4rem;
            display: block;
            color: #6b7280;
            transition: color .25s;
        }
        .teaser-item:hover svg {
            color: #ec4899;
        }
        .teaser-item span {
            display: block;
            font-size: .55rem;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #6b7280;
            font-weight: 700;
            transition: color .25s;
        }
        .teaser-item:hover span {
            color: #d1d5db;
        }

        /* ── Back Button ── */
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: .6rem;
            padding: .85rem 2rem;
            border-radius: 999px;
            font-family: var(--font);
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .2em;
            text-transform: uppercase;
            color: #9ca3af;
            background: rgba(255, 255, 255, .03);
            border: 1px solid rgba(255, 255, 255, .06);
            text-decoration: none;
            transition: all .25s ease;
        }
        .btn-back:hover {
            color: #f0f2f7;
            background: rgba(236, 72, 153, .08);
            border-color: rgba(236, 72, 153, .2);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(236, 72, 153, .12);
        }
        .btn-back:active {
            transform: translateY(0);
        }
        .btn-back svg {
            width: 14px;
            height: 14px;
            transition: transform .2s;
        }
        .btn-back:hover svg {
            transform: translateX(-3px);
        }

        /* ── Footer ── */
        .footer-note {
            position: relative;
            z-index: 1;
            font-size: .5rem;
            letter-spacing: .4em;
            text-transform: uppercase;
            color: #374151;
            margin-top: 1rem;
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .glass-card {
                padding: 2.5rem 1.5rem;
                margin: 1rem;
                border-radius: 1.5rem;
            }
            .teaser-grid {
                grid-template-columns: 1fr;
                gap: .5rem;
            }
            .teaser-item {
                display: flex;
                align-items: center;
                gap: .75rem;
                padding: .65rem 1rem;
            }
            .teaser-item svg {
                margin: 0;
                flex-shrink: 0;
            }
            .icon-ring {
                width: 72px;
                height: 72px;
            }
            .icon-ring svg {
                width: 28px;
                height: 28px;
            }
        }

        @media (min-width: 481px) and (max-width: 768px) {
            .teaser-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body>

    <!-- Floating Particles (generated by JS) -->
    <div class="particles" id="particles"></div>

    <!-- Gradient Orbs -->
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <!-- Main Card -->
    <main class="glass-card" role="main" aria-labelledby="coming-title">
        <!-- Animated Icon -->
        <div class="icon-ring">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
            </svg>
        </div>

        <!-- Status -->
        <div class="status-badge">
            <span class="status-dot"></span>
            Dalam Pengembangan
        </div>

        <!-- Title -->
        <h1 id="coming-title" class="coming-title">ANIME</h1>
        <p class="coming-sub">— Coming Soon —</p>

        <!-- Description -->
        <p class="coming-desc">
            <strong>MEeL Anime</strong> sedang dalam tahap pengembangan.
            Kami menyiapkan pengalaman streaming anime terbaik
            dengan koleksi eksklusif dan player khusus.
        </p>

        <!-- Progress -->
        <div class="progress-wrap">
            <div class="progress-labels">
                <span>Progress</span>
                <span>68%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill"></div>
            </div>
            <span class="progress-pct">Frontend selesai · Backend dalam pengerjaan</span>
        </div>

        <!-- Teaser Features -->
        <div class="teaser-grid">
            <div class="teaser-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                    <line x1="8" y1="21" x2="16" y2="21"/>
                    <line x1="12" y1="17" x2="12" y2="21"/>
                </svg>
                <span>Streaming HD</span>
            </div>
            <div class="teaser-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>
                </svg>
                <span>Koleksi Eksklusif</span>
            </div>
            <div class="teaser-item">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
                <span>Episode Tracker</span>
            </div>
        </div>

        <!-- Back Button -->
        <a href="../index.php" class="btn-back">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Kembali ke Hub
        </a>
    </main>

    <p class="footer-note">MEeL · 2025</p>

    <script>
        // ── Generate Floating Particles ──
        (function() {
            const container = document.getElementById('particles');
            if (!container) return;
            const count = window.innerWidth < 480 ? 20 : 40;
            for (let i = 0; i < count; i++) {
                const p = document.createElement('div');
                p.className = 'particle';
                const size = Math.random() * 3 + 1;
                p.style.width = size + 'px';
                p.style.height = size + 'px';
                p.style.left = Math.random() * 100 + '%';
                p.style.animationDuration = (Math.random() * 20 + 15) + 's';
                p.style.animationDelay = (Math.random() * 20) + 's';
                p.style.opacity = Math.random() * .3 + .05;
                container.appendChild(p);
            }
        })();
    </script>

</body>
</html>
