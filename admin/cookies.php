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
    <style>
        body {
            background-color: #0b0e14;
            font-family: 'DM Sans', sans-serif;
        }

        .glass {
            background: rgba(14, 17, 24, 0.8);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, .06);
        }

        .glass-dark {
            background: rgba(8, 11, 17, .9);
            border: 1px solid rgba(255, 255, 255, .05);
        }

        /* ── Sticky nav ── */
        .top-nav {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(8, 11, 17, .92);
            backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(255, 255, 255, .06);
            padding: 0 24px;
            height: 56px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* ── Table row actions ── */
        .row-actions {
            opacity: 0;
            transition: opacity .15s;
        }

        tr:hover .row-actions {
            opacity: 1;
        }

        /* ── Pill badge ── */
        .badge-video {
            background: rgba(239, 68, 68, .1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, .2);
        }

        .badge-music {
            background: rgba(249, 115, 22, .1);
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, .2);
        }

        /* ── Stat card hover ── */
        .stat-card {
            transition: border-color .2s, background .2s;
        }

        .stat-card:hover {
            border-color: rgba(255, 255, 255, .12) !important;
            background: rgba(255, 255, 255, .04) !important;
        }

        /* ── Delete confirm modal ── */
        #delete-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 200;
            background: rgba(0, 0, 0, .7);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }

        #delete-modal.open {
            display: flex;
        }
    </style>
</head>

<body class="text-gray-300 min-h-screen">

    <!-- ── Top Nav ── -->
    <nav class="top-nav">
        <a href="../index.php" style="font-family:sans-serif;font-size:14px;font-weight:800;color:#fff;text-decoration:none;letter-spacing:.05em;">
            MEeL<span style="color:#2563eb;">Admin</span>
        </a>
        <div style="width:1px;height:20px;background:rgba(255,255,255,.08);"></div>
        <a href="index.php" style="font-size:11px;font-weight:600;color:#555e6e;text-decoration:none;">Dashboard</a>
        <span style="color:#353d4a;">›</span>
        <span style="font-size:11px;font-weight:600;color:#e2e6ef;">Media Analytics</span>
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px;">
            <a href="<?= htmlspecialchars($back_url) ?>"
                style="display:inline-flex;align-items:center;gap:6px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#6b7280;padding:7px 14px;border-radius:10px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);text-decoration:none;transition:all .2s;"
                onmouseover="this.style.color='#e2e6ef';this.style.background='rgba(255,255,255,.07)'"
                onmouseout="this.style.color='#6b7280';this.style.background='rgba(255,255,255,.03)'">
                <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Kembali
            </a>
        </div>
    </nav>

    <!-- ── Page body ── -->
    <div class="max-w-6xl mx-auto px-4 md:px-6 py-8">

        <!-- Header -->
        <div class="flex items-center gap-4 mb-8">
            <div style="width:48px;height:48px;border-radius:16px;background:rgba(37,99,235,.15);border:1px solid rgba(37,99,235,.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <i data-lucide="bar-chart-2" style="width:22px;height:22px;color:#2563eb;"></i>
            </div>
            <div>
                <h1 style="font-size:22px;font-weight:800;color:#fff;line-height:1.1;">Media Analytics</h1>
                <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.2em;color:#455060;margin-top:2px;">Monitor & Kelola Seluruh Konten</p>
            </div>
        </div>

        <?php if ($delete_msg): ?>
            <div style="margin-bottom:20px;padding:14px 18px;border-radius:14px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:10px;
        <?= $delete_msg['type'] === 'success'
                ? 'background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.2);color:#4ade80;'
                : 'background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#f87171;' ?>">
                <i data-lucide="<?= $delete_msg['type'] === 'success' ? 'check-circle' : 'alert-triangle' ?>" style="width:15px;height:15px;flex-shrink:0;"></i>
                <?= htmlspecialchars($delete_msg['text']) ?>
            </div>
        <?php endif; ?>

        <!-- Summary chips -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:24px;">
            <?php
            $chips = [
                'all'   => ['label' => 'Semua Media', 'color' => '#2563eb', 'bg' => 'rgba(37,99,235,.1)', 'border' => 'rgba(37,99,235,.2)', 'icon' => 'layers'],
                'video' => ['label' => 'Video',        'color' => '#ef4444', 'bg' => 'rgba(239,68,68,.1)',  'border' => 'rgba(239,68,68,.2)',  'icon' => 'film'],
                'music' => ['label' => 'Musik',        'color' => '#f97316', 'bg' => 'rgba(249,115,22,.1)', 'border' => 'rgba(249,115,22,.2)', 'icon' => 'music'],
            ];
            foreach ($chips as $key => $chip): ?>
                <div class="stat-card" style="padding:12px 18px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);display:flex;align-items:center;gap:10px;cursor:default;">
                    <div style="width:32px;height:32px;border-radius:10px;background:<?= $chip['bg'] ?>;border:1px solid <?= $chip['border'] ?>;display:flex;align-items:center;justify-content:center;">
                        <i data-lucide="<?= $chip['icon'] ?>" style="width:14px;height:14px;color:<?= $chip['color'] ?>;"></i>
                    </div>
                    <div>
                        <div style="font-size:18px;font-weight:800;color:<?= $chip['color'] ?>;line-height:1;"><?= number_format($total_counts[$key]) ?></div>
                        <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#455060;margin-top:2px;"><?= $chip['label'] ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter & Sort bar -->
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:20px;align-items:center;">
            <!-- Sort -->
            <div style="display:flex;gap:2px;background:rgba(255,255,255,.04);padding:4px;border-radius:12px;border:1px solid rgba(255,255,255,.06);">
                <?php foreach (['views' => 'Views', 'likes' => 'Likes', 'dislikes' => 'Dislikes', 'title' => 'Nama'] as $k => $v): ?>
                    <a href="?sort=<?= $k ?>&type=<?= $type_filter ?>"
                        style="padding:6px 14px;border-radius:9px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;text-decoration:none;transition:all .2s;
                   <?= $sort === $k ? 'background:#2563eb;color:#fff;' : 'color:#555e6e;' ?>">
                        <?= $v ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Type -->
            <div style="display:flex;gap:2px;background:rgba(255,255,255,.04);padding:4px;border-radius:12px;border:1px solid rgba(255,255,255,.06);">
                <?php foreach (['all' => 'Semua', 'video' => 'Video', 'music' => 'Musik'] as $k => $v): ?>
                    <a href="?sort=<?= $sort ?>&type=<?= $k ?>"
                        style="padding:6px 14px;border-radius:9px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;text-decoration:none;transition:all .2s;
                   <?= $type_filter === $k ? 'background:#f97316;color:#fff;' : 'color:#555e6e;' ?>">
                        <?= $v ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- Count -->
            <div style="margin-left:auto;font-size:11px;font-weight:600;color:#455060;">
                <?= $result_media->num_rows ?> item ditemukan
            </div>
        </div>

        <!-- Table -->
        <div class="glass" style="border-radius:20px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.4);">
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;text-align:left;">
                    <thead>
                        <tr style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.18em;color:#455060;border-bottom:1px solid rgba(255,255,255,.05);background:rgba(255,255,255,.02);">
                            <th style="padding:14px 20px;">Konten</th>
                            <th style="padding:14px 12px;text-align:center;">Views</th>
                            <th style="padding:14px 12px;text-align:center;">Likes</th>
                            <th style="padding:14px 12px;text-align:center;">Dislikes</th>
                            <th style="padding:14px 12px;text-align:center;">Tipe</th>
                            <th style="padding:14px 20px;text-align:right;">Aksi</th>
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
                                $type_color = $is_video ? '#ef4444' : '#f97316';
                                $type_bg    = $is_video ? 'rgba(239,68,68,.1)' : 'rgba(249,115,22,.1)';
                                $type_bdr   = $is_video ? 'rgba(239,68,68,.2)' : 'rgba(249,115,22,.2)';
                        ?>
                                <tr style="border-bottom:1px solid rgba(255,255,255,.04);transition:background .15s;"
                                    onmouseover="this.style.background='rgba(255,255,255,.02)'"
                                    onmouseout="this.style.background='transparent'">

                                    <!-- Title -->
                                    <td style="padding:14px 20px;max-width:320px;">
                                        <div style="display:flex;align-items:center;gap:10px;">
                                            <div style="font-size:10px;font-weight:700;color:#2c3440;min-width:24px;text-align:center;"><?= $row_i ?></div>
                                            <div>
                                                <a href="<?= $watch_url ?>" target="_blank"
                                                    style="font-size:13px;font-weight:600;color:#e2e6ef;text-decoration:none;display:block;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;transition:color .15s;"
                                                    onmouseover="this.style.color='#60a5fa'"
                                                    onmouseout="this.style.color='#e2e6ef'">
                                                    <?= htmlspecialchars($row['title']) ?>
                                                </a>
                                                <span style="font-size:9px;color:#2c3440;font-weight:600;text-transform:uppercase;letter-spacing:.1em;">
                                                    ID #<?= $row['id'] ?>
                                                </span>
                                            </div>
                                        </div>
                                    </td>

                                    <!-- Views -->
                                    <td style="padding:14px 12px;text-align:center;font-size:12px;font-family:monospace;color:#c9cdd6;">
                                        <?= number_format($row['views']) ?>
                                    </td>

                                    <!-- Likes -->
                                    <td style="padding:14px 12px;text-align:center;font-size:12px;font-family:monospace;color:#4ade80;">
                                        <?= number_format($row['likes']) ?>
                                    </td>

                                    <!-- Dislikes -->
                                    <td style="padding:14px 12px;text-align:center;font-size:12px;font-family:monospace;color:#f87171;">
                                        <?= number_format($row['dislikes']) ?>
                                    </td>

                                    <!-- Type badge -->
                                    <td style="padding:14px 12px;text-align:center;">
                                        <span style="font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:4px 9px;border-radius:8px;background:<?= $type_bg ?>;color:<?= $type_color ?>;border:1px solid <?= $type_bdr ?>;">
                                            <?= strtoupper($row['media_type']) ?>
                                        </span>
                                    </td>

                                    <!-- Actions -->
                                    <td style="padding:14px 20px;text-align:right;">
                                        <div class="row-actions" style="display:inline-flex;align-items:center;gap:6px;">
                                            <!-- Edit -->
                                            <a href="<?= $edit_url ?>"
                                                title="Edit"
                                                style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:9px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;text-decoration:none;background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.2);color:#60a5fa;transition:all .15s;"
                                                onmouseover="this.style.background='#2563eb';this.style.color='#fff'"
                                                onmouseout="this.style.background='rgba(37,99,235,.1)';this.style.color='#60a5fa'">
                                                <i data-lucide="edit-2" style="width:11px;height:11px;"></i> Edit
                                            </a>
                                            <!-- Delete -->
                                            <button type="button"
                                                title="Hapus"
                                                onclick="confirmDelete(<?= $row['id'] ?>, '<?= $row['media_type'] ?>', '<?= addslashes(htmlspecialchars($row['title'])) ?>')"
                                                style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:9px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.18);color:#f87171;cursor:pointer;transition:all .15s;"
                                                onmouseover="this.style.background='#ef4444';this.style.color='#fff'"
                                                onmouseout="this.style.background='rgba(239,68,68,.08)';this.style.color='#f87171'">
                                                <i data-lucide="trash-2" style="width:11px;height:11px;"></i> Hapus
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile;
                        else: ?>
                            <tr>
                                <td colspan="6" style="padding:60px;text-align:center;color:#2c3440;font-size:12px;font-style:italic;">
                                    Tidak ada media ditemukan.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div><!-- /max-w -->

    <!-- ── Delete Confirm Modal ── -->
    <div id="delete-modal">
        <div style="background:#0e1118;border:1px solid rgba(255,255,255,.08);border-radius:24px;padding:32px;width:100%;max-width:400px;box-shadow:0 40px 80px rgba(0,0,0,.6);position:relative;">
            <div style="width:52px;height:52px;border-radius:16px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.22);display:flex;align-items:center;justify-content:center;margin-bottom:20px;">
                <i data-lucide="trash-2" style="width:22px;height:22px;color:#ef4444;"></i>
            </div>
            <h3 style="font-size:18px;font-weight:800;color:#fff;margin:0 0 8px;">Hapus Konten?</h3>
            <p style="font-size:13px;color:#6b7280;margin:0 0 6px;">Anda akan menghapus:</p>
            <div id="modal-title" style="font-size:14px;font-weight:700;color:#e2e6ef;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:10px 14px;margin-bottom:6px;word-break:break-word;"></div>
            <div id="modal-type-badge" style="display:inline-block;margin-bottom:20px;"></div>
            <p style="font-size:11px;color:#ef4444;margin:0 0 24px;padding:10px 14px;border-radius:10px;background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);">
                ⚠️ Tindakan ini tidak dapat dibatalkan. File dan semua data terkait akan dihapus permanen.
            </p>
            <form method="POST" id="delete-form">
                <?php if (isset($_SESSION['csrf_token'])): ?>
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <?php endif; ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="media_id" id="modal-media-id">
                <input type="hidden" name="media_type" id="modal-media-type">
                <div style="display:flex;gap:10px;">
                    <button type="button" onclick="closeDeleteModal()"
                        style="flex:1;padding:12px;border-radius:12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);color:#6b7280;cursor:pointer;transition:all .2s;"
                        onmouseover="this.style.background='rgba(255,255,255,.09)';this.style.color='#e2e6ef'"
                        onmouseout="this.style.background='rgba(255,255,255,.05)';this.style.color='#6b7280'">
                        Batal
                    </button>
                    <button type="submit"
                        style="flex:1;padding:12px;border-radius:12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;background:#ef4444;border:none;color:#fff;cursor:pointer;transition:all .2s;box-shadow:0 4px 16px rgba(239,68,68,.25);"
                        onmouseover="this.style.background='#f87171'"
                        onmouseout="this.style.background='#ef4444'">
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
            badge.style.cssText = `font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;padding:3px 9px;border-radius:8px;` +
                (isVideo ?
                    'background:rgba(239,68,68,.1);color:#ef4444;border:1px solid rgba(239,68,68,.2);' :
                    'background:rgba(249,115,22,.1);color:#f97316;border:1px solid rgba(249,115,22,.2);');

            document.getElementById('delete-modal').classList.add('open');
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.remove('open');
        }

        // Close on backdrop click
        document.getElementById('delete-modal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });

        // Close on Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDeleteModal();
        });
    </script>
</body>

</html>