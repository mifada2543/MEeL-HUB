<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../auth/activity_logger.php';
include '../helpers.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$id = $_GET['id'];
$stmt = $conn->prepare("SELECT * FROM books WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$book = $stmt->get_result()->fetch_assoc();
$current_chapter = $_GET['ch'] ?? '';

if (!$book) {
    die("<div style='background:#0b0e14; color:#ef4444; height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; font-family:sans-serif;'><h1 style='font-size:4rem; font-weight:900;'>Mohon maaf</h1><p style='text-transform:uppercase; letter-spacing:4px;'>MEeL</p><p style='color:#4b5563; margin-top:10px;'>Alasan: Buku tidak ditemukan atau tidak tersedia.</p></div>");
}
$current_page = "Reading: " . $book['title'];
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

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #05070a;
        }

        ::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 10px;
        }

        .manga-container img {
            width: 100%;
            max-width: 800px;
            margin: 0 auto;
            display: block;
        }
    </style>
</head>

<body class="flex flex-col h-screen">
    <!-- Navbar -->
    <div class="bg-[#0b0e14]/80 backdrop-blur-md border-b border-gray-800 p-4 flex justify-between items-center sticky top-0 z-50">
        <div class="flex items-center gap-4"><a href="index.php" class="p-2 hover:bg-gray-800 rounded-xl transition"><i data-lucide="arrow-left" class="w-5 h-5"></i></a>
            <div>
                <h1 class="text-sm font-bold truncate max-w-[200px] md:max-w-md"><?= htmlspecialchars($book['title']) ?></h1>
                <p class="text-[10px] text-gray-500 uppercase font-black tracking-widest"><?= $book['type'] ?> Mode</p>
            </div>
        </div>
        <div class="flex items-center gap-2"><button onclick="window.location.reload()" class="p-2 hover:bg-gray-800 rounded-xl transition"><i data-lucide="refresh-cw" class="w-4 h-4 text-gray-400"></i></button></div>
    </div>

    <div class="flex-grow overflow-y-auto">
        <?php if ($book['type'] === 'pdf'): ?>
            <div class="w-full h-full bg-gray-900">
                <iframe src="./upload/pdf/<?= htmlspecialchars($book['path_folder']) ?>#toolbar=0"
                    class="w-full h-[calc(100vh-64px)] border-none"></iframe>
            </div>
        <?php else: ?>
            <div class="manga-container py-4 space-y-0">
                <?php if ($book['has_chapters'] == 1): ?>
                    <div class="max-w-4xl mx-auto mb-6 px-4">
                        <select onchange="location.href='?id=<?= $id ?>&ch=' + this.value" class="w-full bg-gray-900 text-sm p-3 rounded-2xl border border-gray-800 outline-none">
                            <option value="">-- Pilih Chapter --</option>
                            <?php
                            $ch_path = "upload/manga/" . $book['path_folder'];
                            $chapters = array_filter(glob($ch_path . '/*'), 'is_dir');
                            natsort($chapters);
                            foreach ($chapters as $ch) {
                                $ch_name = basename($ch);
                                $selected = ($current_chapter == $ch_name) ? 'selected' : '';
                                echo "<option value='$ch_name' $selected>$ch_name</option>";
                            }
                            ?>
                        </select>
                    </div>
                <?php endif; ?>

                <?php
                // Tentukan path target: Folder Utama atau Folder Chapter
                $target_path = "upload/manga/" . $book['path_folder'];
                if ($book['has_chapters'] == 1 && empty($current_chapter)) {
                    echo '<div class="py-20 text-center flex flex-col items-center gap-4"><i data-lucide="book-open" class="w-12 h-12 text-blue-500 opacity-50"></i><p class="text-gray-500 font-bold uppercase tracking-widest text-xs">Silakan pilih chapter untuk mulai membaca</p></div>';
                } else if ($book['has_chapters'] == 1 && !empty($current_chapter)) {
                    $target_path .= "/" . $current_chapter;
                }
                if (is_dir($target_path)) {
                    $images = glob($target_path . "/*.{jpg,jpeg,png,webp,JPG,PNG}", GLOB_BRACE);
                    natsort($images);
                    if (count($images) > 0) {
                        foreach ($images as $img) {
                            echo '<img src="' . htmlspecialchars($img) . '" loading="lazy" class="w-full max-w-4xl mx-auto block" alt="page">';
                        }
                    } else {
                        echo '<p class="text-center py-20 text-gray-500">Tidak ada gambar di: ' . htmlspecialchars($current_chapter ?: 'Folder Utama') . '</p>';
                    }
                }
                ?>
            </div>
        <?php endif; ?>
        <?php if ($book['has_chapters'] == 1): ?>
            <div class="max-w-4xl mx-auto mb-6 px-4"><select onchange="location.href='?id=<?= $id ?>&ch=' + this.value" class="w-full bg-gray-900 text-sm p-3 rounded-2xl border border-gray-800 outline-none">
                    <option value="">-- Pilih Chapter --</option><?php $ch_path = "upload/manga/" . $book['path_folder'];
                                                                    $chapters = array_filter(glob($ch_path . '/*'), 'is_dir');
                                                                    natsort($chapters);
                                                                    foreach ($chapters as $ch) {
                                                                        $ch_name = basename($ch);
                                                                        $selected = ($current_chapter == $ch_name) ? 'selected' : '';
                                                                        echo "<option value='$ch_name' $selected>$ch_name</option>";
                                                                    } ?>
                </select></div>
        <?php endif; ?>
    </div>
    <?php include '../partials/footer.php'; ?>
    <script>
        lucide.createIcons();
    </script>
</body>

</html>