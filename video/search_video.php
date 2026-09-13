<?php
include '../auth/config.php';
require_once '../modules/media/SearchEngine.php';

$engine = new SearchEngine($conn);
$params = $engine->parseParams();
$result = $engine->searchVideo($params);

if ($result['count'] > 0) {
    foreach ($result['results'] as $v) {
        if ($result['sidebar']) {
?>
            <a href="<?= base_url('/video/watch?v=' . (int)$v['id']) ?>"
                class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 px-2 py-2.5 rounded-xl no-underline htmx-added"
                title="<?= htmlspecialchars($v['title']) ?>">
                <div class="w-full lg:w-32 aspect-video lg:h-20 lg:aspect-auto rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                    <img src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                        class="rec-thumb-img w-full h-full object-cover transition-transform duration-300"
                        loading="lazy">
                </div>
                <div class="flex-1 min-w-0 flex flex-col justify-center">
                    <div class="text-[11px] sm:text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                        <?= htmlspecialchars($v['title']) ?>
                    </div>
                    <div class="text-[9px] text-gray-300 mt-1"><?= number_format($v['views'] ?? 0) ?> views</div>
                    <?php if (!empty($v['uploader_name'])): ?>
                        <div class="text-[9px] font-bold text-red-500/60 uppercase tracking-wider mt-0.5 truncate">
                            <?= htmlspecialchars($v['uploader_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </a>
        <?php
        } else {
            include 'video_card.php';
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
        $curPage    = (int)((int)$result['offset'] / max((int)$result['limit'], 1)) + 1;
        $totalPages = max(1, (int)$result['total_pages']);

        if ($result['hasMore']) {
            ?>
            <div id="load-more-area"
                class="aspect-video flex items-center justify-center bg-white/[.02] border border-dashed border-white/[.06] rounded-2xl cursor-pointer hover:border-red-500/30 hover:bg-white/[.03] transition-all group"
                hx-get="search?search=<?= urlencode($result['query']) ?>&exclude=<?= $result['exclude'] ?>&offset=<?= $result['offset'] + $result['limit'] ?>"
                hx-target="#load-more-area"
                hx-swap="outerHTML">
                <span class="text-[10px] font-bold uppercase tracking-[.2em] text-gray-300 group-hover:text-red-500 transition-colors">
                    Muat Lebih Banyak · <?= $curPage ?>/<?= $totalPages ?>
                </span>
            </div>
            <?php
        } elseif ((int)$result['offset'] > 0) {
            ?>
            <div class="aspect-video flex items-center justify-center border border-dashed border-white/[.04] rounded-2xl">
                <span class="text-[9px] text-gray-800 uppercase tracking-widest">End of Results · <?= $curPage ?>/<?= $totalPages ?></span>
            </div>
            <?php
        }
    }
} elseif ($result['offset'] === 0) {
    echo '<div class="col-span-full py-16 text-center text-[10px] text-gray-700 uppercase tracking-widest">Video tidak ditemukan.</div>';
}
