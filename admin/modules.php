<?php
include '../auth/config.php';
include '../auth/auth.php';
include_once '../modules/core/helpers.php';
include_once '../modules/core/activity_logger.php';

require_admin($conn);

define('MEEL_ADMIN_CONTEXT', true);

include '../controllers/admin/admin_actions.php';

require_once __DIR__ . '/../modules/core/helpers/settings.php';
require_once __DIR__ . '/../modules/core/Modules.php';

// Daftar modul opsional yang dapat di-toggle admin (key = Modules::OPTIONAL).
$modules = [
    [
        'key'     => 'arcade',
        'label'   => 'MEeL Arcade',
        'desc'    => 'Koleksi 9 mini-game (Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout, Simon Says, Ludo, MEeL!Mania). Nonaktifkan untuk menyembunyikan arcade dari seluruh platform tanpa menghapus file.',
        'setting' => 'modules_arcade',
        'home'    => 'arcade/beranda',
        'color'   => '#ec4899',
    ],
];

$msg = $_GET['msg'] ?? null;
?>
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modules | MEeL Admin</title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/admin/manifest.php' as $__f): ?>
        <link href="../assets/css/admin/<?= $__f ?>" rel="stylesheet">
    <?php endforeach; ?>
</head>
<body class="bg-[#0b0e14] min-h-screen">
    <?php
    $is_admin = true;
    $page_title = 'Modules';
    $media_type = 'dashboard';
    $back_url = 'index.php';
    include 'header-admin.php';
    ?>
    <div style="max-width:640px;margin:0 auto;padding:32px 16px;">

        <div style="display:flex;align-items:center;gap:16px;margin-bottom:32px;">
            <div style="width:48px;height:48px;border-radius:16px;background:rgba(59,130,246,0.15);border:1px solid rgba(59,130,246,0.25);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            </div>
            <div>
                <h1 style="font-size:22px;font-weight:800;color:#fff;line-height:1.2;margin:0;">Modules</h1>
                <p style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:#6b7280;margin-top:4px;">Modul Opsional Platform</p>
            </div>
        </div>

        <?php if ($msg): ?>
            <div style="margin-bottom:24px;padding:12px 16px;border-radius:12px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.2);color:#4ade80;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;">
                <?= htmlspecialchars(str_replace('_', ' ', $msg)) ?>
            </div>
        <?php endif; ?>

        <div style="display:flex;flex-direction:column;gap:16px;">
            <?php foreach ($modules as $mod): ?>
                <?php
                $exists  = Modules::exists($mod['key']);
                $enabled = Modules::enabled($mod['key']);
                $toggle  = get_site_setting($conn, $mod['setting'], '1') !== '0';
                ?>
                <div class="glass" style="border-radius:20px;padding:20px;border:1px solid rgba(255,255,255,0.06);">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
                        <div style="min-width:0;">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                <span style="font-size:14px;font-weight:800;color:#fff;"><?= htmlspecialchars($mod['label']) ?></span>
                                <?php if (!$exists): ?>
                                    <span style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;padding:3px 8px;border-radius:999px;background:rgba(107,114,128,0.15);border:1px solid rgba(107,114,128,0.3);color:#9ca3af;">Tidak Terpasang</span>
                                <?php elseif (!$enabled): ?>
                                    <span style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;padding:3px 8px;border-radius:999px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);color:#f87171;">Nonaktif</span>
                                <?php else: ?>
                                    <span style="font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:0.1em;padding:3px 8px;border-radius:999px;background:rgba(34,197,94,0.12);border:1px solid rgba(34,197,94,0.3);color:#4ade80;">Aktif</span>
                                <?php endif; ?>
                            </div>
                            <p style="font-size:11px;color:#9ca3af;line-height:1.6;margin-top:8px;"><?= htmlspecialchars($mod['desc']) ?></p>
                            <?php if ($exists && !$enabled): ?>
                                <p style="font-size:10px;color:#6b7280;margin-top:6px;">Seluruh URL <code style="color:#93c5fd;">/arcade/*</code> dialihkan ke HUB &amp; semua jejaknya (link, menu, sitemap) disembunyikan otomatis.</p>
                            <?php endif; ?>
                        </div>
                        <form method="POST" action="modules" style="flex-shrink:0;">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="toggle_module" value="1">
                            <input type="hidden" name="module_key" value="<?= htmlspecialchars($mod['key']) ?>">
                            <input type="hidden" name="module_enabled" value="<?= $toggle ? '0' : '1' ?>">
                            <label class="admin-toggle" style="<?= $exists ? '' : 'opacity:0.3;pointer-events:none;' ?>">
                                <input type="checkbox" <?= $toggle ? 'checked' : '' ?>
                                    onchange="this.closest('form').querySelector('input[name=module_enabled]').value = this.checked ? '1' : '0'; this.closest('form').submit();">
                                <div class="admin-toggle-track"></div>
                            </label>
                        </form>
                    </div>
                    <?php if ($exists): ?>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.05);display:flex;align-items:center;justify-content:space-between;font-size:10px;color:#6b7280;">
                        <span>Toggle ini disimpan di database (site_settings: <code><?= htmlspecialchars($mod['setting']) ?></code>) — berlaku untuk semua user secara langsung.</span>
                        <?php if ($enabled): ?>
                            <a href="../<?= htmlspecialchars($mod['home']) ?>" target="_blank" rel="noopener" style="color:<?= $mod['color'] ?>;font-weight:700;text-decoration:none;">Buka modul ↗</a>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.05);font-size:10px;color:#6b7280;">
                        Folder modul tidak ditemukan di server — toggle tidak berpengaruh. MEeL-HUB tetap berfungsi normal tanpa modul ini.
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script src="../assets/js/admin/shared/modal.js?v=<?= filemtime('../assets/js/admin/shared/modal.js') ?>"></script>
    <script src="../assets/js/admin/shared/hover-effects.js?v=<?= filemtime('../assets/js/admin/shared/hover-effects.js') ?>"></script>
    <script>if (typeof lucide !== 'undefined') lucide.createIcons();</script>
</body>

</html>
