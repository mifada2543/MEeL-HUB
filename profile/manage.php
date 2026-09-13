<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/core/helpers.php';
require_once '../modules/media/ProfileRepository.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login");
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$username  = htmlspecialchars($_SESSION['username'] ?? '');
$is_admin  = ($_SESSION['role'] ?? '') === 'admin';

$profileRepo = new ProfileRepository($conn);

$total_video = $profileRepo->countVideo($user_id);
$total_music = $profileRepo->countMusic($user_id);

$has_content = ($total_video + $total_music) > 0;

if (!$has_content) {
    header("Location: ../upload?first=1");
    exit();
}

define('MEEL_MANAGE_ACCESS', true);
require_once '../controllers/profile/fun-manage.php';

$cleaned_count = cleanupPendingDeletions();

$delete_msg = '';
if (isset($_GET['delete']) && isset($_GET['type']) && isset($_GET['id'])) {
    $csrf_input = $_GET['csrf_token'] ?? ($_POST['csrf_token'] ?? null);
    $csrf_input = is_string($csrf_input) ? $csrf_input : null;
    if (!verify_csrf_token($csrf_input)) {
        $delete_msg = 'Token tidak valid.';
    } else {
        $del_id   = (int)$_GET['id'];
        $del_type = $_GET['type'];

        if ($del_type === 'video') {
            $result = handleDeleteVideo($del_id, $user_id, $conn);
        } elseif ($del_type === 'music') {
            $result = handleDeleteMusic($del_id, $user_id, $conn);
        } else {
            $result = ['success' => false, 'message' => 'Tipe tidak dikenal.'];
        }

        $delete_msg = $result['message'];
        if ($result['success']) {
            $total_video = $profileRepo->countVideo($user_id);
            $total_music = $profileRepo->countMusic($user_id);
        }
    }
}

$active_tab = $_GET['tab'] ?? 'video';
if (!in_array($active_tab, ['video', 'music'])) $active_tab = 'video';

$page_size = 20;
$page = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $page_size;

$videos = [];
$music_list = [];

if ($active_tab === 'video') {
    $videos      = $profileRepo->getVideosPaginated($user_id, $page_size, $offset);
    $total_items = $profileRepo->countVideo($user_id);
} else {
    $music_list  = $profileRepo->getMusicPaginated($user_id, $page_size, $offset);
    $total_items = $profileRepo->countMusic($user_id);
}

$total_pages = max(1, ceil($total_items / $page_size));
$back_url = "../profile/" . urlencode($_SESSION['username']);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Kelola konten Anda.">
    <meta property="og:title" content="Kelola Konten | MEeL">
    <meta property="og:description" content="Kelola konten video dan musik Anda di MEeL. Edit, hapus, dan pantau statistik.">
    <title>Kelola Konten | MEeL</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/video/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/video/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/video/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/profile/manage.css?v=<?= filemtime(__DIR__ . '/../assets/css/profile/manage.css') ?>">
</head>

<body class="text-gray-400 min-h-screen">

    
    <nav class="border-b border-white/[.04] bg-[#080a0f]/95 sticky top-0 z-50 backdrop-blur-md">
        <div class="w-full px-3 sm:px-6 h-14 flex items-center justify-between gap-2 sm:gap-4">
            <a href="<?= $back_url ?>" class="flex items-center gap-2 flex-shrink-0" title="Kembali ke Profil">
                <img src="../assets/MEeL.png" class="w-7 h-7 bg-blue-600 rounded-lg flex items-center justify-center" title="MEeL - Kembali ke profil">
                <span class="text-sm font-bold tracking-tight text-white uppercase hidden sm:block">
                    Kelola<span class="text-blue-500">Konten</span>
                </span>
            </a>
        </div>
    </nav>

    <main class="w-full max-w-6xl mx-auto px-4 sm:px-6 pt-6 pb-20">

        
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Dashboard</div>
                <h1 class="text-2xl font-black text-white tracking-tight uppercase">
                    <span class="text-blue-500">@<?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?></span>
                </h1>
            </div>

            <div class="stats-bar">
                <div class="stat-item stat-video">
                    <i data-lucide="play"></i>
                    <span><?= $total_video ?></span>
                    <span class="text-[9px] font-normal text-gray-600 uppercase">Video</span>
                </div>
                <div class="stat-item stat-music">
                    <i data-lucide="music"></i>
                    <span><?= $total_music ?></span>
                    <span class="text-[9px] font-normal text-gray-600 uppercase">Music</span>
                </div>
                <?php if ($cleaned_count > 0): ?>
                    <div class="stat-item text-green-500" title="File lama dibersihkan">
                        <i data-lucide="trash-2"></i>
                        <span>+<?= $cleaned_count ?></span>
                        <span class="text-[9px] font-normal text-gray-600 uppercase">Bersih</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        
        <?php if (!empty($delete_msg)): ?>
            <div class="alert-bar <?= strpos($delete_msg, 'berhasil') !== false || strpos($delete_msg, 'dibersihkan') !== false ? 'alert-success' : 'alert-error' ?>">
                <i data-lucide="<?= strpos($delete_msg, 'berhasil') !== false || strpos($delete_msg, 'dibersihkan') !== false ? 'check-circle' : 'alert-triangle' ?>" class="w-4 h-4 flex-shrink-0"></i>
                <?= htmlspecialchars($delete_msg) ?>
            </div>
        <?php endif; ?>
        
        <div class="manage-tabs mb-6 max-w-sm">
            <a href="?tab=video<?= isset($_GET['csrf_token']) ? '&csrf_token=' . urlencode($_GET['csrf_token']) : '' ?>"
                class="manage-tab <?= $active_tab === 'video' ? 'active-video' : '' ?>" title="Kelola video Anda">
                <i data-lucide="play" class="w-3.5 h-3.5 inline-block -ml-1 mr-1.5"></i>
                Video
            </a>
            <a href="?tab=music<?= isset($_GET['csrf_token']) ? '&csrf_token=' . urlencode($_GET['csrf_token']) : '' ?>"
                class="manage-tab <?= $active_tab === 'music' ? 'active-tab' : '' ?>" title="Kelola musik Anda">
                <i data-lucide="music" class="w-3.5 h-3.5 inline-block -ml-1 mr-1.5"></i>
                Music
            </a>
        </div>

        
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
            <?php if ($active_tab === 'video'): ?>
                <?php if (!empty($videos)): ?>
                    <?php foreach ($videos as $v):
                        $thumb = !empty($v['thumbnail'])
                            ? '../video/upload/thumbnail/' . htmlspecialchars($v['thumbnail'])
                            : '../assets/img/video0.webp';
                    ?>
                        <div class="content-card">
                            <a href="<?= base_url('/video/watch?v=' . (int)$v['id']) ?>" class="block card-thumb" title="<?= htmlspecialchars($v['title']) ?>">
                                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($v['title']) ?>" loading="lazy" width="640" height="360">
                            </a>
                            <div class="card-body">
                                <a href="<?= base_url('/video/watch?v=' . (int)$v['id']) ?>" class="card-title no-underline hover:text-red-400 transition-colors" title="<?= htmlspecialchars($v['title']) ?>">
                                    <?= htmlspecialchars($v['title']) ?>
                                </a>
                                <div class="card-meta">
                                    <span><?= number_format($v['views'] ?? 0) ?> views</span>
                                    <span class="flex items-center gap-1 text-green-500/80">
                                        <i data-lucide="thumbs-up" class="w-3 h-3"></i>
                                        <?= number_format($v['likes'] ?? 0) ?>
                                    </span>
                                    <span class="flex items-center gap-1 text-red-500/80">
                                        <i data-lucide="thumbs-down" class="w-3 h-3"></i>
                                        <?= number_format($v['dislikes'] ?? 0) ?>
                                    </span>
                                    <span>•</span>
                                    <span><?= date('d M Y', strtotime($v['upload_date'])) ?></span>
                                </div>
                                <div class="flex gap-2 mt-3 pt-3 border-t border-white/[.04]">
                                    <a href="<?= base_url(($is_admin ? '/admin' : '/profile') . '/edit-video?id=' . (int)$v['id']) ?>"
                                        class="action-btn action-btn-edit" title="Edit video <?= htmlspecialchars($v['title']) ?>">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </a>
                                    <a href="?tab=video&type=video&id=<?= $v['id'] ?>&delete=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                        onclick="return confirmHapus(event, '<?= htmlspecialchars(addslashes($v['title']), ENT_QUOTES) ?>', 'video')"
                                        class="action-btn action-btn-delete" title="Hapus video <?= htmlspecialchars($v['title']) ?>">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i data-lucide="play" class="w-10 h-10 text-gray-700 mx-auto mb-4 block"></i>
                        <p>Belum ada video di sini.</p>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <?php if (!empty($music_list)): ?>
                    <?php foreach ($music_list as $m):
                        $thumb = !empty($m['thumbnail'])
                            ? '../music/upload/thumbnail/' . htmlspecialchars($m['thumbnail'])
                            : '../assets/img/music0.webp';
                    ?>
                        <div class="content-card">
                            <a href="<?= base_url('/music/watch?v=' . (int)$m['id']) ?>" class="block card-thumb" title="<?= htmlspecialchars($m['title']) ?>">
                                <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($m['title']) ?>" loading="lazy" width="640" height="360">
                            </a>
                            <div class="card-body">
                                <a href="<?= base_url('/music/watch?v=' . (int)$m['id']) ?>" class="card-title no-underline hover:text-orange-400 transition-colors" title="<?= htmlspecialchars($m['title']) ?>">
                                    <?= htmlspecialchars($m['title']) ?>
                                </a>
                                <div class="card-meta">
                                    <span><?= htmlspecialchars($m['artist'] ?? 'Unknown') ?></span>
                                    <span>•</span>
                                    <span><?= number_format($m['views']) ?> views</span>
                                    <span class="flex items-center gap-1 text-green-500/80">
                                        <i data-lucide="thumbs-up" class="w-3 h-3"></i>
                                        <?= number_format($m['likes']) ?>
                                    </span>
                                    <span class="flex items-center gap-1 text-red-500/80">
                                        <i data-lucide="thumbs-down" class="w-3 h-3"></i>
                                        <?= number_format($m['dislikes']) ?>
                                    </span>
                                </div>
                                <div class="flex gap-2 mt-3 pt-3 border-t border-white/[.04]">
                                    <a href="<?= base_url(($is_admin ? '/admin' : '/profile') . '/edit-music?id=' . (int)$m['id']) ?>"
                                        class="action-btn action-btn-edit" title="Edit musik <?= htmlspecialchars($m['title']) ?>">
                                        <i data-lucide="edit" class="w-3 h-3"></i> Edit
                                    </a>
                                    <a href="?tab=music&type=music&id=<?= $m['id'] ?>&delete=1&csrf_token=<?= $_SESSION['csrf_token'] ?>"
                                        onclick="return confirmHapus(event, '<?= htmlspecialchars(addslashes($m['title']), ENT_QUOTES) ?>', 'music')"
                                        class="action-btn action-btn-delete" title="Hapus musik <?= htmlspecialchars($m['title']) ?>">
                                        <i data-lucide="trash-2" class="w-3 h-3"></i> Hapus
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <i data-lucide="music" class="w-10 h-10 text-gray-700 mx-auto mb-4 block"></i>
                        <p>Belum ada musik di sini.</p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        
        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?tab=<?= $active_tab ?>&p=<?= $i ?>"
                        class="page-link <?= $i === $page ? 'active-page' : '' ?>" title="Halaman <?= $i ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/compatibilitas/sweetalert2.all.min.js"></script>
    <script src="../assets/js/compatibilitas/script.min.js"></script>
    <script src="../assets/js/profile/manage.js?v=<?= filemtime(__DIR__ . '/../assets/js/profile/manage.js') ?>"></script>
</body>

</html>
