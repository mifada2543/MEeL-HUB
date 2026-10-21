<?php
/**
 * Kontainer like/dislike — SATU-SATUNYA sumber markup untuk tombol ini.
 *
 * Markup ini sebelumnya ditulis tiga kali: di `music/watch.php`,
 * `video/watch.php`, dan `controllers/api/like.php`. Tiga salinan itu
 * melenceng satu sama lain, dan yang paling basi justru yang menang: begitu
 * like/dislike diklik, htmx menukar kontainer dengan salinan API — yang
 * tombol "Simpan"-nya masih `plus` + "Playlist" dan warnanya beda. Jadi
 * setelah diklik, tombolnya berubah bentuk di depan mata.
 *
 * Karena container ini ditukar lewat `hx-swap="outerHTML"`, markup yang
 * dikirim server WAJIB sama persis dengan yang ada di halaman — kalau
 * berbeda, setiap klik mengubah tampilannya.
 *
 * Kontrak pemanggil (semua opsional, punya nilai default):
 *   $like_dislike_media_id    int    id media
 *   $like_dislike_media_type  string 'music' atau 'video'
 *   $like_dislike_state       string '', 'like', atau 'dislike'
 *   $like_dislike_likes       int
 *   $like_dislike_dislikes    int
 *   $like_dislike_csrf        string token CSRF
 *   $like_dislike_rate_limit  int    detik tersisa kalau rate limit aktif
 */

$__ld_id = (int) ($like_dislike_media_id ?? 0);
$__ld_type = ($like_dislike_media_type ?? 'video') === 'music' ? 'music' : 'video';
$__ld_state = in_array($like_dislike_state ?? '', ['like', 'dislike'], true) ? $like_dislike_state : '';
$__ld_likes = (int) ($like_dislike_likes ?? 0);
$__ld_dislikes = (int) ($like_dislike_dislikes ?? 0);
$__ld_csrf = htmlspecialchars((string) ($like_dislike_csrf ?? ($_SESSION['csrf_token'] ?? '')), ENT_QUOTES);
$__ld_rate_limit = (int) ($like_dislike_rate_limit ?? 0);

$__ld_is_music = $__ld_type === 'music';
$__ld_like_active = $__ld_is_music
    ? 'bg-orange-500/15 border-orange-500/30 text-orange-400'
    : 'bg-red-600/15 border-red-600/30 text-red-400';
$__ld_dislike_active = 'bg-white/10 border-white/15 text-white';
$__ld_inactive = 'bg-gray-800/50 border-white/[.05] text-gray-400 hover:bg-gray-700 hover:text-gray-300';
$__ld_label = $__ld_is_music ? 'lagu' : 'video';
?>
<div id="like-dislike-container" class="flex items-center gap-2 flex-wrap">
<?php if ($__ld_rate_limit > 0): ?>
    <div class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider border border-yellow-500/30 bg-yellow-500/10 text-yellow-500">
        ⏱️ Tunggu <?= (int) $__ld_rate_limit ?>d
    </div>
    <button disabled
        class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-not-allowed <?= $__ld_inactive ?>">
        Like
    </button>
    <button disabled
        class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-not-allowed <?= $__ld_inactive ?>">
        Dislike
    </button>
<?php else: ?>
    <button
        hx-post="../api/like" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $__ld_id ?>","media_type":"<?= $__ld_type ?>","type":"like","csrf_token":"<?= $__ld_csrf ?>"}'
        title="Suka <?= $__ld_label ?>"
        class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
        <?= $__ld_state === 'like' ? $__ld_like_active : $__ld_inactive ?>">
        <i data-lucide="thumbs-up" class="w-3.5 h-3.5 <?= $__ld_state === 'like' ? 'fill-current' : '' ?>"></i>
        Like<?= $__ld_likes > 0 ? " <span class='tabular-nums ml-0.5'>{$__ld_likes}</span>" : '' ?>
    </button>
    <button
        hx-post="../api/like" hx-target="#like-dislike-container" hx-swap="outerHTML"
        hx-vals='{"id":"<?= $__ld_id ?>","media_type":"<?= $__ld_type ?>","type":"dislike","csrf_token":"<?= $__ld_csrf ?>"}'
        title="Tidak suka <?= $__ld_label ?>"
        aria-label="Dislike<?= $__ld_dislikes > 0 ? ' (' . $__ld_dislikes . ')' : '' ?>"
        class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer
        <?= $__ld_state === 'dislike' ? $__ld_dislike_active : $__ld_inactive ?>">
        <i data-lucide="thumbs-down" class="w-3.5 h-3.5 <?= $__ld_state === 'dislike' ? 'fill-current' : '' ?>"></i>
        <?= $__ld_dislikes > 0 ? "<span class='tabular-nums'>{$__ld_dislikes}</span>" : '' ?>
    </button>
<?php if ($__ld_is_music): ?>
    <button onclick="document.getElementById('playlist-modal').classList.remove('hidden')"
        class="flex items-center gap-1.5 px-3 sm:px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-wider transition-all border cursor-pointer <?= $__ld_inactive ?>">
        <i data-lucide="list-plus" class="w-3.5 h-3.5"></i> Simpan
    </button>
<?php endif; ?>
<?php endif; ?>
</div>

<?php
unset(
    $__ld_id,
    $__ld_type,
    $__ld_state,
    $__ld_likes,
    $__ld_dislikes,
    $__ld_csrf,
    $__ld_rate_limit,
    $__ld_is_music,
    $__ld_like_active,
    $__ld_dislike_active,
    $__ld_inactive,
    $__ld_label
);

/* reference build: MEeL-C4H24N11O [like-dislike-partial] */
