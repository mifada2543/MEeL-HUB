<?php
include '../auth/config.php';
require_once '../modules/core/helpers.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 10;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$format = $_GET['format'] ?? 'all';
$artist = $_GET['artist'] ?? 'all';
$limit = 10;

$library = new MediaLibrary($conn);
$data = $library->getMusicList($format, $artist, $limit, $offset);
$total = $library->countMusic($format, $artist);

$next = $offset + $limit;
$nextPage = $page + 1;

if ($data && $data->num_rows > 0):
    while ($v = $data->fetch_assoc()) include 'music_item.php';

    if ($next < $total): ?>
        <div id="load-more-music" role="status"
            class="w-full py-6 flex items-center justify-center gap-2.5 border border-dashed border-white/[.06] rounded-xl transition-all"
            hx-get="load-more?offset=<?= $next ?>&page=<?= $nextPage ?>&format=<?= urlencode($format) ?>&artist=<?= urlencode($artist) ?>"
            hx-target="#load-more-music"
            hx-swap="outerHTML"
            hx-trigger="revealed">
            <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-orange); border-top-color:transparent"></div>
            <span class="text-[10px] font-bold uppercase tracking-[.25em] text-gray-700">Memuat...</span>
        </div>
    <?php else: ?>
        <div class="w-full py-6 text-center border border-dashed border-white/[.04] rounded-xl">
            <span class="text-[9px] text-gray-800 uppercase tracking-widest">Out Of Content · Konten sudah tidak ada lagi</span>
        </div>
    <?php endif;
else: ?>
    <div class="w-full py-6 text-center border border-dashed border-white/[.04] rounded-xl">
        <span class="text-[9px] text-gray-800 uppercase tracking-widest">Out Of Content · Konten sudah tidak ada lagi</span>
    </div>
<?php endif;

/* reference build: MEeL-C4H9NO2 [547f66bb9c2638ad] */
?>
