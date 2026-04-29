<?php
require_once '../auth/auth.php'; require_once '../auth/config.php'; require_once '../auth/activity_logger.php';

$message = "";
$val_title = isset($_GET['reup']) ? $_GET['reup'] : "";
$user_id = $_SESSION['user_id'];
$has_chapters = 0;
// --- PROTEKSI ROLE ADMIN ---
$user_id = $_SESSION['user_id'];
$query_role = $conn->prepare("SELECT role FROM users WHERE id = ?");
$query_role->bind_param("i", $user_id);
$query_role->execute();
$user_role = $query_role->get_result()->fetch_assoc()['role'];

if ($user_role !== 'admin') {
    // Jika bukan admin, tendang ke index atau beri pesan error
    header("Location: index.php?error=unauthorized");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_book'])) {
    $title = $_POST['title'];
    $author = $_POST['author'] ?? 'Unknown';
    $type = $_POST['type']; // 'manga' atau 'pdf'
    $category = $_POST['category'];
    $user_id = $_SESSION['user_id'];

    // --- 1. HANDLING THUMBNAIL ---
    $thumb_name = "default_cover.jpg";
    if (!empty($_FILES['thumbnail']['name'])) {
        $thumb_ext = pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
        $thumb_name = time() . "_" . bin2hex(random_bytes(4)) . "." . $thumb_ext;
        move_uploaded_file($_FILES['thumbnail']['tmp_name'], "upload/thumbnail/" . $thumb_name);
    }

    // --- 2. HANDLING FILE KONTEN ---
    $file_obj = $_FILES['book_file'];
    $file_ext = strtolower(pathinfo($file_obj['name'], PATHINFO_EXTENSION));
    $clean_folder_name = preg_replace('/[^a-zA-Z0-9]/', '_', $title);
    $path_result = "";

    if ($type === 'pdf') {if ($file_ext !== 'pdf') {$message = "Error: File harus berformat PDF!";} else { $final_file = $clean_folder_name . "_" . time() . ".pdf"; if (move_uploaded_file($file_obj['tmp_name'], "upload/pdf/" . $final_file)) {$path_result = $final_file;} else {$message = "Error: Gagal memindahkan file PDF!";}}
    } elseif ($type === 'manga') {
        if ($file_ext !== 'zip') {$message = "Error: Harap upload ZIP!";
        } else {
            $manga_folder = "upload/manga/" . $clean_folder_name;
            // Cek DB
            $check_exist = $conn->prepare("SELECT id FROM books WHERE path_folder = ?");
            $check_exist->bind_param("s", $clean_folder_name);
            $check_exist->execute();
            $exists = $check_exist->get_result()->num_rows > 0;
            if (!is_dir($manga_folder)) mkdir($manga_folder, 0777, true);
            // extract ZIP dan cek apakah ada subfolder (chapter)
            $zip = new ZipArchive;
            if ($zip->open($file_obj['tmp_name']) === TRUE) { $zip->extractTo($manga_folder); $first_entry = $zip->getNameIndex(0); if (strpos($first_entry, '/') !== false) {$has_chapters = 1;} $zip->close();
                // Jika buku sudah ada, kita hanya perlu update status 'has_chapters'
                if ($exists) { $conn->query("UPDATE books SET has_chapters = 1 WHERE path_folder = '$clean_folder_name'"); $message = "Success: Chapter tambahan berhasil digabungkan!"; $path_result = "";} else {$path_result = $clean_folder_name;}
            }
        }
    }
    // --- 3. INSERT DATABASE ---
    if ($path_result !== "") {
        $stmt = $conn->prepare("INSERT INTO books (title, author, type, has_chapters, category, path_folder, thumbnail, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssisssi", $title, $author, $type, $has_chapters, $category, $path_result, $thumb_name, $user_id);
        if ($stmt->execute()) {
            $message = "Success: " . ($type === 'manga' ? "Manga" : "Buku") . " berhasil ditambahkan!";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><title>MEeL | Upload Book</title><link rel="icon" href="../assets/logo.png" type="image/png"><script src="../assets/js/tailwind.js"></script><script src="../assets/js/lucide.js"></script>
    <style>
        body {background-color: #05070a; color: white;}
    </style>
</head>

<body class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-xl bg-[#0b0e14] border border-gray-800 rounded-[2.5rem] p-10 shadow-2xl">
        <div class="flex justify-between items-center mb-8"><h1 class="text-2xl font-black">Upload to Library</h1><a href="index.php" class="text-gray-500 hover:text-white transition"><i data-lucide="x"></i></a></div>
        <?php if ($message): ?><div class="mb-6 p-4 rounded-2xl text-xs font-bold bg-blue-500/10 text-blue-400 border border-blue-500/20"><?= $message ?></div><?php endif; ?>
        <!-- Upload -->
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Tipe Konten</label><select name="type" class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none"><option value="manga">Manga / Komik (ZIP)</option><option value="pdf">E-Book / Dokumen (PDF)</option></select></div><div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Kategori</label><input type="text" name="category" placeholder="Edukasi, Action, dll" class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none"></div></div>
            <div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Judul Buku</label><input type="text" name="title" value="<?= htmlspecialchars($val_title) ?>" required placeholder="Contoh: Belajar PHP Dasar" class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none"></div>
            <div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Penulis / Artist</label><input type="text" name="author" placeholder="Nama Penulis" class="w-full bg-[#05070a] border border-gray-800 rounded-2xl p-3 text-sm focus:border-blue-600 outline-none"></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4"><div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">File (PDF/ZIP)</label><input type="file" name="book_file" required class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-gray-800 file:text-gray-300"></div><div class="space-y-2"><label class="text-[10px] font-bold text-gray-500 uppercase ml-1">Cover (Thumbnail)</label><input type="file" name="thumbnail" class="w-full text-xs text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-bold file:bg-gray-800 file:text-gray-300"></div></div>
            <button type="submit" name="upload_book" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-4 rounded-2xl transition-all flex items-center justify-center gap-2 mt-4"><i data-lucide="plus-circle" class="w-5 h-5"></i>SIMPAN KE PERPUSTAKAAN</button>
        </form>
    </div>
    <script>lucide.createIcons();</script>
</body>
</html>