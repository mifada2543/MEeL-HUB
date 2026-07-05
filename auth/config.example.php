<?php
// Session config - hanya set jika session belum jalan
if (session_status() === PHP_SESSION_NONE) {
    $timeout = 43200; // 12 jam
    ini_set('session.gc_maxlifetime', $timeout);
    session_set_cookie_params($timeout, "/");
    session_name('meel');
    session_start();
}
// Sesuaikan sendiri dengan konfigurasi database Anda
$server   = "";
$username = "";
$password = "";
$db       = "";

// JALUR EDUKASI: Deteksi jika mereka malas membaca README dan langsung menjalankan kode
if (empty($server) || empty($username) || empty($db)) {
    die("<pre style='color: #ef4444; background: #1e1e2e; padding: 20px; border-radius: 5px; font-family: monospace;'>
[MEeL SYSTEM ERROR]
Wah, tampaknya kamu terlalu terburu-buru! 
Kamu belum mengisi konfigurasi database di file 'auth/config.php'.

Yang harus kamu lakukan:
1. Isi \$server, \$username, dan \$db dengan kredensial database lokalmu.
2. Pastikan database-nya sudah di-import.
3. Jangan lupa baca README.md lagi ya :)
</pre>");
}

// Database connection - ditulis langsung tanpa tanda kutip ganda ("")
$conn = new mysqli($server, $username, $password, $db);
if ($conn->connect_error) {
    die("Koneksi ke database gagal: " . $conn->connect_error);
}

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// CSRF Verification Function - return status instead of die
if (!function_exists('verify_csrf')) {
    function verify_csrf()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
                return false;
            }
        }
        return true;
    }
}

// Session timeout check (12 jam)
$timeout = 43200;
if (isset($_SESSION['LAST_ACTIVITY'])) {
    $elapsed_time = time() - $_SESSION['LAST_ACTIVITY'];
    if ($elapsed_time > $timeout) {
        session_unset();
        session_destroy();
        header("Location: ../auth/login.php?reason=expired");
        exit;
    }
}
$_SESSION['LAST_ACTIVITY'] = time();

// Include activity logger
include_once __DIR__ . '/../modules/activity_logger.php';

// Function to convert Japanese text to Romaji
if (!function_exists('getRomajiName')) {
    function getRomajiName($text)
    {
        if (empty($text)) return 'untitled';

        // 1. Kamus Koreksi Karakter Spesifik & Simbol
        $search = [
            '×',
            'x',
            'X',
            '*',
            '&',
            '/', // Simbol pemisah
            '【',
            '】',
            '「',
            '」',
            '(',
            ')', // Kurung
            '鏡音',
            '巡音',
            '初音'
        ];
        $replace = [
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            ' ',
            'かがみね',
            'めぐりね',
            'hatsune'
        ];
        $text = str_replace($search, $replace, $text);

        // 2. Eksekusi MeCab tanpa -Oyomi
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"]
        ];

        $process = proc_open('mecab', $descriptorspec, $pipes);

        $parsedText = '';
        if (is_resource($process)) {
            fwrite($pipes[0], $text);
            fclose($pipes[0]);

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            // Parsing baris per baris hasil MeCab
            $lines = explode("\n", trim($output));
            foreach ($lines as $line) {
                if ($line === 'EOS' || trim($line) === '') continue;

                $parts = explode("\t", $line);
                if (count($parts) >= 2) {
                    $surface = $parts[0];
                    $features = explode(',', $parts[1]);

                    $yomi = '*';
                    if (isset($features[7]) && $features[7] !== '*') {
                        $yomi = $features[7];
                    } elseif (isset($features[8]) && $features[8] !== '*') {
                        $yomi = $features[8];
                    }

                    if ($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) {
                        $parsedText .= ' ' . $yomi;
                    } else {
                        $parsedText .= ' ' . $surface;
                    }
                }
            }
            $text = trim($parsedText);
        }

        // 3. Gunakan rule php-intl
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);

        if ($transliterator) {
            $text = $transliterator->transliterate($text);
        }

        // 4. Sanitasi Akhir untuk Slug
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));

        return $clean ?: 'untitled-media';
    }
}

// ─── OPTIMASI: analisis gabungan (romaji + english) dalam SATU kali panggil MeCab ──
if (!function_exists('analyzeJapaneseText')) {
    function analyzeJapaneseText(string $text): array
    {
        $result = ['romaji' => 'untitled-media', 'english' => ''];
        if (empty(trim($text))) return $result;

        // 1. Preprocessing simbol/karakter khusus (sama seperti getRomajiName)
        $search  = ['×', 'x', 'X', '*', '&', '/', '【', '】', '「', '」', '(', ')', '鏡音', '巡音', '初音'];
        $replace = [' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', ' ', 'かがみね', 'めぐりね', 'hatsune'];
        $clean_text = str_replace($search, $replace, $text);

        // 2. Jalankan MeCab SEKALI untuk kedua kebutuhan
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $process = proc_open('mecab', $descriptorspec, $pipes);
        if (!is_resource($process)) {
            $result['romaji'] = getRomajiName($text); // fallback ke jalur lama
            return $result;
        }

        fwrite($pipes[0], $clean_text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        // 3. Koneksi kamus offline — static supaya sekali connect per request, bukan per panggilan
        static $pdo = null, $dict_ready = null, $dict_stmt = null;
        if ($dict_ready === null) {
            $dict_path = __DIR__ . '/../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo        = new PDO('sqlite:' . $dict_path);
                    $dict_stmt  = $pdo->prepare("SELECT glosses FROM entries WHERE reading = :w LIMIT 1");
                    $dict_ready = true;
                } catch (Exception $e) {
                    $dict_ready = false;
                }
            } else {
                $dict_ready = false;
            }
        }

        // 4. Satu kali loop token untuk isi romaji & english sekaligus
        $parsed_romaji = '';
        $glosses = [];

        foreach (explode("\n", trim($output)) as $line) {
            if ($line === 'EOS' || trim($line) === '') continue;

            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $surface  = $parts[0];
            $features = explode(',', $parts[1]);

            // -- Romaji: yomi kalau ada & bukan huruf latin, else surface --
            $yomi = '*';
            if (isset($features[7]) && $features[7] !== '*') {
                $yomi = $features[7];
            } elseif (isset($features[8]) && $features[8] !== '*') {
                $yomi = $features[8];
            }
            $parsed_romaji .= ' ' . (($yomi !== '*' && !preg_match('/[a-zA-Z]/', $yomi)) ? $yomi : $surface);

            // -- English: lookup kamus lokal via surface / base form --
            if ($dict_ready) {
                $base_form = $features[6] ?? '*';
                foreach (array_unique([$surface, $base_form]) as $candidate) {
                    if ($candidate === '*' || $candidate === '') continue;
                    $dict_stmt->execute([':w' => $candidate]);
                    $row = $dict_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['glosses'])) {
                        $glosses[] = explode(';', $row['glosses'])[0];
                        break;
                    }
                }
            }
        }

        // 5. Finalisasi romaji (transliterasi + slug), identik dengan getRomajiName
        $romaji_text = trim($parsed_romaji);
        $rule = "Katakana-Latin; Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Latin-ASCII; Any-Lower;";
        $transliterator = Transliterator::create($rule);
        if ($transliterator) {
            $romaji_text = $transliterator->transliterate($romaji_text);
        }
        $clean = preg_replace('/[^a-z0-9\-]/u', '-', $romaji_text);
        $clean = preg_replace('/-+/', '-', trim($clean, '-'));

        $result['romaji']  = $clean ?: 'untitled-media';
        $result['english'] = trim(implode(' ', array_unique($glosses)));

        return $result;
    }
}
// 100% OFFLINE: pakai MeCab (sudah dipakai getRomajiName) + kamus lokal JMdict (SQLite).
// Kamus perlu dibangun sekali lewat build_dict.php (lihat file terpisah) sebelum fitur ini aktif.
if (!function_exists('getEnglishTranslation')) {
    function getEnglishTranslation(string $text): string
    {
        static $pdo = null;
        static $dict_ready = null;

        if ($dict_ready === null) {
            $dict_path = __DIR__ . '/../assets/dict/jmdict.sqlite3';
            if (file_exists($dict_path)) {
                try {
                    $pdo = new PDO('sqlite:' . $dict_path);
                    $dict_ready = true;
                } catch (Exception $e) {
                    $dict_ready = false;
                }
            } else {
                $dict_ready = false; // Kamus belum dibuat, fitur nonaktif (fail-safe, bukan fail-error)
            }
        }

        if (!$dict_ready || empty(trim($text))) return '';

        // 1. Tokenisasi pakai MeCab (offline)
        $descriptorspec = [0 => ["pipe", "r"], 1 => ["pipe", "w"]];
        $process = proc_open('mecab', $descriptorspec, $pipes);
        if (!is_resource($process)) return '';

        fwrite($pipes[0], $text);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        // 2. Lookup tiap kata (surface & base form) ke kamus lokal
        $stmt = $pdo->prepare("SELECT glosses FROM entries WHERE reading = :w LIMIT 1");
        $glosses = [];

        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if ($line === 'EOS' || trim($line) === '') continue;

            $parts = explode("\t", $line);
            if (count($parts) < 2) continue;

            $surface  = $parts[0];
            $features = explode(',', $parts[1]);
            $base_form = $features[6] ?? '*'; // kolom ke-7 IPADIC = bentuk dasar/lemma

            foreach (array_unique([$surface, $base_form]) as $candidate) {
                if ($candidate === '*' || $candidate === '') continue;

                $stmt->execute([':w' => $candidate]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($row && !empty($row['glosses'])) {
                    $glosses[] = explode(';', $row['glosses'])[0]; // ambil arti pertama saja
                    break;
                }
            }
        }

        return trim(implode(' ', array_unique($glosses)));
    }
}
