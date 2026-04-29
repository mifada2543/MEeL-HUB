<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../auth/auth.php';
require '../auth/config.php';
require '../helpers.php';

$back_url = '../index.php';
if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];
    if (parse_url($ref, PHP_URL_HOST) === $host) {
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['index.php'];
        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }
        if (!$should_exclude) {
            $back_url = $ref;
        }
    }
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$username = $_SESSION['username'] ?? 'unknown';

if ($user_role !== 'admin' && $user_role !== 'member') {
    die(include '../err/denied.php');
}

$current_scope = isset($_GET['scope']) && $_GET['scope'] === 'private' ? 'private' : 'public';

if ($current_scope === 'private') {
    if ($user_role !== 'admin' && $user_role !== 'member') {
        $current_scope = 'public';
    }
}

function get_drive_files($folder_name, $scope, $username)
{
    if ($scope === 'private') {
        $dir = "../data_drive/private_admins/" . $username . "/" . $folder_name;
    } else {
        $dir = "../data_drive/public/" . $folder_name;
    }

    $files = [];
    if (!is_dir($dir)) mkdir($dir, 0777, true);

    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item !== '.' && $item !== '..') {
            $path = $dir . '/' . $item;
            if (is_file($path)) {
                $files[] = [
                    'name' => $item,
                    'size' => filesize($path),
                    'time' => filemtime($path),
                    'path' => $path
                ];
            }
        }
    }
    usort($files, function ($a, $b) {
        return $b['time'] - $a['time'];
    });
    return $files;
}

$videos   = get_drive_files('video',   $current_scope, $username);
$audios   = get_drive_files('audio',   $current_scope, $username);
$dokumens = get_drive_files('dokumen', $current_scope, $username);
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Drive</title>
    <link rel="icon" href="../assets/MEeL.png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <link rel="stylesheet" href="../assets/css/drive.css">
</head>

<body>

    <!-- ═══════════════ SIDEBAR ═══════════════ -->
    <aside id="sidebar">
        <!-- Logo -->
        <div style="padding:20px 20px 16px; border-bottom:1px solid var(--border);">
            <a href="<?= htmlspecialchars($back_url) ?>" style="display:flex;align-items:center;gap:12px;text-decoration:none;">
                <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <img src="../assets/MEeL.png" alt="Kembali">
                </div>
                <div>
                    <div class="syne" style="color:#fff;font-size:18px;font-weight:800;letter-spacing:-.02em;line-height:1;">MEeL <span style="color:var(--accent-b);">Cloud</span></div>
                    <div style="font-size:9px;color:var(--text-muted);letter-spacing:.12em;text-transform:uppercase;margin-top:1px;">Pusat Penyimpanan</div>
                </div>
            </a>
        </div>

        <!-- Scope -->
        <div style="padding:16px 16px 8px;">
            <div style="font-size:9px;color:var(--text-muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;padding-left:4px;">Storage Scope</div>
            <a href="?scope=public" class="nav-item <?= $current_scope === 'public' ? 'active' : '' ?>" style="margin-bottom:4px;">
                <i data-lucide="globe" style="width:16px;height:16px;color:var(--accent-b);flex-shrink:0;"></i>
                Public Space
            </a>
            <?php if ($user_role === 'admin' || $user_role === 'member'): ?>
                <a href="?scope=private" class="nav-item <?= $current_scope === 'private' ? 'active-purple' : '' ?>">
                    <i data-lucide="shield-check" style="width:16px;height:16px;color:var(--accent-p);flex-shrink:0;"></i>
                    Private Cloud
                </a>
            <?php endif; ?>
        </div>

        <!-- Nav sections (desktop) -->
        <div style="padding:8px 16px;border-top:1px solid var(--border);margin-top:8px;">
            <div style="font-size:9px;color:var(--text-muted);letter-spacing:.15em;text-transform:uppercase;margin-bottom:8px;padding-left:4px;">Kategori</div>
            <button onclick="showSection('video',this)" class="nav-btn-desktop nav-item active" style="width:100%;text-align:left;margin-bottom:4px;">
                <i data-lucide="play-square" style="width:16px;height:16px;color:var(--accent-r);flex-shrink:0;"></i> Video
            </button>
            <button onclick="showSection('audio',this)" class="nav-btn-desktop nav-item" style="width:100%;text-align:left;margin-bottom:4px;">
                <i data-lucide="music" style="width:16px;height:16px;color:var(--accent-o);flex-shrink:0;"></i> Music
            </button>
            <button onclick="showSection('dokumen',this)" class="nav-btn-desktop nav-item" style="width:100%;text-align:left;">
                <i data-lucide="file-text" style="width:16px;height:16px;color:var(--accent-g);flex-shrink:0;"></i> Dokumen
            </button>
        </div>
    </aside>

    <!-- ═══════════════ OVERLAY (mobile) ═══════════════ -->
    <div id="overlay" onclick="closeSidebar()"></div>

    <!-- ═══════════════ MAIN ═══════════════ -->
    <div id="main-content">

        <!-- Topbar (mobile only) -->
        <div id="topbar" style="position:sticky;top:0;z-index:20;background:rgba(7,9,15,.9);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:12px 16px;display:flex;align-items:center;gap:12px;">
            <button onclick="openSidebar()" style="background:var(--bg-glass);border:1px solid var(--border);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#e5e7eb;">
                <i data-lucide="menu" style="width:18px;height:18px;"></i>
            </button>
            <div style="display:flex;align-items:center;gap:8px;flex:1;">
                <!-- User info -->
                <div style="width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#3b82f6,#8b5cf6);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:13px;font-weight:700;color:#fff;text-transform:uppercase;">
                    <?= mb_substr($username, 0, 1) ?>
                </div>
                <div style="min-width:0;">
                    <div style="font-size:12px;font-weight:700;color:#e5e7eb;truncate;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars($username) ?></div>
                    <div style="font-size:9px;color:var(--text-muted);letter-spacing:.06em;text-transform:uppercase;"><?= htmlspecialchars($user_role) ?></div>
                </div>
            </div>
            <span class="badge" style="background:<?= $current_scope === 'private' ? 'rgba(139,92,246,.15)' : 'rgba(59,130,246,.15)' ?>;color:<?= $current_scope === 'private' ? 'var(--accent-p)' : 'var(--accent-b)' ?>;">
                <?= $current_scope === 'private' ? 'Private' : 'Public' ?>
            </span>
        </div>

        <div style="padding:24px 28px;max-width:1200px;margin:0 auto;">

            <!-- ── Upload Panel ── -->
            <?php if ($user_role === 'admin' || $user_role === 'member'): ?>
                <div class="glass-panel" style="padding:20px;margin-bottom:20px;<?= $current_scope === 'private' ? 'border-color:rgba(139,92,246,.2)' : 'border-color:rgba(59,130,246,.2)' ?>;">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
                        <div>
                            <div class="syne" style="color:#fff;font-size:18px;font-weight:800;">Upload File</div>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Tambah file ke <?= $current_scope === 'private' ? 'Cloud Pribadi' : 'Public Space' ?></div>
                        </div>
                        <span class="badge" style="background:<?= $current_scope === 'private' ? 'rgba(139,92,246,.15)' : 'rgba(59,130,246,.15)' ?>;color:<?= $current_scope === 'private' ? 'var(--accent-p)' : 'var(--accent-b)' ?>;">
                            <i data-lucide="<?= $current_scope === 'private' ? 'shield-check' : 'globe' ?>" style="width:10px;height:10px;"></i>
                            <?= ucfirst($current_scope) ?>
                        </span>
                    </div>

                    <form action="upload.php" method="POST" enctype="multipart/form-data">
                        <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;" class="upload-grid">

                            <div>
                                <label style="display:block;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px;">Lokasi</label>
                                <select name="scope" style="width:100%;background:var(--bg-glass);border:1px solid var(--border);color:#e5e7eb;font-size:12px;font-family:inherit;border-radius:10px;padding:10px 14px;outline:none;appearance:none;cursor:pointer;">
                                    <option value="private" <?= $current_scope === 'private' ? 'selected' : '' ?>>☁ Cloud Pribadi Saya</option>
                                    <?php if ($user_role === 'admin'): ?>
                                        <option value="public" <?= $current_scope === 'public' ? 'selected' : '' ?>>🌐 Public Space</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div>
                                <label style="display:block;font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:.12em;text-transform:uppercase;margin-bottom:6px;">Pilih Berkas</label>
                                <div class="upload-zone">
                                    <input type="file" name="file_drive" required id="fileInput" style="display:none;" onchange="updateFileName(this)">
                                    <label for="fileInput" style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <i data-lucide="paperclip" style="width:14px;height:14px;color:var(--accent-b);flex-shrink:0;"></i>
                                        <span id="fileLabel" style="font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Klik untuk pilih file...</span>
                                    </label>
                                </div>
                            </div>

                            <button type="submit" name="submit_upload" style="display:flex;align-items:center;justify-content:center;gap:8px;background:var(--accent-b);color:#fff;border:none;border-radius:10px;padding:10px 22px;font-size:12px;font-weight:700;font-family:inherit;letter-spacing:.05em;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;" onmouseover="this.style.background='#2563eb'" onmouseout="this.style.background='var(--accent-b)'">
                                <i data-lucide="upload-cloud" style="width:14px;height:14px;"></i>
                                Upload
                            </button>
                        </div>
                    </form>
                </div>

                <style>
                    @media(max-width:600px) {
                        .upload-grid {
                            grid-template-columns: 1fr !important;
                        }
                    }
                </style>
            <?php endif; ?>

            <!-- ── Quota Alert ── -->
            <?php if (isset($_GET['status']) && $_GET['status'] === 'quota_full'): ?>
                <div style="display:flex;align-items:center;gap:12px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);border-radius:12px;padding:14px 16px;margin-bottom:16px;">
                    <i data-lucide="alert-triangle" style="width:18px;height:18px;color:var(--accent-r);flex-shrink:0;"></i>
                    <span style="font-size:12px;font-weight:600;color:#fca5a5;">Penyimpanan penuh (Limit 20 GB). Hapus file lama untuk melanjutkan upload.</span>
                </div>
            <?php endif; ?>

            <!-- ── Storage Bar ── -->
            <?php if ($user_role === 'member'):
                $usage = get_user_usage($username);
                $limit = 20 * 1024 * 1024 * 1024;
                $perc  = min(100, ($usage / $limit) * 100);
            ?>
                <div class="glass-panel" style="padding:14px 18px;margin-bottom:20px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:7px;">
                            <i data-lucide="hard-drive" style="width:14px;height:14px;color:<?= $perc > 80 ? 'var(--accent-r)' : 'var(--accent-b)' ?>;"></i>
                            <span style="font-size:11px;font-weight:700;color:#9ca3af;text-transform:uppercase;letter-spacing:.07em;">Penyimpanan Pribadi</span>
                        </div>
                        <span style="font-size:11px;font-weight:700;color:<?= $perc > 80 ? 'var(--accent-r)' : 'var(--accent-b)' ?>;"><?= format_bytes($usage) ?> / 20 GB</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-fill" style="width:<?= $perc ?>%;background:<?= $perc > 80 ? 'var(--accent-r)' : 'linear-gradient(90deg,#3b82f6,#8b5cf6)' ?>;"></div>
                    </div>
                    <div style="font-size:9px;color:var(--text-muted);margin-top:5px;text-align:right;"><?= round($perc, 1) ?>% terpakai</div>
                </div>
            <?php endif; ?>

            <!-- ── Section Header ── -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:18px;">
                <div id="sectionHeading" class="syne page-title" style="color:#fff;font-size:28px;font-weight:800;">
                    Drive <span id="sectionAccent" style="color:var(--accent-r);">Video</span>
                </div>
                <div id="fileCount" style="font-size:11px;color:var(--text-muted);font-weight:600;"></div>
            </div>

            <!-- ── File Sections ── -->
            <?php
            function render_file_grid($files, $accent_color, $lucide_icon, $folder_type, $current_scope)
            {
                if (empty($files)) {
                    echo '<div style="padding:48px 20px;text-align:center;border:1.5px dashed rgba(255,255,255,.08);border-radius:16px;">';
                    echo '  <div style="width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.04);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">';
                    echo '    <i data-lucide="' . $lucide_icon . '" style="width:22px;height:22px;color:rgba(255,255,255,.2);"></i>';
                    echo '  </div>';
                    echo '  <p style="font-size:13px;font-weight:600;color:rgba(255,255,255,.25);margin:0;">Folder masih kosong</p>';
                    echo '</div>';
                    return;
                }

                echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;">';
                foreach ($files as $f) {
                    $preview_path = $f['path'];
                    $file_name    = htmlspecialchars($f['name']);
                    $download_url = "download.php?file=" . urlencode($f['name']) . "&type=" . $folder_type . "&scope=" . $current_scope;

                    // Extension badge color
                    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    $ext_color = match (true) {
                        in_array($ext, ['mp4', 'mkv', 'mov', 'avi']) => '#ef4444',
                        in_array($ext, ['mp3', 'flac', 'wav', 'ogg']) => '#f97316',
                        in_array($ext, ['pdf']) => '#f43f5e',
                        in_array($ext, ['doc', 'docx']) => '#3b82f6',
                        in_array($ext, ['xls', 'xlsx']) => '#10b981',
                        in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']) => '#8b5cf6',
                        default => '#6b7280'
                    };

                    echo '<div class="file-card fade-up">';
                    echo '  <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">';
                    echo '    <div style="display:flex;align-items:center;gap:10px;min-width:0;">';
                    echo '      <div style="width:38px;height:38px;border-radius:10px;background:rgba(' . hex2rgb_str($accent_color) . ',.1);display:flex;align-items:center;justify-content:center;flex-shrink:0;">';
                    echo '        <i data-lucide="' . $lucide_icon . '" style="width:18px;height:18px;color:' . $accent_color . ';"></i>';
                    echo '      </div>';
                    echo '      <div style="min-width:0;">';
                    echo '        <div style="font-size:12px;font-weight:700;color:#e5e7eb;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;" title="' . $file_name . '">' . $file_name . '</div>';
                    echo '        <div style="display:flex;align-items:center;gap:6px;margin-top:3px;">';
                    echo '          <span style="font-size:9px;font-weight:700;background:' . $ext_color . '22;color:' . $ext_color . ';padding:1px 6px;border-radius:4px;text-transform:uppercase;">' . $ext . '</span>';
                    echo '          <span style="font-size:9px;color:#4b5563;font-family:monospace;">' . format_bytes($f['size']) . '</span>';
                    echo '        </div>';
                    echo '      </div>';
                    echo '    </div>';
                    echo '    <div style="display:flex;gap:2px;flex-shrink:0;">';
                    echo '      <button onclick="openPreview(\'' . urlencode($preview_path) . '\', \'' . $folder_type . '\', \'' . urlencode($f['name']) . '\')" class="icon-btn cyan" title="Preview"><i data-lucide="eye" style="width:14px;height:14px;"></i></button>';
                    echo '      <a href="' . $download_url . '" class="icon-btn blue" title="Download"><i data-lucide="download" style="width:14px;height:14px;"></i></a>';
                    if ($GLOBALS['user_role'] === 'admin' || $current_scope === 'private') {
                        $delete_url = "delete.php?file=" . urlencode($f['name']) . "&type=" . $folder_type . "&scope=" . $current_scope;
                        echo '      <a href="' . $delete_url . '" onclick="return confirm(\'Hapus file ini?\')" class="icon-btn red" title="Hapus"><i data-lucide="trash-2" style="width:14px;height:14px;"></i></a>';
                    }
                    echo '    </div>';
                    echo '  </div>';
                    echo '  <div style="border-top:1px solid var(--border);padding-top:10px;display:flex;justify-content:space-between;align-items:center;">';
                    echo '    <span style="font-size:9px;color:#374151;text-transform:uppercase;letter-spacing:.06em;">' . date('d M Y · H:i', $f['time']) . '</span>';
                    echo '    <i data-lucide="clock" style="width:10px;height:10px;color:#374151;"></i>';
                    echo '  </div>';
                    echo '</div>';
                }
                echo '</div>';
            }

            function hex2rgb_str($hex)
            {
                $hex = ltrim($hex, '#');
                if (strlen($hex) == 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
                return hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
            }
            ?>

            <div id="drive-video" class="drive-section">
                <?php render_file_grid($videos, '#ef4444', 'play-square', 'video', $current_scope); ?>
            </div>

            <div id="drive-audio" class="drive-section" style="display:none;">
                <?php render_file_grid($audios, '#f97316', 'music', 'audio', $current_scope); ?>
            </div>

            <div id="drive-dokumen" class="drive-section" style="display:none;">
                <?php render_file_grid($dokumens, '#10b981', 'file-text', 'dokumen', $current_scope); ?>
            </div>

        </div><!-- /inner -->
    </div><!-- /main-content -->

    <!-- ═══════════════ PREVIEW MODAL ═══════════════ -->
    <div id="previewModal" style="display:none;position:fixed;inset:0;z-index:100;background:rgba(0,0,0,.85);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:16px;">
        <div style="position:relative;max-width:900px;width:100%;background:var(--bg-card);border-radius:20px;overflow:hidden;border:1px solid var(--border);box-shadow:0 40px 120px rgba(0,0,0,.8);">
            <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);background:rgba(255,255,255,.02);">
                <div style="display:flex;align-items:center;gap:8px;min-width:0;">
                    <i data-lucide="file" style="width:14px;height:14px;color:var(--text-muted);flex-shrink:0;"></i>
                    <h3 id="previewTitle" style="font-size:13px;font-weight:600;color:#d1d5db;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Nama File</h3>
                </div>
                <button onclick="closePreview()" style="background:rgba(255,255,255,.06);border:1px solid var(--border);border-radius:8px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#9ca3af;transition:all .15s;flex-shrink:0;" onmouseover="this.style.background='rgba(239,68,68,.15)';this.style.color='#ef4444'" onmouseout="this.style.background='rgba(255,255,255,.06)';this.style.color='#9ca3af'">
                    <i data-lucide="x" style="width:16px;height:16px;"></i>
                </button>
            </div>
            <div id="previewContent" style="display:flex;align-items:center;justify-content:center;min-height:280px;max-height:78vh;background:var(--bg-base);overflow:auto;"></div>
        </div>
    </div>

    <script>
        <?php include '../assets/script/drive.php'; ?>
    </script>
</body>

</html>