<?php
include '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 15;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 15;

$library = new MediaLibrary($conn);
$data = $library->getVideos($limit, $offset);
$total = $library->countVideos();
$totalPages = max(1, (int)ceil($total / $limit));

if ($data && $data->num_rows > 0):
    while ($v = $data->fetch_assoc()):
        include 'video_card.php';
    endwhile;

    $next = $offset + $limit;
    $nextPage = $page + 1;
    if ($next < $total): ?>
        <div id="load-more-area" role="status"
            class="col-span-full flex items-center justify-center gap-2.5 py-6 bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl transition-all"
            hx-get="load-more?offset=<?= $next ?>&page=<?= $nextPage ?>"
            hx-target="#load-more-area"
            hx-swap="outerHTML"
            hx-trigger="revealed">
            <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-red); border-top-color:transparent"></div>
            <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300">Memuat...</span>
        </div>
    <?php else: ?>
        <div class="col-span-full py-6 text-center border border-dashed border-white/[.04] rounded-2xl">
            <span class="text-[9px] text-gray-800 uppercase tracking-widest">Konten Habis · Semua konten sudah ditampilkan</span>
        </div>
    <?php endif;
else: ?>
    <div class="col-span-full py-6 text-center border border-dashed border-white/[.04] rounded-2xl">
        <span class="text-[9px] text-gray-800 uppercase tracking-widest">Konten Habis · Semua konten sudah ditampilkan</span>
    </div>
<?php endif;
?>
<script>lucide.createIcons();
</script>

<!-- reference build: MEeL-C10H12N2O [38a8a232819f09be] -->
