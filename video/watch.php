<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_name('meel');
session_start();

include '../auth/config.php';
require_once '../helpers.php';
include '../auth/MediaViewer.php';

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
// --- LOGIKA THUMBNAIL PREVIEW (Versi Lebih Rapi) ---
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
    <meta name="viewport" content="width=device-width, initial-scale=0.65">
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

        .video-title {
            font-family: var(--font-display);
            font-size: 2rem;
            letter-spacing: .04em;
            color: #f0f2f7;
            line-height: 1.08;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-clamp: 2;
        }

        .rec-title-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            line-clamp: 2;
            overflow: hidden;
        }

        /* PERBAIKAN: Pisahkan display flex dan naikkan z-index */
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
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-5 h-14 flex items-center justify-between gap-4">

            <a href="index.php" class="flex items-center gap-2.5 flex-shrink-0" title="MEeL Video">
                <div class="w-7 h-7 bg-red-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="play" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-sm font-bold tracking-tight text-white uppercase">
                    MEeL<span class="text-red-500">Video</span>
                </span>
            </a>

            <div class="flex-1 max-w-sm flex items-center gap-2">
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

            <div class="flex items-center gap-5 text-[10px] font-bold uppercase tracking-wider flex-shrink-0" title="MEeL Music">
                <a href="../music/index.php" class="flex items-center gap-1.5 text-gray-600 hover:text-orange-500 transition-all">
                    <i data-lucide="music" class="w-3.5 h-3.5"></i> Music
                </a>
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <div id="app-content-grid" class="max-w-7xl mx-auto px-5 pt-8 pb-20 grid grid-cols-1 lg:grid-cols-3 gap-8">

        <div id="left-column" class="lg:col-span-2 space-y-5">

            <div id="main-video-wrapper" class="relative bg-black rounded-2xl overflow-hidden border border-white/[.06] shadow-2xl">
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
                    <div class="bg-[#141820] border border-red-600/25 border-t-2 border-t-red-600 rounded-2xl p-7 max-w-xs w-full text-center">
                        <div class="text-sm font-black text-white uppercase tracking-wider mb-2">Lanjutkan Sesi?</div>
                        <div class="text-[10px] text-gray-500 uppercase tracking-widest mb-1">
                            Menit ke‑ <span id="resume-time" class="text-red-400 font-mono">0:00</span>
                        </div>
                        <p id="resume-countdown" class="text-[10px] text-gray-600 italic mb-5">Otomatis ulang dalam 15s...</p>
                        <div class="flex gap-2">
                            <button id="btn-resume" title="Melanjutkan video"
                                class="flex-1 bg-red-600 hover:bg-red-500 text-white text-xs font-black uppercase tracking-wider py-2.5 rounded-xl transition-all border-none cursor-pointer">
                                Lanjut
                            </button>
                            <button id="btn-restart" title="Mengulang video"
                                class="flex-1 bg-white/5 hover:bg-white/10 text-gray-400 text-xs font-black uppercase tracking-wider py-2.5 rounded-xl border border-white/10 cursor-pointer transition-all">
                                Ulang
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <div id="watch-details-wrapper" class="space-y-5">
                <div id="video-info" class="bg-[#0d1017] border border-white/[.06] rounded-2xl p-6">
                    <div class="video-title mb-4" title="<?= htmlspecialchars($v['title']) ?>"><?= htmlspecialchars($v['title']) ?></div>
                    <?php if (!empty($v['description'])): ?>
                        <p class="text-sm text-gray-400 leading-relaxed"><?= nl2br(htmlspecialchars($v['description'])) ?></p>
                    <?php endif; ?>
                </div>
                <div class="h-px bg-white/[.04] mb-4"></div>
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <a href="../profile/?u=<?= urlencode($v['uploader']) ?>" title="<?= htmlspecialchars($v['uploader']) ?>"
                            class="w-10 h-10 rounded-full overflow-hidden border border-red-600/25 flex-shrink-0 block">
                            <?php if (!empty($v['uploader_pfp'])): ?>
                                <img src="../profile/upload/<?= htmlspecialchars($v['uploader_pfp']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <img src="../profile/upload/default.png" class="w-full h-full object-cover">
                            <?php endif; ?>
                        </a>
                        <div>
                            <a href="../profile/?u=<?= urlencode($v['uploader']) ?>" title="<?= htmlspecialchars($v['uploader']) ?>"
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
                            class="bg-gray-800 text-gray-400 flex items-center gap-1.5 text-[10px] font-black uppercase tracking-wider px-4 py-2 rounded-xl border border-transparent transition-all cursor-pointer">
                            <i data-lucide="repeat" class="w-3.5 h-3.5"></i>
                            <span id="loop-text">Loop Off</span>
                        </button>
                        <?php if (isset($_SESSION['username'])): ?>
                            <a href="../transcode.php?id=<?= $id ?>" title="Download Audio"
                                class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all bg-gray-800/50 border border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300 no-underline">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Audio
                            </a>
                            <div id="like-dislike-container" class="flex items-center gap-2">
                                <button
                                    hx-post="../like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"like"}'
                                    title="Suka video"
                                    class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
                                   <?= $user_interaction === 'like'
                                        ? 'bg-red-600/15 border-red-600/30 text-red-400'
                                        : 'bg-gray-800/50 border-white/[.05] text-gray-500 hover:bg-gray-700 hover:text-gray-300' ?>">
                                    <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $user_interaction === 'like' ? 'fill-current' : '' ?>"></i>
                                    Like<?= ($v['likes'] ?? 0) > 0 ? " <span class='tabular-nums ml-0.5'>{$v['likes']}</span>" : '' ?>
                                </button>
                                <button
                                    hx-post="../like.php" hx-target="#like-dislike-container" hx-swap="outerHTML"
                                    hx-vals='{"id":"<?= $id ?>","media_type":"video","type":"dislike"}'
                                    title="Tidak suka video"
                                    class="flex items-center gap-1.5 px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
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


                <section class="bg-[#0d1017] border border-white/[.06] rounded-2xl overflow-hidden" id="comment-section">
                    <div class="px-6 py-4 border-b border-white/[.04] bg-black/10 flex items-center gap-2">
                        <i data-lucide="message-square" class="w-3.5 h-3.5 text-red-500"></i>
                        <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-600">Komentar</span>
                    </div>
                    <div class="p-6">
                        <?php if (isset($_SESSION['user_id'])): ?>
                            <form action="watch.php?id=<?= $id ?>" method="post" class="mb-6">
                                <textarea name="comments"
                                    class="w-full bg-black/25 border border-white/[.06] rounded-xl p-4 text-sm text-gray-300 focus:outline-none focus:border-red-500/40 min-h-[90px] resize-y transition-all"
                                    placeholder="Tulis komentar..." required></textarea>
                                <div class="flex justify-end mt-2">
                                    <button name="send"
                                        class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-black uppercase tracking-wider px-6 py-2.5 rounded-xl transition-all border-none cursor-pointer">
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
                            ?>
                                    <div class="comment-row flex gap-3 p-3 rounded-xl" style="margin-left:<?= min($level * 20, 80) ?>px">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-red-600 to-red-900 flex items-center justify-center text-white text-xs font-bold flex-shrink-0">
                                            <?= strtoupper(substr($author, 0, 1)) ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between gap-2 mb-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-[11px] font-bold text-red-400">@<?= htmlspecialchars($author) ?></span>
                                                    <span class="text-[10px] text-gray-600"><?= time_ago($c['created_at']) ?></span>
                                                </div>
                                                <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $c['user_id']): ?>
                                                    <a href="../delete_comment.php?id=<?= $c['id'] ?>"
                                                        onclick="return confirm('Hapus komentar ini?')"
                                                        class="text-gray-600 hover:text-red-400 transition-colors no-underline">
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
                                                            class="flex-1 bg-black/30 border border-white/[.06] rounded-xl px-3 py-2 text-xs text-gray-300 focus:outline-none focus:border-red-500/40"
                                                            placeholder="Balas @<?= htmlspecialchars($author) ?>..." required>
                                                        <button name="send"
                                                            class="bg-red-600 hover:bg-red-500 text-white text-[10px] font-black uppercase px-4 py-2 rounded-xl border-none cursor-pointer transition-all">
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

        <div id="recommendation-wrapper" class="space-y-4">
            <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] px-1 flex items-center gap-2" id="rec-title">
                <i data-lucide="play-circle" class="w-3 h-3 text-red-500"></i>
                Video Lainnya
            </div>
            <div id="recommendation-column" class="space-y-1">
                <?php while ($r = $rekom->fetch_assoc()): ?>
                    <a href="watch.php?id=<?= $r['id'] ?>" class="rekomendasi-item flex gap-3 px-2 py-2.5 rounded-xl no-underline" title="<?= htmlspecialchars($r['title']) ?>">
                        <div class="w-32 h-20 rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                            <img src="upload/thumbnail/<?= htmlspecialchars($r['thumbnail']) ?>"
                                class="rec-thumb-img w-full h-full object-cover transition-transform duration-300" loading="lazy">
                        </div>
                        <div class="flex-1 min-w-0 flex flex-col justify-center">
                            <div class="text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
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

</body>

</html>