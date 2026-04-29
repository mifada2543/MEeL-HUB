<?php
require_once '../auth/auth.php';
require_once '../auth/config.php';
require_once '../auth/activity_logger.php';
include '../helpers.php';
// Ambil kategori dari URL (default: all)
$filter = isset($_GET['type']) ? $_GET['type'] : 'all';
// Ambil role user
$u_id = $_SESSION['user_id'];
$role_res = $conn->query("SELECT role FROM users WHERE id = $u_id");
$current_role = $role_res->fetch_assoc()['role'];
// Siapkan Query
if ($filter === 'manga') {
    $stmt = $conn->prepare("SELECT * FROM books WHERE type = 'manga' ORDER BY upload_date DESC");
} elseif ($filter === 'pdf') {
    $stmt = $conn->prepare("SELECT * FROM books WHERE type = 'pdf' ORDER BY upload_date DESC");
} else {
    $stmt = $conn->prepare("SELECT * FROM books ORDER BY upload_date DESC");
}
$stmt->execute();
$books = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.7">
    <title>MEeL | library</title>
    <link rel="icon" href="../assets/MEeL.png" type="image/png">
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <style>
        body {
            background-color: #05070a;
            color: #e5e7eb;
        }

        .glass {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(10px);
        }
    </style>
</head>

<body class="min-h-screen bg-[#05070a]">
    <!-- Navbar -->
    <nav class="border-b border-gray-800 bg-gradient-to-r from-green-600/20 via-[#0b0e14]/90 to-blue-600/20 sticky top-0 z-50 backdrop-blur-md w-full">
        <div class="w-full px-4 md:px-8 h-16 flex items-center justify-between">
            <a href="../index.php" class="flex items-center gap-2 group">
                <div class="bg-green-600 p-1.5 rounded-lg group-hover:bg-green-500 transition-colors shadow-lg"><i data-lucide="library" class="w-5 h-5 text-white fill-current"></i></div>
                <span class="text-xl font-black tracking-tighter text-white uppercase">MEeL<span class="text-green-600">Books</span></span>
            </a>
            <div class="flex items-center gap-6 text-[11px] font-bold uppercase tracking-wider">
                <a href="../video/index.php" class="flex items-center gap-1.5 text-gray-400 hover:text-red-500 transition-all"><i data-lucide="play" class="w-3.5 h-3.5"></i> Video</a>
                <div class="h-4 w-[1px] bg-gray-800"></div>
                <a href="../music/index.php" class="flex items-center gap-1.5 text-gray-400 hover:text-orange-500 transition-all"><i data-lucide="music" class="w-3.5 h-3.5"></i> Music</a>
                <div class="h-4 w-[1px] bg-gray-800"></div>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($current_role === 'admin'): ?>
                        <a href="upload.php" class="flex items-center gap-2 text-gray-400 hover:text-green-500 transition-all mr-2"><i data-lucide="upload-cloud" class="w-4 h-4"></i>Upload</a>
                    <?php endif; ?>
                    <div class="flex items-center gap-3 border-l border-gray-800 pl-4">
                        <div class="flex flex-col items-end">
                            <a href="../profile/?u=<?= urlencode($_SESSION['username']) ?>" class="group flex items-center gap-2 text-white font-bold"><?= htmlspecialchars($_SESSION['username']) ?></a>
                            <?php if ($current_role === 'admin'): ?>
                                <a href="../system_check.php" class="flex items-center gap-1.5 mt-1 hover:opacity-80 transition-opacity group"><span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-red-600"></span></span><span class="text-[9px] text-red-600 font-black uppercase tracking-[0.2em]">Admin</span></a>
                            <?php else: ?>
                                <div class="flex items-center gap-1 mt-1"><span class="h-1.5 w-1.5 rounded-full bg-gray-700"></span><span class="text-[9px] text-gray-500 font-medium uppercase tracking-tighter">Member</span></div>
                            <?php endif; ?>
                        </div>
                        <a href="../auth/logout.php" class="bg-gray-800/50 p-2 rounded-xl hover:bg-red-600 group transition-all duration-300"><i data-lucide="log-out" class="w-4 h-4 text-gray-400 group-hover:text-white"></i></a>
                    </div>
                <?php endif; ?>
                <a href="../introduction.php" class="text-gray-500 hover:text-white transition-all p-2 rounded-lg hover:bg-white/5 group" title="Cara Bernavigasi">
                    <i data-lucide="compass" class="w-4.5 h-4.5 group-hover:rotate-12 transition-transform"></i>
                </a>
            </div>
        </div>
    </nav>
    <!-- Filter Bar -->
    <div class="max-w-7xl mx-auto px-4 md:px-8 pt-8">
        <div class="flex gap-3 mb-8">
            <a href="index.php?type=all" class="px-6 py-2 rounded-full text-[10px] font-black uppercase transition-all <?= $filter === 'all' ? 'bg-green-600 text-white shadow-lg shadow-green-900/40' : 'bg-white/5 text-gray-500 hover:bg-white/10' ?>">All</a>
            <a href="index.php?type=manga" class="px-6 py-2 rounded-full text-[10px] font-black uppercase transition-all <?= $filter === 'manga' ? 'bg-orange-600 text-white shadow-lg shadow-orange-900/40' : 'bg-white/5 text-gray-500 hover:bg-white/10' ?>">Manga</a>
            <a href="index.php?type=pdf" class="px-6 py-2 rounded-full text-[10px] font-black uppercase transition-all <?= $filter === 'pdf' ? 'bg-purple-600 text-white shadow-lg shadow-purple-900/40' : 'bg-white/5 text-gray-500 hover:bg-white/10' ?>">PDF</a>
        </div>
    </div>
    <!-- Main -->
    <div class="max-w-7xl mx-auto px-4 md:px-8 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-8">
        <?php if ($books->num_rows > 0): ?>
            <?php while ($book = $books->fetch_assoc()): ?>
                <div class="relative group">
                    <?php if ($current_role === 'admin'): ?>
                        <div class="absolute top-2 left-2 opacity-0 group-hover:opacity-100 transition-opacity z-30">
                            <a href="upload.php?reup=<?= urlencode($book['title']) ?>"
                                class="p-2 bg-green-600/90 backdrop-blur-md rounded-xl text-white hover:bg-green-500 hover:scale-110 transition-all shadow-lg block">
                                <i data-lucide="plus" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                    <!-- Books -->
                    <a href="read.php?id=<?= $book['id'] ?>" class="block">
                        <div class="relative aspect-[3/4] overflow-hidden rounded-2xl border border-gray-800 shadow-lg glass transition-all group-hover:border-blue-500 group-hover:scale-[1.04] group-hover:shadow-blue-500/20">
                            <img src="upload/thumbnail/<?= $book['thumbnail'] ?>" class="w-full h-full object-cover transition-all group-hover:brightness-50" alt="<?= htmlspecialchars($book['title']) ?>">
                            <!-- Type -->
                            <div class="absolute top-2 right-2">
                                <span class="text-[9px] font-black px-2 py-1 rounded-lg uppercase shadow-md <?= $book['type'] == 'manga' ? 'bg-orange-600' : 'bg-purple-600' ?>">
                                    <i data-lucide="book-open" class="w-3 h-3 inline-block mr-1"></i><?= $book['type'] ?>
                                </span>
                            </div>
                            <div class="absolute inset-0 flex flex-col justify-end p-4 opacity-0 group-hover:opacity-100 transition-opacity bg-gradient-to-t from-black/60 via-transparent to-transparent">
                                <h3 class="text-sm font-bold text-white line-clamp-2 drop-shadow-lg">
                                    <?= htmlspecialchars($book['title']) ?>
                                </h3>
                                <p class="text-[10px] text-gray-300 mt-1 italic">
                                    <?= htmlspecialchars($book['author'] ?? 'Unknown Author') ?>
                                </p>
                            </div>
                        </div>
                    </a>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="col-span-full py-20 text-center glass rounded-3xl border border-dashed border-gray-800">
                <i data-lucide="book-open" class="w-12 h-12 text-gray-700 mx-auto mb-4"></i>
                <p class="text-gray-500 font-bold uppercase tracking-widest">Belum ada koleksi di sini, <?= htmlspecialchars($_SESSION['username'] ?? 'Guest') ?>.</p>
            </div>
        <?php endif; ?>
    </div>
    <script>
        lucide.createIcons();
    </script>
    <style>
        .glass {
            background: rgba(255,255,255,0.03);
            backdrop-filter: blur(10px);
        }
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
    </style>
</body>

</html>