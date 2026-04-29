<?php
// index.php - Versi Optimasi MEeL Cloud
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../auth/auth.php';
require '../auth/config.php';
require '../helpers.php';

// 1. Validasi Sesi & Role
$user_id = $_SESSION['user_id'] ?? die("Sesi berakhir.");
$user_role = $_SESSION['role'] ?? 'guest';
$username = $_SESSION['username'] ?? 'User';

if (!in_array($user_role, ['admin', 'member'])) {
    die(include '../err/denied.php');
}

// 2. Logika Scope
$current_scope = (isset($_GET['scope']) && $_GET['scope'] === 'private') ? 'private' : 'public';

// 3. Fungsi Pengambil File (Lebih Efisien)
function fetch_drive_data($type, $scope, $username)
{
    $base_dir = ($scope === 'private')
        ? "../data_drive/private_admins/{$username}/{$type}"
        : "../data_drive/public/{$type}";

    if (!is_dir($base_dir)) {
        mkdir($base_dir, 0777, true);
    }

    $files = [];
    $iterator = new DirectoryIterator($base_dir);
    foreach ($iterator as $fileinfo) {
        if (!$fileinfo->isDot() && $fileinfo->isFile()) {
            $files[] = [
                'name' => $fileinfo->getFilename(),
                'size' => $fileinfo->getSize(),
                'time' => $fileinfo->getMTime(),
                'path' => $base_dir . '/' . $fileinfo->getFilename(),
                'ext'  => strtolower($fileinfo->getExtension())
            ];
        }
    }

    // Sortir: Terbaru di atas
    usort($files, fn($a, $b) => $b['time'] - $a['time']);
    return $files;
}

$videos   = fetch_drive_data('video', $current_scope, $username);
$audios   = fetch_drive_data('audio', $current_scope, $username);
$dokumens = fetch_drive_data('dokumen', $current_scope, $username);
?>

<!DOCTYPE html>
<html lang="id" class="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MEeL Cloud | Dashboard</title>
    <link rel="icon" href="../assets/MEeL.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="../assets/css/drive.css">
    <style>
        :root {
            --bg-main: #0b0f1a;
            --bg-card: #161b2a;
            --accent-blue: #3b82f6;
        }

        body {
            background-color: var(--bg-main);
            color: #f3f4f6;
            font-family: 'Inter', sans-serif;
        }

        .glass {
            background: rgba(22, 27, 42, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        .nav-active {
            background: rgba(59, 130, 246, 0.1);
            border-left: 4px solid var(--accent-blue);
            color: var(--accent-blue);
        }
    </style>
</head>

<body class="antialiased">

    <div class="flex min-h-screen">
        <aside class="w-64 glass border-r border-gray-800 hidden md:flex flex-col sticky top-0 h-screen">
            <div class="p-6">
                <div class="flex items-center gap-3 mb-8">
                    <img src="../assets/MEeL.png" class="w-10 h-10 rounded-xl shadow-lg shadow-blue-500/20" alt="Logo">
                    <div>
                        <h1 class="font-bold text-lg leading-none">MEeL <span class="text-blue-500">Cloud</span></h1>
                        <p class="text-[10px] text-gray-500 tracking-widest uppercase mt-1">Storage System</p>
                    </div>
                </div>

                <nav class="space-y-1">
                    <p class="text-[10px] font-bold text-gray-600 uppercase tracking-widest px-3 mb-2">Scope</p>
                    <a href="?scope=public" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition <?= $current_scope === 'public' ? 'nav-active' : '' ?>">
                        <i data-lucide="globe" class="w-5 h-5"></i> Public Space
                    </a>
                    <a href="?scope=private" class="flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition <?= $current_scope === 'private' ? 'nav-active' : '' ?>">
                        <i data-lucide="shield-check" class="w-5 h-5"></i> Private Cloud
                    </a>
                </nav>

                <nav class="mt-10 space-y-1">
                    <p class="text-[10px] font-bold text-gray-600 uppercase tracking-widest px-3 mb-2">Kategori</p>
                    <button onclick="showSection('video', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop active">
                        <i data-lucide="play-circle" class="w-5 h-5 text-red-500"></i> Video
                    </button>
                    <button onclick="showSection('audio', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop text-gray-400">
                        <i data-lucide="music" class="w-5 h-5 text-orange-500"></i> Audio
                    </button>
                    <button onclick="showSection('dokumen', this)" class="w-full flex items-center gap-3 px-4 py-3 rounded-lg hover:bg-gray-800 transition nav-btn-desktop text-gray-400">
                        <i data-lucide="file-text" class="w-5 h-5 text-green-500"></i> Dokumen
                    </button>
                </nav>
            </div>

            <div class="mt-auto p-4 border-t border-gray-800 bg-black/20">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-500 to-purple-600 flex items-center justify-center font-bold">
                        <?= strtoupper(substr($username, 0, 1)) ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-sm font-semibold truncate"><?= htmlspecialchars($username) ?></p>
                        <p class="text-[10px] text-gray-500 uppercase"><?= $user_role ?></p>
                    </div>
                </div>
            </div>
        </aside>

        <main class="flex-1 p-6 md:p-10">
            <header class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
                <div>
                    <h2 id="sectionHeading" class="text-3xl font-extrabold tracking-tight">
                        Drive <span id="sectionAccent" class="text-red-500">Video</span>
                    </h2>
                    <p id="fileCount" class="text-sm text-gray-500 mt-1">Memuat file...</p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="relative">
                        <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                        <input type="text" placeholder="Cari file..." class="bg-gray-900 border border-gray-800 rounded-full py-2 pl-10 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 w-64">
                    </div>
                </div>
            </header>

            <?php if ($user_role === 'member'):
                $usage = get_user_usage($username);
                $limit = 20 * 1024 * 1024 * 1024;
                $perc = min(100, ($usage / $limit) * 100);
            ?>
                <div class="glass rounded-2xl p-4 mb-8 flex items-center gap-6">
                    <div class="flex-1">
                        <div class="flex justify-between text-[11px] font-bold uppercase mb-2">
                            <span class="text-gray-400">Penyimpanan Terpakai</span>
                            <span class="<?= $perc > 80 ? 'text-red-500' : 'text-blue-500' ?>"><?= format_bytes($usage) ?> / 20 GB</span>
                        </div>
                        <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-blue-500 to-purple-500 transition-all duration-500" style="width: <?= $perc ?>%"></div>
                        </div>
                    </div>
                    <a href="index.php?scope=<?= $current_scope ?>" class="p-2 hover:bg-gray-800 rounded-lg"><i data-lucide="refresh-cw" class="w-4 h-4"></i></a>
                </div>
            <?php endif; ?>

            <section class="glass rounded-2xl p-6 mb-8 border-dashed border-2 border-gray-800 hover:border-blue-500/50 transition-colors">
                <form action="upload.php" method="POST" enctype="multipart/form-data" class="flex flex-col md:flex-row items-center gap-6">
                    <input type="hidden" name="scope" value="<?= $current_scope ?>">

                    <div class="flex-1 w-full">
                        <label for="fileInput" class="flex items-center justify-center gap-3 p-4 bg-black/30 rounded-xl cursor-pointer hover:bg-black/50 transition border border-gray-800">
                            <i data-lucide="cloud-upload" class="w-6 h-6 text-blue-500"></i>
                            <span id="fileLabel" class="text-sm text-gray-400 font-medium">Tarik file atau klik untuk memilih</span>
                            <input type="file" name="file_drive" id="fileInput" class="hidden" onchange="updateFileName(this)">
                        </label>
                    </div>

                    <button type="submit" name="submit_upload" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-8 py-4 rounded-xl font-bold flex items-center justify-center gap-2 transition-all shadow-lg shadow-blue-600/20">
                        Unggah Berkas <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </form>
            </section>

            <div id="drive-video" class="drive-section">
                <?php render_file_grid_new($videos, '#ef4444', 'play', 'video', $current_scope); ?>
            </div>
            <div id="drive-audio" class="drive-section hidden">
                <?php render_file_grid_new($audios, '#f97316', 'music', 'audio', $current_scope); ?>
            </div>
            <div id="drive-dokumen" class="drive-section hidden">
                <?php render_file_grid_new($dokumens, '#10b981', 'file-text', 'dokumen', $current_scope); ?>
            </div>
        </main>
    </div>

    <?php
    // Fungsi Grid Baru yang Lebih Indah
    function render_file_grid_new($files, $accent, $icon, $type, $scope)
    {
        if (empty($files)) {
            echo '<div class="flex flex-col items-center justify-center py-20 opacity-20">
                <i data-lucide="folder-open" class="w-16 h-16 mb-4"></i>
                <p>Tidak ada file ditemukan</p>
              </div>';
            return;
        }

        echo '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">';

        foreach ($files as $f) {
            // 1. Persiapkan Data (Clean)
            $name = htmlspecialchars($f['name'], ENT_QUOTES);
            $path = htmlspecialchars($f['path'], ENT_QUOTES);
            $size = format_bytes($f['size']);
            $date = date('d M Y', $f['time']);

            // 2. Persiapkan URL
            $dl_url  = "download.php?file=" . urlencode($f['name']) . "&type=$type&scope=$scope";
            $del_url = "delete.php?file=" . urlencode($f['name']) . "&type=$type&scope=$scope";

            // 3. Render HTML
            echo "
        <div class='glass p-4 rounded-2xl group hover:border-blue-500/50 transition-all duration-300 transform hover:-translate-y-1 shadow-xl hover:shadow-blue-900/10'>
            <div class='flex items-start justify-between mb-4'>
                <div class='p-3 rounded-xl bg-gray-900 group-hover:bg-blue-500/10 transition'>
                    <i data-lucide='$icon' class='w-6 h-6' style='color: $accent'></i>
                </div>
                
                <div class='flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity'>
                    <button onclick=\"openPreview('$path', '$type', '$name')\" class='p-2 hover:bg-blue-500/20 rounded-lg text-blue-400' title='Pratinjau'>
                        <i data-lucide='eye' class='w-4 h-4'></i>
                    </button>
                    
                    <a href='$dl_url' class='p-2 hover:bg-green-500/20 rounded-lg text-green-400' title='Unduh'>
                        <i data-lucide='download' class='w-4 h-4'></i>
                    </a>
                    
                    <a href='$del_url' onclick=\"return confirm('Hapus file ini?')\" class='p-2 hover:bg-red-500/20 rounded-lg text-red-400' title='Hapus'>
                        <i data-lucide='trash-2' class='w-4 h-4'></i>
                    </a>
                </div>
            </div>

            <h3 class='text-sm font-bold truncate mb-1 text-gray-200' title='$name'>$name</h3>
            <div class='flex justify-between items-center text-[10px] text-gray-500 font-medium uppercase tracking-tighter'>
                <span>$size</span>
                <span>$date</span>
            </div>
        </div>";
        }

        echo '</div>';
    }
    ?>

    <div id="previewModal" class="hidden fixed inset-0 z-[100] bg-black/90 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-[#161b2a] border border-gray-800 w-full max-w-4xl rounded-2xl overflow-hidden shadow-2xl relative">

            <div class="flex items-center justify-between p-4 border-bottom border-gray-800 bg-black/20">
                <div class="flex items-center gap-2">
                    <i data-lucide="file" class="w-4 h-4 text-blue-500"></i>
                    <h3 id="previewTitle" class="text-sm font-semibold truncate max-w-[200px] md:max-w-md text-gray-300">Nama File</h3>
                </div>
                <button onclick="closePreview()" class="p-2 hover:bg-red-500/20 text-gray-500 hover:text-red-500 rounded-lg transition">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>

            <div id="previewContent" class="min-h-[300px] flex items-center justify-center bg-black/40">
            </div>

        </div>
    </div>

    <script>
        // 1. Inisialisasi Ikon Lucide
        lucide.createIcons();

        // 2. Data untuk UI Dynamics
        const counts = {
            video: document.querySelectorAll('#drive-video .glass').length,
            audio: document.querySelectorAll('#drive-audio .glass').length,
            dokumen: document.querySelectorAll('#drive-dokumen .glass').length
        };

        const accents = {
            video: {
                color: 'text-red-500',
                label: 'Video'
            },
            audio: {
                color: 'text-orange-500',
                label: 'Audio'
            },
            dokumen: {
                color: 'text-green-500',
                label: 'Dokumen'
            }
        };

        // 3. Navigasi Antar Seksi (Video/Audio/Dokumen)
        function showSection(id, btn) {
            // Sembunyikan semua seksi
            document.querySelectorAll('.drive-section').forEach(s => s.classList.add('hidden'));

            // Tampilkan seksi yang dipilih
            const target = document.getElementById('drive-' + id);
            if (target) target.classList.remove('hidden');

            // Update Styling Tombol Sidebar (Desktop)
            document.querySelectorAll('.nav-btn-desktop').forEach(b => {
                b.classList.remove('nav-active', 'text-blue-500');
                b.classList.add('text-gray-400');
            });
            if (btn) {
                btn.classList.add('nav-active');
                btn.classList.remove('text-gray-400');
            }

            // Update Header Text & Warna
            const headingAccent = document.getElementById('sectionAccent');
            headingAccent.innerText = accents[id].label;
            headingAccent.className = accents[id].color;

            document.getElementById('fileCount').innerText = `${counts[id]} file ditemukan`;

            // Re-render ikon jika ada konten baru
            lucide.createIcons();
        }

        // 4. Update Nama File Saat Pilih Upload
        function updateFileName(input) {
            const label = document.getElementById('fileLabel');
            if (input.files.length > 0) {
                label.innerText = "Siap unggah: " + input.files[0].name;
                label.classList.remove('text-gray-400');
                label.classList.add('text-blue-400', 'font-bold');
            }
        }

        function openPreview(path, type, name) {
            const modal = document.getElementById('previewModal');
            const content = document.getElementById('previewContent');
            const title = document.getElementById('previewTitle');

            // Set Judul
            title.innerText = name;

            // Tampilkan Modal
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            // Loading state
            content.innerHTML = '<div class="text-gray-500 flex flex-col items-center"><div class="animate-spin mb-2">⏳</div> Memuat pratinjau...</div>';

            let html = '';
            const ext = name.split('.').pop().toLowerCase();

            // Logika Pemilihan Player
            if (type === 'video') {
                html = `
            <video controls autoplay class="max-w-full max-h-[75vh] rounded-lg shadow-2xl bg-black">
                <source src="${path}" type="video/mp4">
                <source src="${path}" type="video/webm">
                Browser Anda tidak mendukung pratinjau video.
            </video>`;
            } else if (type === 'audio') {
                html = `
            <div class="bg-gray-900 p-10 rounded-2xl border border-gray-800 w-full max-w-md text-center shadow-2xl">
                <div class="mb-6 inline-block p-4 bg-orange-500/10 rounded-full">
                    <i data-lucide="music" class="w-12 h-12 text-orange-500"></i>
                </div>
                <audio controls autoplay class="w-full">
                    <source src="${path}" type="audio/mpeg">
                    <source src="${path}" type="audio/wav">
                    Browser Anda tidak mendukung pratinjau audio.
                </audio>
                <p class="text-gray-500 text-xs mt-4 truncate">${name}</p>
            </div>`;
            } else if (type === 'dokumen') {
                const imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                if (imgExts.includes(ext)) {
                    html = `<img src="${path}" class="max-w-full max-h-[75vh] object-contain rounded-lg shadow-2xl">`;
                } else {
                    html = `
                <div class="text-center p-10 bg-gray-900 rounded-2xl border border-gray-800">
                    <i data-lucide="file-warning" class="w-16 h-16 text-gray-600 mx-auto mb-4"></i>
                    <p class="text-gray-400 mb-4">Pratinjau tidak tersedia untuk file .${ext}</p>
                    <a href="${path}" download class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition font-bold">
                        Unduh untuk Melihat
                    </a>
                </div>`;
                }
            }

            // Masukkan ke DOM dengan delay sedikit agar transisi mulus
            setTimeout(() => {
                content.innerHTML = html;
                if (window.lucide) lucide.createIcons();
            }, 200);
        }

        function closePreview() {
            const modal = document.getElementById('previewModal');
            const content = document.getElementById('previewContent');

            // Penting: Kosongkan innerHTML agar video/musik berhenti saat modal ditutup
            content.innerHTML = '';
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // 6. Fitur Pencarian Sederhana (Client-side)
        document.querySelector('input[placeholder="Cari file..."]').addEventListener('input', function(e) {
            const keyword = e.target.value.toLowerCase();
            const activeSection = document.querySelector('.drive-section:not(.hidden)');
            const cards = activeSection.querySelectorAll('.glass');

            cards.forEach(card => {
                const fileName = card.querySelector('h3').innerText.toLowerCase();
                if (fileName.includes(keyword)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Jalankan hitung file pertama kali
        document.addEventListener('DOMContentLoaded', () => {
            showSection('video', document.querySelector('.nav-btn-desktop.active'));
        });
    </script>

</body>

</html>