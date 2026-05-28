lucide.createIcons();
// --- 1. INISIALISASI PLYR ---
const player = new Plyr('#main-player', {
    controls: ['play', 'progress', 'current-time', 'duration', 'mute', 'volume', 'settings'],
    settings: ['speed'],
    speed: {
        selected: 1,
        options: [0.5, 0.75, 1, 1.25, 1.5, 2]
    },
    keyboard: {
        focused: true,
        global: true
    }
});

// --- LOGIKA LOOP CUSTOM ---
const btnLoop = document.getElementById('btn-loop');
const loopText = document.getElementById('loop-text');

window.toggleLoop = function() {
    player.loop = !player.loop;
    updateLoopUI();
};

function updateLoopUI() {
    if (player.loop) {
        btnLoop.classList.remove('bg-gray-800', 'text-gray-400');
        btnLoop.classList.add('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
        loopText.innerText = 'Loop On';
    } else {
        btnLoop.classList.add('bg-gray-800', 'text-gray-400');
        btnLoop.classList.remove('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
        loopText.innerText = 'Loop Off';
    }
}

document.addEventListener('keydown', (e) => {
    if (e.target.tagName.toLowerCase() === 'input' || e.target.tagName.toLowerCase() === 'textarea') return;
    if (e.key.toLowerCase() === 'l') {
        setTimeout(updateLoopUI, 50);
    }
});

// --- 2. VARIABEL GLOBAL ---
const audio = document.getElementById('main-player');
const storageKeyMusic = 'music_pos_' + window.MEEL_MUSIC_CONFIG.id;
let isFinished = false;
let canResume = false;

document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('player-container');
    const savedTime = localStorage.getItem(storageKeyMusic);
    const bitrateDisplay = document.getElementById('realtime-bitrate');
    const cavaContainer = document.getElementById('cava-container');

    // Check jika ada audio state dari mini-player (restore current time & play status)
    const audioState = sessionStorage.getItem('meel_audio_state');
    let shouldRestore = false;
    let restoreTime = 0;
    let restorePlayStatus = false;

    if (audioState) {
        try {
            const state = JSON.parse(audioState);
            // Only restore jika music ID sama
            if (state.musicId == window.MEEL_MUSIC_CONFIG.id) {
                shouldRestore = true;
                restoreTime = state.currentTime;
                restorePlayStatus = state.isPlaying;
            }
        } catch (e) {
            console.log("Error parsing audio state:", e);
        }
    }

    if (savedTime && !isFinished && !shouldRestore) {
        canResume = true;
    }

    // --- 3. SETTING VISUALIZER (CAVA) ---
    let isVisualizerEnabled = window.innerWidth >= 1024; // Default mati di layar <1024px (mobile)
    const isMobile = window.innerWidth < 768;
    const numBars = isMobile ? 20 : 40;
    const bars = [];

    cavaContainer.innerHTML = '';
    for (let i = 0; i < numBars; i++) {
        const bar = document.createElement('div');
        bar.className = 'flex-1 bg-gradient-to-t from-orange-600 to-orange-400 rounded-t-sm transition-all duration-75';
        bar.style.height = '4px';
        bar.style.minWidth = '1px';
        cavaContainer.appendChild(bar);
        bars.push(bar);
    }

    const realFileSize = window.MEEL_MUSIC_CONFIG.fileSizeBytes;
    let baseBitrate = 160;
    let audioCtx, analyser, source, isInitialized = false;
    let animationId;
    let userInteracted = false;

    document.addEventListener('click', () => userInteracted = true, { once: true });
    document.addEventListener('keydown', () => userInteracted = true, { once: true });

    // Toggle UI Initialization
    setTimeout(() => {
        const btnVis = document.getElementById('btn-vis');
        const visText = document.getElementById('vis-text');
        if (isVisualizerEnabled) {
            if(btnVis) {
                btnVis.classList.add('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
                btnVis.classList.remove('bg-gray-800', 'text-gray-400');
            }
            if(visText) visText.innerText = 'Vis On';
            cavaContainer.classList.remove('hidden');
            cavaContainer.style.display = 'flex';
        } else {
            if(btnVis) {
                btnVis.classList.add('bg-gray-800', 'text-gray-400');
                btnVis.classList.remove('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
            }
            if(visText) visText.innerText = 'Vis Off';
            cavaContainer.style.display = 'none';
        }
    }, 100);

    window.toggleVisualizer = function() {
        isVisualizerEnabled = !isVisualizerEnabled;
        const btnVis = document.getElementById('btn-vis');
        const visText = document.getElementById('vis-text');
        
        if (isVisualizerEnabled) {
            if(btnVis) {
                btnVis.classList.remove('bg-gray-800', 'text-gray-400');
                btnVis.classList.add('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
            }
            if(visText) visText.innerText = 'Vis On';
            cavaContainer.classList.remove('hidden');
            cavaContainer.style.display = 'flex';
            if (!isInitialized && !player.paused) {
                if (initAudio()) render();
            } else if (!player.paused) {
                render();
            }
        } else {
            if(btnVis) {
                btnVis.classList.add('bg-gray-800', 'text-gray-400');
                btnVis.classList.remove('bg-orange-500/10', 'text-orange-500', 'border', 'border-orange-500/30');
            }
            if(visText) visText.innerText = 'Vis Off';
            cavaContainer.style.display = 'none';
            cancelAnimationFrame(animationId);
        }
    };

    function initAudio() {
        try {
            if (!userInteracted) {
                console.warn('Cava: Waiting for user gesture...');
                return false;
            }
            if (audioCtx && audioCtx.state !== 'closed') {
                if (audioCtx.state === 'suspended') {
                    audioCtx.resume();
                }
                return true;
            }
            audioCtx = new(window.AudioContext || window.webkitAudioContext)();
            analyser = audioCtx.createAnalyser();
            source = audioCtx.createMediaElementSource(audio);
            source.connect(analyser);
            analyser.connect(audioCtx.destination);
            analyser.fftSize = 256;
            isInitialized = true;
            return true;
        } catch (e) {
            console.error('Cava init error:', e);
            return false;
        }
    }

    function render() {
        if (!isVisualizerEnabled || !isInitialized || player.paused) {
            cancelAnimationFrame(animationId);
            return;
        }
        const dataArray = new Uint8Array(analyser.frequencyBinCount);
        analyser.getByteFrequencyData(dataArray);

        let sum = 0;
        for (let i = 0; i < numBars; i++) {
            const idx = Math.floor(i * (dataArray.length / numBars) * 0.7);
            const val = dataArray[idx];
            bars[i].style.height = `${Math.max(4, (val / 255) * 100)}%`;
            sum += val;
        }
        const fluctuation = ((sum / numBars) / 128) * 0.25;
        if (isInitialized) {
            bitrateDisplay.innerText = Math.round(baseBitrate * (0.85 + fluctuation));
        } else {
            bitrateDisplay.innerText = Math.round(baseBitrate);
        }
        animationId = requestAnimationFrame(render);
    }

    // --- 4. LOGIKA MODAL RESUME ---
    const modal = document.getElementById('resume-modal');
    const btnResume = document.getElementById('btn-resume');
    const btnRestart = document.getElementById('btn-restart');
    const displayTime = document.getElementById('resume-time');

    let countdownText = document.createElement('p');
    countdownText.className = 'text-[9px] text-gray-500 italic mb-4';
    displayTime.parentNode.after(countdownText);

    let autoRestartTimer;
    let countdownInterval;
    let sessionHandled = false;

    function showResumeModal() {
        const savedPos = localStorage.getItem(storageKeyMusic);
        if (savedPos && parseFloat(savedPos) > 10 && (!player.duration || parseFloat(savedPos) < player.duration - 5)) {
            sessionHandled = false;
            clearInterval(countdownInterval);
            clearTimeout(autoRestartTimer);
            let timeLeft = 15;
            const mins = Math.floor(savedPos / 60);
            const secs = Math.floor(savedPos % 60);
            displayTime.innerText = `${mins}:${secs.toString().padStart(2, '0')}`;
            audio.autoplay = false;
            player.autoplay = false;
            audio.currentTime = parseFloat(savedPos);
            modal.classList.remove('hidden');
            countdownText.innerText = `Otomatis putar dari awal dalam ${timeLeft}s...`;
            countdownInterval = setInterval(() => {
                timeLeft--;
                if (timeLeft >= 0) {
                    countdownText.innerText = `Otomatis putar dari awal dalam ${timeLeft}s...`;
                } else {
                    countdownText.innerText = `Otomatis putar dari awal...`;
                    clearInterval(countdownInterval);
                }
            }, 1000);
            autoRestartTimer = setTimeout(() => {
                if (!sessionHandled && !modal.classList.contains('hidden')) {
                    clearInterval(countdownInterval);
                    btnRestart.click();
                }
            }, 15000);
        }
    }

    // Di js_music.php, ubah bagian player.on('ready') menjadi:
    player.on('ready', () => {
        if (realFileSize > 0 && player.duration > 0) {
            baseBitrate = Math.round((realFileSize * 8) / (player.duration * 1000));
        }
        const plyrContainer = document.querySelector('.plyr');
        if (plyrContainer) {
            plyrContainer.tabIndex = 0;
            plyrContainer.focus();
        }

        // Jika ada restore dari mini-player, prioritas restore dibanding savedPos
        if (shouldRestore) {
            player.currentTime = Math.max(0, restoreTime);
            if (restorePlayStatus) {
                player.play().catch(() => console.log("Playback dimulai..."));
            } else {
                player.pause();
            }
            // Clear audio state setelah restore
            sessionStorage.removeItem('meel_audio_state');
        } else {
            const savedPos = localStorage.getItem(storageKeyMusic);
            // Cek apakah perlu memunculkan modal
            if (savedPos && parseFloat(savedPos) > 10 && (!player.duration || parseFloat(savedPos) < player.duration - 5)) {
                showResumeModal();
            } else {
                // Jika tidak ada sesi lanjutan, baru play!
                player.play().catch(() => console.log("Menunggu interaksi user..."));
            }
        }
    });

    audio.addEventListener('loadedmetadata', () => {
        showResumeModal();
    });

    btnResume.onclick = () => {
        sessionHandled = true;
        clearTimeout(autoRestartTimer);
        clearInterval(countdownInterval);
        const savedPos = localStorage.getItem(storageKeyMusic);
        player.currentTime = parseFloat(savedPos);
        player.play();
        modal.classList.add('hidden'); // Menutup modal dengan benar
    };

    btnRestart.onclick = () => {
        sessionHandled = true;
        clearTimeout(autoRestartTimer);
        clearInterval(countdownInterval);
        localStorage.removeItem(storageKeyMusic);
        player.currentTime = 0;
        player.play();
        modal.classList.add('hidden'); // Menutup modal dengan benar
    };

    // --- 5. EVENT LISTENER PLYR ---
    player.on('play', () => {
        isFinished = false;
        container.classList.add('playing');
        const vinyl = document.querySelector('.vinyl-wrap .vinyl-spin');
        if (vinyl) vinyl.classList.add('playing');
        
        if (isVisualizerEnabled) {
            if (!isInitialized) {
                if (initAudio()) {
                    render();
                }
            } else {
                render();
            }
        }
    });

    player.on('pause', () => {
        container.classList.remove('playing');
        const vinyl = document.querySelector('.vinyl-wrap .vinyl-spin');
        if (vinyl) vinyl.classList.remove('playing');
        cancelAnimationFrame(animationId);
    });

    player.on('timeupdate', () => {
        if (!isFinished && player.currentTime > 0 && player.currentTime < player.duration - 1) {
            localStorage.setItem(storageKeyMusic, player.currentTime);
        }
    });

    player.on('ended', () => {
        const nextPlaylistTrack = window.MEEL_MUSIC_CONFIG.nextSongUrl;

        if (nextPlaylistTrack !== "") {
            window.location.href = nextPlaylistTrack;
        } else {
            localStorage.removeItem(storageKeyMusic);
            const nextTrack = document.querySelector('.rekomendasi-item');
            if (nextTrack) window.location.href = nextTrack.href;
        }
    });
}); // Tutup DOMContentLoaded

// --- FUNGSI GLOBAL ---
window.toggleReply = function(id) {
    const element = document.getElementById(id);
    if (element) {
        element.classList.toggle('hidden');
        const input = element.querySelector('input[type="text"]');
        if (input && !element.classList.contains('hidden')) input.focus();
    }
}

// === MINI PLAYER FUNCTIONALITY ===
const miniPlayer = document.getElementById('mini-player');
const playerContainer = document.getElementById('player-container');
let isMiniPlayerActive = false;

// Toggle mini-player dengan keyboard shortcut 'i'
document.addEventListener('keydown', (e) => {
    if (e.target.tagName.toLowerCase() === 'input' || e.target.tagName.toLowerCase() === 'textarea') return;
    if (e.key.toLowerCase() === 'i' && e.ctrlKey === false && e.altKey === false && e.metaKey === false) {
        toggleMiniPlayer();
    }
});

// Click mini-player header untuk toggle (expand/minimize)
const miniPlayerHeader = document.querySelector('.mini-player-header');
if (miniPlayerHeader) {
    miniPlayerHeader.addEventListener('click', () => {
        if (isMiniPlayerActive) {
            toggleMiniPlayer();
        }
    });
}

// Toggle mini player visibility
window.toggleMiniPlayer = function() {
    isMiniPlayerActive = !isMiniPlayerActive;
    
    if (isMiniPlayerActive) {
        miniPlayer.classList.add('active');
        playerContainer.style.display = 'none';
        updateMiniPlayerUI();
        
        // Simpan audio state
        saveAudioState();
        
        // Load index content dengan HTMX (no page reload, smooth)
        const mainContent = document.querySelector('main');
        if (mainContent) {
            htmx.ajax('GET', 'index.php?content_only=1', {
                target: mainContent,
                swap: 'innerHTML',
                onLoad: function(xhr) {
                    // Reinit lucide icons
                    lucide.createIcons();
                    // Setup mini player
                    if (window.initMiniPlayerIndex) {
                        window.initMiniPlayerIndex();
                    }
                }
            });
        }
    } else {
        // Expand kembali ke full player, tetap keep index-content
        miniPlayer.classList.remove('active');
        playerContainer.style.display = 'block';
        
        // Scroll to top agar player visible
        playerContainer.scrollIntoView({ behavior: 'smooth' });
    }
};

// Save audio state to sessionStorage
function saveAudioState() {
    const state = {
        musicId: window.MEEL_MUSIC_CONFIG.id,
        currentTime: player.currentTime,
        isPlaying: !player.paused,
        title: window.MEEL_MUSIC_CONFIG.title,
        artist: window.MEEL_MUSIC_CONFIG.artist,
        thumbnail: window.MEEL_MUSIC_CONFIG.thumbnail,
        filename: window.MEEL_MUSIC_CONFIG.filename
    };
    sessionStorage.setItem('meel_audio_state', JSON.stringify(state));
}

// Auto-save audio state setiap 5 detik
setInterval(() => {
    if (isMiniPlayerActive) {
        saveAudioState();
    }
}, 5000);

// Play/Pause dari mini player
window.miniPlayPause = function() {
    if (player.paused) {
        player.play();
    } else {
        player.pause();
    }
    updateMiniPlayerUI();
};

// Seek dari mini player
window.miniSeek = function(event) {
    const bar = event.currentTarget;
    const rect = bar.getBoundingClientRect();
    const clickX = event.clientX - rect.left;
    const percentage = clickX / rect.width;
    player.currentTime = percentage * player.duration;
};

// Update mini player UI
function updateMiniPlayerUI() {
    if (!isMiniPlayerActive) return;

    const miniPlayBtn = document.getElementById('mini-play-btn');
    const miniProgressFill = document.getElementById('mini-progress-fill');
    const miniCurrentTime = document.getElementById('mini-current-time');
    const miniDuration = document.getElementById('mini-duration');

    // Update play button
    if (player.paused) {
        miniPlayBtn.innerHTML = '<i data-lucide="play" style="width: 18px; height: 18px;"></i>';
    } else {
        miniPlayBtn.innerHTML = '<i data-lucide="pause" style="width: 18px; height: 18px;"></i>';
    }

    // Update progress bar
    const percentage = (player.currentTime / player.duration) * 100;
    miniProgressFill.style.width = percentage + '%';

    // Update time display
    miniCurrentTime.textContent = formatTime(player.currentTime);
    miniDuration.textContent = formatTime(player.duration);

    lucide.createIcons();
}

// Helper function to format time
function formatTime(seconds) {
    if (!seconds || isNaN(seconds)) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
}

// Update mini player saat main player update
player.on('play', () => {
    updateMiniPlayerUI();
});

player.on('pause', () => {
    updateMiniPlayerUI();
});

player.on('timeupdate', () => {
    updateMiniPlayerUI();
});

player.on('loadedmetadata', () => {
    updateMiniPlayerUI();
});
