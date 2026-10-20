<?php

/**
 * `$conn` di-set oleh `auth/config.php` (di-include di bawah) — sah saat runtime,
 * tapi tak terlihat oleh static analyser karena tidak menelusuri variabel
 * lintas `include`.
 *
 * @var \mysqli $conn
 */

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
meel_editor_context_redirect('video', $edit_id, $is_admin, $_EDIT_CONTEXT);

$back_url = meel_back_url(
    $_EDIT_CONTEXT === 'admin' ? base_url('/admin/stats') : base_url('/profile/manage')
);
require_once __DIR__ . '/../modules/media/MediaAdminRepository.php';
$adminMedia = new MediaAdminRepository($conn);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$video = $adminMedia->getMedia('video', $id);
if (!$video) {
    header("Location: ../err/?code=not_found");
    exit;
}

$is_owner = ((int)$video['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header("Location: ../err/?code=denied");
    exit();
}
$status = "";
$error_message = "";
$cfg = meel_editor_media_config('video');
if (isset($_POST['update'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $old_thumb_name = $video['thumbnail'];
        $thumbnail_url = $old_thumb_name;
        $thumb_upload = meel_editor_upload_thumbnail($_FILES, $cfg, $title, $old_thumb_name, $error_message);
        $thumbnail_url = $thumb_upload['url'];
        if ($thumb_upload['error'] !== '') {
            $error_message = $thumb_upload['error'];
        }
        if (empty($error_message) && isset($_FILES['subtitle']) && $_FILES['subtitle']['error'] === UPLOAD_ERR_OK) {
            $sub_ext = strtolower(pathinfo($_FILES['subtitle']['name'], PATHINFO_EXTENSION));
            $sub_lang = sanitize_subtitle_lang($_POST['subtitle_lang'] ?? 'id');
            $sub_allowed = ['vtt', 'srt'];

            if (in_array($sub_ext, $sub_allowed, true) && validate_subtitle_file($_FILES['subtitle']['tmp_name'])) {
                $sub_content = (string)@file_get_contents($_FILES['subtitle']['tmp_name']);
                if ($sub_content !== '') {
                    if ($sub_ext === 'srt') {
                        $sub_content = convert_srt_to_vtt($sub_content);
                    }
                    $sub_content = strip_utf8_bom($sub_content);

                    $hls_folder = basename(dirname($video['filename']));
                    $sub_dir = meel_media_base_path('video') . '/video/' . $hls_folder . '/';
                    if (is_dir($sub_dir)) {
                        $sub_target = $sub_dir . $hls_folder . '.' . $sub_lang . '.vtt';
                        if (@file_put_contents($sub_target, $sub_content, LOCK_EX) !== false) {
                            $status = "success";
                        } else {
                            $error_message = "Gagal menulis file subtitle ke storage.";
                        }
                    } else {
                        $error_message = "Folder HLS video tidak ditemukan di storage.";
                    }
                } else {
                    $error_message = "File subtitle kosong atau tidak dapat dibaca.";
                }
            } else {
                $error_message = "File subtitle tidak valid. Gunakan format VTT atau SRT (maks 2MB).";
            }
        }
        if ($title === '') {
            $error_message = "Judul video tidak boleh kosong.";
        } elseif ($error_message === '') {

            $meta = generate_search_metadata($title);
            if ($adminMedia->updateVideo($id, $title, $description, $thumbnail_url, $meta)) {
                $status = "success";
                $video['title'] = $title;
                $video['description'] = $description;
                $video['thumbnail'] = $thumbnail_url;
                if (
                    $thumbnail_url !== $old_thumb_name
                    && !empty($old_thumb_name)
                    && !meel_is_default_thumbnail($old_thumb_name)
                ) {
                    $old_path = meel_media_base_path('video') . '/thumbnail/' . basename($old_thumb_name);
                    if (is_file($old_path)) @unlink($old_path);
                    $old_sm = video_thumbnail_sm_name($old_thumb_name);
                    if ($old_sm !== '' && !meel_is_default_thumbnail($old_sm)) {
                        $old_sm_path = meel_media_base_path('video') . '/thumbnail/' . $old_sm;
                        if (is_file($old_sm_path)) @unlink($old_sm_path);
                    }
                }
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
        if ($error_message !== '' && $thumbnail_url !== $video['thumbnail']) {
            $orphan_thumb = meel_media_base_path('video') . '/thumbnail/' . basename($thumbnail_url);
            if (is_file($orphan_thumb)) {
                @unlink($orphan_thumb);
            }
        }
    }
}
if (isset($_POST['delete_subtitle_lang']) && $_POST['delete_subtitle_lang'] !== '') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $del_lang = sanitize_subtitle_lang($_POST['delete_subtitle_lang'], 'und');
        $hls_folder = basename(dirname($video['filename']));
        $del_path = meel_media_base_path('video') . '/video/' . $hls_folder . '/' . $hls_folder . '.' . $del_lang . '.vtt';
        if (file_exists($del_path)) {
            if (@unlink($del_path)) {
                $status = "success";
            } else {
                $error_message = "Gagal menghapus file subtitle.";
            }
        } else {
            $error_message = "File subtitle bahasa tersebut tidak ditemukan.";
        }
    }
}

$existing_subtitles = [];
$hls_folder_dir = basename(dirname($video['filename']));
$sub_scan_dir = meel_media_base_path('video') . '/video/' . $hls_folder_dir . '/';
if (is_dir($sub_scan_dir)) {
    foreach (glob($sub_scan_dir . '*.vtt') ?: [] as $sf) {
        $sbase = basename($sf);
        if ($sbase === 'thumbnails.vtt') continue;
        if (preg_match('/\.([a-z]{2,3}(?:-[a-z]{2,8})?)\.vtt$/i', $sbase, $m)) {
            $existing_subtitles[] = ['lang' => strtolower($m[1]), 'file' => $sbase];
        }
    }
}
usort($existing_subtitles, fn($a, $b) => strcmp($a['lang'], $b['lang']));

$record = $video;
$thumb_src = !empty($video['thumbnail'])
    ? '../video/upload/thumbnail/' . htmlspecialchars($video['thumbnail'])
    : '../assets/img/video0.webp';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <?php
    $_META_TITLE = 'Edit Video | MEeL Admin';
    $_META_DESC = 'Edit detail video di MEeL. Ubah judul, deskripsi, dan thumbnail video.';
    include __DIR__ . '/../partials/link.php';
    ?>
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css?v=<?= filemtime(__DIR__ . '/../assets/css/shared/upload-form.css') ?>">
    <?php meel_css_links('admin', '../assets/css/admin'); ?>
    <link rel="stylesheet" href="../assets/css/admin/edit/shared/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin/edit/shared/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/video/main.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin/edit/video/main.css') ?>">
</head>

<body class="theme-video">
    <div class="page-wrap">

        <?php
        $page_title = 'Edit Video';
        $media_type = 'video';
        include __DIR__ . '/header-admin.php';
        ?>
        <div class="edit-layout">

            <aside class="sidebar-panel">

                <div class="thumb-wrap" id="thumb-wrap">

                    <img src="<?= $thumb_src ?>"
                        alt="Thumbnail <?= htmlspecialchars($video['title']) ?>"
                        class="thumb-img"
                        id="thumb-preview">
                    <div class="thumb-overlay">
                        <div class="thumb-overlay-icon">
                            <i data-lucide="image" style="width:22px;height:22px;color:#fff;"></i>
                        </div>
                        <div class="thumb-overlay-text">Klik atau jatuhkan<br>untuk ganti thumbnail</div>
                    </div>
                    <span class="thumb-label" id="thumb-label">Thumbnail saat ini</span>
                    <span class="thumb-changed-badge" id="thumb-changed-badge">✓ Baru</span>
                </div>

<?php include __DIR__ . '/partials/edit_media_uploader_card.php'; ?>

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
                            <div class="meta-label">Tanggal Unggah</div>
                            <div class="meta-value"><?= !empty($video['upload_date']) ? date('d M Y', strtotime($video['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>

<?php include __DIR__ . '/partials/edit_media_stats.php'; ?>

                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto;">
                    <a href="<?= base_url('/video/watch?v=' . (int)$id) ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Video
                    </a>
                    <?php if ($is_admin): ?>
                        <a href="." class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dasbor Admin
                        </a>
                    <?php else: ?>
                        <a href="../profile/<?= $_SESSION['username'] ?>" class="btn-secondary" style="justify-content:center;">
                            <i data-lucide="user" style="width:13px;height:13px;"></i> Profil Saya
                        </a>
                    <?php endif; ?>
                </div>
            </aside>

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
                <form id="edit-form" method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                        <input type="file" name="thumbnail" accept="image/*" id="thumb-file-hidden" style="display:none">
                    <?php endif; ?>

                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Video</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul video..."
                            required class="field-input"
                            value="<?= htmlspecialchars($video['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    <div class="field-group" style="flex:1;display:flex;flex-direction:column;">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description"
                            placeholder="Masukkan deskripsi video..."
                            class="field-input" style="flex:1;min-height:120px;resize:none;"><?= htmlspecialchars($video['description'] ?? '') ?></textarea>
                    </div>

                    <div class="field-group" style="gap:10px;">
                        <label class="field-label">Subtitle</label>
                        <?php if (!empty($existing_subtitles)): ?>
                            <div style="display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($existing_subtitles as $_sub): ?>
                                    <div class="sub-row">
                                        <i data-lucide="captions" style="width:13px;height:13px;color:var(--accent);flex-shrink:0;"></i>
                                        <span class="sub-lang"><?= htmlspecialchars(subtitle_lang_label($_sub['lang'])) ?></span>
                                        <span class="sub-file"><?= htmlspecialchars($_sub['file']) ?></span>
                                        <form method="POST" style="display:inline;margin:0;"
                                            onsubmit="return meelConfirmForm(event, { title:'Hapus Subtitle', text:'Hapus subtitle bahasa <?= htmlspecialchars(subtitle_lang_label($_sub['lang'])) ?>?', confirmButtonText:'HAPUS' })">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="delete_subtitle_lang" value="<?= htmlspecialchars($_sub['lang']) ?>">
                                            <button type="submit" title="Hapus subtitle" class="sub-delete"
                                                aria-label="Hapus subtitle <?= htmlspecialchars($_sub['lang']) ?>">
                                                <i data-lucide="trash-2" style="width:13px;height:13px;"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div style="font-size:10px;color:var(--admin-muted);">Belum ada subtitle untuk video ini.</div>
                        <?php endif; ?>
                    </div>

                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <label class="field-label">Unggah / Ganti Subtitle</label>

                        <div class="drop-zone-subtitle" id="subtitle-zone">
                            <input type="file" name="subtitle" accept=".vtt,.srt"
                                id="f-subtitle" onchange="handleSubtitleFile(this)" aria-label="Pilih file subtitle (VTT atau SRT)">
                            <div class="drop-zone-icon">
                                <i data-lucide="captions" style="width:18px;height:18px;color:var(--accent);"></i>
                            </div>
                            <div class="drop-zone-text">
                                <div class="drop-zone-label" id="subtitle-label">Subtitle</div>
                                <div class="drop-zone-sub" id="subtitle-sub">Opsional · VTT / SRT (maks 2MB)</div>
                            </div>
                        </div>

                        <div class="field-group" id="subtitle-lang-wrap" style="display:none;">
                            <label class="field-label" for="f-subtitle-lang-trigger">Bahasa Subtitle</label>
                            <div class="lang-dropdown" id="f-subtitle-lang-dropdown" data-name="subtitle_lang">
                                <button type="button" class="lang-trigger" id="f-subtitle-lang-trigger"
                                    aria-haspopup="listbox" aria-expanded="false">
                                    <span class="lang-trigger-label" id="f-subtitle-lang-label"><?= htmlspecialchars(lang_map()['id'] ?? 'Indonesia') ?></span>
                                    <i data-lucide="chevron-down" class="lang-trigger-chevron"></i>
                                </button>
                                <div class="lang-options hidden" role="listbox" aria-label="Pilih bahasa subtitle">
                                    <?php foreach (lang_map() as $_lang_code => $_lang_label): ?>
                                        <button type="button" class="lang-option<?= $_lang_code === 'id' ? ' active' : '' ?>"
                                            data-lang="<?= htmlspecialchars($_lang_code) ?>" role="option"
                                            aria-selected="<?= $_lang_code === 'id' ? 'true' : 'false' ?>"><?= htmlspecialchars($_lang_label) ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <input type="hidden" name="subtitle_lang" id="f-subtitle-lang" value="id">
                        </div>
                        <div style="font-size:9px;color:var(--admin-muted);">SRT dikonversi otomatis ke VTT</div>
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
    <script src="../assets/js/admin/edit/video.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin/edit/video.js') ?>"></script>
    <script src="../assets/js/shared/lang-dropdown.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/lang-dropdown.js') ?>"></script>
    <script>
        <?php if ($status === "success"): ?>
            Swal.fire({
                title: 'Berhasil!',
                text: 'Detail video telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#dc2626',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>
    </script>
</body>

</html>

<!-- reference build: MEeL-C8H11NO2 [1e3a187567818dfa] -->