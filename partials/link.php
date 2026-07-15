<?php
// ── Open Graph & Twitter Card ──────────────────────────────────────────────
// Gunakan fungsi detectProtocol (dari modules/helpers.php) jika tersedia,
// fallback ke deteksi manual untuk kompatibilitas
if (function_exists('detectProtocol')) {
    $_og_proto = detectProtocol();
} else {
    $_og_proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https'
        : (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https'
        : (!empty($_SERVER['HTTP_CF_VISITOR']) && ($cf = @json_decode($_SERVER['HTTP_CF_VISITOR'], true)) && !empty($cf['scheme']) && $cf['scheme'] === 'https' ? 'https'
        : 'http'));
}
$_og_host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_og_base  = $_og_proto . "://" . $_og_host;
$_og_url   = $_og_base . $_SERVER['REQUEST_URI'];
$_og_img   = $_og_base . "/assets/MEeL.png";
?>
<meta property="og:image" content="<?= htmlspecialchars($_og_img) ?>">
<meta property="og:url" content="<?= htmlspecialchars($_og_url) ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<link rel="manifest" href="../assets/manifest.json">
<link rel="icon" type="image/png" href="../assets/MEeL.png">
<link href="../assets/css/tailwind.min.css" rel="stylesheet">
<script src="../assets/js/lucide.js"></script>
<script>/* Suppress JQMIGRATE warnings from bundled third-party libs */
(function(){var ow=console.warn;console.warn=function(){if(arguments[0]&&typeof arguments[0]==='string'&&arguments[0].startsWith('JQMIGRATE'))return;return ow.apply(console,arguments)};})();</script>