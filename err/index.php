<?php

require_once __DIR__ . '/../modules/core/base_url.php';
$meel_base = meel_base_url_path();

if (!function_exists('meel_err_alpha')) {

    function meel_err_alpha(string $hex, int $alpha): string
    {
        return $hex . str_pad(dechex($alpha), 2, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('meel_err_shade')) {

    /**
     * Menggelapkan warna hex menuju hitam.
     *
     * Dipakai untuk varian teks/ikon aksen pada mode terang (latar #fafafa),
     * karena semua warna aksen MEeL gagal WCAG AA di latar terang
     * (contoh: #06b6d4 = 2.33:1, #67e8f9 = 1.39:1) sementara di latar
     * gelap mereka 8-14:1. Dengan 50% ke hitam, rasio terendah menjadi
     * 5.11:1 — seluruh palet aksen lolos AA.
     */
    function meel_err_shade(string $hex, float $amount = 0.5): string
    {
        $clean = ltrim($hex, '#');
        if (strlen($clean) !== 6) {
            return $hex;
        }

        $amount = max(0.0, min(1.0, $amount));
        $out = '#';
        for ($i = 0; $i < 3; $i++) {
            $ch = (int) round(hexdec(substr($clean, $i * 2, 2)) * (1 - $amount));
            $out .= str_pad(dechex($ch), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }
}

$types = [
    'denied' => [
        'status' => 403,
        'protocol' => '403_Forbidden',
        'title' => 'Access Denied',
        'desc' => 'Kredensial atau peran akun Anda tidak memiliki otorisasi untuk membuka berkas/halaman ini.',
        'icon' => 'shield-ban',
        'dot' => 'RESTRICTED',
        'accent' => '#3b82f6',
        'accent2' => '#06b6d4',
        'meta_t' => 'Access Denied | MEeL',
        'meta_d' => 'Akses ditolak. Anda tidak memiliki izin untuk mengakses halaman ini.',
    ],
    'not_found' => [
        'status' => 404,
        'protocol' => '404_Not_Found',
        'title' => 'Not Found',
        'desc' => 'Halaman atau berkas yang Anda cari tidak dapat ditemukan di server. Mungkin telah dipindahkan, dihapus, atau URL tidak valid.',
        'icon' => 'search-x',
        'dot' => 'MISSING',
        'accent' => '#eab308',
        'accent2' => '#f59e0b',
        'meta_t' => '404 Not Found | MEeL',
        'meta_d' => 'MEeL - Halaman tidak ditemukan.',
    ],
    'banned' => [
        'status' => 403,
        'protocol' => '403_Banned',
        'title' => 'Akses Dibatasi',
        'desc' => 'Akses Anda ke MEeL telah dibatasi oleh sistem keamanan.',
        'icon' => 'ban',
        'dot' => 'BLOCKED',
        'accent' => '#ef4444',
        'accent2' => '#f97316',
        'meta_t' => 'Akses Dibatasi | MEeL',
        'meta_d' => 'Akses Anda ke MEeL telah dibatasi oleh sistem keamanan.',
    ],
    'revoked' => [
        'status' => 401,
        'protocol' => '401_Revoked',
        'title' => 'Session Revoked',
        'desc' => 'Sesi Anda telah dicabut. Silakan masuk kembali untuk melanjutkan aktivitas.',
        'icon' => 'key-round',
        'dot' => 'EXPIRED',
        'accent' => '#f43f5e',
        'accent2' => '#ef4444',
        'meta_t' => 'Session Revoked | MEeL',
        'meta_d' => 'Sesi Anda telah dicabut.',
    ],
    'maintance' => [
        'status' => 503,
        'protocol' => '503_Maintenance',
        'title' => 'Sedang Maintenance',
        'desc' => 'Server sedang dalam perawatan. Silakan kembali beberapa saat lagi.',
        'icon' => 'wrench',
        'dot' => 'MAINTENANCE',
        'accent' => '#f97316',
        'accent2' => '#fb923c',
        'meta_t' => 'Maintenance | MEeL',
        'meta_d' => 'MEeL sedang dalam perawatan.',
    ],
    'storage' => [
        'status' => 503,
        'protocol' => '503_Storage_Unavailable',
        'title' => 'Storage Belum Siap',
        'desc' => 'Volume penyimpanan media belum ter-mount di server. Halaman ini akan kembali normal otomatis setelah storage tersedia.',
        'icon' => 'hard-drive-download',
        'dot' => 'OFFLINE',
        'accent' => '#f97316',
        'accent2' => '#fbbf24',
        'meta_t' => 'Storage Belum Siap | MEeL',
        'meta_d' => 'Penyimpanan media belum ter-mount. Coba lagi beberapa saat lagi.',
    ],
    'server_error' => [
        'status' => 500,
        'protocol' => '500_Server_Error',
        'title' => 'Kesalahan Server',
        'desc' => 'Terjadi kesalahan saat memproses permintaan Anda. Silakan coba lagi beberapa saat lagi.',
        'icon' => 'server-crash',
        'dot' => 'ERROR',
        'accent' => '#ef4444',
        'accent2' => '#f87171',
        'meta_t' => 'Kesalahan Server | MEeL',
        'meta_d' => 'Terjadi kesalahan server. Silakan coba lagi.',
    ],
];

$code = (isset($_GET['code']) && is_string($_GET['code'])) ? $_GET['code'] : 'not_found';
if (!isset($types[$code])) {
    $code = 'not_found';
}
$type = $types[$code];
if (!headers_sent()) {
    http_response_code($type['status']);
}

$modules = [
    'video' => ['accent' => '#ef4444', 'accent2' => '#f87171', 'label' => 'Video Library', 'back' => '../video/beranda'],
    'music' => ['accent' => '#f97316', 'accent2' => '#fb923c', 'label' => 'Music Library', 'back' => '../music/beranda'],
    'books' => ['accent' => '#eab308', 'accent2' => '#facc15', 'label' => 'Book Library', 'back' => '../books/beranda'],
    'drive' => ['accent' => '#3b82f6', 'accent2' => '#60a5fa', 'label' => 'Drive', 'back' => '../drive/beranda'],
    'admin' => ['accent' => '#a855f7', 'accent2' => '#c084fc', 'label' => 'Dasbor Admin', 'back' => '../admin/beranda'],
    'profile' => ['accent' => '#22d3ee', 'accent2' => '#67e8f9', 'label' => 'Profil', 'back' => '../profile/'],
];

$module = null;
$ref_path = '';
if (isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '') {
    $ref = $_SERVER['HTTP_REFERER'];
    if (parse_url($ref, PHP_URL_HOST) === ($_SERVER['HTTP_HOST'] ?? '')) {
        $ref_path = parse_url($ref, PHP_URL_PATH) ?? '';
        $rel_path = ltrim(substr($ref_path, strlen($meel_base)), '/');
        $seg = explode('/', $rel_path)[0] ?? '';
        if (isset($modules[$seg])) {
            $module = $modules[$seg];
            $module['key'] = $seg;
        }
    }
}

$accent = $module['accent'] ?? $type['accent'];
$accent2 = $module['accent2'] ?? $type['accent2'];
$reason = (isset($_GET['reason']) && is_string($_GET['reason'])) ? htmlspecialchars(trim($_GET['reason'])) : '';
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= htmlspecialchars($type['meta_d']) ?>">
    <meta property="og:title" content="<?= htmlspecialchars($type['meta_t']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($type['meta_d']) ?>">
    <title><?= htmlspecialchars($type['meta_t']) ?></title>
<?php
$_META_TITLE = $type['meta_t'];
$_META_DESC = $type['meta_d'];
include __DIR__ . '/../partials/link.php';
$scripts_root = '../';
include __DIR__ . '/../partials/scripts.php';
?>
    <link rel="stylesheet" href="<?= $meel_base ?>/assets/css/err/err.css?v=<?= @filemtime(__DIR__ . '/../assets/css/err/err.css') ?>">
    <link rel="stylesheet" href="<?= $meel_base ?>/assets/css/shared/light-theme.css?v=<?= @filemtime(__DIR__ . '/../assets/css/shared/light-theme.css') ?>">
    <style>
/* reference build: MEeL-C5H9NO2 [355612b2e1204125] */

        :root {
            --acc: <?= $accent ?>;
            --acc2: <?= $accent2 ?>;
            --acc-a04: <?= meel_err_alpha($accent, 10) ?>;
            --acc-a10: <?= meel_err_alpha($accent, 26) ?>;
            --acc-a12: <?= meel_err_alpha($accent, 31) ?>;
            --acc-a20: <?= meel_err_alpha($accent, 51) ?>;
            --acc-a30: <?= meel_err_alpha($accent, 77) ?>;
            --acc-a45: <?= meel_err_alpha($accent, 115) ?>;
            --acc-a15shadow: <?= meel_err_alpha($accent, 38) ?>;
            --acc-a25shadow: <?= meel_err_alpha($accent, 64) ?>;
            --acc-a45shadow: <?= meel_err_alpha($accent, 115) ?>;

            /* Tinta aksen untuk teks & ikon — mode gelap = warna asli. */
            --acc-text: <?= $accent ?>;
            --acc2-text: <?= $accent2 ?>;
        }

        /* Mode terang: tinta aksen digelapkan agar kontras di latar #fafafa.
           50% ke hitam -> rasio terendah 5.11:1 (seluruh palet lolos AA). */
        html[data-theme="light"] {
            --acc-text: <?= meel_err_shade($accent) ?>;
            --acc2-text: <?= meel_err_shade($accent2) ?>;
        }

    </style>
</head>

<body class="bg-[#05070c] text-slate-300 min-h-screen flex flex-col justify-between bg-grid relative overflow-hidden">

    <div class="absolute inset-0 pointer-events-none z-0">
        <div class="absolute top-1/4 left-1/3 -translate-x-1/2 w-[500px] h-[500px] rounded-full blur-[140px]" style="background: var(--acc-a10)"></div>
        <div class="absolute bottom-1/4 right-1/3 translate-x-1/2 w-[400px] h-[400px] rounded-full blur-[120px]" style="background: var(--acc-a10)"></div>
    </div>

    <div class="err-scanlines absolute inset-0 pointer-events-none opacity-5 z-0" style="background: linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(255, 0, 0, 0.06), rgba(0, 255, 0, 0.02), rgba(0, 0, 255, 0.06)); background-size: 100% 4px, 6px 100%;"></div>

    <main class="relative z-10 flex-grow flex items-center justify-center p-6">
        <div class="max-w-md w-full glass-panel rounded-3xl p-8 text-center relative overflow-hidden">

            <div class="absolute top-0 left-0 right-0 h-[2px]"
                style="background: linear-gradient(to right, transparent, var(--acc), transparent)"></div>

            <div class="relative inline-block mb-8 mt-4">
                <div class="absolute inset-0 icon-ring rounded-full animate-pulse"></div>
                <div class="relative w-24 h-24 bg-slate-900/80 rounded-2xl flex items-center justify-center mx-auto icon-box">
                    <i data-lucide="<?= $type['icon'] ?>" class="w-12 h-12 glow-effect animate-pulse" style="color: var(--acc-text)"></i>
                </div>
            </div>

            <p class="text-[10px] font-bold uppercase tracking-[0.5em] mb-3" style="color: var(--acc2-text)">
                Error Protocol :: <?= $type['protocol'] ?>
            </p>

            <h1 class="text-3xl sm:text-4xl font-black tracking-tight mb-4 text-white uppercase glitch-text">
                <?= htmlspecialchars($type['title']) ?>
            </h1>

            <p class="text-slate-400 text-sm leading-relaxed mb-6 max-w-xs mx-auto">
                <?= htmlspecialchars($type['desc']) ?>
            </p>

            <?php if ($reason !== ''): ?>
                <div class="mb-6 mx-auto max-w-xs text-left text-[10px] leading-relaxed text-slate-500 font-mono border border-white/10 rounded-xl px-4 py-3">
                    <span style="color: var(--acc2-text)">alasan:</span> <?= $reason ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col gap-3 mb-4">
                <a href="<?= htmlspecialchars($meel_base . '/') ?>"
                    class="group relative w-full inline-flex items-center justify-center gap-3 px-8 py-4 back-btn text-white font-bold uppercase tracking-widest text-xs rounded-2xl">
                    <i data-lucide="home" class="w-4 h-4 transition-transform duration-300 group-hover:-translate-y-1"></i>
                    Ke Halaman Utama
                </a>
            </div>

            <div class="pt-4 border-t border-white/10 text-[9px] text-slate-500 font-mono tracking-wider flex justify-between items-center px-2">
                <span><?= $module ? 'SRC: ' . htmlspecialchars($module['key']) : 'SYS_URI: ' . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'UNKNOWN') ?></span>
                <span class="font-semibold animate-pulse" style="color: var(--acc-text)">● <?= $type['dot'] ?></span>
            </div>

        </div>
    </main>

    <div class="w-full text-center pb-6 relative z-20">
        <?php include __DIR__ . '/../partials/footer.php'; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                lucide.createIcons();
            } else {
                var checkLucide = setInterval(function() {
                    if (typeof lucide !== 'undefined' && lucide.createIcons) {
                        lucide.createIcons();
                        clearInterval(checkLucide);
                    }
                }, 100);
                setTimeout(function() { clearInterval(checkLucide); }, 5000);
            }
        });
    </script>
</body>

</html>
