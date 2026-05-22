<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../auth/activity_logger.php';
require_once '../auth/MediaLibrary.php';
include '../helpers.php';

// ── Validasi ID ──────────────────────────────────────────────────────────────
if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$repo = new BookRepository($conn);
$book = $repo->getBookById((int)$_GET['id']);

if (!$book) {
    die("
        <div style='background:#0b0e14;color:#ef4444;height:100vh;display:flex;
                    flex-direction:column;align-items:center;justify-content:center;font-family:sans-serif;'>
            <h1 style='font-size:4rem;font-weight:900;'>Mohon maaf</h1>
            <p style='text-transform:uppercase;letter-spacing:4px;'>MEeL</p>
            <p style='color:#4b5563;margin-top:10px;'>Alasan: Buku tidak ditemukan atau tidak tersedia.</p>
        </div>
    ");
}

// ── Sanitasi chapter — cegah path traversal ─────────────────────────────────
// Hanya izinkan karakter alfanumerik, spasi, strip, underscore, titik
$raw_chapter     = $_GET['ch'] ?? '';
$current_chapter = preg_replace('/[^a-zA-Z0-9 _.\-]/', '', $raw_chapter);
// Pastikan tidak ada komponen '..' setelah sanitasi
if (str_contains($current_chapter, '..')) {
    $current_chapter = '';
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL Read | <?= htmlspecialchars($book['title']) ?></title>
    <link rel="icon" href="../assets/logo.png" type="image/x-icon">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #05070a;
            color: white;
        }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #05070a; }
        ::-webkit-scrollbar-thumb { background: #1e293b; border-radius: 10px; }

        /* ── Lazy-load placeholder ── */
        .manga-img {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            display: block;
            /* Placeholder abu-abu sebelum gambar dimuat */
            background: #0f1318;
            min-height: 200px;
            transition: opacity 0.3s ease;
        }

        /* Gambar yang belum masuk viewport disembunyikan via opacity */
        .manga-img.lazy {
            opacity: 0;
        }

        .manga-img.loaded {
            opacity: 1;
        }
    </style>
</head>

<body class="flex flex-col h-screen">
    <!-- Navbar -->
    <div class="bg-[#0b0e14]/80 backdrop-blur-md border-b border-gray-800 p-4 flex justify-between items-center sticky top-0 z-50">
        <div class="flex items-center gap-4">
            <a href="index.php" class="p-2 hover:bg-gray-800 rounded-xl transition">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
            <div>
                <h1 class="text-sm font-bold truncate max-w-[200px] md:max-w-md">
                    <?= htmlspecialchars($book['title']) ?>
                </h1>
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-widest">
                    <?= htmlspecialchars($book['type']) ?> Mode
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="window.location.reload()"
                    class="p-2 hover:bg-gray-800 rounded-xl transition">
                <i data-lucide="refresh-cw" class="w-4 h-4 text-gray-400"></i>
            </button>
        </div>
    </div>

    <!-- Konten -->
    <div class="flex-grow overflow-y-auto" id="scroll-container">
        <?php if ($book['type'] === 'pdf'): ?>
            <!-- ── MODE PDF ── -->
            <div class="w-full h-full bg-gray-900">
                <iframe
                    src="./upload/pdf/<?= htmlspecialchars($book['path_folder']) ?>#toolbar=0"
                    class="w-full h-[calc(100vh-64px)] border-none">
                </iframe>
            </div>

        <?php else: ?>
            <!-- ── MODE MANGA ── -->
            <div class="py-4 space-y-0" id="manga-container">

                <?php
                // ── Chapter selector ─────────────────────────────────────────
                $book_id   = (int)$book['id'];
                $ch_base   = "upload/manga/" . $book['path_folder'];

                if ($book['has_chapters'] == 1):
                    $chapters = array_filter(glob($ch_base . '/*'), 'is_dir');
                    natsort($chapters);
                ?>
                    <div class="max-w-4xl mx-auto mb-6 px-4">
                        <select onchange="location.href='?id=<?= $book_id ?>&ch=' + encodeURIComponent(this.value)"
                                class="w-full bg-gray-900 text-sm p-3 rounded-2xl border border-gray-800 outline-none">
                            <option value="">-- Pilih Chapter --</option>
                            <?php foreach ($chapters as $ch):
                                $ch_name  = basename($ch);
                                $selected = ($current_chapter === $ch_name) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($ch_name, ENT_QUOTES) . "' $selected>"
                                   . htmlspecialchars($ch_name) . "</option>";
                            endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php
                // ── Tentukan path gambar ─────────────────────────────────────
                $target_path = $ch_base;

                if ($book['has_chapters'] == 1) {
                    if (empty($current_chapter)) {
                        // Belum pilih chapter — tampilkan prompt
                        echo '<div class="py-20 text-center flex flex-col items-center gap-4">
                                <i data-lucide="book-open" class="w-12 h-12 text-blue-500 opacity-50"></i>
                                <p class="text-gray-500 font-bold uppercase tracking-widest text-xs">
                                    Silakan pilih chapter untuk mulai membaca
                                </p>
                              </div>';
                        $target_path = null; // Tandai agar tidak render gambar
                    } else {
                        $target_path .= '/' . $current_chapter;
                    }
                }

                // ── Render gambar dengan data-src (Intersection Observer) ────
                if ($target_path !== null && is_dir($target_path)) {
                    $images = glob($target_path . '/*.{jpg,jpeg,png,webp,JPG,PNG}', GLOB_BRACE);
                    natsort($images);

                    if ($images && count($images) > 0) {
                        foreach ($images as $img) {
                            $safe_src = htmlspecialchars($img);
                            // Gambar pertama dimuat langsung (eager), sisanya lazy via IO
                            $is_first = ($img === reset($images));
                            if ($is_first) {
                                echo '<img src="' . $safe_src . '" '
                                   . 'class="manga-img loaded" '
                                   . 'alt="page" '
                                   . 'decoding="async">' . "\n";
                            } else {
                                echo '<img data-src="' . $safe_src . '" '
                                   . 'src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" '
                                   . 'class="manga-img lazy" '
                                   . 'alt="page" '
                                   . 'decoding="async">' . "\n";
                            }
                        }
                    } else {
                        echo '<p class="text-center py-20 text-gray-500">'
                           . 'Tidak ada gambar di: '
                           . htmlspecialchars($current_chapter ?: 'Folder Utama')
                           . '</p>';
                    }
                }
                ?>

                <?php
                // ── Chapter selector bawah (hanya jika ada chapter) ──────────
                if ($book['has_chapters'] == 1 && !empty($chapters)):
                ?>
                    <div class="max-w-4xl mx-auto mt-6 mb-4 px-4">
                        <select onchange="location.href='?id=<?= $book_id ?>&ch=' + encodeURIComponent(this.value)"
                                class="w-full bg-gray-900 text-sm p-3 rounded-2xl border border-gray-800 outline-none">
                            <option value="">-- Pilih Chapter --</option>
                            <?php foreach ($chapters as $ch):
                                $ch_name  = basename($ch);
                                $selected = ($current_chapter === $ch_name) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($ch_name, ENT_QUOTES) . "' $selected>"
                                   . htmlspecialchars($ch_name) . "</option>";
                            endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>

            </div><!-- /manga-container -->
        <?php endif; ?>

        <?php include '../partials/footer.php'; ?>
    </div><!-- /scroll-container -->

    <script>
        lucide.createIcons();

        // ── Intersection Observer — lazy load gambar manga ───────────────────
        (function () {
            const lazyImages = document.querySelectorAll('img.manga-img.lazy');
            if (!lazyImages.length) return;

            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;

                    const img = entry.target;
                    const src = img.dataset.src;
                    if (!src) return;

                    img.src = src;
                    img.onload  = function () { img.classList.add('loaded'); };
                    img.onerror = function () { img.classList.add('loaded'); }; // Tetap tampil meski error
                    observer.unobserve(img);
                });
            }, {
                // Mulai muat 400px sebelum gambar masuk viewport
                rootMargin: '400px 0px',
                threshold: 0
            });

            lazyImages.forEach(function (img) {
                observer.observe(img);
            });
        })();
    </script>
</body>

</html>