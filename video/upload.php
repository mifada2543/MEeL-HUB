<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../auth/auth.php';
include '../auth/config.php';
include '../modules/helpers.php';
include '../modules/Uploader.php';

set_time_limit(0);
$status  = "";
$user    = $_SESSION['username'];
$user_id = $_SESSION['user_id'];
$alert_message = "";

// Instansiasi Objek Uploader
$uploader = new Uploader($conn, $user_id, $user);

if (isset($_POST['upload'])) {
    verify_csrf();
    $result = $uploader->processVideo($_POST, $_FILES, __DIR__ . "/");

    if ($result['status'] === 'success') {
        $status = "success";
    } elseif (isset($result['alert']) && $result['alert'] == true) {
        $alert_message = $result['msg'];
    } else {
        die("<div style='color:red; padding:20px; background:#000;'><h2>$user, Error!</h2><p>{$result['msg']}</p></div>");
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL Video | Upload</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass-card {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="text-gray-200 min-h-screen flex flex-col">
    <main class="flex-grow flex items-center justify-center p-4 w-full">
        <div class="w-full max-w-lg">
            <div class="glass-card rounded-[2.5rem] p-8 shadow-2xl relative overflow-hidden">
                <div class="absolute -top-24 -right-24 w-48 h-48 bg-red-600/10 rounded-full blur-3xl"></div>

                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-2xl font-black text-white tracking-tight italic uppercase">Halo <?= htmlspecialchars($user) ?>, Upload <span class="text-red-600">Video</span></h2>
                            <p class="text-[10px] text-gray-500 uppercase tracking-[0.2em]">Tambahkan Koleksi MP4 ke Library</p>
                        </div>
                        <i data-lucide="clapperboard" class="text-red-600 w-8 h-8 opacity-50"></i>
                    </div>

                    <?php if ($status === "success"): ?>
                        <div class="bg-green-500/10 text-green-400 p-4 rounded-2xl text-xs mb-6 border border-green-500/20 flex items-center gap-3 animate-pulse">
                            <i data-lucide="check-circle" class="w-4 h-4"></i> Video berhasil di Upload!
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="space-y-6" onsubmit="load()">
                        <?php if (isset($_SESSION['csrf_token'])): ?>
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <?php endif; ?>
                        <div>
                            <label class="text-[10px] font-bold text-gray-600 uppercase ml-1 tracking-widest">Judul Konten</label>
                            <input type="text" name="title" placeholder="Masukkan judul video..." title="Judul" required
                                class="w-full bg-[#0b0e14]/50 border border-gray-800 rounded-2xl px-5 py-4 text-sm focus:border-red-600 transition-all outline-none mt-1 text-white">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <label class="group bg-[#0b0e14]/50 p-6 rounded-[2rem] border-2 border-dashed border-gray-800 cursor-pointer text-center hover:border-red-600 transition-all flex flex-col items-center">
                                <i data-lucide="file-video" class="w-8 h-8 text-red-600 mb-2"></i>
                                <span id="v-txt" class="text-[10px] text-gray-400 font-bold uppercase truncate w-full">Pilih MP4</span>
                                <input type="file" name="video" accept=".mp4,.webm,.mkv" required class="hidden" onchange="checkFile(this)">
                            </label>

                            <label class="group bg-[#0b0e14]/50 p-6 rounded-[2rem] border-2 border-dashed border-gray-800 cursor-pointer text-center hover:border-red-600 transition-all flex flex-col items-center">
                                <i id="icon-v" data-lucide="image" class="w-8 h-8 text-gray-500 mb-2"></i>
                                <img id="preview-v" src="" class="hidden w-10 h-10 rounded-lg object-cover mb-2">
                                <span id="t-txt" class="text-[10px] text-gray-400 font-bold uppercase truncate w-full">Thumbnail (Opsional)</span>
                                <input type="file" name="thumbnail" accept="image/*" class="hidden" onchange="previewThumb(this)">
                            </label>
                        </div>

                        <button name="upload" id="btn" class="w-full bg-red-600 hover:bg-red-500 text-white py-4 rounded-2xl font-bold text-xs transition-all flex items-center justify-center gap-2 shadow-lg shadow-red-900/30">
                            MULAI SIMPAN <i data-lucide="arrow-right" class="w-4 h-4"></i>
                        </button>
                    </form>

                    <footer class="mt-10 flex justify-center gap-8 border-t border-white/5 pt-8">
                        <a href="index.php" class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition">Library</a>
                        <a href="../index.php" class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition">Portal</a>
                        <a href="../music/upload.php" class="text-[10px] font-bold text-orange-600 hover:text-orange-400 uppercase tracking-widest transition">Go to Music</a>
                        <a class="text-[10px] font-bold text-gray-600 hover:text-white uppercase tracking-widest transition" href="../upload_advanced.php" onclick="return meelAlertRedirect({ title: 'Upload Lanjutan', text: 'Anda dan Server memerlukan koneksi internet', icon: 'info', redirectUrl: '../upload_advanced.php' })">Upload Lanjutan</a>
                    </footer>
                </div>
            </div>
        </div>
    </main>
    <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        lucide.createIcons();

        <?php if ($alert_message !== ""): ?>
            meelAlertRedirect({
                title: 'Upload Video',
                text: <?= json_encode($alert_message) ?>,
                icon: 'warning',
                redirectUrl: 'upload.php'
            });
        <?php endif; ?>

        function checkFile(input) {
            const file = input.files[0];
            const fileName = file.name;
            const fileExt = fileName.split('.').pop().toLowerCase();
            const allowed = ['mp4', 'webm', 'mkv'];

            if (!allowed.includes(fileExt)) {
                meelAlert({
                    title: 'Format Ditolak',
                    text: "File ." + fileExt + " tidak didukung browser. Silakan gunakan MP4 atau WEBM.",
                    icon: 'error'
                });
                input.value = "";
                document.getElementById('v-txt').innerText = "Pilih MP4/WEBM";
                return;
            }

            document.getElementById('v-txt').innerText = fileName;
        }

        function previewThumb(input) {
            if (input.files && input.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('preview-v').src = e.target.result;
                    document.getElementById('preview-v').classList.remove('hidden');
                    document.getElementById('icon-v').classList.add('hidden');
                    document.getElementById('t-txt').innerText = input.files[0].name;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function load() {
            const btn = document.getElementById('btn');
            btn.innerHTML = '<div class="w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div> PROSES UPLOAD...';
            btn.style.opacity = '0.5';
            btn.style.pointerEvents = 'none';
        }
    </script>
</body>

</html>
