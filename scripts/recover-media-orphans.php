<?php
/**
 * Pulihkan baris media yang hilang dari file yang masih ada di disk.
 *
 * LATAR BELAKANG
 * --------------
 * Database MEeL di-DROP lalu dibangun ulang dari skema. Data dipulihkan dari
 * backup 22 Juli 2026, sehingga semua media yang diunggah SETELAH tanggal itu
 * kehilangan baris DB-nya -- padahal file fisiknya masih utuh di disk.
 * Dashboard admin melaporkan 1805 "file sampah (tidak ada di DB)".
 *
 * ANGKA 1805 MENYESATKAN, TAPI BUKAN BERARTI 1805 DATA HILANG
 * ----------------------------------------------------------
 * Pemetaan file ke baris tidak 1:1. Video dicocokkan per FOLDER (satu video =
 * satu stream HLS berisi ~30 file), musik per file, thumbnail per nama.
 *
 *   video/upload/video/   10.845 file -> 1.725 orphan ->  53 baris hilang
 *   video thumbnail/         496 file ->   52 orphan -> ikut pulih bersama induk
 *   music/upload/file/        96 file ->   14 orphan ->  14 baris hilang
 *   music thumbnail/          94 file ->   14 orphan -> ikut pulih bersama induk
 *   books/upload/manga/    2.255 file ->    0 orphan ->  tidak ada yang hilang
 *
 * Jadi yang harus dibangun ulang hanya 53 video + 14 musik. Sisanya segmen .ts,
 * subtitle .vtt, sprite, dan thumbnail turunan -- terlihat "sampah" hanya karena
 * induknya hilang.
 *
 * ATURAN YANG MENGIKAT
 * --------------------
 *  - Skrip ini TIDAK PERNAH menghapus, memindah, atau menulis file di disk.
 *  - Skrip ini TIDAK PERNAH menyentuh tabel `users`. Ia hanya menulis kolom
 *    user_id pada video/music, nilainya dari flag --user-id.
 *  - Tanpa --apply, skrip HANYA melaporkan. Tidak ada penulisan DB sama sekali.
 *  - --apply berjalan dalam satu transaksi dan membatalkan diri kalau jumlah
 *    baris tidak sesuai harapan.
 *  - Idempoten: baris yang `filename`-nya sudah ada dilewati, bukan diduplikasi.
 *
 * PEMAKAIAN
 * ---------
 *   php scripts/recover-media-orphans.php                     # laporan saja
 *   php scripts/recover-media-orphans.php --lookup            # + cari di YouTube
 *   php scripts/recover-media-orphans.php --lookup --apply    # terapkan
 *
 * @see controllers/admin/admin_data.php (logika deteksi orphan yang disalin)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Access denied. Jalankan dari terminal.');
}

require_once __DIR__ . '/../auth/config.php';

$opt = [
    'apply'    => false,
    'lookup'   => false,
    'user-id'  => 9,
    'report'   => __DIR__ . '/../temp/cache/recover-media-report.csv',
    'lookup-log' => __DIR__ . '/../temp/cache/recover-media-lookup.log',
    'toleransi-abs' => 20,
    'toleransi-rel' => 0.10,
    'jeda'     => 2,
    'dari-laporan' => '',
];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        continue;
    }
    $key = $m[1];
    if (!array_key_exists($key, $opt)) {
        fwrite(STDERR, "Opsi tidak dikenal: --{$key}\n");
        exit(2);
    }
    if ($key === 'apply' || $key === 'lookup') {
        $opt[$key] = true;
    } else {
        $opt[$key] = $m[2] ?? '';
    }
}
$opt['user-id'] = (int) $opt['user-id'];
$opt['jeda'] = (int) $opt['jeda'];

/** Path yang bisa ditulis; temp/cache repo sering milik user lain (daemon). */
function meel_recover_writable(string $path): string
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $path;
    }
    return rtrim(sys_get_temp_dir(), '/') . '/' . basename($path);
}

$opt['report'] = meel_recover_writable($opt['report']);
$opt['lookup-log'] = meel_recover_writable($opt['lookup-log']);

if (!isset($conn) || !$conn instanceof mysqli) {
    fwrite(STDERR, "Koneksi database gagal.\n");
    exit(1);
}

$cekUser = $conn->prepare("SELECT id, username, role FROM users WHERE id = ? LIMIT 1");
$cekUser->bind_param('i', $opt['user-id']);
$cekUser->execute();
$userDipakai = $cekUser->get_result()->fetch_assoc();
$cekUser->close();
if ($userDipakai === null) {
    fwrite(STDERR, "❌ user_id {$opt['user-id']} tidak ada di tabel users.\n");
    fwrite(STDERR, "   Lihat daftar user yang tersedia lebih dulu:\n");
    fwrite(STDERR, "     php scripts/recover-media-orphans.php --user-id=<id yang ada>\n");
    exit(1);
}
if (!function_exists('generate_search_metadata')) {
    require_once __DIR__ . '/../modules/core/helpers/metadata.php';
}

function meel_recover_scan(string $dir): array
{
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    foreach (scandir($dir) as $v) {
        if ($v === '.' || $v === '..') {
            continue;
        }
        $p = rtrim($dir, '/') . '/' . $v;
        if (is_file($p)) {
            $out[] = $p;
        } elseif (is_dir($p)) {
            $out = array_merge($out, meel_recover_scan($p . '/'));
        }
    }
    return $out;
}

/** Panjang playlist HLS = jumlah EXTINF. Persis yang diputar pemutar. */
function meel_recover_hls_duration(string $m3u8): int
{
    $total = 0.0;
    foreach (file($m3u8) ?: [] as $line) {
        if (preg_match('/^#EXTINF:([\d.]+)/', trim($line), $m)) {
            $total += (float) $m[1];
        }
    }
    return (int) round($total);
}

function meel_recover_ffprobe_duration(string $file): int
{
    $cmd = 'ffprobe -v error -show_entries format=duration -of csv=p=0 '
        . escapeshellarg($file) . ' 2>/dev/null';
    return (int) round((float) trim((string) shell_exec($cmd)));
}

/** slug -> teks: "abunomariti-danshingaru-leo" -> "abunomariti danshingaru leo" */
function meel_recover_deslug(string $slug): string
{
    $t = str_replace(['_', '-'], ' ', $slug);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim((string) $t);
}

echo "═══ Mendeteksi file tanpa baris DB ═══\n";

$ignored = array_flip(['.htaccess', 'default_video.png', 'music_default.png',
                       'default_cover.jpg', 'default.thumb.webp']);

$dbVideoFolders = [];
$dbVideoThumbs  = [];
$dbMusicFiles   = [];
$dbMusicThumbs  = [];

$r = $conn->query("SELECT filename, thumbnail FROM video");
while ($x = $r->fetch_assoc()) {
    $folder = basename(dirname((string) $x['filename']));
    if ($folder !== '.' && $folder !== '') {
        $dbVideoFolders[$folder] = true;
    }
    if (!empty($x['thumbnail'])) {
        $dbVideoThumbs[$x['thumbnail']] = true;
    }
}
$r = $conn->query("SELECT filename, thumbnail FROM music");
while ($x = $r->fetch_assoc()) {
    if (!empty($x['filename'])) {
        $dbMusicFiles[$x['filename']] = true;
    }
    if (!empty($x['thumbnail'])) {
        $dbMusicThumbs[$x['thumbnail']] = true;
    }
}

if ($opt['dari-laporan'] !== '') {
    goto terapkan;
}

$videoDir = meel_media_base_path('video') . '/video/';
$videoThumbDir = meel_media_base_path('video') . '/thumbnail/';
$musicDir = meel_media_base_path('music') . '/file/';
$musicThumbDir = meel_media_base_path('music') . '/thumbnail/';

function meel_recover_thumb_index(string $dir): array
{
    $idx = [];
    foreach (meel_recover_scan($dir) as $p) {
        $name = basename($p);
        if (preg_match('/_sm\.[^.]+$/', $name)) {
            continue;
        }
        $idx[pathinfo($name, PATHINFO_FILENAME)] = $name;
        $idx[$name] = $name;
    }
    return $idx;
}
$videoThumbIdx = meel_recover_thumb_index($videoThumbDir);
$musicThumbIdx = meel_recover_thumb_index($musicThumbDir);

$candidates = ['video' => [], 'music' => []];

$folderTidakLengkap = [];
foreach (scandir($videoDir) as $entry) {
    if ($entry === '.' || $entry === '..' || !is_dir($videoDir . $entry)) {
        continue;
    }
    $folder = $entry;
    if (isset($dbVideoFolders[$folder])) {
        continue;
    }

    $m3u8 = null;
    foreach (scandir($videoDir . $folder) as $f) {
        if (str_ends_with($f, '.m3u8') && is_file($videoDir . $folder . '/' . $f)) {
            $m3u8 = $videoDir . $folder . '/' . $f;
            break;
        }
    }
    if ($m3u8 === null) {
        $folderTidakLengkap[] = $folder;
        continue;
    }

    $dur = meel_recover_hls_duration($m3u8);
    $thumb = $videoThumbIdx[$folder . '_thumb'] ?? $videoThumbIdx[$folder] ?? 'default_thumb.webp';
    $slugTitle = meel_recover_deslug($folder);

    $candidates['video'][$folder] = [
        'jenis'        => 'video',
        'folder'       => $folder,
        'filename'     => "video/{$folder}/{$folder}.m3u8",
        'judul_awal'   => $slugTitle,
        'judul'        => $slugTitle,
        'deskripsi'    => null,
        'artist'       => null,
        'album'        => null,
        'thumbnail'    => $thumb,
        'durasi'       => $dur,
        'upload_date'  => date('Y-m-d H:i:s', (int) (filemtime($m3u8) ?: time())),
        'sumber'       => 'slug',
        'yt_id'        => '',
        'yt_durasi'    => 0,
    ];
}

echo "  folder tanpa .m3u8 (unggahan terputus, tidak dipulihkan): "
   . count($folderTidakLengkap) . "\n";

foreach (meel_recover_scan($musicDir) as $p) {
    $name = basename($p);
    if (isset($ignored[$name]) || isset($dbMusicFiles[$name])) {
        continue;
    }
    $stem = pathinfo($name, PATHINFO_FILENAME);
    $thumb = $musicThumbIdx[$stem] ?? $musicThumbIdx[$name] ?? 'music_default.png';
    $dur = meel_recover_ffprobe_duration($p);
    $slugTitle = meel_recover_deslug($stem);

    $candidates['music'][$stem] = [
        'jenis'        => 'music',
        'folder'       => $stem,
        'filename'     => $name,
        'judul_awal'   => $slugTitle,
        'judul'        => $slugTitle,
        'deskripsi'    => null,
        'artist'       => null,
        'album'        => null,
        'thumbnail'    => $thumb,
        'durasi'       => $dur,
        'upload_date'  => date('Y-m-d H:i:s', (int) (filemtime($p) ?: time())),
        'sumber'       => 'slug',
        'yt_id'        => '',
        'yt_durasi'    => 0,
    ];
}

$nVideo = count($candidates['video']);
$nMusic = count($candidates['music']);
echo "  kandidat video : {$nVideo}\n";
echo "  kandidat musik : {$nMusic}\n";
echo "  TOTAL          : " . ($nVideo + $nMusic) . "\n\n";

if ($nVideo === 0 && $nMusic === 0) {
    echo "Tidak ada yang perlu dipulihkan.\n";
    exit(0);
}

if ($opt['lookup']) {
    $ytdlp = trim((string) shell_exec('command -v yt-dlp 2>/dev/null'));
    if ($ytdlp === '') {
        fwrite(STDERR, "yt-dlp tidak ditemukan di PATH — lookup dilewati.\n");
    } else {
        echo "═══ Mencari judul & deskripsi di YouTube ═══\n";
        echo "    (divalidasi lewat durasi; yang tidak cocok dibiarkan dari slug)\n\n";
        $log = fopen($opt['lookup-log'], 'a');
        if ($log === false) {
            fwrite(STDERR, "  ⚠ log lookup tidak bisa ditulis ({$opt['lookup-log']}) — lanjut tanpa log\n");
            $log = fopen('php://memory', 'a');
        }

        $all = [];
        foreach (['video', 'music'] as $k) {
            foreach ($candidates[$k] as $key => $c) {
                $all[] = [$k, $key];
            }
        }

        $i = 0;
        foreach ($all as [$k, $key]) {
            $i++;
            $c = &$candidates[$k][$key];
            $query = $c['judul_awal'];
            if ($query === '') {
                continue;
            }
            printf("  [%d/%d] %s %-42s ", $i, count($all), $k === 'video' ? 'V' : 'M',
                mb_strimwidth($query, 0, 40, '…'));

            $cmd = escapeshellarg($ytdlp)
                . ' --skip-download --no-warnings --ignore-errors'
                . ' --playlist-end 5 --dump-json'
                . ' ' . escapeshellarg('ytsearch5:' . $query)
                . ' 2>>' . escapeshellarg($opt['lookup-log']);
            $out = (string) shell_exec($cmd);

            $dipakai = null;
            foreach (array_filter(array_map('trim', explode("\n", $out))) as $line) {
                $j = json_decode($line, true);
                if (!is_array($j) || empty($j['id'])) {
                    continue;
                }
                $d = (int) ($j['duration'] ?? 0);
                $loc = $c['durasi'];

                $toler = max($opt['toleransi-abs'], (int) ($loc * $opt['toleransi-rel']));
                if ($loc > 0 && $d > 0 && abs($d - $loc) <= $toler) {
                    $dipakai = $j;
                    break;
                }
            }

            if ($dipakai === null) {
                echo "tidak ada yang cocok durasi → pakai slug\n";
            } else {
                $c['judul']        = $dipakai['title'];
                $c['deskripsi']    = $dipakai['description'] ?: null;
                $c['artist']       = $dipakai['uploader'] ?: null;
                $c['yt_id']        = $dipakai['id'];
                $c['yt_durasi']    = (int) $dipakai['duration'];
                $c['sumber']       = 'youtube';
                printf("✓ %s (%ds)\n", mb_strimwidth((string) $dipakai['title'], 0, 46, '…'), $c['yt_durasi']);
                fwrite($log, $c['yt_id'] . "\t" . $query . "\n");
            }
            unset($c);
            if ($opt['jeda'] > 0) {
                sleep($opt['jeda']);
            }
        }
        fclose($log);
        echo "\n";
    }
}

$rows = [];
foreach (['video', 'music'] as $k) {
    foreach ($candidates[$k] as $c) {
        $rows[] = $c;
    }
}

$fh = fopen($opt['report'], 'w');
if ($fh === false) {
    fwrite(STDERR, "Tidak bisa menulis laporan: {$opt['report']}\n");
    exit(1);
}
fputcsv($fh, ['jenis', 'filename', 'judul', 'deskripsi', 'artist', 'album',
              'thumbnail', 'durasi', 'upload_date', 'sumber', 'yt_id', 'yt_durasi']);
foreach ($rows as $c) {
    fputcsv($fh, [$c['jenis'], $c['filename'], $c['judul'], $c['deskripsi'],
                  $c['artist'], $c['album'], $c['thumbnail'], $c['durasi'],
                  $c['upload_date'], $c['sumber'], $c['yt_id'], $c['yt_durasi']]);
}
fclose($fh);

$dariYoutube = count(array_filter($rows, fn($c) => $c['sumber'] === 'youtube'));
printf("Laporan  : %s (%d baris)\n", $opt['report'], count($rows));
printf("Metadata : %d dari YouTube, %d tetap dari slug\n\n",
    $dariYoutube, count($rows) - $dariYoutube);

echo "═══ Ringkasan kandidat ═══\n";
foreach ($rows as $c) {
    printf("  %-5s %-46s %5ds  %s\n",
        $c['jenis'] === 'video' ? 'VID' : 'MUS',
        mb_strimwidth($c['judul'], 0, 44, '…'),
        $c['durasi'],
        $c['deskripsi'] ? 'deskripsi ✓' : 'deskripsi —');
}

terapkan:

if ($opt['dari-laporan'] !== '') {
    $src = $opt['dari-laporan'];
    if (!is_file($src)) {
        fwrite(STDERR, "Laporan tidak ditemukan: {$src}\n");
        exit(1);
    }
    $rows = [];
    $fh = fopen($src, 'r');
    $head = fgetcsv($fh);
    $need = ['jenis', 'filename', 'judul', 'durasi', 'upload_date'];
    foreach ($need as $k) {
        if (!in_array($k, $head, true)) {
            fwrite(STDERR, "Laporan tidak punya kolom '{$k}'\n");
            exit(1);
        }
    }
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) !== count($head)) {
            continue;
        }
        $c = array_combine($head, $r);
        $c['deskripsi'] = ($c['deskripsi'] ?? '') !== '' ? $c['deskripsi'] : null;
        $c['artist']    = ($c['artist'] ?? '') !== '' ? $c['artist'] : null;
        $c['album']     = ($c['album'] ?? '') !== '' ? $c['album'] : null;
        $c['durasi']    = (int) $c['durasi'];
        $rows[] = $c;
    }
    fclose($fh);
    if (count($rows) === 0) {
        fwrite(STDERR, "Laporan kosong (0 baris) -- tidak ada yang dipulihkan.\n");
        fwrite(STDERR, "Laporan yang kosong hampir selalu berarti file CSV rusak, bukan\n");
        fwrite(STDERR, "memang tidak ada yang perlu dipulihkan. Dihentikan.\n");
        exit(1);
    }
    printf("Dari laporan: %s (%d baris)\n", $src, count($rows));
} elseif (!$opt['apply']) {
    echo "\nMode laporan. Tidak ada yang ditulis.\n";
    echo "Tinjau {$opt['report']}, lalu terapkan dengan:\n";
    echo "  php scripts/recover-media-orphans.php --apply --dari-laporan={$opt['report']}\n";
    exit(0);
}

$conn->begin_transaction();
try {
    $insVideo = $conn->prepare(
        "INSERT INTO video (title, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
         VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?)"
    );
    $insMusic = $conn->prepare(
        "INSERT INTO music (title, artist, album, description, filename, thumbnail, duration, views, user_id, search_metadata, upload_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?)"
    );
    if (!$insVideo || !$insMusic) {
        throw new RuntimeException('prepare gagal: ' . $conn->error);
    }

    $baru = 0;
    $lewat = 0;
    foreach ($rows as $c) {
        $cek = $conn->prepare("SELECT id FROM " . ($c['jenis'] === 'video' ? 'video' : 'music')
                             . " WHERE filename = ? LIMIT 1");
        $cek->bind_param('s', $c['filename']);
        $cek->execute();
        $ada = $cek->get_result()->fetch_assoc();
        $cek->close();
        if ($ada) {
            $lewat++;
            continue;
        }

        $meta = generate_search_metadata($c['judul'], (string) $c['artist'], (string) $c['album']);
        $uid  = $opt['user-id'];
        $tgl  = $c['upload_date'];

        $dur = (int) $c['durasi'];
        $jdl = (string) $c['judul'];
        $fil = (string) $c['filename'];
        $thm = (string) $c['thumbnail'];
        $dsk = $c['deskripsi'];
        $art = $c['artist'];
        $alb = $c['album'];

        if ($c['jenis'] === 'video') {
            $stmt = $insVideo;

            $stmt->bind_param('ssssiiss', $jdl, $dsk, $fil, $thm, $dur, $uid, $meta, $tgl);
        } else {
            $stmt = $insMusic;

            $stmt->bind_param('ssssssiiss', $jdl, $art, $alb, $dsk, $fil, $thm, $dur, $uid, $meta, $tgl);
        }
        if (!$stmt->execute()) {
            throw new RuntimeException("INSERT gagal ({$c['filename']}): " . $stmt->error);
        }

        $baru++;
    }

    $insVideo->close();
    $insMusic->close();

    $harus = count($rows);
    if ($baru + $lewat !== $harus) {
        throw new RuntimeException("Jumlah tidak sesuai: expecting {$harus}, got {$baru} new + {$lewat} existing");
    }

    $conn->commit();
    echo "\n✅ Selesai: {$baru} baris baru, {$lewat} dilewati (sudah ada).\n";
    echo "   user_id = {$opt['user-id']} ({$userDipakai['username']}, peran {$userDipakai['role']})\n";
    echo "   Tabel `users` hanya DIBACA untuk pengecekan di atas, tidak ditulis.\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\n❌ Dibatalkan: " . $e->getMessage() . "\n   Transaksi di-rollback, tidak ada perubahan.\n");
    exit(1);
}
