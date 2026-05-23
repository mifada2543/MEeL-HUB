<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_name('meel');
session_start();

include '../auth/config.php';
require_once '../modules/helpers.php';
include '../modules/MediaViewer.php';

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'] ?? null;

$viewer = new MediaViewer($conn, $user_id, 'video', $id);
$viewer->recordView();

if (isset($_POST['send'])) {
    if ($viewer->addComment($_POST)) {
        header("Location: watch.php?id=$id#comment-section");
        exit;
    }
}

$v = $viewer->getMediaData();
$video_src = "upload/" . $v['filename'];
$is_hls = (pathinfo($video_src, PATHINFO_EXTENSION) === 'm3u8');
$video_dir = dirname($video_src);
$vtt_path = $video_dir . "/thumbnails.vtt";
$vtt_src = file_exists($vtt_path) ? $vtt_path : "";
if ($is_hls) {
    $video_dir = dirname($video_src);
    $potential_vtt = $video_dir . "/thumbnails.vtt";
    if (file_exists($potential_vtt)) {
        $vtt_src = $potential_vtt;
    }
}

$user_interaction = $viewer->getUserInteraction();
$comments_data    = $viewer->getComments();
$comments_grouped = $comments_data['grouped'];
$user_map         = $comments_data['user_map'];
$rekom            = $viewer->getRecommendations(15);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <!-- [FIX MOBILE] initial-scale=1 agar tidak di-zoom-out paksa di HP -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($v['title']) ?> — MEeL Video</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link rel="stylesheet" href="../assets/css/plyr.css">
    <link rel="stylesheet" href="../assets/css/video.css">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/htmx.js"></script>
    <script src="../assets/js/hls.js"></script>
    <style>
        :root {
            --font-display: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
        }

        /* [FIX MOBILE] font lebih kecil di layar sempit */
        .video-title {
            font-family: var(--font-display);
            font-size: clamp(1.3rem, 5vw, 2rem);
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

        #resume-modal {
            position: absolute;
            inset: 0;
            z-index: 50;
            background: rgba(0, 0, 0, .7);
            backdrop-filter: blur(4px);
        }

        #resume-modal:not(.hidden) {
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
            border-left-color: #dc2626;
        }

        .rekomendasi-item {
            border-left: 2px solid transparent;
            transition: background .2s, border-color .2s;
        }

        .rekomendasi-item:hover {
            background: rgba(255, 255, 255, .025);
            border-left-color: #dc2626;
        }

        .rekomendasi-item:hover .rec-thumb-img {
            transform: scale(1.06);
        }

        /* [MOBILE] search bar di navbar: sembunyikan di layar sangat kecil */
        @media (max-width: 480px) {
            #navbar-search-wrap {
                display: none;
            }

            #navbar-search-icon-btn {
                display: flex !important;
            }
        }

        /* [MOBILE] search overlay saat ikon search diklik */
        #mobile-search-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #080a0f;
            border-bottom: 1px solid rgba(255, 255, 255, .06);
            padding: 0.75rem 1rem;
            z-index: 60;
            gap: 0.5rem;
            align-items: center;
        }

        #mobile-search-overlay.open {
            display: flex;
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-4 sm:px-5 h-14 flex items-center justify-between gap-3">

            <a href="index.php" class="flex items-center gap-2 flex-shrink-0" title="MEeL Video">
                <div class="w-7 h-7 bg-red-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="play" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-sm font-bold tracking-tight text-white uppercase">
                    MEeL<span class="text-red-500">Video</span>
                </span>
            </a>

            <!-- Search bar (tersembunyi di bawah 480px) -->
            <div id="navbar-search-wrap" class="flex-1 max-w-sm flex items-center gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-red-500 transition-colors"></i>
                    <input type="text"
                        id="v-search-watch"
                        name="search"
                        placeholder="Cari video lain..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-9 pr-4 text-xs focus:outline-none focus:border-red-500/40 transition-all text-gray-300"
                        hx-get="search_video.php?exclude=<?= $id ?>"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#recommendation-column"
                        hx-indicator="#search-indicator"
                        autocomplete="off"
                        title="Cari Video">
                    <div id="search-indicator" class="htmx-indicator absolute right-3.5 top-1/2 -translate-y-1/2">
                        <div class="animate-spin h-3 w-3 border-2 border-red-500 border-t-transparent rounded-full"></div>
                    </div>
                </div>
                <button hx-get="search_video.php?exclude=<?= $id ?>"
                    title="Cari"
                    hx-include="#v-search-watch"
                    hx-target="#recommendation-column"
                    hx-indicator="#search-indicator"
                    class="px-3 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase text-gray-500 hover:text-red-400 hover:border-red-500/30 transition-all flex-shrink-0">
                    Cari
                </button>
            </div>

            <div class="flex items-center gap-3 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <!-- Ikon search (muncul di < 480px menggantikan search bar) -->
                <button id="navbar-search-icon-btn"
                    onclick="document.getElementById('mobile-search-overlay').classList.toggle('open')"
                    class="hidden items-center justify-center w-8 h-8 text-gray-500 hover:text-red-400 transition-all"
                    title="Cari">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </button>
                <a href="../music/index.php" class="hidden sm:flex items-center gap-1.5 text-gray-600 hover:text-orange-500 transition-all">
                    <i data-lucide="music" class="w-3.5 h-3.5"></i>
                    <span class="hidden md:inline">Music</span>
                </a>
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <!-- Mobile search overlay -->
    <div id="mobile-search-overlay">
        <button onclick="document.getElementById('mobile-search-overlay').classList.remove('open')"
            class="text-gray-600 flex-shrink-0">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
        </button>
        <div class="relative flex-1">
            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-600"></i>
            <input type="text"
                id="v-search-mobile"
                name="search"
                placeholder="Cari video..."
                class="w-full bg-white/[.06] border border-white/[.08] rounded-xl py-2.5 pl-9 pr-4 text-sm focus:outline-none focus:border-red-500/40 text-gray-300"
                hx-get="search_video.php?exclude=<?= $id ?>"
                hx-trigger="keyup[key=='Enter']"
                hx-target="#recommendation-column"
                autocomplete="off">
        </div>
    </div>

    <!-- [FIX MOBILE] Layout: kolom tunggal di mobile, 2 kolom di lg+ -->
    <div id="app-content-grid" class="max-w-7xl mx-auto px-4 sm:px-5 pt-4 sm:pt-8 pb-20 grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8">

        <div id="left-column" class="lg:col-span-2 space-y-4 sm:space-y-5">

            <div id="main-video-wrapper" class="relative bg-black rounded-xl sm:rounded-2xl overflow-hidden border border-white/[.06] shadow-2xl">
                <?php
                $video_src = "upload/" . $v['filename'];
                $is_hls = (pathinfo($video_src, PATHINFO_EXTENSION) === 'm3u8');
                ?>
                <video id="main-video" playsinline controls
                    data-poster="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                    data-src="<?= htmlspecialchars($video_src) ?>"
                    data-ishls="<?= $is_hls ? 'true' : 'false' ?>"
                    data-vtt="<?= htmlspecialchars($vtt_src ?? '') ?>"
                    class="w-full block">
                    <?php if (!$is_hls): ?>
                        <source src="<?= $video_src ?>" type="video/mp4">
                    <?php endif; ?>
                    <?php if (!empty($vtt_src)): ?>
                        <track kind="metadata" src="<?= $vtt_src ?>" default>
                    <?php endif; ?>
                </video>
                <div id="resume-modal" class="hidden">
                    <div class="bg-[#141820] border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl p-6 max-w-xs w-full mx-4 text-center">
                        <div class="text-sm font-black text-white uppercase tracking-wider mb-2">Lanjutkan Sesi?</div>
                        <div class="text-[10px] text-gray-500 uppercase tracking-widest mb-1">
                            Menit ke‑ <span id="resume-time" class="text-red-400 font-mono">0:00</span>
                        </div>
                        <p id="resume-countdown" class="text-[10px] text-gray-600 italic mb-5">Otomatis ulang dalam 15s...</p>
                        <div class="flex gap-2">
                            <button id="btn-resume"
                                class="flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                Lanjut
                            </button>
                            <button id="btn-restart"
                                class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all">
                                Ulang
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div id="watch-details-wrapper" class="space-y-4 sm:space-y-5">
                <div id="video-info" class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl p-4 sm:p-6">
                    <div class="video-title mb-3" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></div>
                    <?php if (!empty($v['description'])): ?>
                        <p class="text-sm text-gray-400 leading-relaxed"><?= nl2br(htmlspecialchars($v['description'])) ?></p>
                    <?php endif; ?>
                </div>
                <div class="h-px bg-white/[.04]"></div>

                <!-- [MOBILE] Uploader info + action buttons: stack di mobile, row di sm+ -->
                <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                            class="w-10 h-10 rounded-full overflow-hidden border border-red-600/25 flex-shrink-0 block">
                            <?php if (!empty($v['uploader_pfp'])): ?>
                                <img src="../profile/upload/<?= htmlspecialchars($v['uploader_pfp']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full bg-gradient-to-br from-red-600 to-red-900 flex items-center justify-center text-white text-sm font-bold">
                                    <?= strtoupper(substr($v['uploader'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="../profile/?u=<?= urlencode($v['uploader']) ?>"
                                class="text-[10px] font-black uppercase tracking-widest text-red-400 hover:underline block leading-tight">
                                <?= htmlspecialchars($v['uploader']) ?>
                            </a>
                            <div class="text-[10px] text-gray-600 mt-0.5">
                                <?= number_format($v['views']) ?> tayangan &nbsp;•&nbsp; <?= time_ago($v['upload_date']) ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-wrap">
                        <button id="btn-loop" onclick="toggleLoop()" title="Perulangan"
                            class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider px-3 sm:px-4 py-2 rounded-xl border border-transparent transition-all cursor-pointer">
                            <i data-lucide="repeat" class="w-3.5 h-3.5"></i>
                            <span id="loop-text">Loop Off</span>
                        </button>
                        <?php if (isset($_SESSION['username'])): ?>
                            <a href="../transcode.php?id=<?= $id ?>"
                                class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all bg-gray-800/50 border border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300 no-underline">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Audio
                            </a>
                            <div id="like-dislike-container" class="flex items-center gap-2">
                                <button
                                    hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"like"}'
                                    title="Suka video"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'like'
                                        ? 'bg-red-600/15 border-red-600/30 text-red-400'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
                                    Like<?= ($v['likes'] ?? 0) > 0 ? " <span class='tabular-nums ml-0.5'>{$v['likes']}</span>" : '' ?>
                                </button>
                                <button
                                    hx-post="../controllers/like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"dislike"}'
                                    title="Tidak suka video"
                                    class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'dislike'
                                        ? 'bg-white/10 border-white/15 text-white'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $user_interaction === 'dislike' ? 'fill-current' : '' ?>"></i>
                                    <?= ($v['dislikes'] ?? 0) > 0 ? "<span class='tabular-nums'>{$v['dislikes']}</span>" : '' ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <section class="bg-[#0d1017] border border-white/[.06] rounded-xl sm:rounded-2xl overflow-hidden" id="comment-section">
                    <div class="px-4 sm:px-6 py-4 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-3.5 h-3.5 text-red-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600">Komentar</span>
                    </div>
                    <div class="p-4 sm:p-6">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <form action="watch.php?id=<?= $id ?>" method="post" class="mb-6">
                                <textarea name="comments"
                                    class="w-full bg-black/25 border border-white/[.06] rounded-xl p-3 sm:p-4 text-sm text-gray-300 focus:outline-none focus:border-red-500/40 min-h-[80px] resize-y transition-all"
                                    placeholder="Tulis komentar..." required></textarea>
                                <div class="flex justify-end mt-2">
                                    <button name="send"
                                        class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-black uppercase tracking-wider px-5 py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                        Kirim
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <div class="space-y-1 max-h-[500px] overflow-y-auto pr-1">
                            <?php
                            function render_video_comments($parent_id, $grouped, $level = 0)
                            {
                                global $id, $user_map;
                                if (!isset($grouped[$parent_id])) return;
                                foreach ($grouped[$parent_id] as $c):
                                    $author      = $c['username'] ?? 'Guest';
                                    $parent_user = ($c['parent_id'] > 0) ? ($user_map[$c['parent_id']] ?? 'Guest') : null;
                                    // [FIX MOBILE] batasi indent di mobile agar tidak overflow
                                    $indent = min($level * 16, 48);
                            ?>
                                    <div class="comment-row flex gap-3 p-3 rounded-xl" style="margin-left:<?= $indent ?>px">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-red-600 to-red-900 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            <?= strtoupper(substr($author, 0, 1)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <span class="text-[11px] font-bold text-red-400 truncate">@<?= htmlspecialchars($author) ?></span>
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
                                                    <span class="text-blue-400 text-[10px] font-bold bg-blue-500/10 px-1.5 py-0.5 rounded mr-1">@<?= htmlspecialchars($parent_user) ?></span>
                                                <?php endif; ?>
                                                <?= nl2br(htmlspecialchars($c['comment'])) ?>
                                            </p>
                                            <?php if (isset($_SESSION['user_id'])): ?>
                                                <button onclick="toggleReply('vid-<?= $c['id'] ?>')"
                                                    class="text-[10px] font-bold text-gray-500 hover:text-red-400 uppercase tracking-wider mt-2 bg-none border-none cursor-pointer p-0 transition-colors">
                                                    Balas
                                                </button>
                                                <div id="vid-<?= $c['id'] ?>" class="hidden mt-3">
                                                    <form action="watch.php?id=<?= $id ?>" method="post" class="flex gap-2">
                                                        <input type="hidden" name="parent_id" value="<?= $c['id'] ?>">
                                                        <input type="text" name="comments"
                                                            class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-red-500/40 min-w-0"
                                                            placeholder="Balas @<?= htmlspecialchars($author) ?>..." required>
                                                        <button name="send"
                                                            class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-black uppercase px-3 sm:px-4 py-2 rounded-xl border-none cursor-pointer transition-all flex-shrink-0">
                                                            Kirim
                                                        </button>
                                                    </form>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                            <?php
                                    render_video_comments($c['id'], $grouped, $level + 1);
                                endforeach;
                            }
                            if (empty($comments_grouped)) {
                                echo "<div class='py-10 text-center text-[10px] text-gray-700 uppercase tracking-widest'>Belum ada komentar.</div>";
                            } else {
                                render_video_comments(0, $comments_grouped);
                            }
                            ?>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <!-- [FIX MOBILE] Rekomendasi: muncul setelah konten utama di mobile -->
        <div id="recommendation-wrapper" class="space-y-4">
            <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] px-1 flex items-center gap-2" id="rec-title">
                <i data-lucide="play-circle" class="w-3 h-3 text-red-500"></i>
                Video Lainnya
            </div>
            <!-- [MOBILE] grid 2 kolom di mobile, list di lg -->
            <div id="recommendation-column" class="grid grid-cols-2 lg:grid-cols-1 gap-3 lg:gap-0 lg:space-y-1">
                <?php while ($r = $rekom->fetch_assoc()): ?>
                    <a href="watch.php?id=<?= $r['id'] ?>"
                        class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 px-2 py-2.5 rounded-xl no-underline"
                        title="<?= htmlspecialchars($r['title']) ?>">
                        <div class="w-full lg:w-32 aspect-video lg:h-20 lg:aspect-auto rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                            <img src="upload/thumbnail/<?= htmlspecialchars($r['thumbnail']) ?>"
                                class="rec-thumb-img w-full h-full object-cover transition-transform duration-300" loading="lazy">
                        </div>
                        <div class="flex-1 min-w-0 flex flex-col justify-center">
                            <div class="text-[11px] sm:text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                                <?= htmlspecialchars($r['title']) ?>
                            </div>
                            <div class="text-[9px] text-gray-600 mt-1"><?= number_format($r['views']) ?> views</div>
                            <?php if (!empty($r['uploader'])): ?>
                                <div class="text-[9px] font-bold text-red-500/60 uppercase tracking-wider mt-0.5 truncate">
                                    <?= htmlspecialchars($r['uploader']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        </div>

    </div>

    <?php include '../partials/footer.php'; ?>

    <script src="../assets/js/plyr.js"></script>
    <script src="../assets/js/script.js"></script>
    <?php include 'script/js.php'; ?>

    <script>
        lucide.createIcons();
        document.body.addEventListener('htmx:afterOnLoad', function() {
            lucide.createIcons();
        });
    </script>
</body>

</html>