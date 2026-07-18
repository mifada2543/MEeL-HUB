<?php
// 1. Verifikasi Role Admin
$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ?");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

if (!$user_data || $user_data['role'] !== 'admin') {
    header("Location: ../index.php?error=ditolak");
    exit();
}

// 🔒 FIX CSRF: Verifikasi token dulu sebelum semua action POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verify_csrf()) {
    header("Location: index.php?msg=CSRF_Token_Invalid");
    exit();
}

// LOGIKA SECURITY (BAN/UNBAN)
if (isset($_POST['ban_ip'])) {
    $ip_to_ban = $_POST['ip_target'];
    // Mengambil alasan dari input, jika kosong pakai default
    $reason = !empty($_POST['ban_reason']) ? $_POST['ban_reason'] : "Manual Ban by Admin";

    $stmt = $conn->prepare("INSERT IGNORE INTO ip_ban (ip_address, reason) VALUES (?, ?)");
    $stmt->bind_param("ss", $ip_to_ban, $reason);

    if ($stmt->execute()) {
        header("Location: index.php?msg=IP_Banned");
    }
    exit();
}

// LOGIKA UNBAN (MIRO FIX: Prepared Statement)
if (isset($_GET['unban_ip'])) {
    $ip_to_unban = $_GET['unban_ip'];
    $stmt_unban = $conn->prepare("DELETE FROM ip_ban WHERE ip_address = ?");
    $stmt_unban->bind_param("s", $ip_to_unban);

    if ($stmt_unban->execute()) {
        header("Location: index.php?msg=IP_Unbanned#unban");
    }
    exit();
}

// Ambil data IP yang di-ban
$banned_ips = $conn->query("SELECT * FROM ip_ban ORDER BY banned_at DESC");
if (isset($_POST['clear_all_guests'])) {
    // Hapus Guest yang tidak aktif (is_active 0)
    $stmt = $conn->prepare("DELETE FROM users WHERE role = 'guest' AND is_active = 0");
    if ($stmt->execute()) {
        // ✅ Reset AUTO_INCREMENT ke MAX(id) user asli + 1 — cegah ID melonjak
        $result_ai = $conn->query("SELECT COALESCE(MAX(id), 0) + 1 AS new_ai FROM users");
        if ($result_ai) {
            $row_ai = $result_ai->fetch_assoc();
            $new_ai = (int)$row_ai['new_ai'];
            $reset = $conn->query("ALTER TABLE users AUTO_INCREMENT = {$new_ai}");
            if (!$reset) {
                error_log("[MEeL] Gagal reset AUTO_INCREMENT: " . $conn->error);
            }
        }
        header("Location: index.php?msg=Guests_Cleared_Efficiently");
    } else {
        header("Location: index.php?msg=Error_Cleaning");
    }
    exit();
}

if (isset($_POST['clean_stuck_queues'])) {
    // 1. Deteksi dinamis path file System.php untuk mencegah Fatal Error
    $path_modules = __DIR__ . '/../modules/System.php';
    $path_auth = __DIR__ . '/../auth/System.php';

    if (file_exists($path_modules)) {
        include_once $path_modules;
    } elseif (file_exists($path_auth)) {
        include_once $path_auth;
    } else {
        die("System Error: File System.php tidak ditemukan di /modules/ maupun /auth/!");
    }

    // 2. Eksekusi pembersihan
    $sys = new System($conn);
    $cleaned = $sys->cleanStuckQueues();
    $redirect_url = "index.php?msg=Queues_Cleaned_" . $cleaned . "#queues";

    // 3. Fallback cerdas untuk menghindari "Headers Already Sent"
    if (!headers_sent()) {
        header("Location: " . $redirect_url);
        exit();
    } else {
        echo "<script>window.location.href='$redirect_url';</script>";
        exit();
    }
}
// LOGIKA FORCE STOP KUBIK (Per-Item)
if (isset($_POST['force_stop_queue'])) {
    $queue_id = (int)$_POST['queue_id'];
    $task_type = $_POST['task_type']; // 'download' atau 'transcode'

    // Load System class
    $path_modules = __DIR__ . '/../modules/System.php';
    $path_auth = __DIR__ . '/../auth/System.php';
    if (file_exists($path_modules)) {
        include_once $path_modules;
    } elseif (file_exists($path_auth)) {
        include_once $path_auth;
    }

    $sys = new System($conn);
    $sys->forceStopQueue($queue_id, $task_type);

    $redirect_url = "index.php?msg=Queue_Force_Stopped#queues";

    if (!headers_sent()) {
        header("Location: " . $redirect_url);
        exit();
    } else {
        echo "<script>window.location.href='$redirect_url';</script>";
        exit();
    }
}
// 2. Logika Aksi (Approve, Reject, Clean)
if (isset($_GET['approve_id'])) {
    $id = (int)$_GET['approve_id'];
    $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: index.php?msg=Approved");
    exit();
}
if (isset($_GET['reject_id'])) {
    $id = (int)$_GET['reject_id'];
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND is_active = 2");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: index.php?msg=Rejected");
    exit();
}
if (isset($_POST['clean_orphans'])) {
    $files = json_decode($_POST['files_to_delete'], true);
    foreach ($files as $f) {
        if (file_exists($f)) unlink($f);
    }
    header("Location: index.php?status=cleaned#system_check");
    exit();
}
// LOGIKA HAPUS AKUN (Hanya boleh hapus non-admin)
if (isset($_GET['delete_user_id'])) {
    $id_to_delete = (int)$_GET['delete_user_id'];

    // 1. Ambil data user yang mau dihapus untuk cek role-nya
    $check_role = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $check_role->bind_param("i", $id_to_delete);
    $check_role->execute();
    $target_user = $check_role->get_result()->fetch_assoc();

    // 2. Validasi: Jangan hapus diri sendiri ATAU sesama admin
    if ($id_to_delete === $_SESSION['user_id']) {
        header("Location: index.php?msg=Cannot_Delete_Self");
        exit();
    } elseif ($target_user && $target_user['role'] === 'admin') {
        header("Location: index.php?msg=Cannot_Delete_Admin");
        exit();
    }

    // 3. Eksekusi hapus jika lolos validasi
    $stmt_del = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt_del->bind_param("i", $id_to_delete);

    if ($stmt_del->execute()) {
        header("Location: index.php?msg=User_Deleted");
    }
    exit();
}

// Ambil data user - Pastikan kolom 'email' dihapus jika tidak ada di tabel database Anda
$all_users = $conn->query("SELECT id, username, role, is_active, created_at FROM users WHERE role != 'guest' ORDER BY role ASC, username ASC");
if (!$all_users) {
    die("Query Gagal: " . $conn->error);
} // 3. Pengambilan Data & Statistik
function get_count($conn, $q)
{
    $r = $conn->query($q);
    return ($r) ? ($r->fetch_row()[0] ?? 0) : 0;
}

$stats = [
    'video'       => get_count($conn, "SELECT COUNT(*) FROM video"),
    'music'       => get_count($conn, "SELECT COUNT(*) FROM music"),
    'books'       => get_count($conn, "SELECT COUNT(*) FROM books"),
    'total_views' => get_count($conn, "SELECT SUM(views) FROM video") + get_count($conn, "SELECT SUM(views) FROM music"),
    'total_likes' => get_count($conn, "SELECT COUNT(*) FROM interactions WHERE type = 'like'"),
    'total_dislikes' => get_count($conn, "SELECT COUNT(*) FROM interactions WHERE type = 'dislike'"),
    'pending'     => get_count($conn, "SELECT COUNT(*) FROM users WHERE is_active = 2")
];

// Ambil 1 Video & 1 Music dengan Views tertinggi (dijamin masing-masing 1, bukan top-2 global)
$query_top_media = "
    (SELECT id, title, views, 'video' as type FROM video ORDER BY views DESC LIMIT 1)
    UNION ALL
    (SELECT id, title, views, 'music' as type FROM music ORDER BY views DESC LIMIT 1)";
$top_media = $conn->query($query_top_media);

include_once __DIR__ . '/../modules/System.php';
$sys = new System($conn);
$storage_usage = $sys->getStorageUsage();

$ssd_free = $storage_usage['ssd']['free'];
$ssd_total = $storage_usage['ssd']['total'];
$ssd_used = $storage_usage['ssd']['used'];
$ssd_perc = $storage_usage['ssd']['perc'];

$hdd_free = $storage_usage['hdd']['free'];
$hdd_total = $storage_usage['hdd']['total'];

$sz_vid = $storage_usage['sizes']['video'];
$sz_mus = $storage_usage['sizes']['music'];
$sz_book = $storage_usage['sizes']['books'];
$sz_d_pub = $storage_usage['sizes']['drive_pub'];
$sz_d_prv = $storage_usage['sizes']['drive_prv'];
$sz_drive_total = $storage_usage['sizes']['drive_total'];

$p_vid = $storage_usage['percentages']['video'];
$p_mus = $storage_usage['percentages']['music'];
$p_book = $storage_usage['percentages']['books'];
$p_drive = $storage_usage['percentages']['drive'];
// 4. Orphan Check (Sync Check) - VERSI OPTIMAL & CEPAT
$orphans = [];
$check_map = [
    'video/upload/video/' => 'video',
    'music/upload/file/' => 'music',
    'video/upload/thumbnail/' => 'video',
    'music/upload/thumbnail/' => 'music',
    'books/upload/manga/' => 'books',
    'books/upload/pdf/' => 'books',
    'books/upload/thumbnail/' => 'books'
];

// --- LANGKAH 1: Ambil semua data DB sekaligus ke dalam memori ---
$db_data = [
    'video_files' => [],
    'music_files' => [],
    'books_folders' => [],
    'books_thumbs' => []
];

// Ambil data Video
$res_video = $conn->query("SELECT filename, thumbnail FROM video");
$db_data['video_files'] = [];
$db_data['video_thumbs'] = [];
while ($row = $res_video->fetch_assoc()) {
    $db_data['video_files'][] = $row['filename'];
    if (!empty($row['thumbnail'])) $db_data['video_thumbs'][] = $row['thumbnail'];
}
// Ambil data Music
$res = $conn->query("SELECT filename, thumbnail FROM music");
while ($r = $res->fetch_assoc()) {
    $db_data['music_files'][] = $r['filename'];
    $db_data['music_files'][] = $r['thumbnail'];
}

// Ambil data Books
$res = $conn->query("SELECT path_folder, thumbnail FROM books");
while ($r = $res->fetch_assoc()) {
    $db_data['books_folders'][] = $r['path_folder'];
    $db_data['books_thumbs'][] = $r['thumbnail'];
}

// --- LANGKAH 2: Scanning File dengan Perbandingan Memori ---
if (!function_exists('get_all_files_recursive')) {
    function get_all_files_recursive($dir)
    {
        $result = [];
        if (!is_dir($dir)) return $result;
        $root = scandir($dir);
        foreach ($root as $value) {
            if ($value === '.' || $value === '..') continue;
            $path = $dir . $value;
            if (is_file($path)) {
                $result[] = $path;
            } elseif (is_dir($path)) {
                $result = array_merge($result, get_all_files_recursive($path . '/'));
            }
        }
        return $result;
    }
}
$ignored_files = ['.htaccess', 'default_video.png', 'music_default.png', 'default_cover.jpg'];

foreach ($check_map as $base_path => $table) {
    $all_files = get_all_files_recursive($base_path);

    foreach ($all_files as $full_path) {
        $f = basename($full_path);
        if (in_array($f, $ignored_files)) continue;

        $is_orphan = true;
        $path_fix  = str_replace('\\', '/', $full_path);
        $segments  = explode('/', $path_fix);
        $seg_count = count($segments);

        // 1. Pengecekan Khusus Folder MANGA
        if (strpos($full_path, 'books/upload/manga/') !== false) {
            $relative     = str_replace('books/upload/manga/', '', $full_path);
            $folder_manga = explode('/', $relative)[0];
            if (in_array($folder_manga, $db_data['books_folders'])) $is_orphan = false;
        }

        // 2. Pengecekan Video (Thumbnail & HLS/MP4)
        elseif ($table === 'video') {

            // A. Thumbnail video - cek folder, bukan nama file
            if (strpos($full_path, '/thumbnail/') !== false) {
                if (in_array($f, $db_data['video_thumbs'])) $is_orphan = false;
            }

            // B. JIKA DI FOLDER VIDEO (HLS/MP4)
            else {
                if (in_array($f, $db_data['video_files'])) {
                    $is_orphan = false;
                } else {
                    $path_fix  = str_replace('\\', '/', $full_path);
                    $segments  = explode('/', $path_fix);
                    $seg_count = count($segments);

                    $parent_folder = ($seg_count >= 2) ? $segments[$seg_count - 2] : '';

                    if (!empty($parent_folder) && !in_array($parent_folder, ['video', 'upload'])) {
                        foreach ($db_data['video_files'] as $db_file) {
                            if (strpos($db_file, $parent_folder) !== false) {
                                $is_orphan = false;
                                break;
                            }
                        }
                    }

                    // Fallback: scan semua segment
                    if ($is_orphan) {
                        foreach ($segments as $segment) {
                            if (empty($segment) || in_array($segment, ['video', 'upload'])) continue;
                            foreach ($db_data['video_files'] as $db_file) {
                                if (strpos($db_file, $segment) !== false) {
                                    $is_orphan = false;
                                    break 2;
                                }
                            }
                        }
                    }
                }
            }
        }

        // 3. Pengecekan Umum (Music & Books Non-Manga)
        else {
            if ($table === 'books') {
                if (in_array($f, $db_data['books_folders']) || in_array($f, $db_data['books_thumbs'])) {
                    $is_orphan = false;
                }
            } elseif ($table === 'music') {
                if (in_array($f, $db_data['music_files'])) {
                    $is_orphan = false;
                }
            }
        }

        if ($is_orphan) {
            $orphans[] = $full_path;
        }
    }
}
$pending_users = $conn->query("SELECT id, username, created_at FROM users WHERE is_active = 2");
// --- TARUH INI DI ATAS, SEBELUM QUERY $result_monitor ---
if (isset($_GET['kick_user'])) {
    $target_username = $_GET['kick_user'];
    $stmt_kick = $conn->prepare("UPDATE users SET 
        last_session_id = 'KICKED', 
        last_page = 'KICKED BY ADMIN', 
        last_activity = DATE_SUB(NOW(), INTERVAL 10 MINUTE) 
        WHERE username = ?");
    $stmt_kick->bind_param("s", $target_username);

    if ($stmt_kick->execute()) {
        // Redirect kembali agar query $result_monitor di bawah mengambil data terbaru
        header("Location: index.php?msg=Kicked_Success#monitor");
        exit();
    }
}

$result_monitor = $conn->query("SELECT username, role, last_activity, last_page, user_agent, access_via, ip_address FROM users ORDER BY last_activity DESC LIMIT 10");
