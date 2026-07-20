<?php
require_once '../modules/helpers.php';
/**
 * read_pdf.php — PDF viewer dengan PWA support
 * 
 * Menyajikan file PDF dalam halaman HTML yang menyertakan manifest, logo,
 * dan meta tags untuk PWA. Cocok untuk akses langsung di HP (tab baru).
 * 
 * File PDF asli di-serve oleh controllers/api/pdf.php (binary endpoint).
 */

require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../modules/media/MediaLibrary.php';

// ── Validasi ID ──────────────────────────────────────────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header("Location: index.php");
    exit();
}

// ── Ambil data buku dari database ────────────────────────────────────────────
$repo  = new BookRepository($conn);
$book  = $repo->getBookById($id);

if (!$book || $book['type'] !== 'pdf') {
    header("Location: index.php");
    exit();
}

// ── Ambil ukuran file ────────────────────────────────────────────────────────
$pdf_path   = __DIR__ . '/upload/pdf/' . basename($book['path_folder']);
$pdf_size   = is_file($pdf_path) ? filesize($pdf_path) : 0;
$pdf_size_f = $pdf_size > 1048576
    ? number_format($pdf_size / 1048576, 1) . ' MB'
    : number_format($pdf_size / 1024, 1) . ' KB';

$title = htmlspecialchars($book['title']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="MEeL - Baca PDF: <?= $title ?>">
    <meta property="og:title" content="<?= $title ?> — MEeL PDF">
    <meta property="og:description" content="Baca dokumen PDF <?= $title ?> di MEeL Books.">
    <meta property="og:image" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/assets/MEeL.png">
    <meta property="og:url" content="<?= (function_exists('detectProtocol') ? detectProtocol() : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http')) . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'] ?>">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <title>MEeL PDF | <?= $title ?></title>
    <link rel="manifest" href="../assets/manifest.json">
    <link rel="icon" type="image/png" href="../assets/MEeL.png">
    <link href="../assets/css/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/books.css">
    <style>
        body {
            background-color: #080a0f;
            color: white;
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
        }
        .pdf-wrap {
            width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .pdf-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.6rem 1rem;
            background: rgba(8,10,15,.92);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid rgba(255,255,255,.05);
            gap: 1rem;
            flex-shrink: 0;
        }
        .pdf-nav-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            min-width: 0;
        }
        .pdf-nav-icon {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: #7c3aed;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .pdf-nav-icon i {
            color: white;
        }
        .pdf-nav-title {
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: #e5e7eb;
        }
        .pdf-nav-meta {
            font-size: 0.6rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        .pdf-nav-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-shrink: 0;
        }
        .pdf-nav-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.85rem;
            background: #7c3aed;
            color: #fff;
            border-radius: 10px;
            font-size: 0.65rem;
            font-weight: 700;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        .pdf-nav-btn:hover {
            background: #6d28d9;
        }
        .pdf-nav-back {
            padding: 0.4rem;
            color: #6b7280;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
            display: flex;
        }
        .pdf-nav-back:hover {
            color: #22c55e;
            background: rgba(255,255,255,.04);
        }
        .pdf-body {
            flex: 1;
            min-height: 0;
            position: relative;
            background: #0f1318;
        }
        .pdf-body iframe {
            width: 100%;
            height: 100%;
            border: none;
            display: block;
        }
        .pdf-fallback-full {
            display: none;
        }
        /* Loading state: shimmer while JS fetches PDF */
        .pdf-body.loading {
            background: #0f1318;
        }
        .pdf-body.loading::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
            background:
                linear-gradient(110deg, #0f1318 30%, #161b24 50%, #0f1318 70%);
            background-size: 200% 100%;
            animation: pdfShimmer 1.5s ease-in-out infinite;
            pointer-events: none;
        }
        @keyframes pdfShimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }
        .pdf-body.loaded::after {
            display: none;
        }
        /* Fallback overlay — JS tampilkan hanya saat error */
        .pdf-fallback-full.active {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #0f1318;
            padding: 2rem;
            z-index: 2;
        }
        .pdf-fallback-full .icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 1.2rem;
            border-radius: 18px;
            background: rgba(255,255,255,.04);
            border: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pdf-fallback-full h2 {
            font-size: 1.1rem;
            font-weight: 700;
            color: #f0f2f7;
            margin-bottom: 0.3rem;
            text-align: center;
        }
        .pdf-fallback-full p {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            margin-bottom: 1.5rem;
        }
        .pdf-fallback-full .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.8rem;
            background: #7c3aed;
            color: #fff;
            border-radius: 14px;
            font-size: 0.8rem;
            font-weight: 700;
            text-decoration: none;
            box-shadow: 0 8px 24px rgba(124,58,237,.3);
            transition: all 0.25s;
        }
        .pdf-fallback-full .btn:hover {
            background: #6d28d9;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>
    <div class="pdf-wrap">
        <!-- Navigation bar -->
        <div class="pdf-nav">
            <div class="pdf-nav-left">
                <a href="read.php?id=<?= $id ?>" class="pdf-nav-back" title="Kembali ke pembaca">
                    <i data-lucide="arrow-left" class="w-4 h-4"></i>
                </a>
                <div class="pdf-nav-icon">
                    <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                </div>
                <div class="min-w-0">
                    <div class="pdf-nav-title"><?= $title ?></div>
                    <div class="pdf-nav-meta">PDF &middot; <?= $pdf_size_f ?></div>
                </div>
            </div>
            <div class="pdf-nav-actions">
                <a href="../controllers/api/pdf.php?id=<?= $id ?>" target="_blank" rel="noopener" class="pdf-nav-btn">
                    <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    Buka Mentah
                </a>
            </div>
        </div>

        <!-- PDF content -->
        <div class="pdf-body loading" id="pdfBody">
            <!-- Iframe untuk blob URL — JS akan mengisi src-nya -->
            <iframe id="pdfFrame"
                    title="PDF Viewer"></iframe>

            <!-- Fallback overlay — muncul hanya jika fetch gagal -->
            <div class="pdf-fallback-full" id="pdfFallback">
                <div class="icon">
                    <i data-lucide="file-text" class="w-8 h-8 text-purple-400"></i>
                </div>
                <h2 id="fbTitle"><?= $title ?></h2>
                <p id="fbDesc">Dokumen PDF &middot; <?= $pdf_size_f ?></p>
                <a href="../controllers/api/pdf.php?id=<?= $id ?>" target="_blank" rel="noopener" class="btn" id="fbBtn">
                    <i data-lucide="external-link" class="w-4 h-4"></i>
                    Buka PDF
                </a>
            </div>
        </div>
    </div>

    <script>
    /**
     * PDF Blob Loader
     *
     * Masalah: <embed src="api/pdf.php"> di browser mobile sering TIDAK
     * mengirim cookie session ke api/pdf.php, sehingga auth gagal dan
     * halaman login tampil di dalam embed ("Access Denied").
     *
     * Solusi: JavaScript fetch() dengan credentials: 'same-origin' yang
     * WAJIB mengirim cookie, lalu hasil PDF dijadikan blob URL dan
     * ditampilkan di dalam <iframe>. Semua browser modern support ini.
     */
    (async function() {
        const pdfBody  = document.getElementById('pdfBody');
        const pdfFrame = document.getElementById('pdfFrame');
        const fallback = document.getElementById('pdfFallback');
        const fbBtn    = document.getElementById('fbBtn');
        const fbTitle  = document.getElementById('fbTitle');
        const fbDesc   = document.getElementById('fbDesc');

        if (!pdfFrame) return;

        try {
            // Fetch PDF DENGAN cookie — kunci utama perbaikan!
            const pdfUrl = '../controllers/api/pdf.php?id=<?= $id ?>';
            const res = await fetch(pdfUrl, {
                credentials: 'same-origin'
            });

            if (!res.ok) {
                throw new Error('HTTP ' + res.status + ' — ' + res.statusText);
            }

            const blob = await res.blob();

            // Cek apakah benar PDF
            if (blob.type !== 'application/pdf') {
                throw new Error('Respon bukan PDF (type: ' + blob.type + ')');
            }

            const blobUrl = URL.createObjectURL(blob);

            // Set iframe src ke blob URL
            pdfFrame.src = blobUrl;

            // Hapus loading state
            pdfBody.classList.remove('loading');
            pdfBody.classList.add('loaded');

            // Pastikan iframe terlihat (override CSS mobile)
            pdfFrame.style.display = 'block';

            // Sembunyikan fallback
            if (fallback) fallback.classList.remove('active');

        } catch (err) {
            console.error('[PDF] Gagal memuat:', err);

            // Tampilkan fallback dengan pesan error
            pdfBody.classList.remove('loading');
            if (fallback) {
                if (fbTitle) fbTitle.textContent = 'Gagal Memuat PDF';
                if (fbDesc)  fbDesc.textContent  = err.message;
                if (fbBtn)   fbBtn.textContent   = 'Coba Buka Langsung →';
                fallback.classList.add('active');
            }
        }
    })();
    </script>
    <script src="../assets/js/lucide.js"></script>
    <script>lucide.createIcons();</script>
</body>
</html>
