<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/helpers.php';

// Proteksi Admin
if (!isset($_SESSION['user_id'])) {
    die("Akses ditolak.");
}

$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ?");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

if (!$user_data || $user_data['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// Validasi ID Musik
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt_music = $conn->prepare("SELECT m.*, u.username AS uploader, u.profile_picture AS uploader_pfp FROM music m JOIN users u ON m.user_id = u.id WHERE m.id = ? LIMIT 1");
$stmt_music->bind_param("i", $id);
$stmt_music->execute();
$music = $stmt_music->get_result()->fetch_assoc();

if (!$music) {
    die("<div style='color:orange; padding:20px; background:#0b0e14; min-height:100vh; font-family:sans-serif;'><h2>Error: Musik tidak ditemukan!</h2><a href='index.php' style='color:#f97316;'>Kembali ke Dashboard</a></div>");
}

$status = "";
$error_message = "";

if (isset($_POST['update'])) {
    if (!verify_csrf()) {
        $error_message = "CSRF Token tidak valid.";
    } else {
        $title = trim($_POST['title'] ?? '');
        $artist = trim($_POST['artist'] ?? 'Unknown Artist');
        $album = trim($_POST['album'] ?? 'Single');
        $description = trim($_POST['description'] ?? '');
        $thumbnail_url = $music['thumbnail'];
        // Handle cover thumbnail upload
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            $new_name = 'cover_' . time() . '_' . uniqid() . '.' . $ext;
            $target_dir = __DIR__ . '/../music/upload/thumbnail/';
            if (!is_dir($target_dir)) {
                @mkdir($target_dir, 0755, true);
            }
            $upload_path = $target_dir . $new_name;
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $upload_path)) {
                $thumbnail_url = $new_name;
                // Update thumbnail in DB too
                $stmt_thumb = $conn->prepare("UPDATE music SET thumbnail = ? WHERE id = ?");
                $stmt_thumb->bind_param("si", $thumbnail_url, $id);
                $stmt_thumb->execute();
            } else {
                $error_message = 'Gagal mengupload cover thumbnail.';
            }
        }

        if ($title === '') {
            $error_message = "Judul lagu tidak boleh kosong.";
        } else {
            // Generate search_metadata baru
            $meta_string = trim("$title $artist $album");
            $romaji = getRomajiName($meta_string);
            $meta = mb_strtolower($meta_string . " " . $romaji, 'UTF-8');

            $stmt_update = $conn->prepare("UPDATE music SET title = ?, artist = ?, album = ?, description = ?, search_metadata = ? WHERE id = ?");
            $stmt_update->bind_param("sssssi", $title, $artist, $album, $description, $meta, $id);
            if ($stmt_update->execute()) {
                $status = "success";
                // Refresh data musik terupdate
                $music['title'] = $title;
                $music['artist'] = $artist;
                $music['album'] = $album;
                $music['description'] = $description;
                if ($thumbnail_url !== $music['thumbnail']) {
                    $music['thumbnail'] = $thumbnail_url;
                }
            } else {
                $error_message = "Gagal menyimpan perubahan ke database.";
            }
        }
    }
}

// Helper thumbnail URL
$thumb_src = !empty($music['thumbnail'])
    ? '../music/upload/thumbnail/' . htmlspecialchars($music['thumbnail'])
    : '../assets/img/music0.png';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <title>Edit Musik | MEeL Admin</title>
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap');

        :root {
            --accent: #f97316;
            --accent-dim: rgba(249, 115, 22, .12);
            --accent-border: rgba(249, 115, 22, .22);
            --bg-base: #080b11;
            --bg-card: #0e1118;
            --bg-panel: #131720;
            --border: rgba(255, 255, 255, .06);
            --border-strong: rgba(255, 255, 255, .10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-base);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            color: #c9cdd6;
        }

        /* ── Background grain ── */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)' opacity='0.04'/%3E%3C/svg%3E");
            pointer-events: none;
            z-index: 0;
        }

        /* ── Layout ── */
        .page-wrap {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr;
            grid-template-rows: auto 1fr;
            min-height: 100vh;
        }

        .edit-layout {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0;
            flex: 1;
        }

        @media (min-width: 900px) {
            .edit-layout {
                grid-template-columns: 340px 1fr;
                min-height: calc(100vh - 56px);
            }
        }

        @media (min-width: 1200px) {
            .edit-layout {
                grid-template-columns: 380px 1fr;
            }
        }

        /* ── Sidebar (info panel) ── */
        .sidebar-panel {
            background: var(--bg-panel);
            border-right: 1px solid var(--border);
            padding: 32px 28px;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }

        @media (max-width: 899px) {
            .sidebar-panel {
                border-right: none;
                border-bottom: 1px solid var(--border);
                padding: 20px 16px;
                gap: 20px;
            }
        }

        /* ── Cover art ── */
        .cover-wrap {
            position: relative;
            width: 100%;
            max-width: 240px;
            margin: 0 auto;
        }

        @media (max-width: 899px) {
            .cover-wrap {
                max-width: 140px;
            }
        }

        .cover-img {
            width: 100%;
            aspect-ratio: 1;
            object-fit: cover;
            border-radius: 18px;
            display: block;
            border: 1px solid var(--border-strong);
            box-shadow: 0 20px 60px rgba(0, 0, 0, .6);
        }

        .cover-badge {
            position: absolute;
            bottom: -10px;
            right: -10px;
            background: var(--accent);
            color: #000;
            font-family: 'Syne', sans-serif;
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 5px 10px;
            border-radius: 30px;
            border: 2px solid var(--bg-base);
        }

        /* ── Vinyl ring deco ── */
        .vinyl-ring {
            position: absolute;
            inset: -12px;
            border-radius: 50%;
            border: 2px dashed rgba(249, 115, 22, .15);
            animation: spin 20s linear infinite;
            pointer-events: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ── Meta info ── */
        .meta-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .meta-row {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
        }

        .meta-row-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .meta-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: #555e6e;
            margin-bottom: 2px;
        }

        .meta-value {
            font-size: 13px;
            font-weight: 600;
            color: #e2e6ef;
            line-height: 1.3;
            word-break: break-word;
        }

        /* ── Stats strip ── */
        .stats-strip {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .stat-chip {
            flex: 1;
            min-width: 70px;
            padding: 10px 12px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
            text-align: center;
        }

        .stat-number {
            font-family: 'Syne', sans-serif;
            font-size: 18px;
            font-weight: 800;
            color: var(--accent);
            line-height: 1;
        }

        .stat-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .12em;
            color: #555e6e;
            margin-top: 4px;
        }

        /* ── Form panel ── */
        .form-panel {
            background: var(--bg-card);
            padding: 36px 40px;
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 899px) {
            .form-panel {
                padding: 20px 16px;
            }
        }

        @media (max-width: 599px) {
            .form-panel {
                padding: 16px 14px;
            }
        }

        /* ── Form header ── */
        .form-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 36px;
            padding-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }

        @media (max-width: 599px) {
            .form-header {
                margin-bottom: 24px;
                padding-bottom: 16px;
            }
        }

        .form-title {
            font-family: 'Syne', sans-serif;
            font-size: clamp(22px, 4vw, 32px);
            font-weight: 800;
            color: #fff;
            line-height: 1.1;
        }

        .form-title span {
            color: var(--accent);
        }

        .form-subtitle {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .2em;
            color: #454e5e;
            margin-top: 4px;
        }

        /* ── Input fields ── */
        .field-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .16em;
            color: #4a5568;
            padding-left: 4px;
        }

        .field-input {
            width: 100%;
            background: rgba(255, 255, 255, .035);
            border: 1px solid rgba(255, 255, 255, .08);
            border-radius: 14px;
            padding: 14px 18px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: #e2e6ef;
            outline: none;
            transition: border-color .2s, background .2s, box-shadow .2s;
        }

        .field-input:focus {
            border-color: var(--accent);
            background: rgba(249, 115, 22, .05);
            box-shadow: 0 0 0 3px rgba(249, 115, 22, .08);
        }

        .field-input::placeholder {
            color: #3a424f;
        }

        textarea.field-input {
            resize: vertical;
            min-height: 110px;
            line-height: 1.6;
        }

        /* ── Cover upload zone ── */
        .upload-zone {
            border: 2px dashed rgba(255, 255, 255, .1);
            border-radius: 14px;
            padding: 18px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            position: relative;
        }

        .upload-zone:hover,
        .upload-zone:focus-within {
            border-color: var(--accent);
            background: var(--accent-dim);
        }

        .upload-zone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        /* ── Two-col grid ── */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        @media (max-width: 480px) {
            .two-col {
                grid-template-columns: 1fr;
            }
        }

        /* ── Alert banners ── */
        .alert {
            padding: 14px 16px;
            border-radius: 14px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: rgba(34, 197, 94, .08);
            border: 1px solid rgba(34, 197, 94, .2);
            color: #4ade80;
        }

        .alert-error {
            background: rgba(239, 68, 68, .08);
            border: 1px solid rgba(239, 68, 68, .2);
            color: #f87171;
        }

        /* ── Buttons ── */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--accent);
            color: #000;
            font-family: 'Syne', sans-serif;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 14px 28px;
            border-radius: 14px;
            border: none;
            cursor: pointer;
            transition: background .2s, transform .15s, box-shadow .2s;
            box-shadow: 0 4px 20px rgba(249, 115, 22, .25);
        }

        .btn-primary:hover {
            background: #fb923c;
            transform: translateY(-1px);
            box-shadow: 0 8px 30px rgba(249, 115, 22, .35);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            background: rgba(255, 255, 255, .04);
            color: #6b7280;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            padding: 10px 18px;
            border-radius: 12px;
            border: 1px solid var(--border-strong);
            cursor: pointer;
            text-decoration: none;
            transition: background .2s, color .2s, border-color .2s;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, .08);
            color: #e2e6ef;
            border-color: rgba(255, 255, 255, .2);
        }

        /* ── Nav bar ── */
        .top-nav {
            height: 56px;
            background: rgba(8, 11, 17, .9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 20px;
            gap: 12px;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-brand {
            font-family: 'Syne', sans-serif;
            font-size: 14px;
            font-weight: 800;
            color: #fff;
            text-decoration: none;
            letter-spacing: .05em;
        }

        .nav-brand span {
            color: var(--accent);
        }

        .nav-sep {
            width: 1px;
            height: 20px;
            background: var(--border-strong);
        }

        .nav-crumb {
            font-size: 11px;
            font-weight: 600;
            color: #555e6e;
            text-decoration: none;
            transition: color .2s;
        }

        .nav-crumb:hover {
            color: var(--accent);
        }

        .nav-crumb-current {
            font-size: 11px;
            font-weight: 600;
            color: #e2e6ef;
        }

        .nav-chevron {
            color: #353d4a;
            font-size: 14px;
        }

        /* ── ID chip ── */
        .id-chip {
            margin-left: auto;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            color: var(--accent);
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .12em;
            padding: 4px 10px;
            border-radius: 20px;
            font-family: 'Syne', sans-serif;
        }

        /* ── Form actions row ── */
        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .form-actions .btn-primary {
            flex: 1;
            min-width: 160px;
        }

        @media (max-width: 400px) {
            .form-actions {
                flex-direction: column;
            }

            .form-actions .btn-primary,
            .form-actions .btn-secondary {
                width: 100%;
            }
        }

        /* ── Uploader card ── */
        .uploader-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: rgba(255, 255, 255, .03);
            border: 1px solid var(--border);
        }

        .uploader-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            object-fit: cover;
            border: 2px solid var(--accent-border);
        }

        .uploader-avatar-fallback {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(135deg, var(--accent) 0%, #c2410c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syne', sans-serif;
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            border: 2px solid var(--accent-border);
            text-transform: uppercase;
        }

        .uploader-info {
            min-width: 0;
            flex: 1;
        }

        .uploader-role-badge {
            display: inline-block;
            font-size: 8px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .14em;
            padding: 2px 7px;
            border-radius: 20px;
            background: var(--accent-dim);
            border: 1px solid var(--accent-border);
            color: var(--accent);
            margin-bottom: 3px;
        }

        .uploader-name {
            font-size: 13px;
            font-weight: 700;
            color: #e2e6ef;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .uploader-label {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .14em;
            color: #555e6e;
            margin-bottom: 2px;
        }

        /* ── Divider ── */
        .divider {
            height: 1px;
            background: var(--border);
            margin: 28px 0;
        }

        @media (max-width: 599px) {
            .divider {
                margin: 18px 0;
            }
        }

        /* ── Scroll form area ── */
        @media (min-width: 900px) {
            .form-panel {
                overflow-y: auto;
                max-height: calc(100vh - 56px);
            }
        }
    </style>
</head>

<body>
    <div class="page-wrap">

        <!-- Top navigation -->
        <nav class="top-nav">
            <a href="../index.php" class="nav-brand">MEeL<span>Admin</span></a>
            <div class="nav-sep"></div>
            <a href="index.php" class="nav-crumb">Dashboard</a>
            <span class="nav-chevron">›</span>
            <a href="../music/index.php" class="nav-crumb">Musik</a>
            <span class="nav-chevron">›</span>
            <span class="nav-crumb-current">Edit</span>
            <span class="id-chip">#<?= $id ?></span>
        </nav>

        <!-- Main edit layout -->
        <div class="edit-layout">

            <!-- ── LEFT: Info sidebar ── -->
            <aside class="sidebar-panel">
                <div class="cover-wrap">
                    <div class="vinyl-ring"></div>
                    <img src="<?= $thumb_src ?>"
                        alt="Cover <?= htmlspecialchars($music['title']) ?>"
                        class="cover-img"
                        id="cover-preview">
                    <span class="cover-badge">Cover Art</span>
                </div>

                <!-- Uploader card -->
                <div class="uploader-card">
                    <?php if (!empty($music['uploader_pfp'])): ?>
                        <img src="../profile/upload/<?= htmlspecialchars($music['uploader_pfp']) ?>"
                            alt="<?= htmlspecialchars($music['uploader'] ?? '') ?>"
                            class="uploader-avatar">
                    <?php else: ?>
                        <div class="uploader-avatar-fallback">
                            <?= strtoupper(substr($music['uploader'] ?? 'U', 0, 1)) ?>
                        </div>
                    <?php endif; ?>
                    <div class="uploader-info">
                        <div class="uploader-label">Diunggah oleh</div>
                        <div class="uploader-name">@<?= htmlspecialchars($music['uploader'] ?? '—') ?></div>
                    </div>
                    <div class="uploader-role-badge">Uploader</div>
                </div>

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
                            <div class="meta-label">Tanggal Upload</div>
                            <div class="meta-value"><?= !empty($music['upload_date']) ? date('d M Y', strtotime($music['upload_date'])) : '—' ?></div>
                        </div>
                    </div>
                </div>

                <div class="stats-strip">
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($music['views'] ?? 0) ?></div>
                        <div class="stat-label">Views</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number"><?= number_format($music['likes'] ?? 0) ?></div>
                        <div class="stat-label">Likes</div>
                    </div>
                    <div class="stat-chip">
                        <div class="stat-number" style="color:#f87171;"><?= number_format($music['dislikes'] ?? 0) ?></div>
                        <div class="stat-label">Dislikes</div>
                    </div>
                </div>

                <div style="display:flex;flex-direction:column;gap:8px;margin-top:auto">
                    <a href="../music/watch.php?id=<?= $id ?>" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="arrow-left" style="width:13px;height:13px;"></i> Lihat Musik
                    </a>
                    <a href="index.php" class="btn-secondary" style="justify-content:center;">
                        <i data-lucide="layout-dashboard" style="width:13px;height:13px;"></i> Dashboard
                    </a>
                </div>
            </aside>

            <!-- ── RIGHT: Form panel ── -->
            <section class="form-panel">
                <div class="form-header">
                    <div>
                        <h1 class="form-title">Edit <span>Musik</span></h1>
                        <p class="form-subtitle">Ubah keterangan &amp; detail lagu</p>
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

                <form method="POST" enctype="multipart/form-data" onsubmit="handleSubmit()" style="display:flex;flex-direction:column;gap:20px;flex:1;">
                    <?php if (isset($_SESSION['csrf_token'])): ?>
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token']; ?>">
                    <?php endif; ?>

                    <!-- Judul -->
                    <div class="field-group">
                        <label class="field-label" for="f-title">Judul Lagu</label>
                        <input type="text" id="f-title" name="title" placeholder="Masukkan judul lagu..."
                            required class="field-input"
                            value="<?= htmlspecialchars($music['title']) ?>"
                            oninput="document.getElementById('sidebar-title').textContent = this.value || '—'">
                    </div>

                    <!-- Artis & Album -->
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

                    <!-- Deskripsi -->
                    <div class="field-group">
                        <label class="field-label" for="f-desc">Deskripsi / Keterangan</label>
                        <textarea id="f-desc" name="description" placeholder="Masukkan deskripsi musik..."
                            class="field-input"><?= htmlspecialchars($music['description'] ?? '') ?></textarea>
                    </div>

                    <div class="divider" style="margin:4px 0;"></div>

                    <!-- Cover thumbnail upload -->
                    <div class="field-group">
                        <label class="field-label">Ganti Cover Thumbnail</label>
                        <div class="upload-zone" id="upload-zone">
                            <input type="file" name="thumbnail" accept="image/*" onchange="previewCover(this)">
                            <div id="upload-zone-content">
                                <i data-lucide="image-plus" style="width:22px;height:22px;color:#3a424f;display:block;margin:0 auto 8px;"></i>
                                <div style="font-size:12px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.1em;">Klik untuk unggah gambar</div>
                                <div style="font-size:10px;color:#353d4a;margin-top:4px;">JPG, PNG, WEBP — maks. 5 MB</div>
                            </div>
                        </div>
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
                text: 'Detail musik telah diperbarui.',
                icon: 'success',
                confirmButtonColor: '#f97316',
                background: '#0e1118',
                color: '#fff'
            });
        <?php endif; ?>

        function handleSubmit() {
            const btn = document.getElementById('btn-save');
            btn.innerHTML = '<div style="width:16px;height:16px;border:2px solid rgba(0,0,0,.3);border-top-color:#000;border-radius:50%;animation:spin2 .7s linear infinite;"></div> Menyimpan...';
            btn.style.opacity = '.6';
            btn.style.pointerEvents = 'none';
        }

        function previewCover(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('cover-preview').src = e.target.result;
                    const zone = document.getElementById('upload-zone-content');
                    zone.innerHTML = '<div style="font-size:12px;font-weight:700;color:#f97316;text-transform:uppercase;letter-spacing:.1em;">✓ ' + input.files[0].name + '</div>';
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Inject keyframe for spin
        const style = document.createElement('style');
        style.textContent = '@keyframes spin2 { to { transform: rotate(360deg); } }';
        document.head.appendChild(style);
    </script>
</body>

</html>