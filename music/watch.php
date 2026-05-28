<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_name('meel');
session_start();

include '../auth/config.php';
require_once '../modules/helpers.php';
include '../modules/MediaViewer.php';

$id          = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id     = $_SESSION['user_id'] ?? null;
$is_logged_in = isset($_SESSION['user_id']);
$playlist_id = isset($_GET['playlist_id']) ? (int)$_GET['playlist_id'] : 0;

$viewer = new MediaViewer($conn, $user_id, 'music', $id);
$viewer->recordView();

if ($is_logged_in && isset($_POST['send'])) {
    if ($viewer->addComment($_POST)) {
        header("Location: watch.php?id=$id&playlist_id=$playlist_id#comment-section");
        exit;
    }
}

$v = $viewer->getMediaData();
if (!$v) {
    header('Location: index.php');
    exit;
}

$user_interaction = $viewer->getUserInteraction();
$comments_data    = $viewer->getComments();
$comments_grouped = $comments_data['grouped'];
$user_map         = $comments_data['user_map'];
$rekom            = $viewer->getRecommendations(15);
$playlist_data    = $viewer->getPlaylistQueue($playlist_id);
$queue_query      = $playlist_data['queue']    ?? null;
$next_url         = $playlist_data['next_url'] ?? "";
$playlist_context = $playlist_id;
$next_song_url    = $next_url;
$file_size_bytes  = !empty($v['filename'])
    ? (@filesize(__DIR__ . "/upload/file/" . $v['filename']) ?: 0) : 0;

$ext       = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
$fmt_label = strtoupper($ext === 'ogg' ? 'OPUS' : $ext);
switch ($ext) {
    case 'ogg':
    case 'opus':
        $fmt_label = 'OPUS';
        $deskripsi = "Opus adalah codec audio modern untuk web";
        break;
    case 'm4a':
        $fmt_label = 'M4A';
        $deskripsi = "M4a adalah codec audio terbaik dalam hal kompatibilitas";
        break;
    case 'mp3':
        $fmt_label = 'MP3';
        $deskripsi = "Ini adalah codec audio universal yang sangat populer";
        break;
    case 'flac':
        $fmt_label = 'FLAC';
        $deskripsi = "Ini adalah codec audio yang memiliki kualitas audio terbaik";
        break;
    default:
        $fmt_label = strtoupper($ext);
        $deskripsi = "Format audio tidak dikenal";
        break;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <!-- [FIX MOBILE] initial-scale=1 -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($v['title']) ?> — MEeL Music</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link rel="stylesheet" href="../assets/css/plyr.css">
    <link rel="stylesheet" href="../assets/css/music.css">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/htmx.js"></script>
    <style>
        :root {
            --font-display: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
        }

        #player-container {
            position: relative;
        }

        /* [FIX MOBILE] vinyl disc responsif */
        .vinyl-disc {
            width: clamp(120px, 35vw, 180px);
            height: clamp(120px, 35vw, 180px);
            border-radius: 50%;
            border: 8px solid #000;
            overflow: hidden;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, .08), 0 12px 32px rgba(0, 0, 0, .7);
            position: relative;
            flex-shrink: 0;
        }

        .vinyl-disc img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .vinyl-disc::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 20px;
            height: 20px;
            background: #000;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, .12);
        }

        /* [FIX MOBILE] track title font responsif */
        .track-title {
            font-family: var(--font-display);
            font-size: clamp(1.3rem, 6vw, 2rem);
            letter-spacing: .04em;
            color: #f0f2f7;
            line-height: 1.08;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            line-clamp: 3;
        }

        .rec-title-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-clamp: 2;
            overflow: hidden;
        }

        #cava-container {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 2px;
            min-height: 80px;
        }

        #resume-modal {
            position: absolute;
            inset: 0;
            z-index: 20;
            background: rgba(5, 7, 12, .85);
            backdrop-filter: blur(8px);
        }

        #resume-modal:not(.hidden) {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #playlist-modal {
            position: fixed;
            inset: 0;
            z-index: 100;
            padding: 1rem;
            background: rgba(0, 0, 0, .82);
            backdrop-filter: blur(10px);
        }

        #playlist-modal:not(.hidden) {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .comment-row {
            border-left: 2px solid transparent;
            transition: background .2s, border-color .2s;
        }

        .comment-row:hover {
            background: rgba(255, 255, 255, .025);
            border-left-color: #f97316;
        }

        .rekomendasi-item {
            border-left: 2px solid transparent;
            transition: background .2s, border-color .2s;
        }

        .rekomendasi-item:hover {
            background: rgba(255, 255, 255, .025);
            border-left-color: #f97316;
        }

        .rekomendasi-item:hover .rec-thumb-img {
            transform: scale(1.06);
        }

        /* === MINI PLAYER === */
        #mini-player {
            position: fixed;
            bottom: 0;
            right: 0;
            width: 320px;
            background: linear-gradient(135deg, #141820 0%, #0d1017 100%);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 12px 12px 0 0;
            z-index: 40;
            box-shadow: 0 -4px 24px rgba(0, 0, 0, .5);
            transform: translateY(100%);
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: none;
            flex-direction: column;
            max-height: 100vh;
        }

        #mini-player.active {
            display: flex;
            transform: translateY(0);
        }

        .mini-player-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            cursor: pointer;
            user-select: none;
        }

        .mini-player-thumbnail {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .08);
        }

        .mini-player-thumbnail img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mini-player-info {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .mini-player-title {
            font-size: 12px;
            font-weight: 600;
            color: #f0f2f7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mini-player-artist {
            font-size: 10px;
            color: #9ca3af;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .mini-player-close {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 4px;
            cursor: pointer;
            flex-shrink: 0;
            transition: all 0.2s;
            color: #6b7280;
        }

        .mini-player-close:hover {
            background: rgba(255, 255, 255, .1);
            color: #f0f2f7;
        }

        .mini-player-controls {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, .04);
        }

        .mini-player-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            background: rgba(249, 115, 22, .15);
            border: 1px solid rgba(249, 115, 22, .3);
            color: #f97316;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0;
        }

        .mini-player-btn:hover {
            background: rgba(249, 115, 22, .25);
        }

        .mini-player-btn svg {
            width: 18px;
            height: 18px;
        }

        .mini-player-progress {
            padding: 0 12px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .mini-progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, .08);
            border-radius: 2px;
            cursor: pointer;
            overflow: hidden;
        }

        .mini-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #f97316, #fb923c);
            border-radius: 2px;
            transition: width 0.1s linear;
        }

        .mini-progress-text {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #6b7280;
        }

        /* [FIX MOBILE] mini player full-width di mobile */
        @media (max-width: 640px) {
            #mini-player {
                width: 100%;
                right: 0;
                bottom: 0;
                border-radius: 12px 12px 0 0;
            }
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-5 h-14 flex items-center justify-between gap-3">

            <a href="index.php" class="flex items-center gap-2 flex-shrink-0" title="MEeL Music">
                <div class="w-7 h-7 bg-orange-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="music" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-sm font-bold tracking-tight text-white uppercase">
                    MEeL<span class="text-orange-500">Music</span>
                </span>
            </a>

            <!-- [FIX MOBILE] Search bar disembunyikan di layar kecil -->
            <div class="hidden sm:flex flex-1 max-w-sm items-center gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-orange-500 transition-colors"></i>
                    <input type="text"
                        id="m-search-watch"
                        name="search"
                        placeholder="Cari lagu atau artis..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-9 pr-4 text-xs focus:outline-none focus:border-orange-500/40 transition-all text-gray-300"
                        hx-get="search_music.php?exclude=<?= $id ?>"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#music-recommendation-column"
                        hx-indicator="#music-search-indicator"
                        autocomplete="off">
                    <div id="music-search-indicator" class="htmx-indicator absolute right-3.5 top-1/2 -translate-y-1/2">
                        <div class="animate-spin h-3 w-3 border-2 border-orange-500 border-t-transparent rounded-full"></div>
                    </div>
                </div>
                <button hx-get="search_music.php?exclude=<?= $id ?>"
                    hx-include="#m-search-watch"
                    hx-target="#music-recommendation-column"
                    hx-indicator="#music-search-indicator"
                    class="px-3 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase text-gray-500 hover:text-orange-500 hover:border-orange-500/30 transition-all flex-shrink-0">
                    Cari
                </button>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <a href="../video/index.php" class="hidden sm:flex items-center gap-1.5 text-gray-600 hover:text-red-500 transition-all">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i>
                    <span class="hidden md:inline">Video</span>
                </a>
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <!-- [FIX MOBILE] Layout grid responsive -->
    <div class="max-w-7xl mx-auto px-4 sm:px-5 pt-4 sm:pt-8 pb-20 grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">
        <div class="lg:col-span-2 space-y-5">
            <div id="player-container" class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden">
                <div id="resume-modal" class="hidden rounded-xl sm:rounded-2xl">
                    <div class="bg-[#141820] border border-orange-500/25 border-t-2 border-t-orange-500 rounded-2xl p-6 max-w-xs w-full mx-4 text-center">
                        <div class="w-11 h-11 bg-orange-500/10 rounded-full flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="play-circle" class="w-5 h-5 text-orange-500"></i>
                        </div>
                        <div class="text-sm font-black text-white uppercase tracking-wider mb-2">Lanjut Musik?</div>
                        <div class="text-[10px] text-gray-500 uppercase tracking-widest mb-4">
                            Menit ke‑ <span id="resume-time" class="text-orange-400 font-mono">0:00</span>
                        </div>
                        <div class="flex gap-2">
                            <button id="btn-resume"
                                class="flex-1 bg-orange-500 hover:bg-orange-400 text-black text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                Lanjut
                            </button>
                            <button id="btn-restart"
                                class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all">
                                Ulang
                            </button>
                        </div>
                    </div>
                </div>

                <!-- [FIX MOBILE] Vinyl + info: kolom di mobile, row di md+ -->
                <div class="flex flex-col sm:flex-row gap-5 p-4 sm:p-6 border-b border-white/[.04]">
                    <div class="flex-shrink-0 flex items-center justify-center sm:justify-start">
                        <div class="vinyl-spin vinyl-disc">
                            <img src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>" alt="cover" class="w-full h-full object-cover">
                        </div>
                    </div>

                    <div class="flex-1 min-w-0 flex flex-col justify-center gap-3">
                        <div>
                            <div class="track-title text-center sm:text-left" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></div>
                            <a href="index.php?artist=<?= urlencode($v['artist']) ?>"
                                class="text-orange-400 font-bold text-sm uppercase tracking-widest hover:underline block mt-2 truncate text-center sm:text-left">
                                <?= htmlspecialchars($v['artist']) ?>
                            </a>
                        </div>
                        <div class="flex items-center gap-2 flex-wrap justify-center sm:justify-start">
                            <span class="text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg bg-orange-500/10 border border-orange-500/20 text-orange-400"
                                title="<?= htmlspecialchars($deskripsi) ?>">
                                <?= $fmt_label ?>
                            </span>
                            <span class="text-[10px] font-bold px-3 py-1 rounded-lg bg-green-500/8 border border-green-500/15 text-green-400 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-green-400 animate-pulse"></span>
                                <span id="realtime-bitrate">0</span> kbps
                            </span>
                            <button id="btn-loop" onclick="toggleLoop()"
                                class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg border border-transparent transition-all cursor-pointer">
                                <i data-lucide="repeat" class="w-3 h-3"></i>
                                <span id="loop-text">Loop Off</span>
                            </button>
                            <button id="btn-vis" onclick="toggleVisualizer()"
                                class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider px-3 py-1 rounded-lg border border-transparent transition-all cursor-pointer">
                                <i data-lucide="activity" class="w-3 h-3"></i>
                                <span id="vis-text">Vis On</span>
                            </button>
                        </div>
                    </div>

                    <!-- Visualizer -->
                    <div id="cava-container" class="hidden flex-1 min-w-[160px] bg-black/20 border border-white/[.04] rounded-xl p-3 items-end justify-center gap-[2px] min-h-[80px]"></div>
                </div>

                <div class="p-4 sm:p-5">
                    <audio id="main-player" controls preload="metadata" class="w-full">
                        <source src="upload/file/<?= htmlspecialchars($v['filename']) ?>" type="audio/ogg">
                    </audio>
                </div>

                <!-- Uploader info + actions -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3 px-4 sm:px-6 py-4 border-t border-white/[.04] bg-black/10">
                    <div class="flex items-center gap-3">
                        <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                            class="w-9 h-9 rounded-full overflow-hidden border border-orange-500/25 flex-shrink-0 block">
                            <?php if (!empty($v['uploader_pfp'])): ?>
                                <img src="../profile/upload/<?= htmlspecialchars($v['uploader_pfp']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-white text-sm font-bold">
                                    <?= strtoupper(substr($v['uploader'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                                class="text-[10px] font-black uppercase tracking-widest text-orange-400 hover:underline block leading-tight">
                                <?= htmlspecialchars($v['uploader']) ?>
                            </a>
                            <div class="text-[10px] text-gray-600 mt-0.5">
                                <?= number_format($v['views'] ?? 0) ?> tayangan &nbsp;•&nbsp; <?= time_ago($v['upload_date']) ?>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['username'])): ?>
                        <div id="like-dislike-container" class="flex items-center gap-2 flex-wrap">
                            <button
                                hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                hx-vals='{"id":"<?= $id ?>","media_type":"music","type":"like"}'
                                class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                               <?= $user_interaction === 'like'
                                    ? 'bg-orange-500/15 border-orange-500/30 text-orange-400'
                                    : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
                                Like<?= ($v['likes'] ?? 0) > 0 ? " <span class='tabular-nums ml-0.5'>{$v['likes']}</span>" : '' ?>
                            </button>
                            <button
                                hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                hx-vals='{"id":"<?= $id ?>","media_type":"music","type":"dislike"}'
                                class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                               <?= $user_interaction === 'dislike'
                                    ? 'bg-white/10 border-white/15 text-white'
                                    : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
                                <?= ($v['dislikes'] ?? 0) > 0 ? "<span class='tabular-nums'>{$v['dislikes']}</span>" : '' ?>
                            </button>
                            <button onclick="document.getElementById('playlist-modal').classList.remove('hidden')"
                                class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300">
                                <i data-lucide="list-plus" class="w-3.5 h-3.5"></i> Simpan
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div> <?php if (!empty($v['description'])): ?>
                <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl p-4 sm:p-6">
                    <div class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600 mb-3 flex items-center gap-2">
                        <i data-lucide="align-left" class="w-3.5 h-3.5 text-orange-500"></i> Deskripsi
                    </div>
                    <p class="text-sm text-gray-400 leading-relaxed break-words whitespace-pre-wrap"><?= htmlspecialchars($v['description']) ?></p>
                </div>
            <?php endif; ?>
            <?php if ($is_logged_in): ?>
                <!-- KOMENTAR -->
                <section class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden" id="comment-section">
                    <div class="px-4 sm:px-6 py-4 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-3.5 h-3.5 text-orange-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600">Komentar</span>
                    </div>
                    <div class="p-4 sm:p-6">
                        <form action="watch.php?id=<?= $id ?>" method="post" class="mb-6">
                            <textarea name="comments"
                                class="w-full bg-black/25 border border-white/[.06] rounded-xl p-3 sm:p-4 text-sm text-gray-300 focus:outline-none focus:border-orange-500/40 min-h-[80px] resize-y transition-all"
                                placeholder="Tulis komentar..." required></textarea>
                            <div class="flex justify-end mt-2">
                                <button name="send"
                                    class="bg-orange-500 hover:bg-orange-400 text-black text-[10px] font-black uppercase tracking-wider px-5 py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                    Kirim
                                </button>
                            </div>
                        </form>

                        <div class="space-y-1 max-h-[500px] overflow-y-auto pr-1">
                            <?php
                            function render_music_comments($parent_id, $grouped, $level = 0)
                            {
                                global $id, $user_map;
                                if (!isset($grouped[$parent_id])) return;
                                foreach ($grouped[$parent_id] as $c):
                                    $author      = $c['username'] ?? 'Guest';
                                    $parent_user = ($c['parent_id'] > 0) ? ($user_map[$c['parent_id']] ?? 'Guest') : null;
                                    $indent      = min($level * 16, 48);
                            ?>
                                    <div class="comment-row flex gap-3 p-3 rounded-xl" style="margin-left:<?= $indent ?>px">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            <?= strtoupper(substr($author, 0, 1)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <span class="text-[11px] font-bold text-gray-300 truncate">@<?= htmlspecialchars($author) ?></span>
                                                    <span class="text-[10px] text-gray-600 flex-shrink-0"><?= time_ago($c['created_at']) ?></span>
                                                </div>
                                                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $c['user_id']): ?>
                                                    <a href="../delete_comment.php?id=<?= $c['id'] ?>"
                                                        onclick="return confirm('Hapus komentar ini?')"
                                                        class="text-gray-600 hover:text-red-400 transition-colors no-underline flex-shrink-0">
                                                        <i data-lucide="trash-2" class="w-3 h-3"></i>
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-sm text-gray-400 leading-relaxed">
                                                <?php if ($parent_user): ?>
                                                    <span class="text-orange-400 text-[10px] font-bold bg-orange-500/10 px-1.5 py-0.5 rounded mr-1">@<?= htmlspecialchars($parent_user) ?></span>
                                                <?php endif; ?>
                                                <?= nl2br(htmlspecialchars($c['comment'])) ?>
                                            </p>
                                            <?php if (isset($_SESSION['user_id'])): ?>
                                                <button onclick="toggleReply('mus-<?= $c['id'] ?>')"
                                                    class="text-[10px] font-bold text-orange-400 uppercase tracking-wider mt-2 bg-none border-none cursor-pointer p-0">
                                                    Balas
                                                </button>
                                                <div id="mus-<?= $c['id'] ?>" class="hidden mt-3">
                                                    <form action="watch.php?id=<?= $id ?>" method="post" class="flex gap-2">
                                                        <input type="hidden" name="parent_id" value="<?= $c['id'] ?>">
                                                        <input type="text" name="comments"
                                                            class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 min-w-0"
                                                            placeholder="Balas @<?= htmlspecialchars($author) ?>..." required>
                                                        <button name="send"
                                                            class="bg-orange-500 text-white text-[10px] font-black uppercase px-3 sm:px-4 py-2 rounded-xl border-none cursor-pointer flex-shrink-0">
                                                            Kirim
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                            <?php
                                    render_music_comments($c['id'], $grouped, $level + 1);
                                endforeach;
                            }
                            if (empty($comments_grouped)) {
                                echo "<div class='py-10 text-center text-[10px] text-gray-700 uppercase tracking-widest'>Belum ada komentar.</div>";
                            } else {
                                render_music_comments(0, $comments_grouped);
                            }
                            ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

        </div>

        <!-- Sidebar kanan -->
        <div class="space-y-6">

            <?php if ($playlist_context > 0 && $queue_query && $queue_query->num_rows > 0): ?>
                <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden">
                    <div class="px-5 py-3.5 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="list-music" class="w-3.5 h-3.5 text-orange-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600">Up Next</span>
                    </div>
                    <div class="p-3 space-y-0.5 max-h-[320px] overflow-y-auto no-scrollbar">
                        <?php
                        $queue_query->data_seek(0);
                        while ($q = $queue_query->fetch_assoc()):
                            $is_pl = ($q['id'] == $id);
                        ?>
                            <a href="watch.php?id=<?= $q['id'] ?>&playlist_id=<?= $playlist_context ?>"
                                class="flex items-center gap-3 px-2 py-2 rounded-xl transition-all no-underline
                              <?= $is_pl ? 'bg-orange-500/8 border border-orange-500/20' : 'hover:bg-white/[.025] border border-transparent' ?>">
                                <div class="w-10 h-10 rounded-lg overflow-hidden flex-shrink-0 <?= $is_pl ? 'opacity-50' : '' ?>">
                                    <img src="upload/thumbnail/<?= htmlspecialchars($q['thumbnail']) ?>" class="w-full h-full object-cover" loading="lazy">
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[11px] font-bold truncate uppercase <?= $is_pl ? 'text-orange-400' : 'text-gray-400' ?>">
                                        <?= htmlspecialchars($q['title']) ?>
                                    </div>
                                    <div class="text-[9px] text-gray-600 uppercase tracking-wider"><?= htmlspecialchars($q['artist']) ?></div>
                                </div>
                                <?php if ($is_pl): ?><i data-lucide="volume-2" class="w-3.5 h-3.5 text-orange-400 flex-shrink-0"></i><?php endif; ?>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden">
                <div class="px-5 py-3.5 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                    <i data-lucide="shuffle" class="w-3.5 h-3.5 text-gray-600"></i>
                    <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600">Discover</span>
                </div>
                <!-- [MOBILE] grid 2 kolom di mobile, list di lg -->
                <div id="music-recommendation-column" class="p-3 grid grid-cols-2 lg:grid-cols-1 gap-2 lg:gap-0 lg:space-y-0.5">
                    <?php while ($r = $rekom->fetch_assoc()):
                        $r_ext = strtolower(pathinfo($r['filename'], PATHINFO_EXTENSION));
                        $r_lbl = $r_ext === 'ogg' ? 'opus' : $r_ext;
                    ?>
                        <a href="watch.php?id=<?= $r['id'] ?>"
                            class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 p-2 rounded-xl no-underline"
                            title="<?= htmlspecialchars($r['title']) ?>">
                            <div class="w-full lg:w-16 aspect-square lg:h-12 lg:aspect-auto rounded-lg overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                                <img src="upload/thumbnail/<?= htmlspecialchars($r['thumbnail']) ?>"
                                    class="rec-thumb-img w-full h-full object-cover transition-transform duration-300" loading="lazy">
                            </div>
                            <div class="flex-1 min-w-0 flex flex-col justify-center">
                                <div class="text-[11px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                                    <?= htmlspecialchars($r['title']) ?>
                                </div>
                                <div class="text-[10px] text-gray-600 mt-0.5 truncate"><?= htmlspecialchars($r['artist']) ?></div>
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span class="text-[9px] text-gray-700"><?= number_format($r['views']) ?> views</span>
                                    <span class="text-[8px] px-1.5 py-0.5 rounded bg-white/[.04] border border-white/[.05] text-gray-600 uppercase"><?= $r_lbl ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endwhile; ?>
                </div>
            </div>

        </div>
    </div>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div id="playlist-modal" class="hidden">
            <div class="absolute inset-0" onclick="document.getElementById('playlist-modal').classList.add('hidden')"></div>
            <div class="relative bg-[#141820] border border-white/[.07] border-t-2 border-t-orange-500 rounded-2xl p-5 sm:p-6 max-w-sm w-full mx-4">
                <button onclick="document.getElementById('playlist-modal').classList.add('hidden')"
                    class="absolute top-4 right-4 text-gray-600 hover:text-white transition-colors bg-none border-none cursor-pointer">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
                <div class="flex items-center gap-2 mb-5">
                    <i data-lucide="list-music" class="w-4 h-4 text-orange-400"></i>
                    <span class="text-sm font-bold text-white uppercase tracking-wider">Simpan ke Playlist</span>
                </div>
                <div class="space-y-1.5 mb-4 max-h-[180px] overflow-y-auto pr-1 no-scrollbar">
                    <?php
                    $my_playlists = $conn->query("SELECT * FROM playlists WHERE user_id = {$_SESSION['user_id']} ORDER BY id DESC");
                    if ($my_playlists && $my_playlists->num_rows > 0):
                        while ($pl = $my_playlists->fetch_assoc()):
                    ?>
                            <form action="playlist_action.php" method="POST">
                                <input type="hidden" name="action" value="add_to_playlist">
                                <input type="hidden" name="music_id" value="<?= $id ?>">
                                <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                                <button type="submit"
                                    class="w-full text-left px-4 py-2.5 rounded-xl bg-white/[.04] border border-white/[.06] text-sm text-gray-400 hover:bg-orange-500/10 hover:border-orange-500/25 hover:text-orange-400 transition-all cursor-pointer font-medium">
                                    <?= htmlspecialchars($pl['name']) ?>
                                </button>
                            </form>
                        <?php endwhile;
                    else: ?>
                        <p class="text-[11px] text-gray-600 text-center py-3">Belum ada playlist.</p>
                    <?php endif; ?>
                </div>
                <div class="border-t border-white/[.05] pt-4">
                    <form action="playlist_action.php" method="POST" class="flex gap-2">
                        <input type="hidden" name="action" value="create_playlist">
                        <input type="hidden" name="music_id" value="<?= $id ?>">
                        <input type="text" name="playlist_name"
                            class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 transition-all min-w-0"
                            placeholder="Nama playlist baru..." required>
                        <button type="submit"
                            class="bg-orange-500 hover:bg-orange-400 text-black text-xs font-black uppercase px-4 py-2 rounded-xl border-none cursor-pointer transition-all flex-shrink-0">
                            Buat
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- MINI PLAYER -->
    <div id="mini-player">
        <div class="mini-player-header" onclick="toggleMiniPlayer()">
            <div class="mini-player-thumbnail">
                <img id="mini-thumbnail" src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>" alt="cover">
            </div>
            <div class="mini-player-info">
                <div class="mini-player-title" id="mini-title"><?= htmlspecialchars($v['title']) ?></div>
                <div class="mini-player-artist" id="mini-artist"><?= htmlspecialchars($v['artist'] ?? 'Unknown') ?></div>
            </div>
            <div class="mini-player-close" onclick="event.stopPropagation(); toggleMiniPlayer()">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </div>
        </div>
        <div class="mini-player-controls">
            <button class="mini-player-btn" onclick="miniPlayPause()" id="mini-play-btn">
                <i data-lucide="play" style="width: 18px; height: 18px;"></i>
            </button>
        </div>
        <div class="mini-player-progress">
            <div class="mini-progress-bar" id="mini-progress-bar" onclick="miniSeek(event)">
                <div class="mini-progress-fill" id="mini-progress-fill" style="width: 0%"></div>
            </div>
            <div class="mini-progress-text">
                <span id="mini-current-time">0:00</span>
                <span id="mini-duration">0:00</span>
            </div>
        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script>
        window.MEEL_MUSIC_CONFIG = {
            id: <?= $id ?>,
            fileSizeBytes: <?= (int)$file_size_bytes ?>,
            nextSongUrl: "<?= $next_song_url ?>",
            title: '<?= htmlspecialchars(addslashes($v['title'])) ?>',
            artist: '<?= htmlspecialchars(addslashes($v['artist'] ?? '')) ?>',
            thumbnail: '<?= htmlspecialchars($v['thumbnail']) ?>',
            filename: '<?= htmlspecialchars($v['filename']) ?>'
        };
    </script>
    <script src="../assets/js/plyr.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script src="../assets/js/player_music.js"></script>

    <script>
        lucide.createIcons();
        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
    </script>
</body>

</html>
