<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../auth/auth.php';
include '../helpers.php';
include '../auth/config.php';
include '../auth/Uploader.php';

set_time_limit(0);
$status   = "";
$user     = $_SESSION['username'];
$user_id  = $_SESSION['user_id'];

// [FIX #1] Ambil role dari database — tidak bisa diambil dari dalam class Uploader
//          karena $user_role di sana bersifat private
$stmt_role = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$stmt_role->bind_param("i", $user_id);
$stmt_role->execute();
$user_role = $stmt_role->get_result()->fetch_assoc()['role'] ?? 'user';

// Instansiasi Objek Uploader
$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    verify_csrf();
    $result = $uploader->processMusic($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        echo "<script>alert('{$result['msg']}'); window.location.href='upload.php';</script>";
        exit;
    } else {
        die("<div style='color:red;'>Error: {$result['msg']}</div>");
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload | MEeL Music</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(15px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .drag-over {
            border-color: #ea580c !important;
            background-color: rgba(234, 88, 12, 0.1) !important;
            transform: scale(1.02);
        }

        #drop-zone {
            transition: all 0.3s ease;
            border: 2px dashed #374151;
        }
    </style>
</head>

<body class="text-gray-300 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-lg">
        <div class="glass rounded-[3rem] p-10 relative overflow-hidden shadow-2xl">
            <div class="absolute -top-20 -right-20 w-40 h-40 bg-orange-600/20 rounded-full blur-3xl"></div>

            <div class="relative z-10">
                <header class="mb-10 text-center">
                    <div class="bg-orange-500/10 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="music-4" class="text-orange-500 w-8 h-8"></i>
                    </div>
                    <h2 class="text-2xl font-black text-white tracking-tighter uppercase">Add New <span class="text-orange-500">Track</span></h2>
                    <p class="text-[10px] text-gray-500 tracking-[0.3em] uppercase mt-1">Upload FLAC, MP3, or WAV</p>
                </header>

                <?php if ($status === "success"): ?>
                    <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 p-4 rounded-2xl text-xs font-bold mb-8 flex items-center gap-3 animate-bounce">
                        <i data-lucide="check-circle" class="w-5 h-5"></i> Berhasil ditambahkan ke Library!
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="space-y-6" onsubmit="startLoading()">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>
                    <div class="space-y-4">
                        <input type="text" name="title" placeholder="Song Title" required
                            class="w-full bg-black/40 border border-white/5 rounded-2xl px-6 py-4 text-sm focus:border-orange-500 outline-none transition-all">

                        <div class="grid grid-cols-2 gap-4">
                            <input type="text" name="artist" placeholder="Artist" required
                                class="w-full bg-black/40 border border-white/5 rounded-2xl px-6 py-4 text-sm focus:border-orange-500 outline-none transition-all">
                            <input type="text" name="album" placeholder="Album (Optional)"
                                class="w-full bg-black/40 border border-white/5 rounded-2xl px-6 py-4 text-sm focus:border-orange-500 outline-none transition-all">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div id="drop-zone" class="group relative flex flex-col items-center justify-center p-6 bg-black/20 border-2 border-dashed border-white/5 rounded-3xl hover:border-orange-500/50 transition-all cursor-pointer overflow-hidden">
                            <i id="audio-icon" data-lucide="file-audio" class="w-6 h-6 text-orange-500 mb-2 group-hover:scale-110 transition-transform"></i>
                            <span id="audio-label" class="text-[10px] font-bold text-gray-500 uppercase truncate w-full text-center px-2">Drag & Drop Audio</span>

                            <input type="file" name="media" id="file-input" accept="audio/*" required
                                class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                onchange="updateLabel(this, 'audio-label')">
                        </div>

                        <label class="group flex flex-col items-center justify-center p-6 bg-black/20 border-2 border-dashed border-white/5 rounded-3xl hover:border-orange-500/50 transition-all cursor-pointer overflow-hidden">
                            <img id="thumb-preview" class="hidden w-full h-10 object-cover rounded-lg mb-2">
                            <i id="thumb-icon" data-lucide="image" class="w-6 h-6 text-gray-600 mb-2 group-hover:scale-110 transition-transform"></i>
                            <span id="thumb-label" class="text-[10px] font-bold text-gray-500 uppercase truncate w-full text-center px-2">Cover Art</span>
                            <input type="file" name="thumbnail" accept="image/*" class="hidden" onchange="previewThumbnail(this)">
                        </label>
                    </div>

                    <?php if ($user_role === 'admin'): ?>
                        <div class="bg-orange-500/5 border border-orange-500/10 rounded-2xl p-4 mb-4 flex items-center justify-between">
                            <div class="flex flex-col">
                                <span class="text-[10px] font-bold text-orange-500 uppercase tracking-tighter">Anti Transcode</span>
                                <span class="text-[9px] text-gray-500 italic">Simpan file asli (Tanpa Transcode Opus)</span>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="skip_transcode" class="sr-only peer">
                                <div class="w-9 h-5 bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-orange-600"></div>
                            </label>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="upload" id="submit-btn" class="w-full bg-orange-600 hover:bg-orange-500 text-white font-black py-5 rounded-2xl text-xs tracking-widest transition-all shadow-xl shadow-orange-900/20 uppercase flex items-center justify-center gap-3">
                        Save to MEeL Music
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </form>

                <footer class="mt-10 flex justify-center gap-8 border-t border-white/5 pt-8">
                    <a href="index.php" class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition">Library</a>
                    <a href="../index.php" class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition">Portal</a>
                    <a href="../video/upload.php" class="text-[10px] font-bold text-orange-600 hover:text-orange-400 uppercase tracking-widest transition">Go to Video</a>
                    <a class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition" href="../upload_advanced.php" onclick="alert('Anda dan Server memerlukan koneksi internet')">Upload Lanjutan</a>
                </footer>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        // [FIX #2] Hapus definisi duplikat — cukup satu versi yang lengkap (dengan warna orange)
        function updateLabel(input, labelId) {
            if (input.files.length > 0) {
                const label = document.getElementById(labelId);
                label.innerText = input.files[0].name;
                label.classList.remove('text-gray-500');
                label.classList.add('text-orange-500');
            }
        }

        function previewThumbnail(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = (e) => {
                    document.getElementById('thumb-preview').src = e.target.result;
                    document.getElementById('thumb-preview').classList.remove('hidden');
                    document.getElementById('thumb-icon').classList.add('hidden');
                    document.getElementById('thumb-label').innerText = input.files[0].name;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function startLoading() {
            const btn = document.getElementById('submit-btn');
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Processing...';
            btn.classList.add('opacity-50', 'pointer-events-none');
            lucide.createIcons();
        }

        const dropZone = document.getElementById('drop-zone');
        const fileInput = document.getElementById('file-input');

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.add('drag-over');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropZone.classList.remove('drag-over');
            }, false);
        });

        dropZone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                updateLabel(fileInput, 'audio-label');
                dropZone.style.borderColor = "#10b981";
                setTimeout(() => dropZone.style.borderColor = "", 1000);
            }
        });
    </script>
</body>

</html>