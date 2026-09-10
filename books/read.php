<?php
require_once '../modules/core/helpers.php';
require_once '../auth/auth.php';
require_once '../auth/config.php';

require_once '../modules/media/MediaLibrary.php';

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    header("Location: ..");
    exit();
}

$repo = new BookRepository($conn);
$book = $repo->getBookById((int)$_GET['id']);

if (!$book) {
    http_response_code(404);
    $_GET['code'] = 'not_found';
    include '../err/index.php';
    exit;
}

$raw_chapter     = $_GET['ch'] ?? '';
$current_chapter = basename($raw_chapter);

if ($current_chapter === '..') {
    $current_chapter = '';
}

$book_id = (int)$book['id'];
$fs_base    = meel_media_base_path('books');
$total_pages = 0;
if ($book['type'] !== 'pdf') {
    $ch_base = "upload/manga/" . $book['path_folder'];
    $ch_fs   = $fs_base . '/manga/' . $book['path_folder'];
    $target_path = $ch_fs;

    if ($book['has_chapters'] == 1 && !empty($current_chapter)) {
        $target_path .= '/' . $current_chapter;
    }

    if ($book['has_chapters'] == 0 || ($book['has_chapters'] == 1 && !empty($current_chapter))) {
        if (is_dir($target_path)) {
            $images = _scanImages($target_path);
            $total_pages = count($images);
        }
    }
}

function _scanImages(string $dir): array {
    if (!is_dir($dir)) return [];
    $extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'JPG', 'PNG'];
    $files = scandir($dir);
    if ($files === false) return [];
    $images = [];
    foreach ($files as $f) {
        if ($f === '.' || $f === '..') continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $extensions, true)) {
            $images[] = $dir . '/' . $f;
        }
    }
    return $images;
}

function _scanSubdirs(string $dir): array {
    if (!is_dir($dir)) return [];
    $items = scandir($dir);
    if ($items === false) return [];
    $dirs = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $dirs[] = $path;
        }
    }
    return $dirs;
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <meta property="og:title" content="<?= htmlspecialchars($book['title']) ?> — MEeL Read">
    <meta property="og:description" content="Baca <?= htmlspecialchars($book['title']) ?> di MEeL Books - Platform Media Hub Pribadi.">
    <title>MEeL Read | <?= htmlspecialchars($book['title']) ?></title>
    <?php include '../partials/link.php'; ?>
    <?php foreach (require __DIR__ . '/../assets/css/books/manifest.php' as $__f): ?>
    <link rel="stylesheet" href="../assets/css/books/<?= $__f ?>?v=<?= filemtime(__DIR__ . '/../assets/css/books/' . $__f) ?>">
    <?php endforeach; ?>
    <link rel="stylesheet" href="../assets/css/books/read/main.css">
    <div id="reader-data"
         data-book-id="<?= (int)$book['id'] ?>"
         data-book-title="<?= htmlspecialchars($book['title'], ENT_QUOTES) ?>"
         data-book-type="<?= htmlspecialchars($book['type'], ENT_QUOTES) ?>"
         data-chapter="<?= htmlspecialchars($current_chapter, ENT_QUOTES) ?>"
         data-total-pages="<?= (int)$total_pages ?>"
         data-base-url="<?= base_url('/books/read-pdf?id=' . (int)$book['id']) ?>"
         style="display:none;"></div>
</head>

<body class="flex flex-col min-h-screen">

    
    <div class="reader-nav sticky top-0 z-50 px-3 sm:px-6 h-14 flex items-center justify-between transition-all duration-300" id="reader-navbar">
        <div class="flex items-center gap-3 min-w-0 flex-1">
            <a href="beranda" class="p-2 hover:bg-white/[.06] rounded-xl transition-all flex-shrink-0 group">
                <i data-lucide="arrow-left" class="w-4 h-4 text-gray-500 group-hover:text-green-500 transition-colors"></i>
            </a>
            <div class="min-w-0 flex-1">
                <h1 class="text-sm font-bold truncate text-white/90" title="<?= htmlspecialchars($book['title']) ?>">
                    <?= htmlspecialchars($book['title']) ?>
                </h1>
                <div class="flex items-center gap-1.5 mt-0.5 min-w-0">
                    <span class="text-[9px] text-gray-600 uppercase font-black tracking-widest flex-shrink-0">
                        <?= htmlspecialchars($book['type']) ?>
                    </span>
                    <?php if ($book['has_chapters'] == 1 && !empty($current_chapter)): ?>
                        <span class="text-[9px] text-gray-700 flex-shrink-0">•</span>
                        <div class="text-[9px] text-green-500/60 uppercase font-bold tracking-wider min-w-0 line-clamp-1 sm:line-clamp-2">
                            <?= htmlspecialchars($current_chapter) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($total_pages > 0): ?>
                        <span class="text-[9px] text-gray-700 flex-shrink-0 hidden sm:inline">•</span>
                        <span class="text-[9px] text-gray-600 uppercase tracking-wider flex-shrink-0 hidden sm:inline">
                            <?= $total_pages ?> halaman
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <?php if ($book['type'] !== 'pdf'): ?>
                <span class="text-[9px] text-gray-700 uppercase tracking-widest hidden sm:block" id="page-indicator-nav">
                    Halaman <span id="nav-current-page">-</span>
                </span>
            <?php endif; ?>
            <button onclick="window.location.reload()"
                    class="p-2 hover:bg-white/[.06] rounded-xl transition-all group">
                <i data-lucide="refresh-cw" class="w-3.5 h-3.5 text-gray-600 group-hover:text-green-500 transition-colors"></i>
            </button>
        </div>
    </div>

    
    <div class="ch-overlay" id="chOverlay"></div>

    
    <div class="flex-grow overflow-y-auto" id="scroll-container">
        <?php if ($book['type'] === 'pdf'): ?>
            
            <?php
            $pdf_path   = __DIR__ . '/upload/pdf/' . basename($book['path_folder']);
            $pdf_size   = is_file($pdf_path) ? filesize($pdf_path) : 0;
            $pdf_size_f = $pdf_size > 1048576
                ? number_format($pdf_size / 1048576, 1) . ' MB'
                : number_format($pdf_size / 1024, 1) . ' KB';
            ?>
            <div class="pdf-view">
                
                <div class="pdf-body pdf-iframe-wrap" id="readPdfBody">
                    <iframe src="<?= base_url('/books/read-pdf?id=' . (int)$book['id'] . '&raw=1') ?>"
                            id="pdfFrame"
                            title="PDF Viewer"
                            style="width:100%;height:100%;border:none;display:block;"></iframe>
                </div>

                
                <div class="pdf-body pdf-mobile-card">
                    <div class="pdf-card-inner">
                        <div class="pdf-card-icon">
                            <i data-lucide="file-text" class="w-10 h-10 text-purple-400"></i>
                        </div>
                        <h2 class="pdf-card-title"><?= htmlspecialchars($book['title']) ?></h2>
                        <p class="pdf-card-meta">Dokumen PDF &middot; <?= $pdf_size_f ?></p>
                        <a href="<?= base_url('/books/read-pdf?id=' . (int)$book['id']) ?>"
                           class="pdf-card-btn">
                            <i data-lucide="external-link" class="w-4 h-4"></i>
                            Buka PDF
                        </a>
                        <p class="pdf-card-hint">Akan dialihkan ke pembaca PDF</p>
                    </div>
                </div>

                
                <div class="pdf-info-bar">
                    <div class="pdf-info-left">
                        <span class="pdf-info-title"><?= htmlspecialchars($book['title']) ?></span>
                        <span class="pdf-info-meta">PDF &middot; <?= $pdf_size_f ?></span>
                    </div>
                    <a href="<?= base_url('/books/read-pdf?id=' . (int)$book['id']) ?>"
                       target="_blank" rel="noopener"
                       class="pdf-info-btn">
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                        Buka di Tab Baru
                    </a>
                </div>
            </div>

            <script>
            (function() {
                var frame = document.getElementById('pdfFrame');
                if (!frame) return;
                var timeout = setTimeout(function() {
                    window.location.href = '<?= base_url('/books/read-pdf?id=' . (int)$book['id']) ?>';
                }, 10000);
                frame.addEventListener('load', function() { clearTimeout(timeout); });
                frame.addEventListener('error', function() { clearTimeout(timeout); });
            })();
            </script>

        <?php else: ?>
            
            <div class="py-0 space-y-0" id="manga-container">
                <?php
                $ch_base   = "upload/manga/" . $book['path_folder'];
                $ch_fs     = $fs_base . '/manga/' . $book['path_folder'];

                if ($book['has_chapters'] == 1):
                    $chapters = _scanSubdirs($ch_fs);
                    natsort($chapters);
                    $ch_list = array_values(array_map('basename', $chapters));
                    $current_idx = array_search($current_chapter, $ch_list);
                    $prev_ch = ($current_idx !== false && $current_idx > 0) ? $ch_list[$current_idx - 1] : null;
                    $next_ch = ($current_idx !== false && $current_idx < count($ch_list) - 1) ? $ch_list[$current_idx + 1] : null;
                ?>
                    <?php if ($total_pages > 0 && !empty($current_chapter)): ?>
                    
                    <div class="max-w-4xl mx-auto px-4 mb-2 flex items-center justify-between gap-2">
                        <?php if ($prev_ch): ?>
                            <a href="?id=<?= $book_id ?>&ch=<?= urlencode($prev_ch) ?>"
                                class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                <i data-lucide="chevron-left" class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform"></i>
                                Sebelumnya
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                        <span class="text-[9px] text-gray-700 uppercase tracking-widest">
                            <?= $current_idx + 1 ?> / <?= count($ch_list) ?>
                        </span>

                        <?php if ($next_ch): ?>
                            <a href="?id=<?= $book_id ?>&ch=<?= urlencode($next_ch) ?>"
                                class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                Selanjutnya
                                <i data-lucide="chevron-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="sticky top-14 z-30 py-3 px-4 bg-gradient-to-b from-[#080a0f] to-transparent">
                        <div class="max-w-4xl mx-auto ch-dropdown" id="ch-dropdown-top">
                            <button type="button"
                                onclick="toggleChDropdown('top')"
                                class="ch-trigger">
                                <span class="truncate" title="<?= $current_chapter ? htmlspecialchars($current_chapter) : 'Pilih chapter' ?>"><?= $current_chapter ? htmlspecialchars($current_chapter) : '— Pilih Chapter —' ?></span>
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500 flex-shrink-0"></i>
                            </button>
                            <div id="ch-options-top" class="ch-options hidden">
                                <button onclick="goToChapter('')"
                                    class="ch-option <?= empty($current_chapter) ? 'active' : '' ?>">
                                    — Pilih Chapter —
                                </button>
                                <?php foreach ($chapters as $ch):
                                    $ch_name  = basename($ch);
                                    $active   = ($current_chapter === $ch_name) ? 'active' : '';
                                    $enc_name = htmlspecialchars($ch_name, ENT_QUOTES);
                                ?>
                                    <button onclick="goToChapter('<?= $enc_name ?>')"
                                        class="ch-option <?= $active ?>">
                                        <?= htmlspecialchars($ch_name) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php
                $target_path = $ch_fs;

                if ($book['has_chapters'] == 1) {
                    if (empty($current_chapter)) {
                        echo '<div class="max-w-4xl mx-auto px-4 py-16">
                                <div class="glass rounded-3xl p-12 sm:p-16 text-center border border-dashed border-white/[.06]">
                                    <div class="w-20 h-20 mx-auto mb-6 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                                        <i data-lucide="book-open" class="w-9 h-9 text-gray-700"></i>
                                    </div>
                                    <p class="text-gray-500 font-bold uppercase tracking-widest text-xs mb-2">
                                        Silakan pilih chapter untuk mulai membaca
                                    </p>
                                    <p class="text-[10px] text-gray-700 uppercase tracking-widest">
                                        ' . htmlspecialchars($book['title']) . '
                                    </p>
                                </div>
                              </div>';
                        $target_path = null;
                    } else {
                        $target_path .= '/' . $current_chapter;
                    }
                }

                if ($target_path !== null && is_dir($target_path)):
                    $images = _scanImages($target_path);
                    natsort($images);

                    if ($images && count($images) > 0):

                        $page_num = 0;
                        foreach ($images as $img):
                            $page_num++;
                            $url_img  = 'upload' . substr($img, strlen($fs_base));
                            $safe_src = htmlspecialchars($url_img);
                            $is_first = ($img === reset($images));
                        ?>
                            <?php if ($is_first): ?>
                                <img src="<?= $safe_src ?>"
                                    class="manga-img loaded"
                                    alt="Halaman <?= $page_num ?>"
                                    data-page="<?= $page_num ?>"
                                    decoding="async">
                            <?php else: ?>
                                <img data-src="<?= $safe_src ?>"
                                    src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
                                    class="manga-img lazy"
                                    alt="Halaman <?= $page_num ?>"
                                    data-page="<?= $page_num ?>"
                                    decoding="async">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <?php if ($book['has_chapters'] == 1 && !empty($chapters)): ?>
                            <div class="max-w-4xl mx-auto px-4 mt-4 mb-8 flex items-center justify-between gap-2">
                                <?php if ($prev_ch): ?>
                                    <a href="?id=<?= $book_id ?>&ch=<?= urlencode($prev_ch) ?>"
                                        class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                        <i data-lucide="chevron-left" class="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform"></i>
                                        Sebelumnya
                                    </a>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>
                                <a href="beranda"
                                    class="text-[9px] text-gray-700 hover:text-green-500 uppercase tracking-widest transition-colors">
                                    Kembali ke Library
                                </a>

                                <?php if ($next_ch): ?>
                                    <a href="?id=<?= $book_id ?>&ch=<?= urlencode($next_ch) ?>"
                                        class="flex items-center gap-2 px-4 py-2.5 bg-white/[.03] border border-white/[.06] rounded-xl text-[10px] font-bold uppercase tracking-widest text-gray-500 hover:text-green-500 hover:border-green-500/30 transition-all group">
                                        Selanjutnya
                                        <i data-lucide="chevron-right" class="w-3.5 h-3.5 group-hover:translate-x-0.5 transition-transform"></i>
                                    </a>
                                <?php else: ?>
                                    <div></div>
                                <?php endif; ?>
                            </div>

                            
                            <div class="max-w-4xl mx-auto px-4 mb-8">
                                <div class="ch-dropdown" id="ch-dropdown-bottom">
                                    <button type="button"
                                        onclick="toggleChDropdown('bottom')"
                                        class="ch-trigger">
                                        <span class="truncate" title="<?= $current_chapter ? htmlspecialchars($current_chapter) : 'Pilih chapter' ?>"><?= $current_chapter ? htmlspecialchars($current_chapter) : '— Pilih Chapter —' ?></span>
                                        <i data-lucide="chevron-down" class="w-3.5 h-3.5 text-gray-500 flex-shrink-0"></i>
                                    </button>
                                    <div id="ch-options-bottom" class="ch-options hidden">
                                        <button onclick="goToChapter('')"
                                            class="ch-option <?= empty($current_chapter) ? 'active' : '' ?>">
                                            — Pilih Chapter —
                                        </button>
                                        <?php foreach ($chapters as $ch):
                                            $ch_name  = basename($ch);
                                            $active   = ($current_chapter === $ch_name) ? 'active' : '';
                                            $enc_name = htmlspecialchars($ch_name, ENT_QUOTES);
                                        ?>
                                            <button onclick="goToChapter('<?= $enc_name ?>')"
                                                class="ch-option <?= $active ?>">
                                                <?= htmlspecialchars($ch_name) ?>
                                            </button>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="max-w-4xl mx-auto px-4 py-20 text-center">
                            <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                                <i data-lucide="image-off" class="w-6 h-6 text-gray-700"></i>
                            </div>
                            <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">
                                Tidak ada gambar
                            </p>
                            <p class="text-[10px] text-gray-700 mt-1 uppercase tracking-widest">
                                <?= htmlspecialchars($current_chapter ?: 'Folder Utama') ?>
                            </p>
                        </div>
                    <?php endif; ?>
                <?php elseif ($target_path === null): ?>
                    
                <?php else: ?>
                    <div class="max-w-4xl mx-auto px-4 py-20 text-center">
                        <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-white/[.03] border border-white/[.06] flex items-center justify-center">
                            <i data-lucide="folder-open" class="w-6 h-6 text-gray-700"></i>
                        </div>
                        <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">
                            Folder tidak ditemukan
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php include '../partials/footer.php'; ?>
    </div>

    
    <?php if ($total_pages > 0): ?>
    <div class="page-counter <?= $total_pages > 1 ? 'visible' : '' ?>" id="page-counter">
        Halaman <span class="current" id="current-page-display">1</span> / <?= $total_pages ?>
    </div>
    <?php endif; ?>
    
    <button id="scroll-top-btn" onclick="scrollToTop()" title="Ke atas">
        <i data-lucide="chevron-up" class="w-4 h-4"></i>
    </button>

    <script src="../assets/js/books/read/reader.js?v=<?= filemtime(__DIR__ . '/../assets/js/books/read/reader.js') ?>"></script>

    
    <script>window.meelHealthActivityMode = "reading";
</script>
    <script src="../assets/js/shared/state-keys.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/state-keys.js') ?>"></script>
    <script src="../assets/js/shared/health-reminder.js?v=<?= filemtime(__DIR__ . '/../assets/js/shared/health-reminder.js') ?>"></script>
</body>

</html>
