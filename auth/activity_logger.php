<?php
// 1. Fungsi Penangkap IP Asli (Anti-Cloudflare Masking)
function get_real_ip()
{
    if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        return $_SERVER["HTTP_CF_CONNECTING_IP"];
    }
    if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return trim(explode(',', $_SERVER["HTTP_X_FORWARDED_FOR"])[0]);
    }
    return $_SERVER["REMOTE_ADDR"]; // Fallback (misal akses lokal)
}

$user_ip = get_real_ip(); // <-- Ini "Nomor KTP" yang kita cari!

if (isset($conn)) {

    // Ambil role langsung dari DB jika di session tidak ada, supaya lebih akurat
    $session_role = $_SESSION['role'] ?? null;
    // Cek apakah IP user masuk dalam daftar ban
    $check_ban = $conn->prepare("SELECT reason FROM ip_ban WHERE ip_address = ?");
    $check_ban->bind_param("s", $user_ip);
    $check_ban->execute();
    $ban_res = $check_ban->get_result();

    if ($ban_res->num_rows > 0) {
        // Jika bukan admin, baru di-die
        if ($session_role !== 'admin') {
            $row = $ban_res->fetch_assoc();
            die("<div style='background:#0b0e14; color:#ef4444; height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif;'>
                    <h1 style='font-size:4rem; font-weight:900;'>403</h1>
                    <p style='text-transform:uppercase; letter-spacing:4px;'>Akses Dibatasi</p>
                    <p style='color:#4b5563; margin-top:10px;'>Alasan: " . htmlspecialchars($row['reason']) . "</p>
                </div>");
        }
    }
    // Deteksi halaman dan aktivitas user
    $current_page = basename($_SERVER['PHP_SELF']);
    $dir_name = basename(dirname($_SERVER['PHP_SELF']));
    $id_get = isset($_GET['id']) ? $_GET['id'] : null;

    // --- 1. LOGIKA DETEKSI JUDUL KONTEN ---
    if ($id_get) {
        if ($current_page == 'watch.php') {
            $table = ($dir_name == 'music') ? 'music' : 'video';
            $label = ($dir_name == 'music') ? "Listening: " : "Watching: ";

            $get_title = $conn->prepare("SELECT title FROM $table WHERE id = ?");
            if ($get_title) {
                $get_title->bind_param("i", $id_get);
                $get_title->execute();
                $res = $get_title->get_result()->fetch_assoc();
                if ($res) {
                    $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                    $current_page = $label . $short_title;
                }
            }
        } elseif ($current_page == 'read.php') {
            $get_book = $conn->prepare("SELECT title FROM books WHERE id = ?");
            if ($get_book) {
                $get_book->bind_param("i", $id_get);
                $get_book->execute();
                $res = $get_book->get_result()->fetch_assoc();
                if ($res) {
                    $short_title = (mb_strlen($res['title']) > 20) ? mb_substr($res['title'], 0, 17) . '...' : $res['title'];
                    $current_page = "Reading: " . $short_title;
                }
            }
        } elseif ($current_page == 'index.php' && $dir_name == 'profile') {
            $target_user = $_GET['u'] ?? 'Someone';
            $current_page = "Viewing Profile: " . htmlspecialchars($target_user);
        }
    }
    $ua_raw = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $host = $_SERVER['HTTP_HOST'] ?? 'Local';

    // Deteksi Device
    $device = "Unknown";
    if (strpos($ua_raw, 'Android') !== false) $device = "Smartphone";
    elseif (strpos($ua_raw, 'Linux') !== false) $device = "Linux PC";
    elseif (strpos($ua_raw, 'Windows') !== false) $device = "Windows PC";
    elseif (strpos($ua_raw, 'Macintosh') !== false) $device = "Mac";
    elseif (strpos($ua_raw, 'iPhone') !== false) $device = "iPhone";

    if (isset($_SESSION['user_id'])) {
        $uid = $_SESSION['user_id'];
        $current_sid = session_id();

        // 1. MIRO: AMBIL DATA SESI & STATUS DARI DB SEKALIGUS
        $stmt_check = $conn->prepare("SELECT last_session_id, role FROM users WHERE id = ?");
        $stmt_check->bind_param("i", $uid);
        $stmt_check->execute();
        $user_status = $stmt_check->get_result()->fetch_assoc();

        // 2. LOGIKA KICK: Jika SID di DB berbeda dengan browser, langsung tendang!
        // Kita kecualikan Admin agar admin tidak menendang dirinya sendiri secara tidak sengaja
        // HANYA kick jika last_session_id TIDAK KOSONG dan BERBEDA dengan current SID
        if ($user_status && $user_status['role'] !== 'admin') {
            if (!empty($user_status['last_session_id']) && $user_status['last_session_id'] !== $current_sid) {
                session_unset();
                session_destroy();

                // Tampilan layar "Kicked" yang estetik untuk user yang ditendang
                die("<div style='background:#0b0e14; color:#f97316; height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif; text-align:center;'>
                    <div style='padding:20px; border:1px solid rgba(249,115,22,0.2); border-radius:20px; background:rgba(255,255,255,0.02);'>
                        <h1 style='font-size:2rem; font-weight:900; margin-bottom:10px;'>SESSION REVOKED</h1>
                        <p style='text-transform:uppercase; letter-spacing:2px; font-size:10px; color:#4b5563;'>Akses sesi ini telah dihentikan oleh Admin atau login dari perangkat lain.</p>
                        <a href='/MEeL/login.php' style='display:inline-block; margin-top:20px; padding:10px 20px; background:#f97316; color:white; text-decoration:none; border-radius:10px; font-weight:bold; font-size:12px;'>KEMBALI KE LOGIN</a>
                    </div>
                </div>");
            }
        }
        
        // 3. Update session ID HANYA jika kosong di database (first time)
        if (empty($user_status['last_session_id'])) {
            $stmt_update_sid = $conn->prepare("UPDATE users SET last_session_id = ? WHERE id = ?");
            $stmt_update_sid->bind_param("si", $current_sid, $uid);
            $stmt_update_sid->execute();
        }

        // 3. UPDATE AKTIVITAS (Lanjutkan proses logger asli kamu)
        $stmt = $conn->prepare("UPDATE users SET last_page = ?, user_agent = ?, access_via = ?, ip_address = ?, last_activity = NOW() WHERE id = ?");
        $stmt->bind_param("ssssi", $current_page, $device, $host, $user_ip, $uid);
        $stmt->execute();
    } else {
        // ... (Logika Guest tetap sama) ...
        $guest_id = "Guest_" . substr(session_id(), 0, 6);
        $check_guest = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_guest->bind_param("s", $guest_id);
        $check_guest->execute();

        if ($check_guest->get_result()->num_rows == 0) {
            $role = 'guest';
            // Insert Guest Baru dengan IP
            $ins = $conn->prepare("INSERT INTO users (username, role, last_page, user_agent, access_via, ip_address, last_activity) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $ins->bind_param("ssssss", $guest_id, $role, $current_page, $device, $host, $user_ip);
            $ins->execute();
        } else {
            // Update Guest Lama dengan IP terbaru
            $upd = $conn->prepare("UPDATE users SET last_page = ?, user_agent = ?, access_via = ?, ip_address = ?, last_activity = NOW() WHERE username = ?");
            $upd->bind_param("sssss", $current_page, $device, $host, $user_ip, $guest_id);
            $upd->execute();
        }
    }
}
