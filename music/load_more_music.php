<?php
include '../auth/config.php';
require_once '../auth/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 10;
$format = $_GET['format'] ?? 'all';
$artist = $_GET['artist'] ?? 'all';
$limit  = 10;

$library = new MediaLibrary($conn);
$data    = $library->getMusicList($format, $artist, $limit, $offset);
$total   = $library->countMusic($format, $artist);

if ($data && $data->num_rows > 0) {
    while ($v = $data->fetch_assoc()) {
        include 'music_item.php';
    }

    $next = $offset + $limit;
    if ($next < $total): ?>
        <div id="load-more-music" class="pt-4" hx-swap-oob="true">
            <button hx-get="load_more_music.php?offset=<?= $next ?>&format=<?= urlencode($format) ?>&artist=<?= urlencode($artist) ?>"
                    hx-target="#music-list"
                    hx-swap="beforeend"
                    class="w-full py-4 border border-dashed border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-[.25em] text-gray-700 hover:text-orange-500 hover:border-orange-500/30 transition-all">
                Load More
            </button>
        </div>
    <?php else: ?>
        <div id="load-more-music" class="py-10 text-center text-[9px] text-gray-800 uppercase tracking-[.4em]" hx-swap-oob="true">
            End of Collection
        </div>
    <?php endif;
}