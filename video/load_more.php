<?php
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 15;
$limit  = 15;

$library = new MediaLibrary($conn);
$data    = $library->getVideos($limit, $offset);
$total   = $library->countVideos();

if ($data && $data->num_rows > 0):
    while ($v = $data->fetch_assoc()):
        include 'video_card.php';
    endwhile;

    $next = $offset + $limit;
    if ($next < $total): ?>
        <div id="load-more-area" title="Muat lebih banyak"
            class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
            hx-get="load_more.php?offset=<?= $next ?>"
            hx-target="#load-more-area"
            hx-swap="outerHTML" title="Muat lebih">
            <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-700 group-hover:text-red-500 transition-colors">
                Muat Lebih Banyak
            </span>
        </div>
    <?php else: ?>
        <div class="aspect-video flex items-center justify-center border border-dashed border-white/[.04] rounded-2xl">
            <span class="text-[9px] text-gray-800 uppercase tracking-widest">End of Library</span>
        </div>
    <?php endif;
endif;
?>
<script>
lucide.createIcons();
</script>
