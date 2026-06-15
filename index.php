<?php
session_name('meel');
session_start();
include 'auth/config.php';
require_once 'modules/MediaLibrary.php';

$is_logged_in = isset($_SESSION['user_id']);

$library = new MediaLibrary($conn);
$counts  = $library->getCounts();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <title>MEeL | Media Hub</title>
    <link rel="manifest" href="assets/manifest.json">
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <link rel="stylesheet" href="assets/css/index(hub).css">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/lucide.js"></script>
    <script src="assets/js/sweetalert2.all.min.js"></script>
    <script src="assets/js/script.js"></script>
</head>

<body class="text-gray-400 min-h-screen" style="background:#05070c">

    <!-- NAVBAR -->
    <?php include 'partials/navbar.php'; ?>

    <div class="relative z-10 max-w-6xl mx-auto px-6 pt-32 pb-20 flex flex-col items-center">

        <!-- HERO -->
        <div class="text-center mb-20">
            <div class="inline-block mb-6">
                <img onclick="window.location.href='arcade/'" src="assets/MEeL.png" class="w-14 h-14 object-contain mx-auto opacity-80 hover:opacity-100 transition" alt="MEeL" title="MEeL Arcade">
            </div>
            <div class="station-id mb-5">Local Media Station</div>
            <h1 class="hero-title">MEeL <span class="accent">HUB</span></h1>
            <p class="text-xs text-gray-600 mt-4 tracking-[.25em] uppercase">Streaming &amp; Archive Platform</p>
        </div>

        <!-- MEDIA CARDS -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5 w-full mb-20">

            <!-- VIDEO -->
            <div class="media-card card-video flex flex-col gap-4 h-48 md:h-64"
                onclick="window.location.href='video/index.php'"
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

            <!-- MUSIC (diperbesar di tengah) -->
            <div class="media-card card-music flex flex-col gap-4 h-56 md:h-72 md:-mt-4"
                onclick="window.location.href='music/index.php'"
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
                    <div class="card-desc">Audio lossless. Kualitas terbaik.</div>
                </div>
                <div class="flex justify-end">
                    <div class="card-arrow">
                        <i data-lucide="arrow-right" class="w-4 h-4" style="color:#9ca3af"></i>
                    </div>
                </div>
            </div>

            <!-- BOOKS -->
            <?php if ($is_logged_in): ?>
                <div class="media-card card-books flex flex-col gap-4 h-48 md:h-64"
                    onclick="window.location.href='books/index.php'"
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

        <!-- BOTTOM LINKS -->
        <div class="flex flex-wrap items-center justify-center gap-3">
            <?php if ($is_logged_in && isset($_SESSION['role'])): ?>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                    <a href="admin/index.php" class="bottom-link">
                        <i data-lucide="settings" class="w-3 h-3"></i> Admin Panel
                    </a>
                    <a href="upload_advanced.php" class="bottom-link">
                        <i data-lucide="upload-cloud" class="w-3 h-3"></i> Upload Media
                    </a>
                <?php endif; ?>
                <?php if (in_array($_SESSION['role'], ['member', 'admin'])): ?>
                    <a href="drive/index.php" class="bottom-link">
                        <i data-lucide="hard-drive" class="w-3 h-3"></i> Drive
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="update.php" class="bottom-link">
                <i data-lucide="radio" class="w-3 h-3"></i> Changelog
            </a>
        </div>

        <!-- MODE SEHAT -->
        <div class="mt-10 flex items-center gap-3">
            <span class="text-[10px] text-gray-700 uppercase tracking-widest">Mode 20-20-20</span>
            <button id="healthToggle"
                class="px-3 py-1 rounded-full text-[10px] font-bold border border-white/5 text-gray-700 hover:text-white transition-all"
                title="Mode Sehat">
                OFF
            </button>
        </div>
        <p class="text-[9px] text-gray-800 tracking-[0.6em] uppercase mt-8" onclick="window.location.href='index.html'">MEeL • 2025</p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>