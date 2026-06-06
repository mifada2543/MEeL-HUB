<?php
session_name("meel");
session_start();
include '../auth/config.php';
include __DIR__ . '/../modules/helpers.php';

// ── Proteksi Admin ──
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

if (!$user_data || $user_data['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// ── Back URL (smart referer) ──
$back_url = '../index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref      = $_SERVER['HTTP_REFERER'];
    $host     = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path       = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['cookies.php', 'edit-music.php', 'edit-video.php', 'index.php'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) $back_url = $ref;
    }
}

// ── Handle DELETE ──
$delete_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verify_csrf()) {
        $delete_msg = ['type' => 'error', 'text' => 'CSRF Token tidak valid.'];
    } else {
        $del_id   = (int)($_POST['media_id'] ?? 0);
        $del_type = $_POST['media_type'] ?? '';

        if ($del_id > 0 && in_array($del_type, ['video', 'music'])) {
            $del_table = ($del_type === 'video') ? 'video' : 'music';
            $stmt_del  = $conn->prepare("DELETE FROM {$del_table} WHERE id = ?");
            $stmt_del->bind_param("i", $del_id);
            if ($stmt_del->execute() && $stmt_del->affected_rows > 0) {
                $delete_msg = ['type' => 'success', 'text' => ucfirst($del_type) . ' berhasil dihapus.'];
            } else {
                $delete_msg = ['type' => 'error', 'text' => 'Gagal menghapus ' . $del_type . '.'];
            }
        } else {
            $delete_msg = ['type' => 'error', 'text' => 'ID atau tipe media tidak valid.'];
        }
    }
}

// ── Logika Filter & Sorting ──
$sort        = $_GET['sort'] ?? 'views';
$type_filter = $_GET['type'] ?? 'all';

$allowed_sort = [
    'views'    => 'views DESC',
    'likes'    => 'likes DESC',
    'dislikes' => 'dislikes DESC',
    'title'    => 'title ASC',
];
$order_by = $allowed_sort[$sort] ?? 'views DESC';

// ── Query Utama ──
$query_media = "
    SELECT * FROM (
        SELECT id, title, 'video' AS media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'like')    AS likes,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'dislike') AS dislikes
        FROM video
        UNION ALL
        SELECT id, title, 'music' AS media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'like')    AS likes,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'dislike') AS dislikes
        FROM music
    ) AS combined_media";

if ($type_filter !== 'all') {
    $query_media .= " WHERE media_type = '" . $conn->real_escape_string($type_filter) . "'";
}
$query_media .= " ORDER BY $order_by";
$result_media = $conn->query($query_media);

// ── Hitung total untuk summary ──
$total_counts = ['all' => 0, 'video' => 0, 'music' => 0];
$r = $conn->query("SELECT 'video' as t, COUNT(*) as c FROM video UNION ALL SELECT 'music', COUNT(*) FROM music");
while ($rc = $r->fetch_assoc()) {
    $total_counts[$rc['t']] = (int)$rc['c'];
    $total_counts['all'] += (int)$rc['c'];
}

// ── DETEKSI HTMX ──
$is_htmx = isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] == 'true';
?>

<?php if (!$is_htmx): // Hanya muat Header jika bukan request dari HTMX 
?>
    <!DOCTYPE html>
    <html lang="id">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
        <title>MEeL | Media Analytics</title>
        <link rel="icon" type="image/png" href="../assets/MEeL.png">
        <script src="../assets/js/tailwind.js"></script>
        <script src="../assets/js/lucide.js"></script>
        <script src="../assets/js/sweetalert2.all.min.js"></script>
        <script src="../assets/js/htmx.js"></script>

        <style>
            /* ── Animasi Transisi SPA HTMX ── */
            #media-content {
                transition: opacity 0.3s ease-in-out, transform 0.3s ease-in-out;
                opacity: 1;
                transform: translateY(0);
            }

            #media-content.htmx-swapping {
                opacity: 0;
                transform: translateY(10px);
            }

            /* Efek Show Hover di Tabel */
            .group:hover .row-actions {
                opacity: 1;
            }

            /* Loading indikator untuk HTMX */
            .htmx-indicator {
                opacity: 0;
                transition: opacity 200ms ease-in;
            }

            .htmx-request .htmx-indicator,
            .htmx-request.htmx-indicator {
                opacity: 1;
            }
        </style>
    </head>

    <body class="bg-[#0b0e14] text-gray-300 min-h-screen font-sans">

        <nav class="sticky top-0 z-50 bg-[#080b11]/90 backdrop-blur-md border-b border-white/5 px-6 h-14 flex items-center gap-3">
            <a href="../index.php" class="font-sans text-sm font-extrabold text-white no-underline tracking-wider">
                MEeL<span class="text-blue-600">Admin</span>
            </a>
            <div class="w-px h-5 bg-white/10"></div>
            <a href="index.php" class="text-[11px] font-semibold text-gray-500 no-underline hover:text-gray-300 transition-colors">Dashboard</a>
            <span class="text-gray-600">›</span>
            <span class="text-[11px] font-semibold text-gray-200">Media Analytics</span>

            <div class="ml-auto flex items-center gap-2">
                <a href="<?= htmlspecialchars($back_url) ?>" class="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-500 py-1.5 px-3.5 rounded-lg border border-white/10 bg-white/5 no-underline transition-all duration-200 hover:text-gray-200 hover:bg-white/10">
                    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Kembali
                </a>
            </div>
        </nav>

        <div class="max-w-6xl mx-auto px-4 md:px-6 py-8">

            <div class="flex items-center gap-4 mb-8">
                <div class="w-12 h-12 rounded-2xl bg-blue-600/15 border border-blue-600/25 flex items-center justify-center shrink-0">
                    <i data-lucide="bar-chart-2" class="w-5 h-5 text-blue-600"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-extrabold text-white leading-tight">Media Analytics</h1>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-gray-500 mt-1">Monitor & Kelola Seluruh Konten</p>
                </div>

                <div id="loading-spinner" class="htmx-indicator ml-auto flex items-center gap-2 text-sm text-blue-500">
                    <i data-lucide="loader-2" class="animate-spin w-4.5 h-4.5"></i> Memuat...
                </div>
            </div>

            <?php if ($delete_msg): ?>
                <div class="mb-5 px-4.5 py-3.5 rounded-xl text-xs font-semibold flex items-center gap-2.5 <?= $delete_msg['type'] === 'success' ? 'bg-green-500/10 border border-green-500/20 text-green-400' : 'bg-red-500/10 border border-red-500/20 text-red-400' ?>">
                    <i data-lucide="<?= $delete_msg['type'] === 'success' ? 'check-circle' : 'alert-triangle' ?>" class="w-4 h-4 shrink-0"></i>
                    <?= htmlspecialchars($delete_msg['text']) ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-wrap gap-4 mb-8">
                <?php
                $chips = [
                    'all'   => ['label' => 'Semua Media', 'text' => 'text-blue-500',   'bg' => 'bg-blue-500/10',   'border' => 'border-blue-500/20',   'icon' => 'layers'],
                    'video' => ['label' => 'Video',       'text' => 'text-red-500',    'bg' => 'bg-red-500/10',    'border' => 'border-red-500/20',    'icon' => 'film'],
                    'music' => ['label' => 'Musik',       'text' => 'text-orange-500', 'bg' => 'bg-orange-500/10', 'border' => 'border-orange-500/20', 'icon' => 'music'],
                ];
                foreach ($chips as $key => $chip): ?>
                    <div class="px-6 py-4 min-w-[180px] rounded-2xl bg-white/5 border border-white/10 flex items-center gap-4 cursor-default transition-all duration-300 ease-in-out hover:border-white/20 hover:bg-white/10 hover:-translate-y-1 hover:shadow-lg hover:shadow-black/40">
                        <div class="w-12 h-12 rounded-xl <?= $chip['bg'] ?> border <?= $chip['border'] ?> flex items-center justify-center shrink-0">
                            <i data-lucide="<?= $chip['icon'] ?>" class="w-6 h-6 <?= $chip['text'] ?>"></i>
                        </div>
                        <div class="flex flex-col justify-center">
                            <div class="text-3xl font-extrabold <?= $chip['text'] ?> leading-none tracking-tight"><?= number_format($total_counts[$key]) ?></div>
                            <div class="text-[11px] font-bold uppercase tracking-widest text-gray-400 mt-1.5"><?= $chip['label'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div id="media-content">
            <?php endif; // Akhir dari Blok Pengecekan Non-HTMX atas 
            ?>

            <div class="flex flex-wrap items-center gap-3 mb-5">
                <!-- Filter Views/Likes/dll -->
                <div class="flex gap-0.5 bg-white/5 p-1 rounded-xl border border-white/10">
                    <?php foreach (['views' => 'Views', 'likes' => 'Likes', 'dislikes' => 'Dislikes', 'title' => 'Nama'] as $k => $v): ?>
                        <a href="javascript:void(0)"
                            hx-get="?sort=<?= $k ?>&type=<?= $type_filter ?>"
                            hx-target="#media-content"
                            hx-indicator="#loading-spinner"
                            class="py-1.5 px-3.5 rounded-lg text-[10px] font-bold uppercase tracking-widest no-underline transition-all duration-300 ease-in-out <?= $sort === $k ? 'bg-blue-600 text-white shadow-md scale-[1.03]' : 'text-gray-500 hover:text-gray-300 hover:bg-white/5' ?>">
                            <?= $v ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Filter All/Video/Musik -->
                <div class="flex gap-0.5 bg-white/5 p-1 rounded-xl border border-white/10">
                    <?php foreach (['all' => 'Semua', 'video' => 'Video', 'music' => 'Musik'] as $k => $v): ?>
                        <a href="javascript:void(0)"
                            hx-get="?sort=<?= $sort ?>&type=<?= $k ?>"
                            hx-target="#media-content"
                            hx-indicator="#loading-spinner"
                            class="py-1.5 px-3.5 rounded-lg text-[10px] font-bold uppercase tracking-widest no-underline transition-all duration-300 ease-in-out <?= $type_filter === $k ? 'bg-orange-500 text-white shadow-md scale-[1.03]' : 'text-gray-500 hover:text-gray-300 hover:bg-white/5' ?>">
                            <?= $v ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="ml-auto text-[11px] font-semibold text-gray-500">
                    <?= $result_media->num_rows ?> item ditemukan
                </div>
            </div>

            <div class="rounded-2xl overflow-hidden shadow-2xl bg-[#080b11]/90 backdrop-blur-md border border-white/5">
                <div class="overflow-x-auto">
                    <table class="w-full border-collapse text-left">
                        <thead>
                            <tr class="text-[9px] font-bold uppercase tracking-[0.18em] text-gray-500 border-b border-white/5 bg-white/5">
                                <th class="py-3.5 px-5">Konten</th>
                                <th class="py-3.5 px-3 text-center">Views</th>
                                <th class="py-3.5 px-3 text-center">Likes</th>
                                <th class="py-3.5 px-3 text-center">Dislikes</th>
                                <th class="py-3.5 px-3 text-center">Tipe</th>
                                <th class="py-3.5 px-5 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_media->num_rows > 0):
                                $row_i = 0;
                                while ($row = $result_media->fetch_assoc()):
                                    $row_i++;
                                    $is_video   = ($row['media_type'] === 'video');
                                    $watch_url  = $is_video ? "../video/watch.php?id={$row['id']}" : "../music/watch.php?id={$row['id']}";
                                    $edit_url   = $is_video ? "edit-video.php?id={$row['id']}" : "edit-music.php?id={$row['id']}";

                                    $type_text  = $is_video ? 'text-red-500' : 'text-orange-500';
                                    $type_bg    = $is_video ? 'bg-red-500/10' : 'bg-orange-500/10';
                                    $type_bdr   = $is_video ? 'border-red-500/20' : 'border-orange-500/20';
                            ?>
                                    <tr class="group border-b border-white/5 transition-colors duration-150 hover:bg-white/5">
                                        <td class="py-3.5 px-5 max-w-[320px]">
                                            <div class="flex items-center gap-2.5">
                                                <div class="text-[10px] font-bold text-gray-600 min-w-[24px] text-center"><?= $row_i ?></div>
                                                <div>
                                                    <a href="<?= $watch_url ?>" target="_blank" class="text-[13px] font-semibold text-gray-200 no-underline block max-w-[260px] truncate transition-colors duration-150 hover:text-blue-400">
                                                        <?= htmlspecialchars($row['title']) ?>
                                                    </a>
                                                    <span class="text-[9px] text-gray-600 font-semibold uppercase tracking-widest">
                                                        ID #<?= $row['id'] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="py-3.5 px-3 text-center text-xs font-mono text-gray-300">
                                            <?= number_format($row['views']) ?>
                                        </td>

                                        <td class="py-3.5 px-3 text-center text-xs font-mono text-green-400">
                                            <?= number_format($row['likes']) ?>
                                        </td>

                                        <td class="py-3.5 px-3 text-center text-xs font-mono text-red-400">
                                            <?= number_format($row['dislikes']) ?>
                                        </td>

                                        <td class="py-3.5 px-3 text-center">
                                            <span class="text-[8px] font-extrabold uppercase tracking-widest py-1 px-2.5 rounded-lg <?= $type_bg ?> <?= $type_text ?> border <?= $type_bdr ?>">
                                                <?= strtoupper($row['media_type']) ?>
                                            </span>
                                        </td>

                                        <td class="py-3.5 px-5 text-right">
                                            <div class="row-actions opacity-0 transition-opacity duration-150 inline-flex items-center gap-1.5 group-hover:opacity-100">
                                                <a href="<?= $edit_url ?>" title="Edit" class="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-[10px] font-bold uppercase tracking-widest no-underline bg-blue-600/10 border border-blue-600/20 text-blue-400 transition-all duration-150 hover:bg-blue-600 hover:text-white">
                                                    <i data-lucide="edit-2" class="w-3 h-3"></i> Edit
                                                </a>
                                                <button type="button" title="Hapus" onclick="confirmDelete(<?= $row['id'] ?>, '<?= $row['media_type'] ?>', '<?= addslashes(htmlspecialchars($row['title'])) ?>')" class="inline-flex items-center gap-1.5 py-1.5 px-3 rounded-lg text-[10px] font-bold uppercase tracking-widest bg-red-500/10 border border-red-500/20 text-red-400 cursor-pointer transition-all duration-150 hover:bg-red-500 hover:text-white">
                                                    <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile;
                            else: ?>
                                <tr>
                                    <td colspan="6" class="p-16 text-center text-gray-600 text-xs italic">
                                        Tidak ada media ditemukan.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!$is_htmx): // Hanya muat Footer jika bukan request dari HTMX 
            ?>
            </div>
        </div>

        <!-- Delete Modal -->
        <div id="delete-modal" class="hidden fixed inset-0 z-[200] bg-black/70 backdrop-blur-sm items-center justify-center">
            <div class="bg-[#0e1118] border border-white/10 rounded-3xl p-8 w-full max-w-[400px] shadow-[0_40px_80px_rgba(0,0,0,0.6)] relative">
                <div class="w-12 h-12 rounded-2xl bg-red-500/15 border border-red-500/25 flex items-center justify-center mb-5">
                    <i data-lucide="trash-2" class="w-5 h-5 text-red-500"></i>
                </div>
                <h3 class="text-lg font-extrabold text-white m-0 mb-2">Hapus Konten?</h3>
                <p class="text-[13px] text-gray-400 m-0 mb-1.5">Anda akan menghapus:</p>
                <div id="modal-title" class="text-sm font-bold text-gray-200 bg-white/5 border border-white/10 rounded-xl py-2.5 px-3.5 mb-1.5 break-words"></div>
                <div id="modal-type-badge" class="inline-block mb-5"></div>
                <p class="text-[11px] text-red-500 m-0 mb-6 py-2.5 px-3.5 rounded-xl bg-red-500/10 border border-red-500/20">
                    ⚠️ Tindakan ini tidak dapat dibatalkan. File dan semua data terkait akan dihapus permanen.
                </p>

                <form method="POST" id="delete-form">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <?php endif; ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="media_id" id="modal-media-id">
                    <input type="hidden" name="media_type" id="modal-media-type">
                    <div class="flex gap-2.5">
                        <button type="button" onclick="closeDeleteModal()" class="flex-1 p-3 rounded-xl text-xs font-bold uppercase tracking-widest bg-white/5 border border-white/10 text-gray-400 cursor-pointer transition-all duration-200 hover:bg-white/10 hover:text-gray-200">
                            Batal
                        </button>
                        <button type="submit" class="flex-1 p-3 rounded-xl text-xs font-bold uppercase tracking-widest bg-red-500 border-none text-white cursor-pointer transition-all duration-200 shadow-[0_4px_16px_rgba(239,68,68,0.25)] hover:bg-red-400">
                            Ya, Hapus
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            lucide.createIcons();

            <?php if ($delete_msg && $delete_msg['type'] === 'success'): ?>
                Swal.fire({
                    title: 'Berhasil!',
                    text: '<?= addslashes($delete_msg['text']) ?>',
                    icon: 'success',
                    confirmButtonColor: '#2563eb',
                    background: '#0e1118',
                    color: '#fff'
                });
            <?php elseif ($delete_msg && $delete_msg['type'] === 'error'): ?>
                Swal.fire({
                    title: 'Gagal!',
                    text: '<?= addslashes($delete_msg['text']) ?>',
                    icon: 'error',
                    confirmButtonColor: '#ef4444',
                    background: '#0e1118',
                    color: '#fff'
                });
            <?php endif; ?>

            function confirmDelete(id, type, title) {
                document.getElementById('modal-media-id').value = id;
                document.getElementById('modal-media-type').value = type;
                document.getElementById('modal-title').textContent = title;

                const isVideo = type === 'video';
                const badge = document.getElementById('modal-type-badge');
                badge.textContent = type.toUpperCase();
                badge.className = `text-[9px] font-extrabold uppercase tracking-[0.12em] py-1 px-2.5 rounded-lg border ` +
                    (isVideo ?
                        'bg-red-500/10 text-red-500 border-red-500/20' :
                        'bg-orange-500/10 text-orange-500 border-orange-500/20');

                const modal = document.getElementById('delete-modal');
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }

            function closeDeleteModal() {
                const modal = document.getElementById('delete-modal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            // Close on backdrop click
            document.getElementById('delete-modal').addEventListener('click', function(e) {
                if (e.target === this) closeDeleteModal();
            });

            // Close on Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') closeDeleteModal();
            });

            // RE-RENDER ICONS SETELAH HTMX MENYISIPKAN ELEMEN BARU KE DALAM DOM
            document.body.addEventListener('htmx:afterSettle', function() {
                lucide.createIcons();
            });
        </script>
    </body>

    </html>
<?php endif; // Akhir dari Blok Pengecekan Non-HTMX bawah 
?>