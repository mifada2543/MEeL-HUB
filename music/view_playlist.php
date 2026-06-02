<?php
session_name('meel');
session_start();
include '../auth/config.php';
include '../modules/helpers.php';
$playlist_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'] ?? 0;

// 1. Ambil info playlist & pastikan milik user yang login
$pl_query = $conn->query("SELECT * FROM playlists WHERE id = $playlist_id AND user_id = $user_id");
$playlist = $pl_query->fetch_assoc();

if (!$playlist) {
    include '../err/denied.php';
    exit;
}

// 2. Ambil daftar lagu di dalam playlist ini
$songs_query = $conn->query("
    SELECT m.*, pt.id as pivot_id 
    FROM music m 
    JOIN playlist_tracks pt ON m.id = pt.music_id 
    WHERE pt.playlist_id = $playlist_id 
    ORDER BY pt.added_at DESC
");
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=0.7">
    <meta name="description" content="MEeL - Platform Media Hub Pribadi untuk Streaming Video, Musik, dan E-Library.">
    <title><?= htmlspecialchars($playlist['name']) ?> | MEeL Playlist</title>
    <script src="../assets/js/tailwind.js"></script>
    <script src="../assets/js/lucide.js"></script>
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../assets/js/script.js"></script>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .glass {
            background: rgba(255, 255, 255, 0.02);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 4px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #ea580c;
            border-radius: 10px;
        }
    </style>
</head>

<body class="bg-[#0b0e14] text-white font-sans antialiased">

    <div class="max-w-6xl mx-auto px-6 py-12">
        <div class="flex items-end gap-6 mb-10">
            <div class="w-48 h-48 bg-gradient-to-br from-orange-500 to-red-600 rounded-[2rem] shadow-2xl flex items-center justify-center">
                <i data-lucide="music" class="w-20 h-20 text-white/20"></i>
            </div>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.5em] text-orange-500 mb-2">Playlist</p>
                <h1 class="text-6xl font-black tracking-tighter mb-4"><?= htmlspecialchars($playlist['name']) ?></h1>
                <p class="text-gray-500 font-medium"><?= $songs_query->num_rows ?> Tracks • Created by You</p>
                <?php if ($songs_query->num_rows > 0):
                    // Ambil ID lagu pertama untuk tombol Play All
                    $songs_query->data_seek(0);
                    $first_song = $songs_query->fetch_assoc();
                    $songs_query->data_seek(0); // Kembalikan pointer ke awal untuk loop daftar lagu
                ?>
                    <div class="flex gap-4 mt-6">
                        <a href="watch.php?id=<?= $first_song['id'] ?>&playlist_id=<?= $playlist_id ?>"
                            class="bg-orange-600 hover:bg-orange-500 text-white px-8 py-3 rounded-full font-black uppercase tracking-widest text-xs transition-all flex items-center gap-2 shadow-lg shadow-orange-600/20">
                            <i data-lucide="play" class="w-4 h-4 fill-current"></i> Play All
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <form action="playlist_action.php" method="POST" onsubmit="return meelConfirmForm(event, { title: 'Hapus Playlist', text: 'Hapus seluruh playlist ini?', confirmButtonText: 'HAPUS' })">
                <input type="hidden" name="action" value="delete_playlist">
                <input type="hidden" name="playlist_id" value="<?= $playlist_id ?>">
                <button type="submit" class="mt-4 text-[10px] font-black uppercase tracking-widest text-red-500 hover:text-red-400 transition flex items-center gap-2">
                    <i data-lucide="trash" class="w-3 h-3"></i> Delete Playlist
                </button>
            </form>
        </div>
        <div class="grid grid-cols-1 gap-2">
            <?php if ($songs_query->num_rows > 0): ?>
                <?php while ($s = $songs_query->fetch_assoc()): ?>
                    <div class="group flex items-center justify-between p-4 hover:bg-white/5 rounded-2xl transition-all border border-transparent hover:border-white/10">
                        <a href="watch.php?id=<?= $s['id'] ?>&playlist_id=<?= $playlist_id ?>" class="flex items-center gap-4 flex-1">

                            <div class="w-12 h-12 bg-gray-800 rounded-lg flex items-center justify-center overflow-hidden border border-white/5">
                                <?php
                                // Cek apakah kolomnya bernama 'thumbnail' atau 'cover'
                                $thumb = !empty($s['thumbnail']) ? $s['thumbnail'] : '';

                                if ($thumb): ?>
                                    <img src="upload/thumbnail/<?= htmlspecialchars($thumb) ?>"
                                        class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                                <?php else: ?>
                                    <i data-lucide="music" class="w-5 h-5 text-gray-600"></i>
                                <?php endif; ?>
                            </div>

                            <div>
                                <h4 class="font-bold text-gray-200 group-hover:text-orange-500 transition line-clamp-1 italic">
                                    <?= htmlspecialchars($s['title']) ?>
                                </h4>
                                <p class="text-[10px] font-black uppercase tracking-widest text-gray-500 italic">
                                    <?= htmlspecialchars($s['artist']) ?>
                                </p>
                            </div>
                        </a>

                        <form action="playlist_action.php" method="POST" onsubmit="return meelConfirmForm(event, { title: 'Hapus Lagu', text: 'Hapus dari playlist?', confirmButtonText: 'HAPUS' })">
                            <input type="hidden" name="action" value="remove_from_playlist">
                            <input type="hidden" name="pivot_id" value="<?= $s['pivot_id'] ?>">
                            <input type="hidden" name="playlist_id" value="<?= $playlist_id ?>">
                            <button type="submit" class="p-2 text-gray-600 hover:text-red-500 transition">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </form>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="text-center py-20 border-2 border-dashed border-white/5 rounded-[3rem]">
                    <p class="text-gray-600 font-bold uppercase tracking-widest text-xs">Belum ada lagu di playlist ini</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-10">
            <a href="index.php" class="text-xs font-black uppercase tracking-widest text-gray-500 hover:text-white transition inline-flex items-center gap-2">
                <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Library
            </a>
        </div>
    </div>
    <?php include '../partials/footer.php'; ?>
    <script>
        lucide.createIcons();
    </script>
</body>

</html>
