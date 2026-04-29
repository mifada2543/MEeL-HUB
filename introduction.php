<?php
include 'auth/config.php';
$back_url = '../index.php';

if (isset($_SERVER['HTTP_REFERER']) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    $host = $_SERVER['HTTP_HOST'];

    // 1. Pastikan referer berasal dari domain yang sama (MEeL Server)
    if (parse_url($ref, PHP_URL_HOST) === $host) {

        // 2. Ambil hanya bagian path-nya saja (misal: /profile/edit.php)
        $ref_path = parse_url($ref, PHP_URL_PATH);
        $excluded_pages = ['profile_edit.php', 'index.php'];

        $should_exclude = false;
        foreach ($excluded_pages as $page) {
            if (strpos($ref_path, $page) !== false) {
                $should_exclude = true;
                break;
            }
        }

        if (!$should_exclude) {
            $back_url = $ref;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Panduan Penggunaan</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <script src="assets/js/lucide.js"></script>

    <style>
        body {
            background-color: #0b0e14;
            color: #e5e7eb;
        }

        /* Animasi masuk untuk konten */
        .section-enter {
            animation: fadeIn 0.4s ease-out forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Animasi klik (denyut) pada navbar */
        .nav-transition {
            transform: scale(0.98);
            opacity: 0.8;
            transition: all 0.2s ease-in-out;
        }

        /* Menyembunyikan scrollbar agar rapi */
        .no-scrollbar::-webkit-scrollbar {
            display: none;
        }
    </style>
</head>

<body class="min-h-screen flex flex-col md:flex-row overflow-hidden">

    <aside id="nav-container" class="w-full md:w-72 border-r border-white/5 bg-[#0b0e14]/90 backdrop-blur-md z-20 flex flex-col h-auto md:h-screen transition-transform">
        <div class="p-6 border-b border-white/5">
            <a href="<?= htmlspecialchars($back_url) ?>" class="flex items-center gap-3 group">
                <div class="w-10 h-10 bg-white/5 rounded-xl flex items-center justify-center group-hover:bg-blue-600 transition-colors">
                    <i data-lucide="home" class="w-5 h-5 text-gray-400 group-hover:text-white"></i>
                </div>
                <div>
                    <h1 class="font-black tracking-tighter text-white uppercase text-lg leading-tight">MEeL <span class="text-blue-500">Guide</span></h1>
                    <p class="text-[9px] text-gray-500 tracking-widest uppercase">Pusat Bantuan</p>
                </div>
            </a>
        </div>

        <nav class="flex-1 overflow-y-auto no-scrollbar p-4 flex md:flex-col gap-2">

            <button onclick="showGuide('video', this)" class="nav-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all bg-white/10 border-l-4 border-red-500 text-white shadow-lg shadow-black/20">
                <i data-lucide="play-square" class="w-5 h-5 text-red-500"></i>
                <span class="text-sm font-bold uppercase tracking-wide">Video</span>
            </button>

            <button onclick="showGuide('music', this)" class="nav-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all text-gray-400 hover:bg-white/5 hover:text-gray-200 border-l-4 border-transparent">
                <i data-lucide="music" class="w-5 h-5 text-orange-500"></i>
                <span class="text-sm font-bold uppercase tracking-wide">Music</span>
            </button>

            <button onclick="showGuide('books', this)" class="nav-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all text-gray-400 hover:bg-white/5 hover:text-gray-200 border-l-4 border-transparent">
                <i data-lucide="book-open" class="w-5 h-5 text-emerald-500"></i>
                <span class="text-sm font-bold uppercase tracking-wide">Books</span>
            </button>
        </nav>
    </aside>

    <main class="flex-1 h-screen overflow-y-auto bg-black/20 p-6 md:p-12 relative">

        <div id="guide-video" class="guide-section section-enter max-w-4xl mx-auto">
            <div class="mb-8">
                <h2 class="text-3xl font-black uppercase tracking-tighter text-white mb-2">Panduan <span class="text-red-500">Video</span></h2>
                <p class="text-gray-400 text-sm">Kenali cara bernavigasi di MEeL Video dengan Plyr.</p>
            </div>
            <div class="rounded-3xl border border-white/10 overflow-hidden bg-gray-900/50 p-2 shadow-2xl">
                <img src="assets/img/video0.png" alt="Pengenalan Index" onclick="openLightbox(this.src)" loading="lazy" class="w-full rounded-2xl opacity-90 hover:opacity-100 transition-opacity">
                <div class="text-gray-400 text-sm">
                    <p>Menu HUB: Kembali ke HUB MEeL</p>
                    <p>Search: Untuk mencari sebuah lagu</p>
                    <p>Navbar: Untuk berpindah antar halaman (Video, Books, FikaAI)</p>
                    <p>Video: Pilihan video yang tersedia</p>
                    <p>Muat Lebih Banyak: Memuat video lebih banyak</p>
                </div><br><br>
                <img src="assets/img/video1.png" alt="Pengenalan Watch" onclick="openLightbox(this.src)" loading="lazy" class="w-full rounded-2xl opacity-90 hover:opacity-100 transition-opacity">
                <div class="text-gray-400 text-sm">
                    <p>Menu Index(video): Kembali ke Index</p>
                    <p>Search: Untuk mencari sebuah video</p>
                    <p>Navbar: Untuk berpindah antar halaman (Video, Books, FikaAI)</p><br>
                    <p>Video player: Tempat pemutar video</p>
                    <p>Kontrol keyboard:</p>
                    <p>0 hingga 9 Cari dari 0 hingga 90% secara berturut-turut</p>
                    <p>space & K Alihkan pemutaran</p>
                    <p>← Mundur dengan opsi seekTime</p>
                    <p>→ Maju dengan opsi seekTime</p>
                    <p>↑ Tambah suara</p>
                    <p>↓ Kurangi suara</p>
                    <p>M Alihkan bisu</p>
                    <p>F Alihkan layar penuh</p>
                    <p>C Alihkan caption</p>
                    <p>L Alihkan pengulangan</p>
                </div>
            </div>
        </div>

        <div id="guide-music" class="guide-section hidden max-w-4xl mx-auto">
            <div class="mb-8">
                <h2 class="text-3xl font-black uppercase tracking-tighter text-white mb-2">Panduan <span class="text-orange-500">Music</span></h2>
                <p class="text-gray-400 text-sm">Kenali cara bernavigasi di MEeL Music dengan Plyr.</p>
            </div>
            <div class="rounded-3xl border border-white/10 overflow-hidden bg-gray-900/50 p-2 shadow-2xl">
                <img src="assets/img/music0.png" alt="Pengenalan Index" onclick="openLightbox(this.src)" loading="lazy" class="w-full rounded-2xl opacity-90 hover:opacity-100 transition-opacity">
                <div class="text-gray-400 text-sm">
                    <p>Menu HUB: Kembali ke HUB MEeL</p>
                    <p>Search: Untuk mencari sebuah lagu</p>
                    <p>Navbar: Untuk berpindah antar halaman (Video, Books, FikaAI)</p>
                    <p>Music: Pilihan music yang tersedia</p>
                </div><br><br>
                <img src="assets/img/music1.png" alt="Pengenalan Watch" onclick="openLightbox(this.src)" loading="lazy" class="w-full rounded-2xl opacity-90 hover:opacity-100 transition-opacity">
                <div class="text-gray-400 text-sm">
                    <p>Menu Index(music): Kembali ke Index</p>
                    <p>Search: Untuk mencari sebuah lagu</p>
                    <p>Navbar: Untuk berpindah antar halaman (Video, Books, FikaAI)</p><br>
                    <p>Music player: Tempat pemutar musik</p>
                    <p>Kontrol keyboard:</p>
                    <p>0 hingga 9 Cari dari 0 hingga 90% secara berturut-turut</p>
                    <p>space & K Alihkan pemutaran</p>
                    <p>← Mundur dengan opsi seekTime</p>
                    <p>→ Maju dengan opsi seekTime</p>
                    <p>↑ Tambah suara</p>
                    <p>↓ Kurangi suara</p>
                    <p>M Alihkan bisu</p>
                    <p>L Alihkan pengulangan</p>
                </div>
            </div>
        </div>

        <div id="guide-books" class="guide-section hidden max-w-4xl mx-auto">
            <div class="mb-8">
                <h2 class="text-3xl font-black uppercase tracking-tighter text-white mb-2">Panduan <span class="text-emerald-500">Books</span></h2>
                <p class="text-gray-400 text-sm">Cara membaca PDF dan Manga dengan lancar di e-Library kita.</p>
            </div>
            <div class="rounded-3xl border border-white/10 overflow-hidden bg-gray-900/50 p-2 shadow-2xl">
                <img src="assets/img/id=Books.png" alt="Panduan Buku" loading="lazy" class="w-full rounded-2xl opacity-90 hover:opacity-100 transition-opacity">
            </div>
        </div>
        <div id="lightbox" class="fixed inset-0 z-[100] hidden flex items-center justify-center bg-black/95 backdrop-blur-sm p-4 md:p-10 transition-all duration-500 opacity-0">
            <button onclick="closeLightbox()" class="absolute top-6 right-6 text-white/50 hover:text-white transition-colors">
                <i data-lucide="x-circle" class="w-10 h-10"></i>
            </button>

            <img id="lightbox-img" src="" class="max-w-full max-h-full rounded-xl shadow-2xl transform scale-95 transition-transform duration-300">
        </div>
    </main>

    <script>
        lucide.createIcons();

        function showGuide(sectionId, btnElement) {
            // 1. Sembunyikan semua section konten
            const sections = document.querySelectorAll('.guide-section');
            sections.forEach(sec => {
                sec.classList.add('hidden');
                sec.classList.remove('section-enter');
            });

            // 2. Reset semua warna tombol navbar ke abu-abu
            const buttons = document.querySelectorAll('.nav-btn');
            buttons.forEach(btn => {
                btn.className = "nav-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all text-gray-400 hover:bg-white/5 hover:text-gray-200 border-l-4 border-transparent";
            });

            // 3. Tampilkan section yang diklik dan jalankan animasi CSS
            const targetSection = document.getElementById('guide-' + sectionId);
            targetSection.classList.remove('hidden');

            // Trik kecil memicu reflow agar animasi CSS jalan ulang setiap kali diklik
            void targetSection.offsetWidth;
            targetSection.classList.add('section-enter');

            // 4. Ubah warna tombol yang sedang aktif sesuai kategori (Ambil warna dari ikon)
            let borderColor = 'border-white';
            if (sectionId === 'video') borderColor = 'border-red-500';
            if (sectionId === 'music') borderColor = 'border-orange-500';
            if (sectionId === 'books') borderColor = 'border-blue-500';

            btnElement.className = `nav-btn w-full flex items-center gap-3 px-4 py-3 rounded-xl text-left transition-all bg-white/10 border-l-4 ${borderColor} text-white shadow-lg shadow-black/20`;

            // 5. Animasi denyut halus pada navbar saat tombol ditekan
            const navContainer = document.getElementById('nav-container');
            navContainer.classList.add('nav-transition');
            setTimeout(() => {
                navContainer.classList.remove('nav-transition');
            }, 200);
        }

        function openLightbox(src) {
            const lightbox = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');

            img.src = src;
            lightbox.classList.remove('hidden');

            // Trigger animasi muncul
            setTimeout(() => {
                lightbox.classList.add('opacity-100');
                img.classList.remove('scale-95');
                img.classList.add('scale-100');
            }, 10);
        }

        function closeLightbox() {
            const lightbox = document.getElementById('lightbox');
            const img = document.getElementById('lightbox-img');

            lightbox.classList.remove('opacity-100');
            img.classList.add('scale-95');

            setTimeout(() => {
                lightbox.classList.add('hidden');
            }, 300);
        }

        // Tambahan: Tutup dengan tombol ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === "Escape") closeLightbox();
        });
    </script>
</body>

</html>