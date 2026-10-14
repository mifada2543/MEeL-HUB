<?php
include '../auth/config.php';
require_once '../modules/media/SearchEngine.php';
require_once __DIR__ . '/cards.php';

$engine = new SearchEngine($conn);
$params = $engine->parseParams();
$result = $engine->searchVideo($params);

if ($result['count'] > 0) {
    foreach ($result['results'] as $v) {
        if ($result['sidebar']) {
            meel_render_video_rec_card($v, true);
        } else {
            meel_render_video_card($v);
        }
    }

    if ($result['sidebar'] && ($result['hasMore'] || empty($result['query']))) {
        $nextOffset = $result['offset'] + $result['limit'];
        ?>
        <div class="py-3 text-center rec-sentinel"
            hx-get="search?search=<?= urlencode($result['query']) ?>&exclude=<?= $result['exclude'] ?>&offset=<?= $nextOffset ?>"
            hx-target="#recommendation-column"
            hx-swap="beforeend"
            hx-trigger="revealed"
            hx-indicator="#search-indicator">
            <div class="rec-spinner animate-spin h-3 w-3 border-2 border-red-500 border-t-transparent rounded-full mx-auto"></div>
        </div>
        <?php
    } elseif (!$result['sidebar']) {
        if ($result['hasMore']) {
            ?>
            <div id="load-more-area" role="status"
                class="col-span-full flex items-center justify-center gap-2.5 py-6 bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl transition-all"
                hx-get="search?search=<?= urlencode($result['query']) ?>&exclude=<?= $result['exclude'] ?>&offset=<?= $result['offset'] + $result['limit'] ?>"
                hx-target="#load-more-area"
                hx-swap="outerHTML"
                hx-trigger="revealed">
                <div class="animate-spin h-3 w-3 border-2 border-t-transparent rounded-full" style="border-color:var(--meel-red); border-top-color:transparent"></div>
                <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300">Memuat...</span>
            </div>
            <?php
        } elseif ((int)$result['offset'] > 0) {
            ?>
            <div class="col-span-full py-6 text-center border border-dashed border-white/[.04] rounded-2xl">
                <span class="text-[9px] text-gray-800 uppercase tracking-widest">Konten Habis · Semua konten sudah ditampilkan</span>
            </div>
            <?php
        }
    }
} elseif ($result['offset'] === 0) {
    echo '<div class="col-span-full py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">Video tidak ditemukan.</div>';
} else {
    echo '<div class="col-span-full py-6 text-center border border-dashed border-white/[.04] rounded-2xl"><span class="text-[9px] text-gray-800 uppercase tracking-widest">Konten Habis · Semua konten sudah ditampilkan</span></div>';
}

/* reference build: MEeL-C6H9N3O3 [b271388b3aca6acc] */
