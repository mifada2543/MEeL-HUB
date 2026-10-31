<?php
if (!function_exists('meel_render_music_row')) {
    /**
     * @param array<string, mixed> $v Baris data musik.
     */
    function meel_render_music_row(array $v): void
    {
        authorize_stream((int) $v['id']);
        $ext = strtolower(pathinfo($v['filename'], PATHINFO_EXTENSION));
?>
        <div class="music-item flex items-center gap-3 px-3 py-2.5 rounded-xl htmx-added"
            data-id="<?= $v['id'] ?>"
            data-title="<?= htmlspecialchars($v['title']) ?>"
            data-artist="<?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>"
            data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
            data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
            data-filename="<?= htmlspecialchars($v['filename']) ?>">
            <a href="<?= base_url('/music/watch?v=' . (int)$v['id']) ?>"
                class="music-item-link relative w-12 h-12 rounded-lg overflow-hidden flex-shrink-0"
                style="background:var(--meel-surface-hover)"
                data-music-id="<?= $v['id'] ?>"
                data-title="<?= htmlspecialchars($v['title']) ?>"
                data-artist="<?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>"
                data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
                data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                data-filename="<?= htmlspecialchars($v['filename']) ?>"
                title="<?= htmlspecialchars($v['title']) ?> — <?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>">
                <?php if (!empty($v['thumbnail'])): ?>
                    <img src="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                        alt="<?= htmlspecialchars($v['title']) ?> thumbnail"
                        width="96" height="96"
                        class="w-full h-full object-cover" loading="lazy" decoding="async">
                <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center">
                        <i data-lucide="music" class="w-4 h-4" style="color:var(--meel-text-muted)"></i>
                    </div>
                <?php endif; ?>
            </a>
            <div class="flex-1 min-w-0">
                <a href="<?= base_url('/music/watch?v=' . (int)$v['id']) ?>"
                    class="music-item-link block text-[12px] font-bold truncate transition-colors leading-tight"
                    style="color:var(--meel-text)"
                    onmouseover="this.style.color='var(--meel-orange)'"
                    onmouseout="this.style.color='var(--meel-text)'"
                    data-music-id="<?= $v['id'] ?>"
                    data-title="<?= htmlspecialchars($v['title']) ?>"
                    data-artist="<?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>"
                    data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
                    data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                    data-filename="<?= htmlspecialchars($v['filename']) ?>" title="<?= htmlspecialchars($v['title']) ?>">
                    <?= htmlspecialchars($v['title']) ?>
                </a>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-[10px] truncate" style="color:var(--meel-text-secondary)" title="<?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>">
                        <?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>
                    </span>
                    <span class="text-[9px] px-1.5 py-0.5 rounded uppercase flex-shrink-0"
                        style="background:var(--meel-surface-hover); color:var(--meel-text-secondary); border:1px solid var(--meel-border)"
                        title="<?= $ext ?>">
                        <?= $ext ?>
                    </span>
                </div>
            </div>
            <a href="<?= base_url('/music/watch?v=' . (int)$v['id']) ?>"
                class="music-item-link play-btn hidden md:flex opacity-0 -translate-x-2 transition-all duration-200
                      text-[9px] font-bold uppercase tracking-widest
                      text-white px-4 py-2 rounded-lg flex-shrink-0"
                style="background:var(--meel-orange)"
                onmouseover="this.style.background='color-mix(in srgb, var(--meel-orange), black 15%)'"
                onmouseout="this.style.background='var(--meel-orange)'"
                data-music-id="<?= $v['id'] ?>"
                data-title="<?= htmlspecialchars($v['title']) ?>"
                data-artist="<?= htmlspecialchars($v['artist'] ?? 'Tidak diketahui') ?>"
                data-thumbnail="<?= htmlspecialchars($v['thumbnail']) ?>"
                data-thumbnail-url="<?= htmlspecialchars(music_thumbnail_url($v['thumbnail'])) ?>"
                data-filename="<?= htmlspecialchars($v['filename']) ?>"
                title="Putar <?= htmlspecialchars($v['title']) ?>">
                Play
            </a>
        </div>
    <?php
    }
}
if (!function_exists('meel_render_music_rec_card')) {
    /**
     * @param array<string, mixed> $rec Satu baris rekomendasi.
     * @param bool                 $htmx true kalau render dipicu htmx — menambah kelas htmx-added.
     */
    function meel_render_music_rec_card(array $rec, bool $htmx = false): void
    {
        $ext = strtolower(pathinfo((string) ($rec['filename'] ?? ''), PATHINFO_EXTENSION));
        $lbl = $ext === 'ogg' ? 'opus' : $ext;
    ?>
        <a href="<?= base_url('/music/watch?v=' . (int)$rec['id']) ?>"
            class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 px-2 py-2.5 rounded-xl no-underline<?= $htmx ? ' htmx-added' : '' ?>"
            title="<?= htmlspecialchars($rec['title']) ?>">
            <div class="w-full lg:w-32 aspect-video lg:h-20 lg:aspect-auto rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                <img src="<?= htmlspecialchars(music_thumbnail_url($rec['thumbnail'])) ?>"
                    alt="Thumbnail musik <?= htmlspecialchars($rec['title']) ?>"
                    width="256" height="144"
                    class="rec-thumb-img w-full h-full object-cover transition-transform duration-300"
                    loading="lazy" decoding="async">
            </div>
            <div class="flex-1 min-w-0 flex flex-col justify-center">
                <div class="text-[11px] sm:text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                    <?= htmlspecialchars($rec['title']) ?>
                </div>
                <div class="rec-meta-text mt-1 text-[9px] uppercase tracking-wider truncate"><?= htmlspecialchars($rec['artist']) ?></div>
                <div class="flex items-center gap-1.5 mt-1">
                    <span class="text-[9px] text-gray-300"><?= number_format($rec['views'] ?? 0) ?> tayangan</span>
                    <span class="rec-meta-text text-[8px] px-1.5 py-0.5 rounded bg-white/[.04] border border-white/[.05] uppercase"><?= $lbl ?></span>
                </div>
            </div>
        </a>
<?php
    }
}
?>
<!-- reference build: MEeL-C9H12N2 [3d7a10e5c4b9f286] -->
