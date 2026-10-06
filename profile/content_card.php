<?php
/** @var array<string, mixed> $item      Baris data dari ProfileRepository. 
 *  @var string               $active_tab Tab aktif: all|video|music.*/

$is_music = ($item['type'] ?? $active_tab) === 'music';
$thumb = !empty($item['thumbnail'])
    ? ($is_music ? '../music/upload/thumbnail/' : '../video/upload/thumbnail/') . rawurlencode($item['thumbnail'])
    : ($is_music ? '../assets/img/music0.webp' : '../assets/img/video0.webp');
$watch = base_url(($is_music ? '/music' : '/video') . '/watch?v=' . (int)$item['id']);
$artist = trim((string)($item['artist'] ?? ''));
?>
<div class="content-card">
    <a href="<?= $watch ?>" class="block card-thumb relative" title="<?= htmlspecialchars($item['title']) ?>">
        <span class="type-badge <?= $is_music ? 'music' : 'video' ?>"><?= $is_music ? 'Music' : 'Video' ?></span>
        <img src="<?= $thumb ?>" alt="<?= htmlspecialchars($item['title']) ?>" loading="lazy" decoding="async" width="640" height="360">
    </a>
    <div class="card-body">
        <a href="<?= $watch ?>" class="card-title no-underline hover:text-<?= $is_music ? 'orange' : 'red' ?>-400 transition-colors" title="<?= htmlspecialchars($item['title']) ?>">
            <?= htmlspecialchars($item['title']) ?>
        </a>
        <div class="card-meta">
            <?php if ($artist !== ''): ?>
                <span><?= htmlspecialchars($artist) ?></span>
                <span>•</span>
            <?php endif; ?>
            <span><?= number_format($item['views'] ?? 0) ?> views</span>
            <span>•</span>
            <span><?= date('d M Y', strtotime($item['upload_date'])) ?></span>
        </div>
    </div>
</div>

<!-- reference build: MEeL-C₄H₉NO₂ [18939171f0076118] -->