<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/helpers.php';
require_once '../modules/japanese.php';

// Proteksi: harus login
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}
$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

$is_admin  = ($user_data && $user_data['role'] === 'admin');
$curr_role = $user_data['role'] ?? 'user';

// Tolak guest
if (!$user_data || $curr_role === 'guest') {
    header("Location: ../index.php");
    exit();
}

// ── Back URL (smart referer) ──
$back_url = $is_admin ? 'cookies.php' : '../video/index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref      = $_SERVER['HTTP_REFERER'];
    $host     = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path       = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['edit-music.php', 'edit-video.php'];
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

// Validasi ID Video
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt_video = $conn->prepare("SELECT v.*, u.username AS uploader, u.profile_picture AS uploader_pfp FROM video v JOIN users u ON v.user_id = u.id WHERE v.id = ? LIMIT 1");
$stmt_video->bind_param("i", $id);
$stmt_video->execute();
$video = $stmt_video->get_result()->fetch_assoc();

if (!$video) {
    die("<div style='color:red; padding:20px; background:#0b0e14; min-height:100vh; font-family:sans-serif;'><h2>Error: Video tidak ditemukan!</h2><a href='../video/index.php' style='color:#ef4444;'>Kembali ke Video</a></div>");
}

// Cek kepemilikan: admin bisa edit semua, uploader hanya miliknya
$is_owner = ((int)$video['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/denied.php");
    exit();
}

$status = "";
$error_message = "";

if (isset($_POST['update'])) {
    if (!verify_csrf()) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $thumbnail_url = $video['thumbnail'];

        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            $new_name = 'thumb_' . time() . '_' . uniqid() . '.' . $ext;
            $target_dir = __DIR__ . '/../video/upload/thumbnail/';
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            $upload_path = $target_dir . $new_name;
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                $thumbnail_url = $new_name;
            } else {
                $error_message = 'Gagal mengupload thumbnail ke server.';
            }
        }

        if ($title === '') {
            $error_message = "Judul video tidak boleh kosong.";
        } else {
            // Generate search_metadata baru
            $original = trim($title);
            $romaji   = getRomajiName($original);
            $meta     = mb_strtolower($original . " " . $romaji, 'UTF-8');

            $stmt_update = $conn->prepare("UPDATE video SET title = ?, description = ?, thumbnail = ?, search_metadata = ? WHERE id = ?");
            $stmt_update->bind_param("ssssi", $title, $description, $thumbnail_url, $meta, $id);
            if ($stmt_update->execute()) {
                $status = "success";
                $video['title'] = $title;
                $video['description'] = $description;
                $video['thumbnail'] = $thumbnail_url;
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
    }
}

$thumb_src = !empty($video['thumbnail'])
    ? '../video/upload/thumbnail/' . htmlspecialchars($video['thumbnail'])
    : '../assets/img/video0.png';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <title>Edit Video | MEeL Admin</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link rel="stylesheet" href="../assets/css/em.css">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
</head>

<body class="theme-video">
    <div class="page-wrap">

        <!-- Top nav -->
        <?php
        $page_title = 'Edit Video';
        $media_type = 'video';
        include 'header-admin.php';
        ?>

        <div class="edit-layout">

            <!-- ── LEFT: Sidebar ── -->
            <aside class="sidebar-panel">
                <!-- Thumbnail — klik atau drag untuk ganti -->
                <div class="thumb-wrap" id="thumb-wrap">
                    <input type="file" name="thumbnail" accept="image/*"
                        class="thumb-file-input" id="thumb-file-input"
                        onchange="handleThumbChange(this)">
                    <img src="<?= $thumb_src ?>"
                        alt="Thumbnail <?= htmlspecialchars($video['title']) ?>"
                        class="thumb-img"
                        id="thumb-preview">
                    <div class="thumb-overlay">
                        <div class="thumb-overlay-icon">
                            <i data-lucide="image" style="width:22px;height:22px;color:#fff;"></i>
                        </div>
                        <div class="thumb-overlay-text">Klik atau drop<br>untuk ganti thumbnail</div>
                    </div>
                    <span class="thumb-label" id="thumb-label">Thumbnail saat ini</span>
                    <span class="thumb-changed-badge" id="thumb-changed-badge">✓ Baru</span>
                </div>

                <!-- Uploader card -->
                <div class="uploader-card">
                    <?php if (!empty($video['uploader_pfp'])): ?>
                        <img src="../profile/upload/<?= htmlspecialchars($video['uploader_pfp']) ?>"
                            alt="<?= htmlspecialchars($video['uploader'] ?? '') ?>"
                            class="uploader-avatar">
                    <?php else: ?>
                        <div class="uploader-avatar-fallback">
                            <?= strtoupper(substr($video['uploader'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="uploader-info">
                        <div class="uploader-label">Diunggah oleh</div>
                        <div class="uploader-name">@<?= htmlspecialchars($video['uploader'] ?? '—') ?></div>
                    </div>
                    <div class="uploader-role-badge"><?= $is_admin && !$is_owner ? 'Admin Edit' : 'Uploader' ?></div>
                </div>

                <!-- Meta rows -->
                <div class="meta-info">
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="film" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Judul Video</div>
                            <div class="meta-value" id="sidebar-title"><?= htmlspecialchars($video['title']) ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="calendar" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Tanggal Upload</div>
                            <div class="meta-value"><?= !empty($video['upload_date']) ? date('d M Y', strtotime($video['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>

                <!-- Stats -->
                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($video['views'] ?? 0) ?></div>
                        <div class="stat-label">Views</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($video['likes'] ?? 0) ?></div>
                        <div class="stat-label">Likes</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="color:#f87171;"><?= number_format($video['dislikes'] ?? 0) ?></div>
                        <div class="stat-label">Dislikes</div>
                    </div>
                </div>

                <!-- Nav buttons -->
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto;">
                    <a href="../video/watch.php?id=<?= $id ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Video
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="index.php" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dashboard Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/index.php" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Edit <span>Video</span></h1>
                        <p class="form-subtitle"><?= $is_admin && !$is_owner ? 'Edit sebagai Admin · Milik @' . htmlspecialchars($video['uploader']) : 'Ubah keterangan &amp; detail video' ?></p>
                    </div>
                    <i data-lucide="video" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>

                <?php if ($status === "success"): ?>
                    <div class="alert alert-success" style="margin-bottom:20px;">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Detail video berhasil diperbarui!
                    </div>
                <?php endif; ?>

                <?php if ($error_message !== ""): ?>
                    <div class="alert alert-error" style="margin-bottom:20px;">
                        <i data-lucide="alert-triangle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>

                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Video</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul video..."
                            required class="field-input"
                            value="<?= htmlspecialchars($video['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    <!-- Deskripsi — mengisi sisa ruang -->
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi video..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($video['description'] ?? '') ?></textarea>
                    </div>

                    <!-- Actions -->
                    <div class="form-actions">
                        <button type="submit" name="update" id="btn-save" class="btn-primary">
                            <i data-lucide="save" style="width:15px;height:15px;"></i>
                            Simpan Perubahan
                        </button>
                    </div>
                </form>
            </section>

        </div>
    </div>

    <?php include '../partials/footer.php'; ?>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <script>
        lucide.createIcons();

        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Detail video telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#ef4444',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>

        function handleSubmit() {
            const btn = document.getElementById('btn-save');
            btn.innerHTML = '<div style="width:16px;height:16px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin2 .7s linear infinite;"></div> Menyimpan...';
            btn.style.opacity = '.6';
            btn.style.pointerEvents = 'none';
        }

        function handleThumbChange(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('thumb-preview').src = e.target.result;
                    document.getElementById('thumb-changed-badge').style.display = 'block';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Drag-and-drop onto thumbnail
        const thumbWrap = document.getElementById('thumb-wrap');
        const thumbInput = document.getElementById('thumb-file-input');

        thumbWrap.addEventListener('dragover', function(e) {
            e.preventDefault();
            thumbWrap.classList.add('drag-over');
        });
        thumbWrap.addEventListener('dragleave', function() {
            thumbWrap.classList.remove('drag-over');
        });
        thumbWrap.addEventListener('drop', function(e) {
            e.preventDefault();
            thumbWrap.classList.remove('drag-over');
            const files = e.dataTransfer.files;
            if (files && files[0] && files[0].type.startsWith('image/')) {
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                thumbInput.files = dt.files;
                handleThumbChange(thumbInput);
            }
        });

        const style = document.createElement('style');
        style.textContent = '@keyframes spin2 { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>

</html>