<?php
include '../auth/config.php';
require_once '../modules/MediaLibrary.php';

$q       = trim($_GET['search'] ?? '');
$exclude = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;
$sidebar = (isset($_SERVER['HTTP_HX_TARGET']) && $_SERVER['HTTP_HX_TARGET'] === 'recommendation-column');

$library = new MediaLibrary($conn);
$data    = $library->searchVideo($q, $exclude, $sidebar);

if ($data && $data->num_rows > 0) {
    while ($v = $data->fetch_assoc()) {
        if ($sidebar) {
            ?>
            <a href="watch.php?id=<?= $v['id'] ?>"
               class="flex gap-3 group rekomendasi-item htmx-added">
                <div class="w-28 h-[4.5rem] bg-black rounded-xl overflow-hidden flex-shrink-0 border border-white/[.05]">
                    <img src="upload/thumbnail/<?= htmlspecialchars($v['thumbnail']) ?>"
                         class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                         loading="lazy">
                </div>
                <div class="flex-1 min-w-0">
                    <h5 class="text-[11px] font-bold text-gray-300 line-clamp-2 uppercase group-hover:text-red-400 transition leading-snug">
                        <?= htmlspecialchars($v['title']) ?>
                    </h5>
                    <p class="text-[9px] text-gray-700 mt-1"><?= number_format($v['views']) ?> views</p>
                    <?php if (!empty($v['uploader_name'])): ?>
                    <p class="text-[10px] font-bold text-red-600/70 uppercase tracking-widest mt-0.5 truncate">
                        <?= htmlspecialchars($v['uploader_name']) ?>
                    </p>
                    <?php endif; ?>
                </div>
            </a>
            <?php
        } else {
            include 'video_card.php';
        }
    }
} else {
    echo '<div class="py-12 text-center text-[10px] text-gray-700 uppercase tracking-widest">Video tidak ditemukan.</div>';
}