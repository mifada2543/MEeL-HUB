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

function renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter)
{
?>
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
<?php
}

// Check audio state dari sessionStorage (via hidden input)
$audio_state = null;
if (isset($_GET['audio_state'])) {
    $audio_state = json_decode($_GET['audio_state'], true);
}

if (isset($_GET['content_only'])) {
    renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter);
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MEeL Music | Library</title>
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <?php include '../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/music.css">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/htmx.js"></script>
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
            <?php renderLibraryContent($artist_filter, $total_music, $data_init, $format_filter); ?>
        </main>
    </div>

    <!-- MINI PLAYER INDEX (Spotify-style) -->
    <div id="mini-player-index" aria-label="Mini Player">

        <!-- Seekbar atas -->
        <div class="mp-seekbar" id="mp-seekbar-index" onclick="miniSeekIndex(event)" title="Klik untuk seek">
            <div class="mp-seekbar-fill" id="mp-seekbar-fill-index"></div>
            <div class="mp-seekbar-thumb" id="mp-seekbar-thumb-index"></div>
        </div>

        <div class="mp-body">
            <!-- Kiri: art + info -->
            <div class="mp-track" onclick="expandPlayerFromMiniPlayer()" title="Buka player penuh">
                <div class="mp-art">
                    <img id="mini-thumbnail-index" src="upload/thumbnail/default.png" alt="cover">
                    <div class="mp-art-overlay">
                        <i data-lucide="maximize-2" style="width:14px;height:14px;"></i>
                    </div>
                </div>
                <div class="mp-meta">
                    <div class="mp-title" id="mini-title-index">Tidak ada musik</div>
                    <div class="mp-artist" id="mini-artist-index">Unknown</div>
                </div>
            </div>

            <!-- Tengah: kontrol -->
            <div class="mp-controls">
                <button class="mp-btn mp-btn-ghost" onclick="miniPrevIndex()" id="mp-prev-btn-index" title="Sebelumnya">
                    <i data-lucide="skip-back" style="width:16px;height:16px;"></i>
                </button>
                <button class="mp-btn mp-btn-primary" onclick="miniPlayPauseIndex()" id="mini-play-btn-index" title="Play / Pause">
                    <i data-lucide="play" style="width:18px;height:18px;"></i>
                </button>
                <button class="mp-btn mp-btn-ghost" onclick="miniNextIndex()" id="mp-next-btn-index" title="Berikutnya">
                    <i data-lucide="skip-forward" style="width:16px;height:16px;"></i>
                </button>
            </div>

            <!-- Kanan: waktu + tutup -->
            <div class="mp-right">
                <div class="mp-time">
                    <span id="mini-current-time-index">0:00</span>
                    <span class="mp-time-sep">/</span>
                    <span id="mini-duration-index">0:00</span>
                </div>
                <button class="mp-btn mp-btn-ghost mp-close" onclick="closeMiniPlayerIndex()" title="Tutup">
                    <i data-lucide="chevron-down" style="width:16px;height:16px;"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // =============================================
        // === MINI PLAYER INDEX (Spotify-style) ===
        // =============================================
        const miniPlayerIndex = document.getElementById('mini-player-index');
        let audioPlayer = null;
        let isMiniPlayerIndexActive = false;
        let currentState = null; // state object aktif

        // --- Helpers ---
        function fmtTime(s) {
            if (!s || isNaN(s)) return '0:00';
            return `${Math.floor(s/60)}:${String(Math.floor(s%60)).padStart(2,'0')}`;
        }

        function saveIndexState() {
            if (!currentState || !audioPlayer) return;
            currentState.currentTime = audioPlayer.currentTime;
            currentState.isPlaying = !audioPlayer.paused;
            sessionStorage.setItem('meel_audio_state', JSON.stringify(currentState));
        }

        // --- Buat / ganti audio element ---
        function loadAudio(state, autoplay) {
            if (!audioPlayer) {
                audioPlayer = document.createElement('audio');
                audioPlayer.id = 'hidden-audio-player';
                audioPlayer.preload = 'metadata';
                document.body.appendChild(audioPlayer);

                audioPlayer.addEventListener('timeupdate', updateIndexUI);
                audioPlayer.addEventListener('play', () => setPlayIcon('pause'));
                audioPlayer.addEventListener('pause', () => setPlayIcon('play'));
                audioPlayer.addEventListener('ended', () => miniNextIndex());
            }
            currentState = state;
            audioPlayer.src = `upload/file/${state.filename}`;
            audioPlayer.load();
            if (autoplay) {
                audioPlayer.currentTime = state.currentTime || 0;
                audioPlayer.play().catch(() => {});
            }
        }

        // --- Update seluruh UI ---
        function updateIndexUI() {
            if (!audioPlayer || !currentState) return;
            const pct = audioPlayer.duration > 0 ?
                (audioPlayer.currentTime / audioPlayer.duration) * 100 : 0;

            // Seekbar
            const fill = document.getElementById('mp-seekbar-fill-index');
            const thumb = document.getElementById('mp-seekbar-thumb-index');
            if (fill) fill.style.width = pct + '%';
            if (thumb) thumb.style.left = pct + '%';

            // Waktu
            const ct = document.getElementById('mini-current-time-index');
            const dt = document.getElementById('mini-duration-index');
            if (ct) ct.textContent = fmtTime(audioPlayer.currentTime);
            if (dt) dt.textContent = fmtTime(audioPlayer.duration);

            // Thumbnail / judul / artis
            const img = document.getElementById('mini-thumbnail-index');
            const title = document.getElementById('mini-title-index');
            const artist = document.getElementById('mini-artist-index');
            if (img) img.src = `upload/thumbnail/${currentState.thumbnail}`;
            if (title) title.textContent = currentState.title || 'Unknown';
            if (artist) artist.textContent = currentState.artist || 'Unknown';
        }

        function setPlayIcon(icon) {
            const btn = document.getElementById('mini-play-btn-index');
            if (btn) {
                btn.innerHTML = `<i data-lucide="${icon}" style="width:18px;height:18px;"></i>`;
                lucide.createIcons();
            }
        }

        // --- Init: baca sessionStorage ---
        function initMiniPlayerIndex() {
            // Daftarkan event klik pada bar mini player agar ketika diklik langsung pindah ke watch
            const miniPlayerBar = document.getElementById('mini-player-bar'); // Sesuaikan dengan ID elemen mini-player Anda
            if (miniPlayerBar) {
                miniPlayerBar.style.cursor = 'pointer';
                miniPlayerBar.addEventListener('click', (e) => {
                    // Jangan trigger pindah halaman jika yang diklik adalah tombol play/pause mini
                    if (e.target.closest('#mini-play-btn') || e.target.closest('.mp-close')) return;
                    expandPlayerFromMiniPlayer();
                });
            }
            const raw = sessionStorage.getItem('meel_audio_state');
            if (!raw) return;
            try {
                const state = JSON.parse(raw);
                isMiniPlayerIndexActive = true;
                loadAudio(state, state.isPlaying);
                updateIndexUI();
                miniPlayerIndex.classList.add('active');
            } catch (e) {
                console.warn('Mini player init error:', e);
            }
        }

        // --- Play / Pause ---
        window.miniPlayPauseIndex = function() {
            if (!audioPlayer) return;
            audioPlayer.paused ? audioPlayer.play() : audioPlayer.pause();
        };

        // --- Seek ---
        window.miniSeekIndex = function(event) {
            if (!audioPlayer) return;
            const rect = event.currentTarget.getBoundingClientRect();
            const pct = (event.clientX - rect.left) / rect.width;
            audioPlayer.currentTime = Math.max(0, Math.min(pct * audioPlayer.duration, audioPlayer.duration));
        };

        // --- Next via HTMX ---
        window.miniNextIndex = function() {
            if (!currentState) return;
            const nextUrl = currentState.nextSongUrl;
            if (nextUrl && nextUrl !== '') {
                saveIndexState();
                // HTMX load halaman lagu berikutnya, mirip pola video
                htmx.ajax('GET', nextUrl, {
                    target: 'body',
                    swap: 'innerHTML'
                });
                window.history.pushState({}, '', nextUrl);
            }
        };

        // --- Prev: restart jika >3 detik, else coba lagu sebelumnya ---
        window.miniPrevIndex = function() {
            if (!audioPlayer) return;
            if (audioPlayer.currentTime > 3) {
                audioPlayer.currentTime = 0;
                return;
            }
            audioPlayer.currentTime = 0;
        };

        // --- Perbaikan Fungsi Expand di index.php ---
        function expandPlayerFromMiniPlayer() {
            // Ambil data state terakhir untuk mendapatkan ID lagu atau URL-nya
            const savedState = sessionStorage.getItem('meel_audio_state');
            if (savedState) {
                const state = JSON.parse(savedState);
                // Pastikan saat menyimpan lagu dari klik list, Anda menyertakan ID atau URL watch-nya
                if (state.watchUrl) {
                    window.location.href = state.watchUrl;
                } else if (state.id) {
                    window.location.href = `watch.php?id=${state.id}`;
                } else {
                    // Fallback jika tidak ada ID (mencari link dari daftar lagu yang namanya sama)
                    const fallbackItem = document.querySelector(`[data-filename="${state.filename}"]`);
                    if (fallbackItem && fallbackItem.closest('a')) {
                        window.location.href = fallbackItem.closest('a').getAttribute('href');
                    }
                }
            }
        }

        // --- Tutup ---
        window.closeMiniPlayerIndex = function() {
            if (audioPlayer) audioPlayer.pause();
            miniPlayerIndex.classList.remove('active');
            sessionStorage.removeItem('meel_audio_state');
            isMiniPlayerIndexActive = false;
            currentState = null;
        };

        function setupMusicItemClicks() {
            document.querySelectorAll('.music-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    // Jika klik pada tombol download/share, abaikan
                    if (e.target.closest('.no-player')) return;

                    const state = {
                        id: this.dataset.id, // <--- PASTIKAN DATA ID INI ADA DI ELEMEN .music-item ANDA
                        title: this.dataset.title,
                        artist: this.dataset.artist,
                        thumbnail: this.dataset.thumbnail,
                        filename: this.dataset.filename,
                        watchUrl: this.closest('a') ? this.closest('a').getAttribute('href') : '', // Ambil URL asli link-nya
                        nextSongUrl: '',
                        currentTime: 0,
                        isPlaying: true,
                    };
                    loadAudio(state, true);
                    updateIndexUI();
                    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
                });
            });
        }

        // --- Boot & Perbaikan Sinkronisasi ---
        function bootPlayerIndex() {
            initMiniPlayerIndex();
            setupMusicItemClicks();
        }

        // 1. Jalankan saat halaman pertama kali dimuat (Hard Reload / F5)
        document.addEventListener('DOMContentLoaded', () => {
            bootPlayerIndex();
        });

        document.addEventListener('htmx:afterSwap', () => {
            if (typeof lucide !== 'undefined') lucide.createIcons();
            bootPlayerIndex(); // Memastikan player di-inisialisasi ulang dengan data sessionStorage terbaru
        });

        // Keyboard 'i' → Pindah kembali ke full player (watch.php)
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName.toLowerCase() === 'input' ||
                e.target.tagName.toLowerCase() === 'textarea') return;

            if (e.key.toLowerCase() === 'i' && !e.ctrlKey && !e.altKey && !e.metaKey) {
                e.preventDefault();
                // Cek apakah ada lagu yang sedang aktif di index
                const savedState = sessionStorage.getItem('meel_audio_state');
                if (savedState) {
                    saveIndexState(); // Simpan detik terakhir di index dulu
                    expandPlayerFromMiniPlayer(); // Buka halaman watch.php
                }
            }
        });

        // Auto-save tiap 5 detik
        setInterval(() => {
            if (isMiniPlayerIndexActive) saveIndexState();
        }, 5000);
    </script>
    <?php include '../partials/footer.php'; ?>
</body>

</html>