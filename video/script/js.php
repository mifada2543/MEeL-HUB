<script>
    lucide.createIcons();
    // 1. Deklarasi Variabel Utama
    const videoElement = document.getElementById('main-video');
    const videoSrc = <?= json_encode($video_src) ?>;
    const isHls = <?= $is_hls ? 'true' : 'false' ?>;
    const vttSrc = <?= json_encode($vtt_src ?? '') ?>;
    let player;
    let hls;

    // 2. Konfigurasi UI Plyr Dasar
    const plyrOptions = {
        controls: ['play-large', 'play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'captions', 'settings', 'pip', 'airplay', 'fullscreen'],
        settings: ['quality', 'speed'],
        speed: {
            selected: 1,
            options: [0.5, 0.75, 1, 1.25, 1.5, 2]
        },
        tooltips: {
            controls: true,
            seek: true
        },
        clickToPlay: true,
        keyboard: {
            focused: true,
            global: true
        },
        previewThumbnails: {
            enabled: vttSrc !== "",
            src: vttSrc
        }
    };

    // 3. Inisialisasi Engine Pemutar
    if (isHls && Hls.isSupported()) {
        hls = new Hls({
            maxBufferLength: 30,
            enableWorker: true,
            backBufferLength: 60
        });

        hls.loadSource(videoSrc);
        hls.attachMedia(videoElement);

        hls.on(Hls.Events.MANIFEST_PARSED, function() {
            const availableQualities = hls.levels.map((l) => l.bitrate);

            if (availableQualities.length > 1) {
                plyrOptions.quality = {
                    default: availableQualities[0],
                    options: availableQualities,
                    forced: true,
                    onChange: (newBitrate) => {
                        // Cari index level berdasarkan bitrate yang dipilih
                        const levelIndex = hls.levels.findIndex(l => l.bitrate === newBitrate);
                        hls.currentLevel = levelIndex;
                    }
                };

                plyrOptions.i18n = {
                    qualityLabel: {}
                };

                hls.levels.forEach((level) => {
                    // Misal: "1080p (5Mbps)" atau sesuai NAME di m3u8 jika ada
                    const label = level.name ? level.name : `${level.height}p (${Math.round(level.bitrate / 1000)}kbps)`;
                    plyrOptions.i18n.qualityLabel[level.bitrate] = label;
                });
            }

            // Inisialisasi Plyr setelah opsi diperbarui
            player = new Plyr(videoElement, plyrOptions);
            setupMeelPlayerEvents();
        });
    } else {
        // Untuk MP4 biasa atau video single bitrate
        player = new Plyr(videoElement, plyrOptions);
        if (isHls) videoElement.src = videoSrc;
        setupMeelPlayerEvents();
    }

    // 4. Kumpulan Event & Fitur Player (Tetap Sama Seperti Versi Lama Kamu)
    function setupMeelPlayerEvents() {
        window.player = player;

        const storageKeyVideo = 'video_pos_<?= $id ?>';
        const modal = document.getElementById('resume-modal');
        const btnResume = document.getElementById('btn-resume');
        const btnRestart = document.getElementById('btn-restart');
        const displayTime = document.getElementById('resume-time');
        const countdownText = document.getElementById('resume-countdown');

        player.on('ready', event => {
            const savedPos = localStorage.getItem(storageKeyVideo);
            if (savedPos && parseFloat(savedPos) > 10) {
                const mins = Math.floor(savedPos / 60);
                const secs = Math.floor(savedPos % 60);
                if (displayTime) displayTime.innerText = `${mins}:${secs.toString().padStart(2, '0')}`;
                if (modal) modal.classList.remove('hidden');

                let timeLeft = 15;
                const countdownInterval = setInterval(() => {
                    timeLeft--;
                    if (timeLeft > 0) {
                        if (countdownText) countdownText.innerText = `Otomatis ulang dari awal dalam ${timeLeft}s...`;
                    } else {
                        clearInterval(countdownInterval);
                    }
                }, 1000);

                const autoRestartTimer = setTimeout(() => {
                    if (btnRestart) btnRestart.click();
                }, 15000);

                if (btnResume) {
                    btnResume.onclick = () => {
                        clearTimeout(autoRestartTimer);
                        clearInterval(countdownInterval);
                        player.currentTime = parseFloat(savedPos);
                        player.play();
                        modal.classList.add('hidden');
                    };
                }

                if (btnRestart) {
                    btnRestart.onclick = () => {
                        clearTimeout(autoRestartTimer);
                        clearInterval(countdownInterval);
                        localStorage.removeItem(storageKeyVideo);
                        player.currentTime = 0;
                        player.play();
                        modal.classList.add('hidden');
                    };
                }
            } else {
                player.play().catch(() => console.log("Menunggu interaksi user..."));
            }
        });

        player.on('timeupdate', () => {
            if (player.currentTime > 0) localStorage.setItem(storageKeyVideo, player.currentTime);
        });

        player.on('ended', async () => {
            if (player.loop) return;
            localStorage.removeItem(storageKeyVideo);
            const nextVideoLink = document.querySelector('.rekomendasi-item');
            if (!nextVideoLink) return;

            // Jika SEDANG FULLSCREEN: Jangan reload halaman. Swap data di belakang layar.
            if (player.fullscreen.active) {
                try {
                    // Ambil HTML halaman video berikutnya
                    const response = await fetch(nextVideoLink.href);
                    const html = await response.text();
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    // 1. Update URL bar & Title agar riwayat browser tetap benar
                    window.history.pushState({}, '', nextVideoLink.href);
                    document.title = doc.title;

                    // 2. Ekstrak properti video baru dari DOM yang didapat
                    const newVideoEl = doc.getElementById('main-video');
                    if (!newVideoEl) throw new Error("Video elemen tidak ditemukan");

                    const newSrc = newVideoEl.getAttribute('data-src');
                    const newIsHls = newVideoEl.getAttribute('data-ishls') === 'true';
                    const newPoster = newVideoEl.getAttribute('data-poster');

                    // 3. Tukar Info Video, Komentar, dan Rekomendasi secara senyap
                    const swapElements = ['video-info', 'comment-section', 'recommendation-column'];
                    swapElements.forEach(id => {
                        const currentEl = document.getElementById(id);
                        const newEl = doc.getElementById(id);
                        if (currentEl && newEl) currentEl.innerHTML = newEl.innerHTML;
                    });

                    // Render ulang Icon & HTMX untuk elemen baru (Like/Komentar/dll)
                    if (window.lucide) window.lucide.createIcons();
                    if (window.htmx) htmx.process(document.body);

                    // 4. Update pemutar video yang sedang jalan
                    player.poster = newPoster;

                    if (newIsHls) {
                        // Jika video baru adalah HLS
                        if (!hls && Hls.isSupported()) {
                            hls = new Hls();
                            hls.attachMedia(videoElement);
                        }
                        hls.loadSource(newSrc);
                        player.play();
                    } else {
                        // Jika video baru adalah MP4 biasa
                        if (hls) {
                            hls.destroy();
                            hls = null;
                        }
                        player.source = {
                            type: 'video',
                            sources: [{
                                src: newSrc,
                                type: 'video/mp4'
                            }]
                        };
                        player.play();
                    }
                } catch (err) {
                    console.error("Gagal transisi seamless, fallback ke reload:", err);
                    window.location.href = nextVideoLink.href;
                }
            } else {
                // Jika TIDAK FULLSCREEN: Lakukan transisi pindah halaman seperti biasa
                window.location.href = nextVideoLink.href;
            }
        });

        player.on('enterfullscreen', () => {
            if (screen.orientation?.lock) {
                screen.orientation.lock('landscape').catch(() => {});
            }
        });

        player.on('exitfullscreen', () => {
            if (screen.orientation?.unlock) {
                screen.orientation.unlock();
            }
        });

        setupMobileGestures();
    }

    // 5. Fungsi Penunjang UI (Loop & Gestures Tetap Sama)
    function updateLoopUI() {
        const btnLoop = document.getElementById('btn-loop');
        const loopText = document.getElementById('loop-text');
        if (!btnLoop || !loopText) return;

        if (player.loop) {
            btnLoop.classList.remove('bg-gray-800', 'text-gray-400');
            btnLoop.classList.add('bg-red-500/10', 'text-red-400', 'border-red-600/30');
            loopText.innerText = 'Loop On';
        } else {
            btnLoop.classList.add('bg-gray-800', 'text-gray-400');
            btnLoop.classList.remove('bg-red-500/10', 'text-red-400', 'border-red-600/30');
            loopText.innerText = 'Loop Off';
        }
    }

    window.toggleLoop = function() {
        player.loop = !player.loop;
        updateLoopUI();
    };

    // --- FITUR MINI PLAYER SPA ---
    let isMiniPlayerActive = false;
    const watchUrl = window.location.href;

    window.toggleMiniPlayer = async function() {
        const videoWrapper = document.getElementById('main-video-wrapper');
        const detailsWrapper = document.getElementById('watch-details-wrapper');
        const recWrapper = document.getElementById('recommendation-wrapper');
        const appContentGrid = document.getElementById('app-content-grid');
        const leftColumn = document.getElementById('left-column');

        if (!isMiniPlayerActive) {
            // ---> MASUK MODE MINI (Tampilkan Index)
            isMiniPlayerActive = true;
            videoWrapper.classList.add('mini-player-mode');

            // Sembunyikan elemen watch.php
            if (detailsWrapper) detailsWrapper.style.display = 'none';
            if (recWrapper) recWrapper.style.display = 'none';

            // Lepas struktur grid agar index punya ruang full
            if (appContentGrid) appContentGrid.classList.remove('grid', 'grid-cols-1', 'lg:grid-cols-3', 'gap-8');
            if (leftColumn) leftColumn.classList.remove('lg:col-span-2', 'space-y-5');

            let tempIndex = document.getElementById('temp-index-content');
            if (!tempIndex) {
                // Buat kontainer untuk halaman index secara dinamis
                tempIndex = document.createElement('div');
                tempIndex.id = 'temp-index-content';
                tempIndex.className = 'w-full animate-fade-in';
                if (appContentGrid) appContentGrid.appendChild(tempIndex);

                try {
                    // Fetch konten index.php di belakang layar
                    const response = await fetch('index.php');
                    const html = await response.text();
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const indexMain = doc.querySelector('main');

                    if (indexMain) {
                        tempIndex.innerHTML = indexMain.innerHTML;
                        window.history.pushState({
                            miniPlayer: true
                        }, '', 'index.php'); // Ubah URL palsu

                        // Render ulang htmx dan icon untuk layout index yang baru ditarik
                        if (window.lucide) window.lucide.createIcons();
                        if (window.htmx) htmx.process(tempIndex);
                    }
                } catch (err) {
                    console.error("Gagal memuat index:", err);
                }
            } else {
                // Jika sudah pernah ditarik, cukup tampilkan ulang
                tempIndex.style.display = 'block';
                window.history.pushState({
                    miniPlayer: true
                }, '', 'index.php');
            }
        } else {
            // ---> KELUAR MODE MINI (Kembali ke Watch)
            isMiniPlayerActive = false;
            videoWrapper.classList.remove('mini-player-mode');

            // Sembunyikan halaman index temp
            const tempIndex = document.getElementById('temp-index-content');
            if (tempIndex) tempIndex.style.display = 'none';

            // Kembalikan struktur grid watch.php
            if (appContentGrid) appContentGrid.classList.add('grid', 'grid-cols-1', 'lg:grid-cols-3', 'gap-8');
            if (leftColumn) leftColumn.classList.add('lg:col-span-2', 'space-y-5');

            // Munculkan lagi info & komen
            if (detailsWrapper) detailsWrapper.style.display = 'block';
            if (recWrapper) recWrapper.style.display = 'block';

            // Kembalikan URL asli
            window.history.pushState({}, '', watchUrl);
        }
    };

    // Listener Keyboard untuk 'i' (Mini Player) dan 'l' (Loop)
    window.addEventListener('keydown', (e) => {
        if (['INPUT', 'TEXTAREA'].includes(document.activeElement.tagName)) return;
        if (e.key.toLowerCase() === 'l') {
            setTimeout(updateLoopUI, 50);
        }
        if (e.key.toLowerCase() === 'i') {
            toggleMiniPlayer();
        }
    });

    // Listener Klik pada Kontainer Video untuk membesarkan ulang
    document.addEventListener('DOMContentLoaded', () => {
        const videoWrapper = document.getElementById('main-video-wrapper');
        if (videoWrapper) {
            videoWrapper.addEventListener('click', (e) => {
                // Hanya cegat klik jika sedang dalam mode mini-player
                if (isMiniPlayerActive) {
                    e.preventDefault();
                    toggleMiniPlayer();
                }
            });
        }
    });

    // Listener tombol 'Back' browser
    window.addEventListener('popstate', (e) => {
        if (isMiniPlayerActive && window.location.href === watchUrl) {
            toggleMiniPlayer();
        }
    });

    function setupMobileGestures() {
        let lastTap = 0;
        const container = document.querySelector('.plyr');
        if (!container) return;

        container.addEventListener('touchstart', (e) => {
            const now = Date.now();
            if (now - lastTap < 300) {
                const touchX = e.changedTouches[0].clientX;
                if (touchX < window.innerWidth / 2) {
                    player.rewind(5);
                    tampilkanIndikator('⏪ -5s');
                } else {
                    player.forward(5);
                    tampilkanIndikator('+5s ⏩');
                }
            }
            lastTap = now;
        });
    }

    function tampilkanIndikator(teks) {
        const container = document.querySelector('.plyr');
        if (!container) return;
        const ind = document.createElement('div');
        ind.innerText = teks;
        ind.className = 'absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-black/60 text-white font-black py-2 px-4 rounded-full pointer-events-none z-50 transition-opacity duration-500';
        container.appendChild(ind);
        setTimeout(() => {
            ind.style.opacity = '0';
            setTimeout(() => ind.remove(), 500);
        }, 500);
    }

    window.toggleReply = function(id) {
        const element = document.getElementById(id);
        if (element) {
            element.classList.toggle('hidden');
            if (!element.classList.contains('hidden')) {
                const input = element.querySelector('input[type="text"]');
                if (input) input.focus();
            }
        }
    };
</script>