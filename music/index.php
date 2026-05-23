<?php
session_name('meel');
session_start();
include '../auth/config.php';
include '../modules/helpers.php';
require_once '../modules/MediaLibrary.php';

$library       = new MediaLibrary($conn);
$format_filter = $_GET['format'] ?? 'all';
$artist_filter = $_GET['artist'] ?? 'all';

$artists        = $library->getArtists();
$total_music    = $library->countMusic($format_filter, $artist_filter);
$data_init      = $library->getMusicList($format_filter, $artist_filter, 10, 0);
$is_logged_in   = isset($_SESSION['user_id']);

// Check audio state dari sessionStorage (via hidden input)
$audio_state = null;
if (isset($_GET['audio_state'])) {
    $audio_state = json_decode($_GET['audio_state'], true);
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.65">
    <title>MEeL Music | Library</title>
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/music.css">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/htmx.js"></script>
    <style>
        :root {
            --font-display: Impact, Haettenschweiler, "Arial Narrow Bold", sans-serif;
            --orange: #f97316;
        }

        .section-title {
            font-family: var(--font-display);
            font-size: 2.2rem;
            letter-spacing: .06em;
            color: #f0f2f7;
            line-height: 1;
        }

        .format-pill {
            font-size: .6rem;
            letter-spacing: .2em;
            text-transform: uppercase;
            padding: .35rem .85rem;
            border-radius: 99px;
            border: 1px solid rgba(255, 255, 255, .08);
            background: transparent;
            color: #6b7280;
            transition: all .2s;
            text-decoration: none;
            display: inline-block;
        }

        .format-pill:hover {
            color: #f0f2f7;
            border-color: rgba(255, 255, 255, .15);
        }

        .format-pill.active-orange {
            background: #f97316;
            border-color: #f97316;
            color: #fff;
        }

        .format-pill.active-blue {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }

        .format-pill.active-green {
            background: #16a34a;
            border-color: #16a34a;
            color: #fff;
        }

        /* === MINI PLAYER STYLES === */
        #mini-player-index {
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
        }

        #mini-player-index.active {
            display: flex;
            transform: translateY(0);
        }

        .mini-player-header-index {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, .04);
            cursor: pointer;
            user-select: none;
        }

        .mini-player-thumbnail-index {
            width: 48px;
            height: 48px;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .08);
        }

        .mini-player-thumbnail-index img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .mini-player-info-index {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .mini-player-title-index {
            font-size: 12px;
            font-weight: 600;
            color: #f0f2f7;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .mini-player-artist-index {
            font-size: 10px;
            color: #9ca3af;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }

        .mini-player-close-index {
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

        .mini-player-close-index:hover {
            background: rgba(255, 255, 255, .1);
            color: #f0f2f7;
        }

        .mini-player-controls-index {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, .04);
        }

        .mini-player-btn-index {
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

        .mini-player-btn-index:hover {
            background: rgba(249, 115, 22, .25);
        }

        .mini-player-progress-index {
            padding: 0 12px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .mini-progress-bar-index {
            width: 100%;
            height: 4px;
            background: rgba(255, 255, 255, .08);
            border-radius: 2px;
            cursor: pointer;
            overflow: hidden;
        }

        .mini-progress-fill-index {
            height: 100%;
            background: linear-gradient(90deg, #f97316, #fb923c);
            border-radius: 2px;
            transition: width 0.1s linear;
        }

        .mini-progress-text-index {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #6b7280;
        }

        @media (max-width: 768px) {
            #mini-player-index {
                width: 280px;
                right: 8px;
                bottom: 8px;
                border-radius: 10px;
            }
        }
    </style>
</head>

<body class="text-gray-400 min-h-screen">

    <!-- NAVBAR -->
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-3 sm:px-5 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="../index.php" class="flex items-center gap-1 sm:gap-2.5 flex-shrink-0" title="MEeL HUB">
                <div class="w-6 h-6 sm:w-7 sm:h-7 bg-orange-600 rounded-lg flex items-center justify-center">
                    <i data-lucide="music" class="w-3.5 h-3.5 text-white fill-current"></i>
                </div>
                <span class="text-xs sm:text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    MEeL<span class="text-orange-500">Music</span>
                </span>
            </a>

            <div class="flex-1 max-w-sm flex items-center gap-1.5 sm:gap-2">
                <div class="relative flex-1 group">
                    <i data-lucide="search" class="absolute left-2.5 sm:left-3.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-600 group-focus-within:text-orange-500 transition-colors"></i>
                    <input type="text"
                        id="m-search"
                        name="search"
                        placeholder="Cari lagu..."
                        class="w-full bg-white/[.04] border border-white/[.06] rounded-xl py-2 pl-8 sm:pl-9 pr-3 sm:pr-4 text-xs focus:outline-none focus:border-orange-500/40 transition-all text-gray-300"
                        hx-get="search_music.php"
                        hx-trigger="keyup[key=='Enter']"
                        hx-target="#music-list"
                        hx-indicator="#search-indicator"
                        autocomplete="off">
                </div>
                <button hx-get="search_music.php"
                    hx-include="#m-search"
                    hx-target="#music-list"
                    hx-indicator="#search-indicator"
                    class="px-2.5 sm:px-4 py-2 bg-white/[.04] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-orange-500 hover:border-orange-500/30 transition-all flex-shrink-0">
                    <span class="hidden sm:inline">Cari</span>
                    <i data-lucide="search" class="w-3.5 h-3.5 sm:hidden"></i>
                </button>
                <div id="search-indicator" class="htmx-indicator ml-1 sm:ml-2">
                    <div class="animate-spin h-3 w-3 border-2 border-orange-500 border-t-transparent rounded-full"></div>
                </div>
            </div>

            <div class="flex items-center gap-3 sm:gap-5 text-[10px] font-bold uppercase tracking-wider flex-shrink-0">
                <a href="../video/index.php" class="flex items-center gap-1.5 text-gray-600 hover:text-red-500 transition-all">
                    <i data-lucide="play" class="w-3.5 h-3.5"></i> <span class="hidden sm:inline">Video</span>
                </a>
                <?php include '../partials/nav.php'; ?>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-5 pt-8 pb-20 grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- SIDEBAR -->
        <aside class="lg:col-span-3">
            <div class="sticky top-20 space-y-6">

                <!-- FORMAT PILLS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3">Format</div>
                    <div class="flex flex-wrap gap-2">
                        <a href="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>
                </div>

                <!-- ARTISTS (Desktop) -->
                <div class="hidden lg:block">
                    <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3 flex items-center gap-2">
                        <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                    </div>
                    <div class="space-y-0.5 max-h-[45vh] overflow-y-auto no-scrollbar">
                        <a href="index.php?format=<?= $format_filter ?>&artist=all"
                            class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                  <?= $artist_filter === 'all' ? 'active' : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>">
                            <span>All Collections</span>
                        </a>
                        <?php 
                        // reset pointer
                        $artists->data_seek(0);
                        while ($a = $artists->fetch_assoc()): ?>
                            <a href="index.php?format=<?= $format_filter ?>&artist=<?= urlencode($a['artist']) ?>"
                                class="sidebar-link flex items-center justify-between px-3 py-2.5 rounded-lg text-[11px] font-bold transition-all
                                      <?= $artist_filter === $a['artist'] ? 'active' : 'text-gray-600 hover:text-gray-300 hover:bg-white/[.03]' ?>">
                                <span class="truncate"><?= htmlspecialchars($a['artist']) ?></span>
                            </a>
                        <?php endwhile; ?>
                    </div>
                </div>

                <!-- PLAYLISTS (Desktop) -->
                <?php if ($is_logged_in): ?>
                    <div class="hidden lg:block">
                        <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-3 flex items-center gap-2">
                            <i data-lucide="list-music" class="w-3 h-3"></i> Playlists
                        </div>
                        <div class="space-y-0.5 max-h-[30vh] overflow-y-auto no-scrollbar">
                            <?php
                            $playlists = $library->getUserPlaylists($_SESSION['user_id']);
                            while ($pl = $playlists->fetch_assoc()):
                            ?>
                                <a href="view_playlist.php?id=<?= $pl['id'] ?>"
                                    class="sidebar-link flex items-center gap-2 px-3 py-2.5 rounded-lg text-[11px] font-bold text-gray-600 hover:text-gray-300 hover:bg-white/[.03] transition-all">
                                    <i data-lucide="disc-3" class="w-3 h-3 flex-shrink-0"></i>
                                    <span class="truncate"><?= htmlspecialchars($pl['name']) ?></span>
                                </a>
                            <?php endwhile; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- MOBILE FILTERS & MENUS (Select/Dropdowns) -->
                <div class="lg:hidden flex flex-col gap-4 bg-black/20 p-4 rounded-xl border border-white/[.04]">
                    <!-- Format Pills (Mobile) -->
                    <div class="flex flex-wrap gap-2">
                        <a href="index.php?format=all&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'all' ? 'active-orange' : '' ?>">All</a>
                        <a href="index.php?format=ogg&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'ogg' ? 'active-orange' : '' ?>">Opus</a>
                        <a href="index.php?format=m4a&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'm4a' ? 'active-green' : '' ?>">M4A</a>
                        <a href="index.php?format=mp3&artist=<?= urlencode($artist_filter) ?>"
                            class="format-pill <?= $format_filter === 'mp3' ? 'active-blue' : '' ?>">MP3</a>
                    </div>
                    
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <!-- Artists Select -->
                        <div>
                            <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-1.5 flex items-center gap-1.5">
                                <i data-lucide="mic-2" class="w-3 h-3"></i> Artists
                            </div>
                            <select onchange="window.location.href='index.php?format=<?= $format_filter ?>&artist=' + encodeURIComponent(this.value)" class="w-full bg-white/[.04] border border-white/[.06] rounded-xl px-3 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 appearance-none">
                                <option value="all" <?= $artist_filter === 'all' ? 'selected' : '' ?>>All Collections</option>
                                <?php 
                                $artists->data_seek(0);
                                while ($a = $artists->fetch_assoc()): ?>
                                    <option value="<?= htmlspecialchars($a['artist']) ?>" <?= $artist_filter === $a['artist'] ? 'selected' : '' ?>><?= htmlspecialchars($a['artist']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Playlists Select -->
                        <?php if ($is_logged_in): ?>
                        <div>
                            <div class="text-[9px] font-bold text-gray-700 uppercase tracking-[.25em] mb-1.5 flex items-center gap-1.5">
                                <i data-lucide="list-music" class="w-3 h-3"></i> Playlists
                            </div>
                            <select onchange="if(this.value) window.location.href='view_playlist.php?id=' + this.value" class="w-full bg-white/[.04] border border-white/[.06] rounded-xl px-3 py-2.5 text-xs text-gray-300 focus:outline-none focus:border-orange-500/40 appearance-none">
                                <option value="">Pilih Playlist...</option>
                                <?php 
                                $playlists = $library->getUserPlaylists($_SESSION['user_id']);
                                while ($pl = $playlists->fetch_assoc()): ?>
                                    <option value="<?= $pl['id'] ?>"><?= htmlspecialchars($pl['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div>
        </aside>

        <!-- MAIN -->
        <main class="lg:col-span-9">

            <!-- HEADER -->
            <div class="flex items-end justify-between mb-6 pb-4 border-b border-white/[.04]">
                <div>
                    <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Library</div>
                    <div class="section-title">
                        <?= $artist_filter === 'all' ? 'DISCOVERY' : strtoupper(htmlspecialchars($artist_filter)) ?>
                    </div>
                </div>
                <span class="text-[10px] text-gray-700 uppercase tracking-widest">
                    <?= $total_music ?> tracks
                </span>
            </div>

            <!-- MUSIC LIST -->
            <div id="music-list" class="space-y-1">
                <?php if ($data_init && $data_init->num_rows > 0): ?>
                    <?php while ($v = $data_init->fetch_assoc()): ?>
                        <?php include 'music_item.php'; ?>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">
                        Tidak ada lagu ditemukan.
                    </div>
                <?php endif; ?>
            </div>

            <!-- LOAD MORE -->
            <?php if ($total_music > 10): ?>
                <div id="load-more-music" class="pt-6">
                    <button hx-get="load_more_music.php?offset=10&format=<?= $format_filter ?>&artist=<?= urlencode($artist_filter) ?>"
                        hx-target="#music-list"
                        hx-swap="beforeend"
                        class="w-full py-4 border border-dashed border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-[.25em] text-gray-700 hover:text-orange-500 hover:border-orange-500/30 transition-all">
                        Load More
                    </button>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <!-- MINI PLAYER INDEX -->
    <div id="mini-player-index">
        <div class="mini-player-header-index" onclick="toggleMiniPlayerIndex()">
            <div class="mini-player-thumbnail-index">
                <img id="mini-thumbnail-index" src="upload/thumbnail/default.png" alt="cover">
            </div>
            <div class="mini-player-info-index">
                <div class="mini-player-title-index" id="mini-title-index">Tidak ada musik</div>
                <div class="mini-player-artist-index" id="mini-artist-index">Unknown</div>
            </div>
            <div class="mini-player-close-index" onclick="event.stopPropagation(); closeMiniPlayerIndex()">
                <i data-lucide="x" style="width: 16px; height: 16px;"></i>
            </div>
        </div>

        <div class="mini-player-controls-index">
            <button class="mini-player-btn-index" onclick="miniPlayPauseIndex()" id="mini-play-btn-index">
                <i data-lucide="play" style="width: 18px; height: 18px;"></i>
            </button>
        </div>

        <div class="mini-player-progress-index">
            <div class="mini-progress-bar-index" id="mini-progress-bar-index" onclick="miniSeekIndex(event)">
                <div class="mini-progress-fill-index" id="mini-progress-fill-index" style="width: 0%"></div>
            </div>
            <div class="mini-progress-text-index">
                <span id="mini-current-time-index">0:00</span>
                <span id="mini-duration-index">0:00</span>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // === MINI PLAYER INDEX ===
        const miniPlayerIndex = document.getElementById('mini-player-index');
        let audioPlayer = null;
        let isMiniPlayerIndexActive = false;

        // Initialize mini player jika ada audio state
        function initMiniPlayerIndex() {
            const audioState = sessionStorage.getItem('meel_audio_state');
            if (audioState) {
                const state = JSON.parse(audioState);
                isMiniPlayerIndexActive = true;
                
                // Load audio element
                createAudioPlayer(state);
                
                // Update UI
                updateMiniPlayerIndexUI(state);
                miniPlayerIndex.classList.add('active');
            }
        }

        // Create hidden audio player
        function createAudioPlayer(state) {
            if (!audioPlayer) {
                audioPlayer = document.createElement('audio');
                audioPlayer.id = 'hidden-audio-player';
                audioPlayer.preload = 'metadata';
                audioPlayer.crossOrigin = 'anonymous';
                document.body.appendChild(audioPlayer);
            }
            
            // Set source - direct path ke file musik
            const filename = state.filename || 'audio.ogg';
            audioPlayer.src = `upload/file/${filename}`;
            
            // Update jika sedang main
            if (state.isPlaying) {
                audioPlayer.currentTime = state.currentTime;
                audioPlayer.play().catch(() => console.log("Playback dimulai dengan user gesture"));
            }
            
            // Setup event listeners
            audioPlayer.addEventListener('timeupdate', () => updateMiniPlayerUIFromPlayer(state));
            audioPlayer.addEventListener('play', () => updateMiniPlayBtn('pause'));
            audioPlayer.addEventListener('pause', () => updateMiniPlayBtn('play'));
        }

        // Update UI dari state
        function updateMiniPlayerIndexUI(state) {
            document.getElementById('mini-title-index').textContent = state.title;
            document.getElementById('mini-artist-index').textContent = state.artist || 'Unknown';
            document.getElementById('mini-thumbnail-index').src = `upload/thumbnail/${state.thumbnail}`;
        }

        // Update UI dari player
        function updateMiniPlayerUIFromPlayer(state) {
            if (!audioPlayer) return;
            
            const percentage = (audioPlayer.currentTime / audioPlayer.duration) * 100;
            document.getElementById('mini-progress-fill-index').style.width = percentage + '%';
            document.getElementById('mini-current-time-index').textContent = formatTimeIndex(audioPlayer.currentTime);
            document.getElementById('mini-duration-index').textContent = formatTimeIndex(audioPlayer.duration);
        }

        // Format time helper
        function formatTimeIndex(seconds) {
            if (!seconds || isNaN(seconds)) return '0:00';
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }

        // Update play button
        function updateMiniPlayBtn(action) {
            const btn = document.getElementById('mini-play-btn-index');
            if (action === 'play') {
                btn.innerHTML = '<i data-lucide="play" style="width: 18px; height: 18px;"></i>';
            } else {
                btn.innerHTML = '<i data-lucide="pause" style="width: 18px; height: 18px;"></i>';
            }
            lucide.createIcons();
        }

        // Play/Pause
        window.miniPlayPauseIndex = function() {
            if (!audioPlayer) return;
            if (audioPlayer.paused) {
                audioPlayer.play();
            } else {
                audioPlayer.pause();
            }
        };

        // Seek
        window.miniSeekIndex = function(event) {
            if (!audioPlayer) return;
            const bar = event.currentTarget;
            const rect = bar.getBoundingClientRect();
            const clickX = event.clientX - rect.left;
            const percentage = clickX / rect.width;
            audioPlayer.currentTime = percentage * audioPlayer.duration;
        };

        // Toggle visibility
        window.toggleMiniPlayerIndex = function() {
            // Expand player kembali ke full view
            expandPlayerFromMiniPlayer();
        };

        // Expand player kembali dari mini player
        function expandPlayerFromMiniPlayer() {
            const audioState = sessionStorage.getItem('meel_audio_state');
            if (audioState) {
                const state = JSON.parse(audioState);
                // Load watch page sambil maintain audio state
                window.location.href = `watch.php?id=${state.musicId}`;
            }
        };

        // Close mini player
        window.closeMiniPlayerIndex = function() {
            if (audioPlayer) {
                audioPlayer.pause();
            }
            miniPlayerIndex.classList.remove('active');
            sessionStorage.removeItem('meel_audio_state');
            isMiniPlayerIndexActive = false;
        };

        // Handle click musik item untuk play di mini-player
        function setupMusicItemClicks() {
            const musicItems = document.querySelectorAll('.music-item-link');
            musicItems.forEach(item => {
                item.addEventListener('click', function(e) {
                    if (isMiniPlayerIndexActive) {
                        e.preventDefault();
                        const musicId = this.dataset.musicId;
                        const title = this.dataset.title;
                        const artist = this.dataset.artist;
                        const thumbnail = this.dataset.thumbnail;
                        const filename = this.dataset.filename;
                        
                        // Update audio src
                        if (audioPlayer) {
                            audioPlayer.pause();
                            audioPlayer.src = `upload/file/${filename}`;
                            audioPlayer.load();
                            audioPlayer.play().catch(err => console.log("Play:", err));
                            
                            // Update UI
                            updateMiniPlayerIndexUI({
                                musicId, title, artist, thumbnail, filename
                            });
                            
                            // Save state
                            sessionStorage.setItem('meel_audio_state', JSON.stringify({
                                musicId, title, artist, thumbnail, filename, isPlaying: true, currentTime: 0
                            }));
                        }
                    }
                });
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            initMiniPlayerIndex();
            setupMusicItemClicks();
        });

        // For HTMX loaded content
        document.addEventListener('htmx:afterSwap', () => {
            setupMusicItemClicks();
        });

        // Keyboard shortcut 'i' di halaman index untuk toggle expand/minimize
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName.toLowerCase() === 'input' || e.target.tagName.toLowerCase() === 'textarea') return;
            if (e.key.toLowerCase() === 'i' && e.ctrlKey === false && e.altKey === false && e.metaKey === false) {
                if (isMiniPlayerIndexActive) {
                    expandPlayerFromMiniPlayer();
                }
            }
        });

        // Auto-save audio state setiap 5 detik
        setInterval(() => {
            if (isMiniPlayerIndexActive && audioPlayer) {
                const audioState = sessionStorage.getItem('meel_audio_state');
                if (audioState) {
                    const state = JSON.parse(audioState);
                    state.currentTime = audioPlayer.currentTime;
                    state.isPlaying = !audioPlayer.paused;
                    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
                }
            }
        }, 5000);
    </script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>