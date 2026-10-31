<?php
/**
 * Kartu-kartu modul video. Dua bentuk yang berbeda dan TIDAK bisa ditukar:
 *
 * 1. meel_render_video_card()     — kartu grid di beranda/library video.
 * 2. meel_render_video_rec_card() — kartu sidebar rekomendasi di watch.php.
 *
 * Pemanggil wajib: require_once __DIR__ . '/cards.php';
 *
 * @see docs/{id,en}/modules.md
 */

if (!function_exists('meel_render_video_card')) {
    /**
     * Kartu grid video — dipakai video/index.php, video/load_more.php, dan
     * video/search_video.php (mode grid).
     *
     * @param array<string, mixed> $v Baris data video.
     */
    function meel_render_video_card(array $v): void
    {
        $thumb_src = video_thumbnail_url($v['thumbnail'] ?? '');
        if ($thumb_src === '') {
            $thumb_src = '../assets/img/placeholder-video.svg';
        }
        ?>
        <div class="video-card htmx-added meel-card rounded-2xl overflow-hidden group"
             title="<?= htmlspecialchars($v['title']) ?>">

            <div class="aspect-video relative overflow-hidden" style="background:#000">
                <img src="<?= htmlspecialchars($thumb_src, ENT_QUOTES, 'UTF-8') ?>"
                     class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-105"
                     loading="lazy"
                     decoding="async"
                     alt="Thumbnail video <?= htmlspecialchars($v['title']) ?>"
                     width="420"
                     height="236">

                <a href="<?= base_url('/video/watch?v=' . (int)$v['id']) ?>"
                   class="absolute inset-0 flex items-center justify-center
                          opacity-0 group-hover:opacity-100 transition-opacity duration-300"
                   style="background:rgba(0,0,0,0.5)"
                   aria-label="Tonton video <?= htmlspecialchars($v['title']) ?>"
                   title="Tonton video <?= htmlspecialchars($v['title']) ?>">
                    <div class="w-11 h-11 rounded-full flex items-center justify-center
                                scale-90 group-hover:scale-100 transition-transform duration-300"
                         style="background:var(--meel-red); box-shadow:var(--meel-shadow-lg)">
                        <i data-lucide="play" class="fill-white text-white w-5 h-5 ml-0.5"></i>
                    </div>
                </a>

                <div class="absolute bottom-2 right-2 backdrop-blur-sm px-2 py-0.5 rounded text-[9px] font-bold"
                     style="background:rgba(0,0,0,0.6); color:var(--meel-text-secondary)">
                    <?= number_format($v['views'] ?? 0) ?> tayangan
                </div>
            </div>

            <div class="px-3 py-3">
                <a href="<?= base_url('/video/watch?v=' . (int)$v['id']) ?>"
                   class="block text-[12px] font-bold line-clamp-2 leading-snug transition-colors"
                   style="color:var(--meel-text)"
                   onmouseover="this.style.color='var(--meel-red)'"
                   onmouseout="this.style.color='var(--meel-text)'"
                   title="<?= htmlspecialchars($v['title']) ?>">
                    <?= htmlspecialchars($v['title']) ?>
                </a>
                <?php if (!empty($v['upload_date'])): ?>
                    <p class="text-[9px] mt-1.5 uppercase tracking-wider"
                       style="color:var(--meel-text-secondary)">
                        <?= date('d M Y', strtotime($v['upload_date'])) ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}

if (!function_exists('meel_render_video_rec_card')) {
    /**
     * Kartu sidebar rekomendasi — dipakai video/watch.php dan endpoint htmx-nya
     * (video/search_video.php: hasil cari + infinite scroll).
     *
     * Dua sumber data memberi nama kolom berbeda untuk username yang sama:
     * `uploader` dan `uploader_name`. Dua-duanya users.username, jadi di sini
     * keduanya dibaca.
     *
     * @param array<string, mixed> $rec Satu baris rekomendasi.
     * @param bool                 $htmx true kalau render dipicu htmx — menambah kelas htmx-added.
     */
    function meel_render_video_rec_card(array $rec, bool $htmx = false): void
    {
        $uploader = $rec['uploader_name'] ?? $rec['uploader'] ?? '';
        $rec_thumb = video_thumbnail_url($rec['thumbnail'] ?? '');
        if ($rec_thumb === '') {
            $rec_thumb = '../assets/img/placeholder-video.svg';
        }
        ?>
        <a href="<?= base_url('/video/watch?v=' . (int)$rec['id']) ?>"
           class="rekomendasi-item flex flex-col lg:flex-row gap-2 lg:gap-3 px-2 py-2.5 rounded-xl no-underline<?= $htmx ? ' htmx-added' : '' ?>"
           title="<?= htmlspecialchars($rec['title']) ?>">
            <div class="w-full lg:w-32 aspect-video lg:h-20 lg:aspect-auto rounded-xl overflow-hidden flex-shrink-0 bg-white/[.04] border border-white/[.05]">
                <img src="<?= htmlspecialchars($rec_thumb, ENT_QUOTES, 'UTF-8') ?>"
                     class="rec-thumb-img w-full h-full object-cover transition-transform duration-300"
                     loading="lazy" decoding="async" width="256" height="144"
                     alt="Thumbnail video <?= htmlspecialchars($rec['title']) ?>">
            </div>
            <div class="flex-1 min-w-0 flex flex-col justify-center">
                <div class="text-[11px] sm:text-[12px] font-bold text-gray-400 uppercase tracking-tight leading-snug rec-title-text">
                    <?= htmlspecialchars($rec['title']) ?>
                </div>
                <div class="text-[9px] text-gray-300 mt-1"><?= number_format($rec['views'] ?? 0) ?> tayangan</div>
                <?php if (!empty($uploader)): ?>
                    <div class="rec-uploader-text font-bold uppercase tracking-wider mt-0.5 truncate">
                        <?= htmlspecialchars($uploader) ?>
                    </div>
                <?php endif; ?>
            </div>
        </a>
        <?php
    }
}
?>
<!-- reference build: MEeL-C7H9N2 [a41f8c07b6d2e539] -->
