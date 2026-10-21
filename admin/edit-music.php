<?php

/**
 * @var \mysqli $conn
 */
/* reference build: MEeL-C4H9NO2 [e87eb3aad14fb992] */
include __DIR__ . '/../auth/config.php';
include __DIR__ . '/../auth/auth.php';
include_once __DIR__ . '/../modules/core/helpers.php';
require_once __DIR__ . '/../modules/core/japanese.php';
require_once __DIR__ . '/includes/media_editor.php';
$_EDIT_CONTEXT = $_EDIT_CONTEXT ?? 'admin';
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login");
    exit();
}
$user_id = $_SESSION['user_id'];
$curr_role = get_user_role($conn, (int)$user_id);
$is_admin = is_admin($conn);
if ($curr_role === 'guest') {
    header("Location: ../");
    exit();
}
$edit_id = (int)($_GET['id'] ?? 0);
meel_editor_context_redirect('music', $edit_id, $is_admin, $_EDIT_CONTEXT);
$back_url = meel_back_url(
    $_EDIT_CONTEXT === 'admin' ? base_url('/admin/stats') : base_url('/profile/manage')
);
require_once __DIR__ . '/../modules/media/MediaAdminRepository.php';
$adminMedia = new MediaAdminRepository($conn);
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$music = $adminMedia->getMedia('music', $id);
if (!$music) {
    header("Location: ../err/?code=not_found");
    exit;
}
$is_owner = ((int)$music['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/?code=denied");
    exit();
}
$status = "";
$error_message = "";
$cfg = meel_editor_media_config('music');
if (isset($_POST['delete_lyrics_lang']) && $_POST['delete_lyrics_lang'] !== '') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $del_lang = sanitize_subtitle_lang($_POST['delete_lyrics_lang'], 'und');
        delete_music_lyrics($id, $del_lang);
        $status = "lyrics_deleted";
    }
}
if (isset($_POST['update'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $artist = trim($_POST['artist'] ?? 'Artis Tidak Diketahui');
        $album = trim($_POST['album'] ?? 'Single');
        $description = trim($_POST['description'] ?? '');
        $old_thumb_name = $music['thumbnail'];
        $thumbnail_url = $old_thumb_name;
        $thumb_upload = meel_editor_upload_thumbnail($_FILES, $cfg, $title, $old_thumb_name, $error_message);
        $thumbnail_url = $thumb_upload['url'];
        if ($thumb_upload['error'] !== '') {
            $error_message = $thumb_upload['error'];
        }
        if ($title === '') {
            $error_message = "Judul lagu tidak boleh kosong.";
        } else {
            $meta = generate_search_metadata($title, $artist, $album);
            if ($adminMedia->updateMusic($id, $title, $artist, $album, $description, $thumbnail_url, $meta)) {
                $status = "success";
                $music['title'] = $title;
                $music['artist'] = $artist;
                $music['album'] = $album;
                $music['description'] = $description;
                if ($thumbnail_url !== $old_thumb_name) {
                    $music['thumbnail'] = $thumbnail_url;
                    if (!empty($old_thumb_name) && $old_thumb_name !== 'music_default.png') {
                        $old_path = meel_media_base_path('music') . '/thumbnail/' . basename($old_thumb_name);
                        if (is_file($old_path)) @unlink($old_path);
                    }
                }
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
    }
}
$record = $music;
$thumb_src = !empty($music['thumbnail'])
    ? '../music/upload/thumbnail/' . htmlspecialchars($music['thumbnail'])
    : '../assets/img/placeholder-music.svg';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <?php
    $_META_TITLE = 'Edit Musik | MEeL Admin';
    $_META_DESC = 'Edit detail musik di MEeL. Ubah judul, artis, album, deskripsi, dan cover art.';
    include __DIR__ . '/../partials/link.php';
    ?>
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/upload-form.css') ?>">
    <?php meel_css_links('admin', '../assets/css/admin'); ?>
    <link rel="stylesheet" href="../assets/css/admin/edit/shared/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin/edit/shared/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/music/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin/edit/music/main.css') ?>">
</head>

<body class="theme-music">
    <div class="page-wrap">
        <?php
        $page_title = 'Edit Musik';
        $media_type = 'music';
        include __DIR__ . '/header-admin.php';
        ?>
        <div class="edit-layout">
            <aside class="sidebar-panel">
                <div class="cover-wrap" id="cover-wrap">
                    <img src="<?= $thumb_src ?>"
                        alt="Sampul <?= htmlspecialchars($music['title']) ?>"
                        class="cover-img"
                        id="cover-preview">
                    <div class="cover-overlay">
                        <div class="cover-overlay-icon">
                            <i data-lucide="image" style="width:20px;height:20px;color:#fff;"></i>
                        </div>
                        <div class="cover-overlay-text">Klik atau jatuhkan<br>untuk ganti cover</div>
                    </div>
                    <span class="cover-badge" id="cover-badge">Cover Art</span>
                    <span class="cover-changed-badge" id="cover-changed-badge">✓ Baru</span>
                </div>
                <?php include __DIR__ . '/partials/edit_media_uploader_card.php'; ?>
                <div class="meta-info">
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="music" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Judul Lagu</div>
                            <div class="meta-value" id="sidebar-title"><?= htmlspecialchars($music['title']) ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="mic-2" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Artis</div>
                            <div class="meta-value" id="sidebar-artist"><?= htmlspecialchars($music['artist'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="disc" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Album</div>
                            <div class="meta-value" id="sidebar-album"><?= htmlspecialchars($music['album'] ?? '—') ?></div>
                        </div>
                    </div>
                    <div class="meta-row">
                        <div class="meta-row-icon">
                            <i data-lucide="calendar" style="width:13px;height:13px;color:var(--accent)"></i>
                        </div>
                        <div>
                            <div class="meta-label">Tanggal Unggah</div>
                            <div class="meta-value"><?= !empty($music['upload_date']) ? date('d M Y', strtotime($music['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>
                <?php include __DIR__ . '/partials/edit_media_stats.php'; ?>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto">
                    <a href="<?= base_url('/music/watch?v=' . (int)$id) ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Musik
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="." class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dasbor Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Edit <span>Musik</span></h1>
                        <p class="form-subtitle"><?= $is_admin && !$is_owner ? 'Edit sebagai Admin · Milik @' . htmlspecialchars($music['uploader']) : 'Ubah keterangan &amp; detail lagu' ?></p>
                    </div>
                    <i data-lucide="music-2" style="width:36px;height:36px;color:var(--accent);opacity:.3;flex-shrink:0;margin-top:4px;"></i>
                </div>
                <?php if ($status === "success"): ?>
                    <div class="alert alert-success" style="margin-bottom:20px;">
                        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        Detail musik berhasil diperbarui!
                    </div>
                <?php endif; ?>
                <?php if ($error_message !== ""): ?>
                    <div class="alert alert-error" style="margin-bottom:20px;">
                        <i data-lucide="alert-triangle" style="width:15px;height:15px;flex-shrink:0;"></i>
                        <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>
                <form id="edit-form" method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="file" name="thumbnail" accept="image/*" id="cover-file-hidden" style="display:none">
                    <?php endif; ?>
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Lagu</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul lagu..."
                            required class="field-input"
                            value="<?= htmlspecialchars($music['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>
                    <div class="two-col">
                        <div class="field-group">
                            <label class="field-label" for="f-artist">Artis</label>
                            <input type="text" id="f-artist" name="artist" placeholder="Artis..."
                                required class="field-input"
                                value="<?= htmlspecialchars($music['artist'] ?? '') ?>"
                                oninput="document.getElementById('sidebar-artist').textContent = this.value || '—'">
                        </div>
                        <div class="field-group">
                            <label class="field-label" for="f-album">Album</label>
                            <input type="text" id="f-album" name="album" placeholder="Album..."
                                class="field-input"
                                value="<?= htmlspecialchars($music['album'] ?? '') ?>"
                                oninput="document.getElementById('sidebar-album').textContent = this.value || '—'">
                        </div>
                    </div>
                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description" placeholder="Masukkan deskripsi musik..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($music['description'] ?? '') ?></textarea>
                    </div>
                    <div class="divider" style="margin:0;"></div>
                    <div class="lyrics-section">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:12px;">
                            <div>
                                <div style="font-size:11px;font-weight:700;color:var(--accent);text-transform:uppercase;letter-spacing:.1em;">Lirik</div>
                                <div class="lyrics-subtitle">Kelola lirik untuk fitur karaoke</div>
                            </div>
                            <a href="<?= base_url('/' . ($_EDIT_CONTEXT === 'admin' ? 'admin' : 'profile') . '/lrc-editor?id=' . (int)$id) ?>"
                                class="btn-secondary" style="font-size:10px;padding:6px 12px;text-decoration:none;display:inline-flex;align-items:center;gap:5px;">
                                <i data-lucide="pen-tool" style="width:12px;height:12px;"></i> LRC Editor
                            </a>
                        </div>
                        <?php
                        $existing_lyrics = get_music_lyrics_list($id);
                        $has_lyrics = !empty($existing_lyrics);
                        ?>
                        <?php if ($has_lyrics): ?>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($existing_lyrics as $_ly): ?>
                                    <div class="lyrics-row">
                                        <i data-lucide="file-text" style="width:13px;height:13px;color:var(--accent);flex-shrink:0;"></i>
                                        <span class="lyrics-filename"><?= htmlspecialchars($_ly['file']) ?></span>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Hapus lirik ini?')">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="delete_lyrics_lang" value="<?= htmlspecialchars($_ly['lang']) ?>">
                                            <button type="submit" class="lyrics-delete-btn" title="Hapus lirik">
                                                <i data-lucide="trash-2" style="width:12px;height:12px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="lyrics-empty">Belum ada lirik. Tambahkan via LRC Editor.</div>
                        <?php endif; ?>
                    </div>
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
    <?php include __DIR__ . '/../partials/footer.php'; ?>
    <?php $scripts_root = '../';
    include __DIR__ . '/../partials/scripts.php'; ?>
    <script src="../assets/js/admin/edit/shared/form.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin/edit/shared/form.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/thumbnail.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin/edit/shared/thumbnail.js') ?>"></script>
    <script src="../assets/js/admin/edit/shared/dragdrop.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin/edit/shared/dragdrop.js') ?>"></script>
    <script src="../assets/js/admin/edit/music.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin/edit/music.js') ?>"></script>
    <script>
        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Detail musik telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#c2410c',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
        <?php if ($status === "lyrics_deleted"): ?>
            Swal.fire({
                title: 'Dihapus!',
                text: 'Lirik berhasil dihapus.',
                icon: 'success',
                confirmButtonColor: '#c2410c',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>