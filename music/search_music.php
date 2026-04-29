<?php
include '../auth/config.php';
require_once '../auth/MediaLibrary.php';

$q       = trim($_GET['search'] ?? '');
$exclude = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;
$sidebar = (isset($_SERVER['HTTP_HX_TARGET']) && $_SERVER['HTTP_HX_TARGET'] === 'music-recommendation-column');

$library = new MediaLibrary($conn);
$data    = $library->searchMusic($q, $exclude, $sidebar);

if ($data && $data->num_rows > 0) {
    while ($v = $data->fetch_assoc()) {
        if ($sidebar) {
            // Tampilan rekomendasi di watch.php
            $v_ext = pathinfo($v['filename'], PATHINFO_EXTENSION);
            ?>
            <a href="watch.php?id=<?= $v['id'] ?>"
               class="flex gap-3 group rekomendasi-item p-2 rounded-xl hover:bg-white/[.03] transition-all htmx-added">
                <div class="w-14 h-14 rounded-lg overflow-hidden flex-shrink-0 bg-white/[.04]">
                    <img src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                         class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                         loading="lazy">
                </div>
                <div class="flex-1 min-w-0 flex flex-col justify-center">
                    <h5 class="text-[11px] font-bold text-gray-300 truncate group-hover:text-orange-400 transition uppercase tracking-tight">
                        <?= htmlspecialchars($v['title']) ?>
                    </h5>
                    <p class="text-[10px] text-orange-500/70 font-bold truncate mt-0.5">
                        <?= htmlspecialchars($v['artist']) ?>
                    </p>
                    <div class="flex items-center gap-2 mt-1">
                        <span class="text-[9px] text-gray-600"><?= number_format($v['views']) ?> views</span>
                        <span class="text-[8px] px-1.5 py-0.5 rounded bg-orange-600/10 text-orange-500 border border-orange-600/20 font-bold uppercase">
                            <?= $v_ext === 'ogg' ? 'OPUS' : strtoupper($v_ext) ?>
                        </span>
                    </div>
                </div>
            </a>
            <?php
        } else {
            include 'music_item.php';
        }
    }
} else {
    echo '<div class="py-12 text-center text-[10px] text-gray-700 uppercase tracking-widest">Tidak ada lagu ditemukan.</div>';
}