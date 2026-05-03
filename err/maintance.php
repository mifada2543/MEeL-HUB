<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

http_response_code(503);
session_name('meel');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_role = $_SESSION['role'] ?? 'user';
$username  = htmlspecialchars($_SESSION['username'] ?? 'Guest');
$back_url  = $_SERVER['HTTP_REFERER'] ?? '../index.php';

if (strpos($back_url, 'maintance.php') !== false) {
    $back_url = '../index.php';
}

// Pesan Berdasarkan Role (Penyebutan Akiro & Miro)
if ($user_role === 'admin') {
    $title     = "CRITICAL_ERROR: HDD_NOT_FOUND";
    $desc      = "$username, Terdeteksi err, koneksi ke media storage terputus. <br> Periksa mounting HDD di <code>/media/</code> sekarang!";
    $status    = "OFFLINE";
    $action    = "SUDO REPAIR REQUIRED";
} else {
    $title     = "SYSTEM_PAUSED";
    $desc      = "Halo $username, server MEeL sedang melakukan perbaikan. <br> Mohon hubungi admin untuk dilakukannya perbaikan.";
    $status    = "MAINTENANCE";
    $action    = "PLEASE_WAIT";
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | System Health</title>
    <link rel="icon" href="../assets/MEeL.png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700;800&display=swap');
        
        body { font-family: 'JetBrains Mono', monospace; }
        .bg-grid {
            background-image: radial-gradient(circle at 2px 2px, rgba(59, 130, 246, 0.05) 1px, transparent 0);
            background-size: 40px 40px;
        }
        .glitch {
            text-shadow: 2px 0 #ef4444, -2px 0 #06b6d4;
            animation: glitch-anim 2s infinite linear alternate-reverse;
        }
        @keyframes glitch-anim {
            0% { transform: skew(0deg); }
            20% { transform: skew(-1deg); }
            40% { transform: skew(1deg); }
            100% { transform: skew(0deg); }
        }
        .scanline {
            width: 100%; height: 2px;
            background: rgba(59, 130, 246, 0.1);
            position: absolute; top: 0; left: 0;
            animation: scan 4s linear infinite;
        }
        @keyframes scan { from { top: 0%; } to { top: 100%; } }
    </style>
</head>
<body class="bg-[#0b0e14] text-gray-300 h-screen flex items-center justify-center bg-grid overflow-hidden">
    
    <div class="scanline"></div>

    <div class="absolute inset-0 opacity-20 pointer-events-none">
        <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-blue-600/30 rounded-full blur-[160px]"></div>
    </div>

    <div class="relative z-10 w-full max-w-xl p-8 mx-4">
        <div class="bg-gray-900/80 border border-white/10 rounded-t-2xl p-3 flex items-center justify-between backdrop-blur-md">
            <div class="flex gap-1.5 ml-2">
                <div class="w-2.5 h-2.5 rounded-full bg-red-500/50"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-orange-500/50"></div>
                <div class="w-2.5 h-2.5 rounded-full bg-green-500/50"></div>
            </div>
            <span class="text-[10px] font-bold text-gray-500 uppercase tracking-widest">Console :: MEeL_Diagnostic</span>
            <div class="w-10"></div>
        </div>

        <div class="bg-gray-900/40 border-x border-b border-white/10 rounded-b-2xl p-8 backdrop-blur-xl shadow-2xl">
            <div class="flex flex-col items-center text-center">
                <div class="p-5 bg-blue-500/10 rounded-full mb-6 border border-blue-500/20 relative">
                    <i data-lucide="hard-drive" class="w-10 h-10 text-blue-400 animate-pulse"></i>
                    <div class="absolute -top-1 -right-1 bg-red-600 rounded-full p-1 animate-bounce">
                        <i data-lucide="zap-off" class="w-3 h-3 text-white"></i>
                    </div>
                </div>

                <h1 class="text-2xl font-black mb-2 tracking-tighter text-white glitch uppercase">
                    <?= $title ?>
                </h1>
                
                <p class="text-sm text-gray-400 mb-8 leading-relaxed">
                    <?= $desc ?>
                </p>

                <div class="w-full space-y-4 mb-8">
                    <div class="space-y-1.5">
                        <div class="flex justify-between text-[10px] font-bold uppercase tracking-widest">
                            <span class="text-blue-400">Recovery Status</span>
                            <span class="text-gray-500">Scanning...</span>
                        </div>
                        <div class="w-full bg-gray-800 h-1.5 rounded-full overflow-hidden">
                            <div class="bg-blue-500 h-full w-[45%] animate-[pulse_2s_infinite]"></div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-3 w-full pt-6 border-t border-white/5 gap-4">
                    <div class="text-left">
                        <span class="block text-[9px] text-gray-500 uppercase font-black">Status</span>
                        <span class="text-[11px] text-orange-500 font-bold"><?= $status ?></span>
                    </div>
                    <div class="text-left">
                        <span class="block text-[9px] text-gray-500 uppercase font-black">Action</span>
                        <span class="text-[11px] text-blue-400 font-bold"><?= $action ?></span>
                    </div>
                    <div class="text-right">
                         <a href="<?= $back_url ?>" class="inline-flex items-center gap-1 text-[11px] text-gray-400 hover:text-white transition group">
                            <i data-lucide="terminal" class="w-3 h-3 group-hover:text-blue-400"></i> Back
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <p class="mt-4 text-[9px] text-gray-600 text-center uppercase tracking-widest">
            Log: Process [MEeL_Core] terminated due to I/O Exception :: MEeL 2025
        </p>
    </div>
    <script>lucide.createIcons();</script>
    <?php include '../partials/footer.php'; ?>
</body>
</html>