<?php
require_once '../modules/auth/helpers/session.php';
meel_boot_session();
require_once '../auth/config.php';
require_once '../modules/media/ProfileRepository.php';

$target_user = $_GET['u'] ?? '';
$active_tab = $_GET['tab'] ?? 'all';
if (!in_array($active_tab, ['all', 'video', 'music'], true)) {
    $active_tab = 'all';
}

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit = 12;

if ($target_user === '' || $target_user === 'guest') {
    header('Location: ' . base_url('/err/?code=not_found'), true, 302);
    exit;
}

$profileRepo = new ProfileRepository($conn);
$user = $profileRepo->findByUsername($target_user);
if (!$user) {
    header('Location: ' . base_url('/err/?code=not_found'), true, 302);
    exit;
}

$user_id = (int) $user['id'];

if ($active_tab === 'video') {
    $items = $profileRepo->getVideosPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countVideo($user_id);
} elseif ($active_tab === 'music') {
    $items = $profileRepo->getMusicPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countMusic($user_id);
} else {
    $items = $profileRepo->getFeedPaginated($user_id, $limit, $offset);
    $total = $profileRepo->countVideo($user_id) + $profileRepo->countMusic($user_id);
}

$next = $offset + $limit;
$has_more = ($next < $total) && count($items) > 0;
$more_url = 'channel-more?u=' . rawurlencode($target_user)
          . '&tab=' . $active_tab
          . '&offset=' . $next;
?>

<?php foreach ($items as $item): ?>
    <?php include __DIR__ . '/content_card.php'; ?>
<?php endforeach; ?>

<?php if ($has_more): ?>
    <div id="channel-more-area" role="status"
        class="col-span-full flex items-center justify-center gap-2.5 py-6 bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl transition-all"
        hx-get="<?= htmlspecialchars($more_url) ?>"
        hx-target="#channel-more-area"
        hx-swap="outerHTML"
        hx-trigger="revealed"
        aria-label="Memuat lebih banyak konten">
        <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-blue,#3b82f6);border-top-color:transparent"></div>
        <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300">Memuat...</span>
    </div>
<?php else: ?>
    <div class="col-span-full py-6 text-center border border-dashed border-white/[.04] rounded-2xl">
        <span class="text-[9px] text-gray-800 uppercase tracking-widest">Konten Habis · Semua konten sudah ditampilkan</span>
    </div>
<?php endif; ?>
<script>lucide.createIcons();</script>

<!-- reference build: MEeL-C10H15N [bf0998a94032c50c] -->
