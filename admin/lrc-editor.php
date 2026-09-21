<?php
require_once __DIR__ . '/../modules/core/helpers.php';
meel_boot_session();
include __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../modules/media/MediaAdminRepository.php';

$_LRC_CONTEXT = $_LRC_CONTEXT ?? 'admin';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . base_url('/auth/login'));
    exit;
}

$user_id   = $_SESSION['user_id'];
$is_admin  = is_admin($conn);
$edit_id   = (int)($_GET['id'] ?? 0);

if ($edit_id <= 0) {
    header('Location: ' . base_url('/music/beranda'));
    exit;
}

if ($_LRC_CONTEXT === 'admin' && !$is_admin) {
    header('Location: ' . base_url('/profile/lrc-editor?id=' . $edit_id));
    exit;
} elseif ($_LRC_CONTEXT === 'owner' && $is_admin) {
    header('Location: ' . base_url('/admin/lrc-editor?id=' . $edit_id));
    exit;
}

$adminMedia = new MediaAdminRepository($conn);
$music = $adminMedia->getMedia('music', $edit_id);

if (!$music) {
    header('Location: ' . base_url('/err/?code=not_found'));
    exit;
}

$is_owner = ((int)$music['user_id'] === (int)$user_id);
if (!$is_admin && !$is_owner) {
    header('Location: ' . base_url('/err/?code=denied'));
    exit;
}

$save_status = "";
$error_msg   = "";

if (isset($_POST['save_lyrics'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error_msg = "CSRF token tidak valid.";
    } else {
        $lang   = 'id';
        $title  = trim($_POST['song_title'] ?? $music['title']);
        $artist = trim($_POST['song_artist'] ?? $music['artist'] ?? '');
        $content = trim($_POST['lyrics_content'] ?? '');

        if ($content !== '') {
            $lines_raw = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
            $lrc_lines = [];
            foreach ($lines_raw as $rl) {
                $rl = trim($rl);
                if ($rl === '') continue;
                if (preg_match('/^\[(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?\]\s*(.*)$/', $rl, $m)) {
                    $t = (int)$m[1] * 60 + (int)$m[2];
                    if (isset($m[3]) && $m[3] !== '') {
                        $t += intval(str_pad($m[3], 2, '0', STR_PAD_RIGHT)) / 100.0;
                    }
                    $lrc_lines[] = ['time' => $t, 'text' => trim($m[4])];
                } else {
                    $lrc_lines[] = ['time' => 0, 'text' => $rl];
                }
            }
            if (!empty($lrc_lines)) {
                $lrc_content = generate_lrc($lrc_lines, $title, $artist);
                save_music_lyrics($edit_id, $lang, $lrc_content);
                $save_status = "Lirik berhasil disimpan!";
            }
        }
    }
}

$current_content = '';
$current_lines = [];

$lrc_path = get_music_lyrics_path($edit_id, 'id');
if (is_file($lrc_path)) {
    $raw = @file_get_contents($lrc_path);
    if ($raw !== false) {
        $current_lines = parse_lrc($raw);
        $lines_display = [];
        foreach ($current_lines as $cl) {
            if (($cl['time'] ?? 0) > 0) {
                $m = (int)floor($cl['time'] / 60);
                $s = (int)floor($cl['time'] % 60);
                $ms = (int)round(($cl['time'] - floor($cl['time'])) * 100);
                $lines_display[] = sprintf('[%02d:%02d.%02d] %s', $m, $s, $ms, $cl['text']);
            } else {
                $lines_display[] = $cl['text'];
            }
        }
        $current_content = implode("\n", $lines_display);
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LRC Editor — <?= htmlspecialchars($music['title']) ?> — MEeL Music</title>
    <?php include __DIR__ . '/../partials/link.php'; ?>
    <link rel="stylesheet" href="../assets/css/shared/design-tokens.css<?= meel_asset_version('assets/css/shared/design-tokens.css') ?>">
    <link rel="stylesheet" href="../assets/css/shared/upload-form.css<?= meel_asset_version('assets/css/shared/upload-form.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/shared/utility.css<?= meel_asset_version('assets/css/admin/shared/utility.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/shared/main.css<?= meel_asset_version('assets/css/admin/edit/shared/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/admin/edit/music/main.css<?= meel_asset_version('assets/css/admin/edit/music/main.css') ?>">
    <link rel="stylesheet" href="../assets/css/music/lrc-editor.css<?= meel_asset_version('assets/css/music/lrc-editor.css') ?>">
</head>
<body class="theme-music">
    <div class="page-wrap">
            <nav class="top-nav">
                <a href="../" class="nav-brand">MEeL<span>Music</span></a>
                <div class="nav-sep"></div>
                <a href="beranda" class="nav-crumb">Library</a>
                <span class="nav-chevron">›</span>
                <a href="<?= base_url('/music/watch?v=' . (int)$edit_id) ?>" class="nav-crumb"><?= htmlspecialchars(mb_strimwidth($music['title'], 0, 30, '...')) ?></a>
                <span class="nav-chevron">›</span>
                <a href="<?= base_url('/' . ($_LRC_CONTEXT === 'admin' ? 'admin' : 'profile') . '/edit-music?id=' . (int)$edit_id) ?>" class="nav-crumb">Edit Music</a>
                <span class="nav-chevron">›</span>
                <span class="nav-crumb-current">LRC Editor</span>
                <?php if ($is_admin): ?>
                    <span class="admin-badge"><i data-lucide="shield" style="width:10px;height:10px;"></i> Admin</span>
                <?php endif; ?>
            </nav>

        <main style="height:calc(100vh - 56px);display:flex;flex-direction:column;overflow:hidden;">
            <?php if ($save_status): ?>
                <div id="save-status-alert" class="editor-alert-success" style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;text-align:center;flex-shrink:0;transition:opacity 0.5s, max-height 0.5s;max-height:60px;overflow:hidden;">
                    ✓ <?= htmlspecialchars($save_status) ?>
                </div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div id="save-status-alert" class="editor-alert-error" style="padding:10px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;text-align:center;flex-shrink:0;transition:opacity 0.5s, max-height 0.5s;max-height:60px;overflow:hidden;">
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>

            <div class="editor-top-bar">
                <div class="editor-top-left">
                    <a href="<?= base_url('/' . ($_LRC_CONTEXT === 'admin' ? 'admin' : 'profile') . '/edit-music?id=' . (int)$edit_id) ?>" class="editor-back-btn" title="Kembali">
                        <i data-lucide="x" style="width:18px;height:18px;"></i>
                    </a>
                    <div class="editor-mode-tabs">
                        <button type="button" class="editor-mode-tab active" data-mode="simple" onclick="switchMode('simple')">Sederhana</button>
                        <button type="button" class="editor-mode-tab" data-mode="synced" onclick="switchMode('synced')">Disinkronkan</button>
                    </div>
                </div>
                <div class="editor-top-right">
                    <button type="button" class="editor-save-btn" onclick="document.getElementById('editor-form').requestSubmit()">
                        <i data-lucide="check" style="width:16px;height:16px;"></i>
                    </button>
                </div>
            </div>

            <form id="editor-form" method="POST" style="display:flex;flex-direction:column;flex:1;overflow:hidden;" onsubmit="return beforeSubmit()">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="save_lyrics" value="1">
                <input type="hidden" name="song_title" value="<?= htmlspecialchars($music['title']) ?>">
                <input type="hidden" name="song_artist" value="<?= htmlspecialchars($music['artist'] ?? '') ?>">

                <div id="mode-simple" class="editor-mode-panel" style="flex:1;display:flex;flex-direction:column;overflow:hidden;">
                    <div style="flex:1;display:flex;flex-direction:column;overflow:hidden;padding:16px;">
                        <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:#455060;margin-bottom:8px;">Lirik Tertanam</label>
                        <textarea name="lyrics_content" id="lyrics-textarea" class="editor-textarea"
                            placeholder="Paste atau ketik lirik di sini...&#10;&#10;Format: satu baris per lirik.&#10;Bisa langsung paste dari website lirik."
                            style="flex:1;border-radius:12px;padding:16px;font-size:14px;line-height:1.8;resize:none;font-family:inherit;"><?= htmlspecialchars($current_content) ?></textarea>
                    </div>
                </div>

                <div id="mode-synced" class="editor-mode-panel" style="flex:1;display:none;flex-direction:column;overflow:hidden;">
                    <div id="synced-lines-container" style="flex:1;overflow-y:auto;padding:12px 16px;">
                    </div>
                    <div style="padding:12px 16px;border-top:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.2);flex-shrink:0;">
                        <button type="button" class="editor-add-line-btn" onclick="addSyncedLine()">
                            <i data-lucide="plus" style="width:14px;height:14px;"></i> Tambah Baris
                        </button>
                    </div>
                </div>
            </form>

            <div id="editor-player-bar" class="editor-player-bar" style="display:none;">
                <div class="editor-seekbar-wrap">
                    <input type="range" id="editor-seekbar" min="0" max="1000" value="0" step="1"
                        oninput="editorSeek(this.value)" style="width:100%;">
                </div>
                <div class="editor-player-controls">
                    <button type="button" class="editor-ctrl-btn" onclick="editorSkipBack()" title="-5 detik">
                        <i data-lucide="skip-back" style="width:16px;height:16px;"></i>
                    </button>
                    <button type="button" class="editor-ctrl-btn editor-play-btn" onclick="editorPlayPause()" id="editor-play-btn" title="Play / Pause">
                        <i data-lucide="play" style="width:20px;height:20px;"></i>
                    </button>
                    <button type="button" class="editor-ctrl-btn" onclick="editorSkipForward()" title="+5 detik">
                        <i data-lucide="skip-forward" style="width:16px;height:16px;"></i>
                    </button>
                    <div class="editor-time-display">
                        <i data-lucide="clock" style="width:12px;height:12px;color:#f97316;"></i>
                        <span id="editor-current-time">0:00.00</span>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <?php include __DIR__ . '/../partials/footer.php'; ?>
    <script src="../assets/js/compatibilitas/plyr.min.js"></script>
    <script src="../assets/js/shared/htmx-lucide.js<?= meel_asset_version('assets/js/shared/htmx-lucide.js') ?>"></script>
    <script src="../assets/js/music/lrc-editor.js<?= meel_asset_version('assets/js/music/lrc-editor.js') ?>"></script>
    <script>
        var MUSIC_ID = <?= (int)$edit_id ?>;
        var MUSIC_FILENAME = <?= json_encode($music['filename']) ?>;
        var STREAM_URL = '<?= base_url('/music/stream?id=' . (int)$edit_id) ?>';
        var EXISTING_LINES = <?= json_encode($current_lines) ?>;

        (function () {
            var alert = document.getElementById("save-status-alert");
            if (!alert) return;
            setTimeout(function () {
                alert.style.opacity = "0";
                alert.style.maxHeight = "0";
                alert.style.padding = "0 16px";
                alert.style.margin = "0";
                setTimeout(function () { alert.remove(); }, 500);
            }, 5000);
        })();
    </script>
</body>
</html>

<!-- reference build: MEeL-C8H11NO2 [4426c93e9aeb052f] -->
