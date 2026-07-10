<?php
// Error logging diaktifkan, tapi display_errors dimatikan untuk keamanan production
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_name('meel');
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}
include 'config.php';

$back_url = '../index.php'; // Default jika tidak ada referrer

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (strpos($ref, $host) !== false) {
        if (strpos($ref, 'login.php') === false && strpos($ref, 'register.php') === false) {
            $back_url = $ref;
        }
    }
}

$message = "";
$msg_type = "";
$max_reg_attempts = 3;
$reg_time_window = 3600;

// Data percobaan
if (!isset($_SESSION['reg_attempts'])) {
    $_SESSION['reg_attempts'] = [];
}

// Bersihkan data percobaan yang sudah kadaluarsa
$_SESSION['reg_attempts'] = array_filter($_SESSION['reg_attempts'], function ($timestamp) use ($reg_time_window) {
    return $timestamp > (time() - $reg_time_window);
});

// Cek apakah sudah melebihi batas
if (count($_SESSION['reg_attempts']) >= $max_reg_attempts) {
    die("<div style='background:#0b0e14; color:#ef4444; height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif;'><h1 style='font-size:4rem; font-weight:900;'>403</h1><p style='text-transform:uppercase; letter-spacing:4px;'>Akses Dibatasi</p><p style='color:#4b5563; margin-top:10px;'>Alasan: Terlalu banyak percobaan pendaftaran dalam waktu singkat.</p></div>");
}

// Register logic
if (isset($_POST['register'])) {
    verify_csrf();
    $user = trim($_POST['username']);
    $pass_raw = $_POST['password'];

    // 1. Validasi Panjang Karakter
    if (strlen($user) < 8 || strlen($pass_raw) < 8) {
        $message = "Username min 8 karakter, Password min 8 karakter!";
        $msg_type = "warning";
    } else if (!preg_match('/^[a-zA-Z0-9_]+$/', $user)) {
        $message = "Username hanya boleh berisi huruf, angka, dan underscore (_)!";
        $msg_type = "warning";
    } else if (stripos($user, 'guest') !== false) {
        $message = "Username 'Guest' tidak dapat didaftarkan karena dicadangkan untuk sistem!";
        $msg_type = "warning";
    } else {
        // 3. Jika validasi lolos, baru cek ketersediaan username di database
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $user);
        $check->execute();
        $result = $check->get_result();

        // 4. Evaluasi hasil dari Database
        if ($result === false) {
            // Cek jika terjadi error pada database (query gagal dieksekusi)
            // Error database hanya dicatat di log, tidak di-expose ke user
            $message = "Terjadi kesalahan pada database. Silakan coba lagi nanti.";
            $msg_type = "error";
            error_log("[MEeL-Register] Database error: " . $conn->error);
        } else if ($result->num_rows > 0) {
            // Cek jika username sudah ditemukan di database
            $message = "Username sudah terdaftar!";
            $msg_type = "warning";
        } else {
            // Jika belum ada, simpan ke database
            $pass_hashed = password_hash($pass_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, PASSWORD, role, is_active) VALUES (?, ?, 'user', 2)");
            $stmt->bind_param("ss", $user, $pass_hashed);

            if ($stmt->execute()) {
                // Tambahkan percobaan ke session HANYA ketika registrasi benar-benar berhasil disimpan
                $_SESSION['reg_attempts'][] = time();
                $message = "Registrasi berhasil! Silakan tunggu verifikasi admin.";
                $msg_type = "success";
            } else {
                $message = "Error: " . /*$conn->error*/ "Terjadi kesalahan saat menyimpan data.";
                $msg_type = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <title>MEeL | <?= $title ?? 'Register' ?></title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #0b0e14;
        }

        .glass-effect {
            background: rgba(22, 27, 34, 0.8);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
    </style>
</head>

<body class="text-gray-200 min-h-screen flex items-center justify-center p-4">

    <main class="w-full max-w-sm" aria-labelledby="register-title">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="inline-flex p-4 bg-red-600/10 rounded-3xl text-red-600 mb-4 shadow-lg shadow-red-900/10"><i data-lucide="user-plus" class="w-10 h-10"></i></div>
            <h2 id="register-title" class="text-3xl font-black text-white tracking-tighter">Register</h2>
            <p class="text-sm text-gray-300 mt-1">Buat akun <span class="text-red-600 font-bold">MEeL</span></p>
        </div>
        <!-- Message -->
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 animate-pulse <?= $msg_type === 'success' ? 'bg-green-500/10 text-green-400 border border-green-500/20' : ($msg_type === 'warning' ? 'bg-orange-500/10 text-orange-400 border border-orange-500/20' : 'bg-red-500/10 text-red-400 border border-red-500/20') ?>"><i data-lucide="<?= $msg_type === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5"></i><?= $message ?></div>
        <?php endif; ?>
        <!-- Form -->
        <form method="post" class="glass-effect p-8 rounded-[2rem] shadow-2xl space-y-6">
            <?php if (isset($_SESSION['csrf_token'])): ?>
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
            <?php endif; ?>

            <div class="space-y-2">
                <label for="username" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Username</label>
                <div class="relative">
                    <i data-lucide="user" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                    <input id="username" name="username" placeholder="Username" required class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-4 text-sm focus:outline-none focus:border-red-600 focus:ring-1 focus:ring-red-600 text-white transition-all">
                </div>
            </div>

            <div class="space-y-2">
                <label for="password" class="text-[10px] font-bold text-gray-300 uppercase ml-1 tracking-widest">Password</label>
                <div class="relative">
                    <i data-lucide="lock" class="absolute left-4 top-3.5 w-5 h-5 text-gray-300"></i>
                    <input type="password" id="password" name="password" placeholder="••••••••" required class="w-full bg-[#0b0e14] border border-gray-800 rounded-2xl py-3.5 pl-12 pr-14 text-sm focus:outline-none focus:border-red-600 focus:ring-1 focus:ring-red-600 text-white transition-all">
                    <button type="button" id="togglePassword" class="absolute right-2 top-1/2 -translate-y-1/2 w-8 h-8 flex items-center justify-center rounded-full text-gray-300 hover:text-red-500 focus:outline-none focus:ring-2 focus:ring-red-600 transition-colors" aria-label="Tampilkan atau sembunyikan password" aria-pressed="false">
                        <i data-lucide="eye" id="iconEye" class="w-5 h-5"></i>
                        <i data-lucide="eye-off" id="iconEyeOff" class="w-5 h-5 hidden"></i>
                    </button>
                </div>
            </div>

            <div class="pt-4 space-y-3">
                <button name="register" class="w-full bg-red-600 hover:bg-red-500 text-white font-bold py-4 rounded-2xl shadow-lg shadow-red-900/30 transition-all flex items-center justify-center gap-2 group">
                    Daftar Sekarang
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition-transform"></i>
                </button>
                <div class="flex items-center justify-between px-1">
                    <a href="login.php" class="text-xs text-gray-300 hover:text-white transition">Sudah punya akun?</a>
                    <button type="button" onclick="window.location.href = '../index.php'" class="text-xs text-red-500 font-bold hover:underline">Kembali</button>
                </div>
            </div>
        </form>

        <!-- Copyright -->
        <p class="text-center text-[10px] text-gray-300 mt-8 uppercase tracking-[0.3em]">©MEeL - 2025</p>
    </main>

    <script>
        lucide.createIcons();

        // Fitur Toggle Password
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const iconEye = document.getElementById('iconEye');
        const iconEyeOff = document.getElementById('iconEyeOff');

        if (togglePassword) {
            togglePassword.addEventListener('click', function() {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                togglePassword.setAttribute('aria-pressed', String(isHidden));
                togglePassword.setAttribute('aria-label', isHidden ? 'Sembunyikan password' : 'Tampilkan password');
                iconEye.classList.toggle('hidden', !isHidden);
                iconEyeOff.classList.toggle('hidden', isHidden);
            });
        }
    </script>
</body>

</html>
