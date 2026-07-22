<?php
/**
 * MEeL Admin — Activity Log Viewer
 * Menampilkan trail audit dari tabel activity_log.
 * Hanya untuk admin. Query menggunakan prepared statements.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/helpers.php';

if (!isset($_SESSION['user_id'])) {
    die(include '../err/denied.php');
}

// Verifikasi role admin
$__q = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$__q->bind_param("i", $_SESSION['user_id']);
$__q->execute();
$__user_data = $__q->get_result()->fetch_assoc();

if (!$__user_data || $__user_data['role'] !== 'admin') {
    die(include '../err/denied.php');
}

// ─── Filter & Pagination ───────────────────────────────────────
$action_filter = $_GET['action'] ?? '';
$search_q     = trim($_GET['q'] ?? '');
$days         = max(1, min(365, (int)($_GET['days'] ?? 7)));
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 50;
$offset       = ($page - 1) * $per_page;

// ─── Clear Old Logs (POST) ─────────────────────────────────────
$clear_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_older_than'])) {
    if (!verify_csrf()) {
        $clear_msg = 'CSRF Token tidak valid.';
    } else {
        $clear_days = max(1, (int)($_POST['clear_days'] ?? 30));
        $stmt_del = $conn->prepare("DELETE FROM activity_log WHERE created_at < NOW() - INTERVAL ? DAY");
        $stmt_del->bind_param("i", $clear_days);
        $stmt_del->execute();
        $deleted = $stmt_del->affected_rows;
        $clear_msg = "Berhasil menghapus {$deleted} log lebih dari {$clear_days} hari.";
        $stmt_del->close();
    }
}

// ─── Build Query ───────────────────────────────────────────────
$where_conditions = ["1=1"];
$params = [];
$types  = "";

if (!empty($action_filter)) {
    $where_conditions[] = "al.action = ?";
    $params[] = $action_filter;
    $types .= "s";
}

if (!empty($search_q)) {
    $where_conditions[] = "(u.username LIKE ? OR al.ip_address LIKE ?)";
    $search_like = "%{$search_q}%";
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "ss";
}

// Batasi default ke N hari terakhir
$where_conditions[] = "al.created_at >= NOW() - INTERVAL ? DAY";
$params[] = $days;
$types .= "i";

$where_sql = implode(" AND ", $where_conditions);

// ─── Count total ───────────────────────────────────────────────
$stmt_count = $conn->prepare("SELECT COUNT(*) AS total FROM activity_log al LEFT JOIN users u ON al.user_id = u.id WHERE {$where_sql}");
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_rows = (int)$stmt_count->get_result()->fetch_assoc()['total'];
$stmt_count->close();
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = min($page, $total_pages); // Cegah offset tak berguna
$offset = ($page - 1) * $per_page;

// ─── Fetch rows ────────────────────────────────────────────────
$stmt_rows = $conn->prepare(
    "SELECT al.*, u.username
     FROM activity_log al
     LEFT JOIN users u ON al.user_id = u.id
     WHERE {$where_sql}
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?"
);
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . "ii";
$stmt_rows->bind_param($all_types, ...$all_params);
$stmt_rows->execute();
$rows = $stmt_rows->get_result();
$stmt_rows->close();

// ─── Get distinct actions for filter dropdown ──────────────────
$actions_res = $conn->query("SELECT DISTINCT action FROM activity_log ORDER BY action ASC");
$all_actions = [];
if ($actions_res) {
    while ($a = $actions_res->fetch_assoc()) {
        $all_actions[] = $a['action'];
    }
}

// ─── Stats ─────────────────────────────────────────────────────
$stats_res = $conn->query("SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS unique_users FROM activity_log WHERE created_at >= NOW() - INTERVAL 7 DAY");
$stats = $stats_res ? $stats_res->fetch_assoc() : ['total' => 0, 'unique_users' => 0];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Activity Log</title>
    <meta name="description" content="MEeL Activity Log — Audit trail untuk monitoring aktivitas pengguna.">
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.min.js"></script>
    <style>
        body { background-color: #0b0e14; }
        .glass {
            background: rgba(22, 27, 34, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .scroll-table {
            overflow: auto;
            scrollbar-width: thin;
            scrollbar-color: #374151 transparent;
        }
        .scroll-table::-webkit-scrollbar { width: 5px; height: 5px; }
        .scroll-table::-webkit-scrollbar-track { background: transparent; }
        .scroll-table::-webkit-scrollbar-thumb { background: #374151; border-radius: 999px; }
        .scroll-table thead { position: sticky; top: 0; z-index: 10; }
        .scroll-table thead th { background: #0b0e14; }
        .action-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            white-space: nowrap;
        }
        .chip-filter {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            cursor: pointer;
            transition: all .15s;
            border: 1px solid transparent;
        }
        .chip-filter:hover { opacity: .8; }
        .chip-filter.active {
            border-color: currentColor;
            box-shadow: 0 0 0 1px currentColor;
        }
    </style>
</head>
<body class="text-gray-300 min-h-screen">

    <?php
    $page_title = 'Activity Log';
    $media_type = 'analytics';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>

    <div class="max-w-6xl mx-auto px-4 md:px-8 py-8">

        <!-- Header -->
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-2xl bg-blue-500/15 border border-blue-500/25 flex items-center justify-center shrink-0">
                <i data-lucide="activity" class="w-5 h-5 text-blue-500"></i>
            </div>
            <div>
                <h1 class="text-2xl font-extrabold text-white leading-tight">Activity Log</h1>
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1">Audit Trail</p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
            <div class="glass p-4 rounded-2xl border-l-4 border-blue-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1">7 Hari Terakhir</p>
                <span class="text-xl font-bold text-white"><?= number_format($stats['total']) ?></span>
                <span class="text-[10px] text-gray-500 ml-1">events</span>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-green-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1">User Aktif</p>
                <span class="text-xl font-bold text-white"><?= number_format($stats['unique_users']) ?></span>
                <span class="text-[10px] text-gray-500 ml-1">users</span>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-purple-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1">Total Log</p>
                <span class="text-xl font-bold text-white"><?= number_format($total_rows) ?></span>
                <span class="text-[10px] text-gray-500 ml-1">entries</span>
            </div>
            <div class="glass p-4 rounded-2xl border-l-4 border-orange-500">
                <p class="text-[9px] font-bold text-gray-500 uppercase mb-1">Halaman</p>
                <span class="text-xl font-bold text-white"><?= $page ?>/<?= $total_pages ?></span>
            </div>
        </div>

        <!-- Clear Message -->
        <?php if ($clear_msg): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm flex items-center gap-3 bg-green-500/10 text-green-400 border border-green-500/20">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                <?= htmlspecialchars($clear_msg) ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="glass p-6 rounded-2xl mb-6">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <!-- Action Filter -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">Action</label>
                    <select name="action" class="bg-[#131720] border border-white/10 rounded-xl px-3 py-2 text-[11px] text-gray-300 outline-none focus:border-blue-500">
                        <option value="">Semua Aksi</option>
                        <?php foreach ($all_actions as $act): ?>
                            <option value="<?= htmlspecialchars($act) ?>" <?= $action_filter === $act ? 'selected' : '' ?>>
                                <?= htmlspecialchars($act) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Search -->
                <div class="flex flex-col gap-1.5 flex-1 min-w-[160px]">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">Cari Username / IP</label>
                    <input type="text" name="q" value="<?= htmlspecialchars($search_q) ?>"
                        placeholder="Cari username atau IP..."
                        class="bg-[#131720] border border-white/10 rounded-xl px-3 py-2 text-[11px] text-gray-300 outline-none focus:border-blue-500">
                </div>

                <!-- Days -->
                <div class="flex flex-col gap-1.5 w-20">
                    <label class="text-[9px] font-bold text-gray-500 uppercase tracking-widest">Rentang</label>
                    <select name="days" class="bg-[#131720] border border-white/10 rounded-xl px-3 py-2 text-[11px] text-gray-300 outline-none focus:border-blue-500">
                        <?php foreach ([1, 3, 7, 14, 30, 90, 365] as $d): ?>
                            <option value="<?= $d ?>" <?= $days === $d ? 'selected' : '' ?>><?= $d ?> hari</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="bg-blue-600 hover:bg-blue-500 text-white text-[10px] font-bold px-5 py-2.5 rounded-xl transition-all uppercase tracking-wider">
                    <i data-lucide="filter" class="w-3.5 h-3.5 inline mr-1"></i> Filter
                </button>
                <a href="activity_log.php" class="text-[10px] text-gray-500 hover:text-white px-3 py-2.5 transition-all uppercase tracking-wider">
                    Reset
                </a>
            </form>
        </div>

        <!-- Table -->
        <div class="glass rounded-2xl overflow-hidden">
            <div class="scroll-table" style="max-height:70vh;">
                <table class="w-full text-left text-[11px]">
                    <thead class="text-gray-500 uppercase text-[9px] font-black tracking-widest">
                        <tr>
                            <th class="py-3 px-4 w-14">#</th>
                            <th class="py-3 px-4">User</th>
                            <th class="py-3 px-4">Action</th>
                            <th class="py-3 px-4">Media</th>
                            <th class="py-3 px-4 text-center">ID</th>
                            <th class="py-3 px-4 hidden md:table-cell">IP Address</th>
                            <th class="py-3 px-4 text-right">Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php if ($rows && $rows->num_rows > 0): ?>
                            <?php while ($row = $rows->fetch_assoc()):
                                // Color-code by action type
                                $action = $row['action'];
                                if (str_contains($action, 'login') || str_contains($action, 'logout')) {
                                    $ac_color = 'text-blue-400 bg-blue-500/10 border-blue-500/20';
                                    $ac_icon  = $action === 'login' ? 'log-in' : 'log-out';
                                } elseif (str_contains($action, 'upload')) {
                                    $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                    $ac_icon  = 'upload-cloud';
                                } elseif (str_contains($action, 'ban') || str_contains($action, 'unban')) {
                                    $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                    $ac_icon  = 'shield-alert';
                                } elseif (str_contains($action, 'delete') || str_contains($action, 'reject') || str_contains($action, 'kick')) {
                                    $ac_color = 'text-red-400 bg-red-500/10 border-red-500/20';
                                    $ac_icon  = 'trash-2';
                                } elseif (str_contains($action, 'approve')) {
                                    $ac_color = 'text-green-400 bg-green-500/10 border-green-500/20';
                                    $ac_icon  = 'user-check';
                                } else {
                                    $ac_color = 'text-gray-400 bg-white/5 border-white/10';
                                    $ac_icon  = 'circle';
                                }
                            ?>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="py-3 px-4 font-mono text-gray-600"><?= $row['id'] ?></td>
                                    <td class="py-3 px-4">
                                        <span class="font-bold text-white">
                                            <?= htmlspecialchars($row['username'] ?? '—') ?>
                                        </span>
                                        <?php if ($row['user_id'] === null): ?>
                                            <span class="text-[8px] text-gray-600 ml-1">(guest)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="action-badge <?= $ac_color ?>">
                                            <i data-lucide="<?= $ac_icon ?>" class="w-3 h-3"></i>
                                            <?= htmlspecialchars($action) ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-400">
                                        <?= !empty($row['media_type']) ? htmlspecialchars($row['media_type']) : '<span class="text-gray-600">—</span>' ?>
                                    </td>
                                    <td class="py-3 px-4 text-center font-mono text-gray-500">
                                        <?= $row['media_id'] ? $row['media_id'] : '—' ?>
                                    </td>
                                    <td class="py-3 px-4 hidden md:table-cell font-mono text-gray-500 text-[10px]">
                                        <?= htmlspecialchars($row['ip_address'] ?? '—') ?>
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-400 text-[10px] whitespace-nowrap">
                                        <?= date('d/m/Y H:i', strtotime($row['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="py-12 text-center text-gray-500 text-xs italic">
                                    <i data-lucide="inbox" class="w-8 h-8 mx-auto mb-3 opacity-30"></i>
                                    <p>Belum ada data log untuk filter ini.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-6">
                <?php if ($page > 1): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                        ‹ Prev
                    </a>
                <?php endif; ?>

                <?php
                $start = max(1, $page - 2);
                $end   = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
                        class="px-3 py-1.5 rounded-lg text-[11px] font-bold transition-all <?= $i === $page ? 'bg-blue-600 text-white' : 'bg-white/5 border border-white/10 text-gray-400 hover:text-white hover:bg-white/10' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                        class="px-3 py-1.5 rounded-lg bg-white/5 border border-white/10 text-[11px] text-gray-400 hover:text-white hover:bg-white/10 transition-all">
                        Next ›
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Clear Old Logs -->
        <div class="glass p-6 rounded-2xl mt-8 border border-red-500/20">
            <div class="flex items-center gap-3 mb-4">
                <div class="p-2 rounded-xl bg-red-500/10 border border-red-500/20">
                    <i data-lucide="trash-2" class="w-4 h-4 text-red-400"></i>
                </div>
                <div>
                    <h3 class="text-xs font-bold text-gray-300">Maintenance Log</h3>
                    <p class="text-[9px] text-gray-500">Hapus log lama secara permanen untuk menghemat ruang database.</p>
                </div>
            </div>
            <form method="POST" class="flex items-center gap-3 flex-wrap" onsubmit="return meelConfirmForm(event, { title:'Hapus Log', text:'Hapus permanen semua log yang lebih lama dari periode yang dipilih?', confirmButtonText:'HAPUS' })">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <select name="clear_days" class="bg-[#131720] border border-white/10 rounded-xl px-3 py-2 text-[11px] text-gray-300 outline-none focus:border-red-500">
                    <option value="7">Lebih dari 7 hari</option>
                    <option value="14">Lebih dari 14 hari</option>
                    <option value="30" selected>Lebih dari 30 hari</option>
                    <option value="90">Lebih dari 90 hari</option>
                    <option value="365">Lebih dari 1 tahun</option>
                </select>
                <button type="submit" name="clear_older_than" value="1"
                    class="bg-red-600/10 text-red-400 border border-red-500/20 hover:bg-red-600 hover:text-white text-[10px] font-bold px-4 py-2 rounded-xl transition-all uppercase tracking-wider">
                    <i data-lucide="trash-2" class="w-3.5 h-3.5 inline mr-1"></i> Hapus
                </button>
            </form>
        </div>

    </div>

    <script>
        lucide.createIcons();

        <?php if ($clear_msg): ?>
            Swal.fire({
                title: 'Selesai!',
                text: <?= json_encode($clear_msg) ?>,
                icon: 'success',
                confirmButtonColor: '#3b82f6',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>
</html>
