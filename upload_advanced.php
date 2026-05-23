<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);  // Jangan display error langsung (akan diparsing oleh JS)
ignore_user_abort(true);
set_time_limit(0);
putenv("LD_LIBRARY_PATH=/usr/lib/x86_64-linux-gnu:/usr/local/lib");
putenv("PATH=/usr/local/bin:/usr/bin:/bin");

require_once 'auth/auth.php';
require_once 'auth/config.php';
require_once 'auth/activity_logger.php';
require_once 'auth/Transcoder.php';
include 'helpers.php';

// ─── GLOBAL ERROR HANDLER ──────────────────────────────────────────────────
// Tangkap fatal error dan tampilkan ke user via JavaScript
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Jangan tangkap error dari library eksternal
    if (strpos($errfile, 'node_modules') !== false || strpos($errfile, 'vendor') !== false) {
        return false;
    }
    
    $safe_msg = "$errstr (Line $errline)";
    $json_msg = json_encode($safe_msg);
    echo "<script>meelError($json_msg);</script>";
    echo str_repeat(' ', 1024);
    flush();
    return true;
});

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $json_msg = json_encode($error['message']);
        echo "<script>meelError($json_msg);</script>";
        echo str_repeat(' ', 1024);
        flush();
    }
});

$message    = "";
$transcoder = new Transcoder($conn, $_SESSION['user_id']);

// Cek apakah server sedang sibuk
$busy_data = $transcoder->checkServerBusy();

if (isset($_GET['success'])) {
    $message = "<div class='bg-green-500/10 text-green-500 p-4 rounded-xl border border-green-500/20 mb-6 font-bold text-center'>✓ AUDIO OGG BERHASIL DIPROSES!</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    verify_csrf();
    if ($busy_data) {
        $message = "<div class='bg-orange-500/10 text-orange-500 p-4 rounded-xl border border-orange-500/20 mb-6 font-bold text-sm text-center'>" .
            "⚠️ SERVER SEDANG SIBUK<br>" .
            "<span class='font-normal opacity-80 text-xs'>@{$busy_data['username']} sedang mendownload. Coba lagi nanti!</span>" .
            "</div>";
    } else {
        try {
            $url  = trim($_POST['url']);
            $type = $_POST['type'] ?? '';
            $message = $transcoder->processDownload($url, $type);
        } catch (Exception $e) {
            $json_msg = json_encode($e->getMessage());
            echo "<script>meelError($json_msg);</script>";
            echo str_repeat(' ', 1024);
            flush();
            exit;
        } catch (Throwable $e) {
            $json_msg = json_encode($e->getMessage());
            echo "<script>meelError($json_msg);</script>";
            echo str_repeat(' ', 1024);
            flush();
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>MEeL - Advanced Upload</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #05070a;
            color: white;
        }
    </style>
</head>

<!-- 1. Ubah body menjadi flex-col agar elemen tersusun dari atas ke bawah -->

<body class="min-h-screen flex flex-col">

    <!-- 2. Bungkus card dengan main yang memiliki flex-grow agar otomatis mengisi ruang kosong -->
    <main class="flex-grow flex items-center justify-center p-6 w-full">
        <div class="w-full max-w-md bg-[#0b0e14] border border-gray-800 rounded-3xl p-8 shadow-2xl">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-black tracking-tight mb-2">Advanced Upload</h1>
                <p class="text-gray-500 text-sm">Download Video dan Music dari YouTube.</p>
            </div>

            <?= $message ?>

            <form method="POST" class="space-y-6">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                <?php endif; ?>
                <div class="space-y-2">
                    <label class="text-xs font-bold text-gray-400 uppercase ml-1">URL Sumber</label>
                    <div class="relative">
                        <i data-lucide="link" class="absolute left-4 top-3.5 w-5 h-5 text-gray-600"></i>
                        <input type="url" name="url" placeholder="Masukkan URL Video atau Music" required
                            class="w-full bg-[#05070a] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-4 text-sm focus:outline-none focus:border-blue-600 transition-all">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <label class="relative flex items-center justify-center p-4 bg-[#05070a] border border-gray-800 rounded-2xl cursor-pointer hover:border-blue-600 transition-all">
                        <input type="radio" name="type" value="video" checked class="hidden peer">
                        <div class="peer-checked:text-red-500 flex flex-col items-center gap-2">
                            <i data-lucide="video" class="w-6 h-6"></i>
                            <span class="text-xs font-bold uppercase">Video</span>
                        </div>
                    </label>
                    <label class="relative flex items-center justify-center p-4 bg-[#05070a] border border-gray-800 rounded-2xl cursor-pointer hover:border-orange-600 transition-all">
                        <input type="radio" name="type" value="music" class="hidden peer">
                        <div class="peer-checked:text-orange-500 flex flex-col items-center gap-2">
                            <i data-lucide="music" class="w-6 h-6"></i>
                            <span class="text-xs font-bold uppercase">Music</span>
                        </div>
                    </label>
                </div>
                <button type="submit" class="w-full bg-white text-black font-black py-4 rounded-2xl hover:bg-gray-200 transition-all flex items-center justify-center gap-2">
                    <i data-lucide="download-cloud" class="w-5 h-5"></i>
                    MULAI PROSES
                </button>
            </form>
            <div class="mt-8 text-center">
                <a href="index.php" class="text-xs text-gray-600 hover:text-white transition">Kembali ke Dashboard</a>
            </div>
        </div>
    </main>

    <!-- 3. Footer sekarang akan otomatis terdorong ke paling bawah -->
    <?php include 'partials/footer.php'; ?>

    <script>
        lucide.createIcons();
    </script>
</body>

</html>