<?php
require_once 'auth/auth.php';
require_once 'auth/config.php';
require_once 'auth/Transcoder.php';
include 'helpers.php';

$transcoder   = new Transcoder($conn, $_SESSION['user_id']);
$download_link   = null;
$output_filename = "";
$format          = "mp3";

if (isset($_POST['start_transcode'])) {
    $video_id = (int)($_POST['video_id'] ?? 0);
    $format   = $_POST['format'] ?? 'mp3';

    if ($video_id <= 0) {
        echo "<script>alert('ID Video harus berupa angka valid!'); window.location='transcode.php';</script>";
        exit;
    }

    $result = $transcoder->transcodeVideo($video_id, $format);

    if ($result['status'] === 'success') {
        $download_link   = $result['download_link'];
        $output_filename = $result['output_filename'];
    } else {
        echo "<script>alert('" . addslashes($result['msg']) . "'); window.location='transcode.php';</script>";
        exit;
    }
}

// Isi otomatis Video ID jika datang dari link watch_video.php
$video_id_value = isset($_GET['id']) ? (int)$_GET['id'] : "";
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <title>MEeL Transcoder Pro</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
            color: #d1d5db;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        input[type="radio"]:checked+label {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
            color: white;
        }
    </style>
</head>

<body class="flex items-center justify-center min-h-screen p-6">
    <div class="max-w-md w-full">
        <div class="glass p-10 rounded-[3rem] shadow-2xl border border-white/10">
            <h1 class="text-3xl font-black text-white italic text-center mb-8 tracking-tighter">TRANSCODE<span class="text-red-600">.</span></h1>

            <?php if ($download_link): ?>
                <div class="text-center">
                    <div class="w-20 h-20 bg-green-500/10 rounded-3xl flex items-center justify-center mx-auto mb-6 border border-green-500/20">
                        <i data-lucide="download" class="w-10 h-10 text-green-500"></i>
                    </div>
                    <h2 class="text-white font-bold mb-1 italic"><?= strtoupper($format) ?> SIAP!</h2>
                    <p class="text-[10px] text-gray-500 mb-8 uppercase tracking-widest">Klik tombol di bawah untuk unduh</p>
                    <a href="<?= htmlspecialchars($download_link) ?>" download="<?= htmlspecialchars($output_filename) ?>"
                        class="block w-full bg-white text-black font-black py-4 rounded-2xl hover:bg-gray-200 transition shadow-xl">
                        UNDUH SEKARANG
                    </a>
                    <button onclick="window.location='transcode.php'"
                        class="mt-6 text-[10px] text-red-500 font-bold uppercase tracking-widest hover:underline">
                        Transcode Video Lain
                    </button>
                </div>
            <?php else: ?>
                <form action="" method="POST" class="space-y-6">
                    <div>
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest ml-2">Video ID</label>
                        <input type="number"
                            name="video_id"
                            value="<?= htmlspecialchars($video_id_value) ?>"
                            placeholder="00"
                            min="1"
                            step="1"
                            oninput="this.value = this.value.replace(/[^0-9]/g, '');"
                            required
                            class="w-full bg-white/5 border border-white/10 rounded-2xl p-4 mt-2 text-center text-xl font-bold focus:border-red-600 outline-none transition text-white">
                    </div>

                    <div>
                        <label class="text-[10px] font-bold text-gray-500 uppercase tracking-widest ml-2">Pilih Format</label>
                        <div class="grid grid-cols-3 gap-3 mt-2">
                            <input type="radio" name="format" value="mp3" id="f-mp3" class="hidden" checked>
                            <label for="f-mp3" class="border border-white/10 rounded-xl py-3 text-center text-xs font-bold cursor-pointer transition">MP3</label>

                            <input type="radio" name="format" value="ogg" id="f-ogg" class="hidden">
                            <label for="f-ogg" class="border border-white/10 rounded-xl py-3 text-center text-xs font-bold cursor-pointer transition">OGG</label>

                            <input type="radio" name="format" value="m4a" id="f-m4a" class="hidden">
                            <label for="f-m4a" class="border border-white/10 rounded-xl py-3 text-center text-xs font-bold cursor-pointer transition">M4A</label>
                        </div>
                    </div>

                    <button name="start_transcode"
                        class="w-full bg-red-600 hover:bg-red-500 text-white font-black py-5 rounded-2xl transition-all shadow-lg shadow-red-600/20">
                        MULAI PROSES
                    </button>
                </form>
            <?php endif; ?>

            <div class="flex items-center justify-center mt-8 pt-6 border-t border-white/5">
                <a href="video/index.php" class="text-[10px] text-blue-500 font-black uppercase tracking-[0.2em] hover:text-blue-400 transition">Kembali ke Beranda</a>
            </div>
        </div>
    </div>
    <script>
        lucide.createIcons();
        // File JS Anda (misalnya script.js atau di dalam <script> pada transcode.php)

        function finishTranscode(downloadUrl) {
            // 1. Sembunyikan elemen animasi/progress bar agar tidak menimpa UI Audio
            const progressContainer = document.getElementById('progress-container'); // Sesuaikan ID-nya
            if (progressContainer) {
                progressContainer.style.display = 'none';
            }

            // 2. Tampilkan kontainer Download Audio/Video
            const downloadContainer = document.getElementById('audio-download-container'); // Sesuaikan ID
            if (downloadContainer) {
                downloadContainer.style.display = 'block'; // Munculkan elemen
            }

            // 3. Set link untuk tombol Download
            const downloadBtn = document.getElementById('actual-download-btn'); // Sesuaikan ID
            if (downloadBtn) {
                downloadBtn.href = downloadUrl;
            }

            // 4. Perbaiki tombol 'Download Lagi' agar TIDAK nyasar ke upload_advanced.php
            // Melainkan me-refresh atau kembali ke halaman transcode
            const downloadLagiBtn = document.getElementById('download-lagi-btn'); // Sesuaikan ID
            if (downloadLagiBtn) {
                downloadLagiBtn.onclick = function(e) {
                    e.preventDefault();
                    // Arahkan kembali ke halaman transcode, bukan upload_advanced
                    window.location.href = 'transcode.php';
                };
            }
        }
    </script>
</body>

</html>