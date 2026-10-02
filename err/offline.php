<?php /* reference build: MEeL-C4H9NO2 [9f12ede6c379eda8] */ ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline — MEeL</title>
    <meta name="theme-color" content="#05070c">
    <script>
        /* Halaman offline sengaja tanpa partial/head.php, jadi penentuan tema
           harus mandiri (inline, tanpa dependensi) agar tetap jalan saat
           koneksi mati. Sama dengan logika partials/head.php: default gelap. */
        (function() {
            var t = null;
            try { t = localStorage.getItem('meel_theme'); } catch (e) {}
            var light = (t === 'light');
            document.documentElement.setAttribute('data-theme', light ? 'light' : 'dark');
            if (light) {
                document.documentElement.classList.remove('dark');
            } else {
                document.documentElement.classList.add('dark');
            }
            var m = document.querySelector('meta[name="theme-color"]');
            if (m) { m.setAttribute('content', light ? '#fafafa' : '#05070c'); }
        })();
    </script>
    <link rel="stylesheet" href="../assets/css/err/offline.css?v=<?= filemtime(__DIR__ . '/../assets/css/err/offline.css') ?>">
</head>
<body>
    <div class="container">
        <span class="icon">📡</span>
        <div class="status">
            <span class="dot"></span>
            Offline Mode
        </div>
        <h1>Koneksi Terputus</h1>
        <p>
            Kamu sedang offline. Halaman yang diminta tidak tersedia di cache.<br>
            Silakan periksa koneksi internet dan coba lagi.
        </p>
        <a href="javascript:location.reload()" class="btn btn-primary">Coba Lagi</a>
        <a href="../" class="btn" style="margin-left:0.5rem">Ke Halaman Utama</a>
        <div class="footer">MEeL &mdash; Media Hub Platform</div>
    </div>
</body>
</html>
