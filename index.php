<?php
require_once 'modules/core/helpers.php';
require_once 'modules/core/Modules.php';
meel_boot_session();
include 'auth/config.php';
require_once 'modules/media/MediaLibrary.php';

$is_logged_in = isset($_SESSION['user_id']);

$library = new MediaLibrary($conn);
$counts  = $library->getCounts();
?>
<!DOCTYPE html>
<html lang="id">

<head>
<?php
    $_META_TITLE = 'MEeL | Media Hub';
    $_META_DESC  = 'MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.';
    include 'partials/head.php';
?>
    <link rel="stylesheet" href="assets/css/index(hub).css">
    <link href="assets/css/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/shared/theme-tokens.css?v=<?= @filemtime(__DIR__ . '/assets/css/shared/theme-tokens.css') ?>">
    <link rel="stylesheet" href="assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/assets/css/shared/light-theme.css') ?>">
    <link rel="stylesheet" href="assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/assets/css/shared/light-theme.css') ?>">
    <script src="assets/js/compatibilitas/lucide.js"></script>
    <script src="assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="assets/js/compatibilitas/script.min.js"></script>
    <script src="assets/js/shared/state-keys.js?v=<?= filemtime(__DIR__ . '/assets/js/shared/state-keys.js') ?>"></script>
    <script src="assets/js/shared/health-reminder.js?v=<?= filemtime(__DIR__ . '/assets/js/shared/health-reminder.js') ?>"></script>
    <script src="assets/js/shared/theme.js?v=<?= @filemtime(__DIR__ . '/assets/js/shared/theme.js') ?>"></script>
    <div id="index-data" data-logged-in="<?= $is_logged_in ? '1' : '0' ?>" data-csrf-token="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>" style="display:none;"></div>
</head>

<body class="text-gray-300 min-h-screen" style="background:#05070c">

    
    <?php include 'partials/navbar.php'; ?>
    <main class="relative z-10 max-w-6xl mx-auto px-6 pt-32 pb-20 flex flex-col items-center">

        
        <div class="text-center mb-20">
            <div class="inline-block mb-6">
                <?php if (Modules::enabled('arcade')): ?>
                    <!-- Arcade opsional: link hanya dirender saat modul aktif -->
                    <img onclick="window.location.href='arcade/'" src="assets/MEeL.png" class="w-14 h-14 object-contain mx-auto opacity-80 hover:opacity-100 transition cursor-pointer" alt="MEeL" title="MEeL Arcade">
                <?php else: ?>
                    <img src="assets/MEeL.png" class="w-14 h-14 object-contain mx-auto opacity-80" alt="MEeL" title="MEeL">
                <?php endif; ?>
            </div>
            <div class="station-id mb-5">Local Media Station</div>
            <h1 class="hero-title">MEeL <span class="accent">HUB</span></h1>
            <p onclick="window.location.href='index.html'" class="text-xs text-gray-400 mt-4 tracking-[.25em] uppercase">Streaming &amp; Archive Platform</p>
        </div>

        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 w-full mb-20">

            
            <div class="media-card card-music flex flex-col gap-4 md:h-64"
                onclick="window.location.href='music/beranda'"
                title="MEeL Music">
                <div class="flex items-start justify-between">
                    <div class="card-icon-wrap">
                        <i data-lucide="music" class="w-5 h-5" style="color:#f97316"></i>
                    </div>
                    <div class="text-right">
                        <div class="card-count" style="color:#f97316"><?= $counts['music'] ?></div>
                        <div class="card-label">Tracks</div>
                    </div>
                </div>
                <div class="mt-auto">
                    <div class="card-name">MUSIC</div>
                    <div class="card-desc">Audio tinggi dengan kualitas terbaik.</div>
                </div>
                <div class="flex justify-end">
                    <div class="card-arrow">
                        <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                    </div>
                </div>
            </div>

            
            <div class="media-card card-video flex flex-col gap-4 md:h-64"
                onclick="window.location.href='video/beranda'"
                title="MEeL Video" hx-boost="true">
                <div class="flex items-start justify-between">
                    <div class="card-icon-wrap">
                        <i data-lucide="play" class="w-5 h-5" style="color:#dc2626"></i>
                    </div>
                    <div class="text-right">
                        <div class="card-count" style="color:#dc2626"><?= $counts['video'] ?></div>
                        <div class="card-label">Clips</div>
                    </div>
                </div>
                <div class="mt-auto">
                    <div class="card-name">VIDEO</div>
                    <div class="card-desc">Streaming lokal koleksi video.</div>
                </div>
                <div class="flex justify-end">
                    <div class="card-arrow">
                        <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                    </div>
                </div>
            </div>

            
            <?php if ($is_logged_in): ?>
                <div class="media-card card-books flex flex-col gap-4 md:h-64"
                    onclick="window.location.href='books/beranda'"
                    title="MEeL Books">
                    <div class="flex items-start justify-between">
                        <div class="card-icon-wrap">
                            <i data-lucide="book-open" class="w-5 h-5" style="color:#22c55e"></i>
                        </div>
                        <div class="text-right">
                            <div class="card-count" style="color:#22c55e"><?= $counts['books'] ?></div>
                            <div class="card-label">Books</div>
                        </div>
                    </div>
                    <div class="mt-auto">
                        <div class="card-name">BOOKS</div>
                        <div class="card-desc">Komik dan buku digital.</div>
                    </div>
                    <div class="flex justify-end">
                        <div class="card-arrow">
                            <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        
        <div class="flex flex-wrap items-center justify-center gap-3">
            <?php if ($is_logged_in && isset($_SESSION['role'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admin/beranda" class="bottom-link" title="Panel Admin untuk mengelola konten dan pengguna">
                        <i data-lucide="settings" class="w-3 h-3"></i> Admin Panel
                    </a>
                    <a href="upload" class="bottom-link" title="Unggah media baru ke platform">
                        <i data-lucide="upload-cloud" class="w-3 h-3"></i> Upload Media
                    </a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['member', 'admin'])): ?>
                    <a href="drive/beranda" class="bottom-link" title="Akses drive Anda untuk mengelola file dan dokumen">
                        <i data-lucide="hard-drive" class="w-3 h-3"></i> Drive
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="update" class="bottom-link" title="Lihat perubahan terbaru dan pembaruan platform">
                <i data-lucide="radio" class="w-3 h-3"></i> Changelog
            </a>
            <a href="docs/index.html" class="bottom-link" title="Lihat dokumentasi platform">
                <i data-lucide="book-open" class="w-3 h-3"></i> Documentation
            </a>
        </div>

        
        <div class="mt-10 flex items-center gap-3">
            <span class="text-[10px] text-gray-200 uppercase tracking-widest">Mode 20-20-20</span>
            <button id="healthToggle"
                class="px-3 py-1 rounded-full text-[10px] font-bold border border-white/5 text-gray-300 hover:text-white transition-all"
                title="Mode Sehat"
                aria-label="Aktifkan atau nonaktifkan mode sehat 20-20-20">
                OFF
            </button>
        </div>
        <?php include 'partials/footer.php'; ?>
    </main>

    
    <div id="demoBanner" class="demo-banner" role="alert" aria-label="Pemberitahuan website demo">
        <div class="demo-banner-inner">
            <div class="demo-banner-left">
                <span class="demo-badge">⚠️ DEMO</span>
                <span class="demo-banner-text">
                    <strong>Website Demo</strong> — data, konten, dan pengguna di sini bersifat <u title="diperuntukan untuk penggunaan pribadi">tidak nyata</u>. Hanya untuk keperluan showcase &amp; uji coba.
                </span>
            </div>
            <button id="demoBannerClose" class="demo-banner-close" title="Tutup banner" aria-label="Tutup pemberitahuan">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        </div>
    </div>

    <script src="assets/js/shared/index-hub.js?v=<?= filemtime(__DIR__ . '/assets/js/shared/index-hub.js') ?>"></script>
</body>

</html>
