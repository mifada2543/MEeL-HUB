<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'auth/config.php';

$sidebar_data   = $conn->query("SELECT * FROM sidebar_settings WHERE id = 1")->fetch_assoc();
$result_updates = $conn->query("SELECT * FROM updates ORDER BY created_at DESC");

$is_logged_in = isset($_SESSION['user_id']);
$is_admin     = ($is_logged_in && isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Changelog</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/htmx.js"></script>
    <script src="assets/js/lucide.js"></script>
    <style>
        :root {
            /* ── FONT STACK — tanpa Google Fonts API ──────────────────────────
             * --font-display : Impact/Haettenschweiler hadir di semua OS,
             *   memberikan karakter condensed bold. macOS/iOS fallback ke
             *   "Arial Narrow Bold" / "Arial Black".
             * --font-mono    : ui-monospace → SFMono (macOS) → Menlo (macOS lama)
             *   → Consolas (Windows) → Liberation Mono (Linux).
             * --font-serif   : Georgia hadir di semua OS sebagai serif elegan,
             *   digunakan untuk label italic pada entry card.
             * ────────────────────────────────────────────────────────────── */
            --font-display : Impact, Haettenschweiler, "Arial Narrow Bold", "Arial Black", sans-serif;
            --font-mono    : ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            --font-serif   : Georgia, "Times New Roman", Times, serif;

            --bg:      #080a0f;
            --surface: #0d1017;
            --border:  rgba(255,255,255,0.06);
            --orange:  #f97316;
            --blue:    #60a5fa;
            --text:    #c8cdd8;
            --muted:   #4a5166;
            --white:   #f0f2f7;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-mono);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ── NOISE TEXTURE ── */
        body::before {
            content: '';
            position: fixed; inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.03'/%3E%3C/svg%3E");
            pointer-events: none; z-index: 0; opacity: .4;
        }

        /* ── SCANLINE ACCENT ── */
        body::after {
            content: '';
            position: fixed; top: 0; left: 0; right: 0; height: 3px;
            background: linear-gradient(90deg, transparent, var(--orange), transparent);
            z-index: 100;
            animation: scanline-glow 4s ease-in-out infinite;
        }
        @keyframes scanline-glow {
            0%, 100% { opacity: .3; }
            50%       { opacity: 1; }
        }

        /* ── LAYOUT ── */
        .wrap { max-width: 1280px; margin: 0 auto; padding: 2rem 1.5rem; position: relative; z-index: 1; }

        /* ── MASTHEAD ── */
        .masthead {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 2rem;
            border-bottom: 1px solid var(--border);
            padding-bottom: 2rem;
            margin-bottom: 3rem;
        }
        .masthead-logo {
            width: 52px; height: 52px;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            display: flex; align-items: center; justify-content: center;
            background: var(--surface);
            transition: border-color .3s;
        }
        .masthead-logo:hover { border-color: var(--orange); }
        .masthead-logo img { width: 36px; object-fit: contain; }

        .masthead-title {
            font-family: var(--font-display);
            font-size: clamp(2rem, 5vw, 3.5rem);
            letter-spacing: .05em;
            color: var(--white);
            line-height: 1;
        }
        .masthead-title span { color: var(--orange); }

        .masthead-sub {
            font-family: var(--font-mono);
            font-size: .6rem;
            letter-spacing: .3em;
            color: var(--muted);
            text-transform: uppercase;
            margin-top: .35rem;
        }
        .masthead-meta {
            text-align: right;
            font-family: var(--font-mono);
            font-size: .6rem;
            letter-spacing: .15em;
            color: var(--muted);
            text-transform: uppercase;
            line-height: 1.8;
        }

        /* ── ADMIN BAR ── */
        .admin-bar {
            display: flex; align-items: center; gap: .75rem; flex-wrap: wrap;
            background: rgba(249,115,22,.04);
            border: 1px solid rgba(249,115,22,.15);
            border-left: 3px solid var(--orange);
            border-radius: 12px;
            padding: .75rem 1.25rem;
            margin-bottom: 2.5rem;
            animation: slide-in .4s ease-out;
        }
        .admin-badge {
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .2em; text-transform: uppercase;
            color: var(--orange);
            display: flex; align-items: center; gap: .4rem;
        }
        .admin-btn {
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .12em; text-transform: uppercase;
            padding: .45rem .9rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: rgba(255,255,255,.04);
            color: var(--text);
            cursor: pointer;
            display: flex; align-items: center; gap: .4rem;
            transition: all .2s;
        }
        .admin-btn:hover {
            background: rgba(255,255,255,.08);
            border-color: rgba(255,255,255,.15);
            color: var(--white);
        }

        /* ── MAIN GRID ── */
        .main-grid {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 3rem;
            align-items: start;
        }
        @media (max-width: 900px) {
            .main-grid { grid-template-columns: 1fr; }
            .masthead { grid-template-columns: auto 1fr; }
            .masthead-meta { display: none; }
        }

        /* ── ENTRY CARD ── */
        .entry {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 2rem 2.25rem;
            margin-bottom: 1.25rem;
            position: relative; overflow: hidden;
            transition: border-color .25s, transform .25s;
            animation: slide-in .5s ease-out both;
        }
        .entry:hover { border-color: rgba(249,115,22,.25); transform: translateY(-2px); }
        .entry::before {
            content: '';
            position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
            background: linear-gradient(180deg, var(--orange), transparent);
            border-radius: 3px 0 0 3px;
            opacity: 0; transition: opacity .25s;
        }
        .entry:hover::before { opacity: 1; }

        .entry-header {
            display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem;
            margin-bottom: 1.25rem; padding-bottom: 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .entry-version {
            font-family: var(--font-display);
            font-size: 1.05rem; letter-spacing: .12em;
            color: var(--bg); background: var(--orange);
            padding: .2rem .65rem .15rem; border-radius: 6px; line-height: 1.2;
        }
        .entry-label {
            /* Georgia italic — elegan tanpa butuh web font eksternal */
            font-family: var(--font-serif);
            font-style: italic;
            font-size: 1.2rem;
            color: var(--white);
        }
        .entry-date {
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .2em; text-transform: uppercase;
            color: var(--muted); white-space: nowrap; padding-top: .25rem;
        }
        .entry-body {
            font-family: var(--font-mono);
            font-size: .8rem; line-height: 1.85; color: var(--text);
        }

        /* ── SIDEBAR ── */
        .sidebar { position: sticky; top: 2rem; }

        .sidebar-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 20px; overflow: hidden; margin-bottom: 1rem;
        }
        .tab-nav {
            display: grid; grid-template-columns: 1fr 1fr;
            border-bottom: 1px solid var(--border);
        }
        .tab-btn {
            padding: .9rem;
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .18em; text-transform: uppercase;
            border: none; background: transparent; cursor: pointer;
            display: flex; align-items: center; justify-content: center; gap: .4rem;
            color: var(--muted);
            border-bottom: 2px solid transparent;
            transition: all .2s;
        }
        .tab-btn.active-orange { color: var(--orange); border-bottom-color: var(--orange); background: rgba(249,115,22,.05); }
        .tab-btn.active-blue   { color: var(--blue);   border-bottom-color: var(--blue);   background: rgba(96,165,250,.05); }

        .tab-pane {
            padding: 1.5rem;
            font-family: var(--font-mono);
            font-size: .78rem; line-height: 1.8;
            display: none;
        }
        .tab-pane.active { display: block; animation: slide-in .3s ease-out; }
        .tab-pane-label {
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .2em; text-transform: uppercase; font-weight: 500;
            margin-bottom: .75rem;
            display: flex; align-items: center; gap: .4rem;
        }

        /* ── CHECK VERSION BUTTON ── */
        .check-btn {
            width: 100%;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .8rem;
            background: rgba(255,255,255,.03);
            border: 1px solid var(--border); border-radius: 12px;
            color: var(--muted);
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .18em; text-transform: uppercase;
            cursor: pointer; transition: all .2s;
        }
        .check-btn:hover {
            background: rgba(249,115,22,.08);
            border-color: rgba(249,115,22,.3);
            color: var(--orange);
        }

        /* ── MODAL ── */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,.85);
            backdrop-filter: blur(8px);
            z-index: 50; display: none;
            align-items: center; justify-content: center;
            padding: 1rem;
        }
        .modal-backdrop.open { display: flex; }
        .modal-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-top: 2px solid var(--orange);
            border-radius: 20px; padding: 2rem;
            width: 100%; max-width: 520px;
            animation: modal-in .25s ease-out;
            position: relative; z-index: 51;
        }
        @keyframes modal-in {
            from { opacity: 0; transform: translateY(16px) scale(.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }
        .modal-title {
            font-family: var(--font-display);
            font-size: 1.6rem; letter-spacing: .08em;
            color: var(--white); margin-bottom: 1.5rem;
        }
        .modal-title span { color: var(--orange); }

        /* ── FORM ELEMENTS ── */
        .f-label {
            display: block;
            font-family: var(--font-mono);
            font-size: .6rem; letter-spacing: .2em; text-transform: uppercase;
            color: var(--muted); margin-bottom: .4rem;
        }
        .f-input, .f-textarea {
            width: 100%;
            background: rgba(0,0,0,.3);
            border: 1px solid var(--border); border-radius: 10px;
            padding: .75rem 1rem;
            color: var(--white);
            font-family: var(--font-mono);
            font-size: .8rem;
            transition: border-color .2s; resize: vertical;
        }
        .f-input:focus, .f-textarea:focus {
            outline: none; border-color: var(--orange); background: rgba(0,0,0,.5);
        }
        .f-input:disabled { color: var(--muted); cursor: not-allowed; }

        .btn-primary {
            background: var(--orange); color: var(--bg);
            font-family: var(--font-display);
            font-size: .95rem; letter-spacing: .12em;
            padding: .75rem 1.5rem; border-radius: 10px;
            border: none; cursor: pointer;
            transition: opacity .2s, transform .15s; flex: 1;
        }
        .btn-primary:hover { opacity: .85; transform: translateY(-1px); }

        .btn-ghost {
            background: rgba(255,255,255,.05); color: var(--muted);
            font-family: var(--font-mono);
            font-size: .7rem; letter-spacing: .12em; text-transform: uppercase;
            padding: .75rem 1.25rem; border-radius: 10px;
            border: 1px solid var(--border); cursor: pointer; transition: all .2s;
        }
        .btn-ghost:hover { background: rgba(255,255,255,.08); color: var(--white); }

        /* ── EMPTY STATE ── */
        .empty {
            text-align: center; padding: 4rem 2rem;
            color: var(--muted);
            font-family: var(--font-mono);
            font-size: .75rem; letter-spacing: .1em; text-transform: uppercase;
        }

        /* ── ANIMATIONS ── */
        @keyframes slide-in {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .entry:nth-child(1) { animation-delay: .05s; }
        .entry:nth-child(2) { animation-delay: .10s; }
        .entry:nth-child(3) { animation-delay: .15s; }
        .entry:nth-child(4) { animation-delay: .20s; }
        .entry:nth-child(5) { animation-delay: .25s; }
    </style>
</head>
<body>
<div class="wrap">

    <!-- MASTHEAD -->
    <header class="masthead">
        <a href="index.php" class="masthead-logo" title="Beranda">
            <img src="assets/logo.png" alt="MEeL">
        </a>
        <div>
            <div class="masthead-title">CHANGELOG <span>&</span> UPDATES</div>
            <div class="masthead-sub">Catatan Perubahan Sistem MEeL</div>
        </div>
        <div class="masthead-meta">
            <div><?= date('d M Y') ?></div>
            <div>MEeL Platform</div>
            <div style="color:var(--orange)">RELEASE NOTES</div>
        </div>
    </header>

    <!-- ADMIN BAR -->
    <?php if ($is_admin): ?>
    <div class="admin-bar">
        <span class="admin-badge">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Admin Mode
        </span>
        <button class="admin-btn" onclick="openModal('modal-add-update')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#4ade80" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Tambah Update
        </button>
        <button class="admin-btn" onclick="openModal('modal-edit-sidebar')">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Sidebar
        </button>
    </div>
    <?php endif; ?>

    <!-- MAIN GRID -->
    <div class="main-grid">

        <!-- FEED -->
        <main>
            <?php if ($result_updates && $result_updates->num_rows > 0): ?>
                <?php while ($row = $result_updates->fetch_assoc()): ?>
                <article class="entry">
                    <div class="entry-header">
                        <div style="display:flex;align-items:center;gap:.75rem">
                            <span class="entry-version"><?= htmlspecialchars($row['version']) ?></span>
                            <span class="entry-label">System Update</span>
                        </div>
                        <span class="entry-date"><?= date('d M Y', strtotime($row['created_at'])) ?></span>
                    </div>
                    <div class="entry-body">
                        <?= nl2br(htmlspecialchars($row['content'])) ?>
                    </div>
                </article>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty">Belum ada catatan update tersedia.</div>
            <?php endif; ?>
        </main>

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <div class="sidebar-card">
                <div class="tab-nav">
                    <button class="tab-btn active-orange" id="btn-important" onclick="switchTab('important')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Penting
                    </button>
                    <button class="tab-btn" id="btn-announcement" onclick="switchTab('announcement')">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 8.5c0 2.485-4.477 4.5-10 4.5S2 10.985 2 8.5 6.477 4 12 4s10 2.015 10 4.5z"/><path d="M2 8.5c0 4.694 3.582 8.757 8.5 9.5"/><path d="m15 13-3 9"/></svg>
                        Pengumuman
                    </button>
                </div>

                <div id="pane-important" class="tab-pane active">
                    <div class="tab-pane-label" style="color:var(--orange)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Informasi Penting
                    </div>
                    <?= $sidebar_data['important_content'] ?? '<span style="color:var(--muted);font-size:.75rem">Tidak ada konten penting.</span>' ?>
                </div>

                <div id="pane-announcement" class="tab-pane">
                    <div class="tab-pane-label" style="color:var(--blue)">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 8.5c0 2.485-4.477 4.5-10 4.5S2 10.985 2 8.5 6.477 4 12 4s10 2.015 10 4.5z"/><path d="M2 8.5c0 4.694 3.582 8.757 8.5 9.5"/><path d="m15 13-3 9"/></svg>
                        Pengumuman
                    </div>
                    <?= $sidebar_data['announcement_content'] ?? '<span style="color:var(--muted);font-size:.75rem">Tidak ada pengumuman.</span>' ?>
                </div>
            </div>

            <button class="check-btn" hx-get="api/check_update.php" hx-swap="outerHTML">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.08-4.49"/></svg>
                Cek Versi Terbaru
            </button>
        </aside>
    </div>
</div>

<!-- ══════════ MODALS ══════════ -->
<?php if ($is_admin): ?>

<!-- Modal: Tambah Update -->
<div id="modal-add-update" class="modal-backdrop" onclick="handleBackdropClick(event, 'modal-add-update')">
    <div class="modal-box">
        <div class="modal-title">TAMBAH <span>UPDATE</span></div>
        <form action="proses_update.php" method="POST">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem">
                <div>
                    <label class="f-label">Versi</label>
                    <input type="text" name="version" class="f-input" placeholder="v1.4.0" required>
                </div>
                <div>
                    <label class="f-label">Tanggal</label>
                    <input type="text" class="f-input" value="<?= date('d M Y') ?>" disabled>
                    <input type="hidden" name="created_at" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div style="margin-bottom:1.5rem">
                <label class="f-label">Catatan Perubahan</label>
                <textarea name="content" class="f-textarea" rows="6" placeholder="- Fitur baru&#10;- Perbaikan bug&#10;- Peningkatan performa" required></textarea>
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn-primary">PUBLIKASIKAN</button>
                <button type="button" class="btn-ghost" onclick="closeModal('modal-add-update')">Batal</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Sidebar -->
<div id="modal-edit-sidebar" class="modal-backdrop" onclick="handleBackdropClick(event, 'modal-edit-sidebar')">
    <div class="modal-box">
        <div class="modal-title">EDIT <span>SIDEBAR</span></div>
        <form action="proses_sidebar.php" method="POST">
            <div style="margin-bottom:1rem">
                <label class="f-label" style="color:var(--orange)">Konten Penting</label>
                <textarea name="important" class="f-textarea" rows="4"><?= htmlspecialchars($sidebar_data['important_content'] ?? '') ?></textarea>
            </div>
            <div style="margin-bottom:1.5rem">
                <label class="f-label" style="color:var(--blue)">Pengumuman</label>
                <textarea name="announcement" class="f-textarea" rows="4"><?= htmlspecialchars($sidebar_data['announcement_content'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn-primary">SIMPAN PERUBAHAN</button>
                <button type="button" class="btn-ghost" onclick="closeModal('modal-edit-sidebar')">Batal</button>
            </div>
        </form>
    </div>
</div>

<?php endif; ?>

<script>
    lucide.createIcons();

    function openModal(id) {
        document.getElementById(id).classList.add('open');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
        document.body.style.overflow = '';
    }
    function handleBackdropClick(e, id) {
        if (e.target === document.getElementById(id)) closeModal(id);
    }
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
        }
    });

    function switchTab(t) {
        const panes = { important: document.getElementById('pane-important'), announcement: document.getElementById('pane-announcement') };
        const btns  = { important: document.getElementById('btn-important'),  announcement: document.getElementById('btn-announcement') };
        Object.entries(panes).forEach(([k, el]) => el.classList.toggle('active', k === t));
        btns.important.className    = 'tab-btn' + (t === 'important'    ? ' active-orange' : '');
        btns.announcement.className = 'tab-btn' + (t === 'announcement' ? ' active-blue'   : '');
    }
</script>
</body>
</html>