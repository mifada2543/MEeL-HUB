<?php
session_name("meel");
session_start();
include 'auth/config.php';
include '../helpers.php';
// Proteksi Admin
if (!isset($_SESSION['user_id'])) { die("Akses ditolak."); }

$user_id = $_SESSION['user_id'];
$query_user = $conn->prepare("SELECT role FROM users WHERE id = ?");
$query_user->bind_param("i", $user_id);
$query_user->execute();
$user_data = $query_user->get_result()->fetch_assoc();

if (!$user_data || $user_data['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

// Logika Filter & Sorting
$sort = $_GET['sort'] ?? 'views';
$type_filter = $_GET['type'] ?? 'all'; // Filter tambahan untuk tipe media

$allowed_sort = [
    'views' => 'views DESC',
    'likes' => 'likes DESC',
    'dislikes' => 'dislikes DESC',
    'title' => 'title ASC'
];
$order_by = $allowed_sort[$sort] ?? 'views DESC';

// Query Utama dengan Subquery untuk menghitung Like/Dislike dari tabel interactions
$query_media = "
    SELECT * FROM (
        SELECT id, title, 'video' as media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'like') as likes,
            (SELECT COUNT(*) FROM interactions WHERE video_id = video.id AND type = 'dislike') as dislikes
        FROM video
        UNION ALL
        SELECT id, title, 'music' as media_type, views,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'like') as likes,
            (SELECT COUNT(*) FROM interactions WHERE music_id = music.id AND type = 'dislike') as dislikes
        FROM music
    ) AS combined_media";

// Tambahkan Filter Tipe jika dipilih
if ($type_filter !== 'all') {
    $query_media .= " WHERE media_type = '" . $conn->real_escape_string($type_filter) . "'";
}

$query_media .= " ORDER BY $order_by";
$result_media = $conn->query($query_media);
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL | Media Analytics</title>
    <link rel="icon" type="image/png" href="assets/MEeL.png">
    <script src="assets/js/tailwind.js"></script>
    <style>
        body { background-color: #0b0e14; }
        .glass { background: rgba(22, 27, 34, 0.7); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.05); }
        .no-scrollbar::-webkit-scrollbar { display: none; }
    </style>
</head>
<body class="text-gray-300 p-4 md:p-8 font-sans">
    <div class="max-w-6xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <div class="flex items-center gap-3">
                <div class="p-3 bg-blue-600/20 rounded-2xl">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                </div>
                <div>
                    <h1 class="text-2xl font-bold text-white tracking-tight">Media Analytics</h1>
                    <p class="text-[10px] text-gray-500 uppercase tracking-widest font-bold">Media Monitor</p>
                </div>
            </div>
            <a href="system_check.php" class="p-2 hover:bg-gray-800 rounded-xl transition-all">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-gray-500"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
            </a>
        </div>

        <div class="flex flex-wrap gap-4 mb-6">
            <div class="flex gap-1 bg-white/5 p-1 rounded-xl border border-white/5">
                <?php foreach(['views' => 'Views', 'likes' => 'Likes', 'dislikes' => 'Dislikes', 'title' => 'Name'] as $k => $v): ?>
                    <a href="?sort=<?= $k ?>&type=<?= $type_filter ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition-all <?= $sort == $k ? 'bg-blue-600 text-white' : 'text-gray-500 hover:text-white' ?>"><?= $v ?></a>
                <?php endforeach; ?>
            </div>

            <div class="flex gap-1 bg-white/5 p-1 rounded-xl border border-white/5">
                <?php foreach(['all' => 'All', 'video' => 'Videos', 'music' => 'Music'] as $k => $v): ?>
                    <a href="?sort=<?= $sort ?>&type=<?= $k ?>" class="px-3 py-1.5 rounded-lg text-[10px] font-bold uppercase transition-all <?= $type_filter == $k ? 'bg-orange-500 text-white' : 'text-gray-500 hover:text-white' ?>"><?= $v ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="glass rounded-3xl overflow-hidden shadow-2xl">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-[10px] font-bold text-gray-500 uppercase tracking-widest border-b border-white/5 bg-white/[0.02]">
                        <th class="py-4 px-6">Content Title</th>
                        <th class="py-4 px-4 text-center">Views</th>
                        <th class="py-4 px-4 text-center">Likes</th>
                        <th class="py-4 px-4 text-center">Dislikes</th>
                        <th class="py-4 px-6 text-right">Type</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    <?php if ($result_media->num_rows > 0): ?>
                        <?php while ($row = $result_media->fetch_assoc()): ?>
                            <?php 
                                // Set path klik judul berdasarkan tipe media
                                $target_path = ($row['media_type'] === 'video') ? "video/watch.php?id=" . $row['id'] : "music/watch.php?id=" . $row['id'];
                            ?>
                            <tr class="hover:bg-white/[0.02] transition-colors group">
                                <td class="py-4 px-6">
                                    <a href="<?= $target_path ?>" class="flex flex-col group/item">
                                        <span class="text-sm font-semibold text-white group-hover/item:text-blue-400 transition-colors"><?= htmlspecialchars($row['title']) ?></span>
                                        <span class="text-[9px] text-gray-600 uppercase flex items-center gap-1">
                                            ID: <?= $row['id'] ?> 
                                            <svg xmlns="http://www.w3.org/2000/svg" width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="opacity-0 group-hover/item:opacity-100"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                                        </span>
                                    </a>
                                </td>
                                <td class="py-4 px-4 text-center text-xs font-mono"><?= number_format($row['views']) ?></td>
                                <td class="py-4 px-4 text-center text-xs font-mono text-green-500"><?= number_format($row['likes']) ?></td>
                                <td class="py-4 px-4 text-center text-xs font-mono text-red-500"><?= number_format($row['dislikes']) ?></td>
                                <td class="py-4 px-6 text-right">
                                    <span class="text-[8px] px-2 py-1 rounded-md border font-black <?= ($row['media_type'] == 'video') ? 'bg-red-500/10 text-red-500 border-red-500/20' : 'bg-orange-500/10 text-orange-500 border-orange-500/20' ?>">
                                        <?= strtoupper($row['media_type']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="py-20 text-center text-gray-600 text-xs italic">No media found in database.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>