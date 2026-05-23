<?php
session_name('meel');
session_start();
include '../auth/config.php';
include '../modules/helpers.php';
require_once '../modules/MediaLibrary.php';

$library       = new MediaLibrary($conn);
$format_filter = $_GET['format'] ?? 'all';
$artist_filter = $_GET['artist'] ?? 'all';

$artists        = $library->getArtists();
$total_music    = $library->countMusic($format_filter, $artist_filter);
$data_init      = $library->getMusicList($format_filter, $artist_filter, 10, 0);
$is_logged_in   = isset($_SESSION['user_id']);
?>

<!-- HEADER -->
<div class="flex items-end justify-between mb-6 pb-4 border-b border-white/[.04]">
    <div>
        <div class="text-[9px] text-gray-700 uppercase tracking-[.25em] mb-1">Library</div>
        <div class="section-title">
            <?= $artist_filter === 'all' ? 'DISCOVERY' : strtoupper(htmlspecialchars($artist_filter)) ?>
        </div>
    </div>
    <span class="text-[10px] text-gray-700 uppercase tracking-widest">
        <?= $total_music ?> tracks
    </span>
</div>

<!-- MUSIC LIST -->
<div id="music-list" class="space-y-1">
    <?php if ($data_init && $data_init->num_rows > 0): ?>
        <?php while ($v = $data_init->fetch_assoc()): ?>
            <?php include 'music_item.php'; ?>
        <?php endwhile; ?>
    <?php else: ?>
        <div class="py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">
            Tidak ada lagu ditemukan.
        </div>
    <?php endif; ?>
</div>

<!-- LOAD MORE -->
<?php if ($total_music > 10): ?>
    <div id="load-more-music" class="pt-6">
        <button hx-get="load_more_music.php?offset=10&format=<?= $format_filter ?>&artist=<?= urlencode($artist_filter) ?>"
            hx-target="#music-list"
            hx-swap="beforeend"
            class="w-full py-4 border border-dashed border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-[.25em] text-gray-700 hover:text-orange-500 hover:border-orange-500/30 transition-all">
            Load More
        </button>
    </div>
<?php endif; ?>
