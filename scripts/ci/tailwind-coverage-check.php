<?php

/**
 * Penjaga reproducibility `assets/css/tailwind.min.css`.
 *
 * LATAR BELAKANG
 * --------------
 * `assets/css/tailwind.min.css` adalah artefak hasil build yang di-commit,
 * tapi sampai 2026-10-14 tidak ada konfigurasi maupun perintah build-nya
 * di repo. File itu tidak bisa direproduksi: mengubah markup lalu ingin
 * membangun ulang CSS sama saja menebak.
 *
 * Entry build sekarang (T22): `tailwind.config.js` + `tools/tailwind/input.css`
 * + `npm run css:build`. Nilai di config diekstrak dari build yang benar-benar
 * menghasilkan artefak yang sekarang dipakai (Tailwind CLI v3.4.19), bukan
 * ditebak — sumber: `.agents/progress.md` § Khusus T10.
 *
 * TIGA HAL YANG DIPERIKSA
 * -----------------------
 * 1. **Glob `content` benar-benar memindai repo, dan tidak memindai yang
 *    dikecualikan.** Config bisa saja salah ketik dan tetap build "sukses"
 *    dengan CSS setengah kosong — tidak ada error, hanya styling hilang.
 * 2. **Tidak ada regresi.** Class yang ADA di CSS lama, MASIH dipakai markup,
 *    tapi HILANG di CSS baru = regresi. Ini yang paling merusak: halaman
 *    dirender, layout berantakan, tidak ada error.
 * 3. **Class yang hilang tapi tak terpakai boleh hilang.** Itu Kebun, bukan
 *    bug; build yang sengaja memangkas stale token justru hasil T10.
 *
 * PEMAKAIAN
 * ---------
 *   php scripts/ci/tailwind-coverage-check.php                 # audit artefak ter-commit
 *   php scripts/ci/tailwind-coverage-check.php --new=/tmp/x.css # bandingkan kandidat baru
 *   php scripts/ci/tailwind-coverage-check.php --rebuild        # build dulu ke temp, lalu banding
 *
 * Exit code 0 = aman. Non-zero = ada regresi atau config rusak.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** @return array{0:string,1:?string} [css_baru, catatan] */
function meel_cli_args(array $argv): array
{
    $new = null;
    $rebuild = false;
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--new=')) {
            $new = substr($arg, 6);
        } elseif ($arg === '--rebuild') {
            $rebuild = true;
        }
    }

    if ($new === null && $rebuild) {
        $new = meel_rebuild_tailwind();
    }
    return [$new, null];
}

/** Bangun CSS Tailwind ke file temp. Return path, atau null bila toolchain tak ada. */
function meel_rebuild_tailwind(): ?string
{
    $root = dirname(__DIR__, 2);
    $out = tempnam(sys_get_temp_dir(), 'meel-tw-') . '.css';

    $cmd = sprintf(
        'npx --no-install tailwindcss -c tailwind.config.js -i tools/tailwind/input.css -o %s --minify 2>&1',
        escapeshellarg($out)
    );

    $shell = $output = [];
    $code = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . $cmd, $output, $code);

    if ($code !== 0 || !is_file($out)) {
        fwrite(STDERR, "Tailwind build gagal:\n" . implode("\n", $output) . "\n");
        @unlink($out);
        return null;
    }
    return $out;
}

/**
 * Baca array `content` dari tailwind.config.js.
 *
 * Sengaja diparse dengan regex sederhana, bukan `eval`/require: file config
 * adalah data yang boleh ditulis siapa saja di repo, danmengeksekusiinya
 * untuk mengambil daftar glob adalah cara membuka pintu yang tidak perlu.
 *
 * @return array{positive:string[], negative:string[]}
 */
function meel_read_content_globs(string $configPath): array
{
    if (!is_file($configPath)) {
        fwrite(STDERR, "tailwind.config.js tidak ditemukan\n");
        exit(2);
    }
    $src = (string)file_get_contents($configPath);

    if (!preg_match('/content\s*:\s*\[(.*?)\]/s', $src, $m)) {
        fwrite(STDERR, "Blok `content:` tidak ditemukan di tailwind.config.js\n");
        exit(2);
    }

    preg_match_all("/'([^']+)'|\"([^\"]+)\"/", $m[1], $q);
    $positive = [];
    $negative = [];
    foreach ($q[1] as $i => $glob) {
        $glob = $glob !== '' ? $glob : ($q[2][$i] ?? '');
        if ($glob === '') {
            continue;
        }
        if (str_starts_with($glob, '!')) {
            $negative[] = substr($glob, 1);
        } else {
            $positive[] = $glob;
        }
    }
    return [$positive, $negative];
}

/**
 * Ekspansi glob content menjadi daftar file nyata.
 *
 * Hanya mendukung bentuk yang dipakai repo ini: glob rekursif dengan
 * ekstensi brace (mis. seluruh `.php/.js/.html` di root) dan pola
 * pengecualian direktori. Kalau ada bentuk lain, berhenti dengan pesan
 * jelas — lebih baik gagal daripada diam-diam memindai 0 file.
 *
 * @param string[] $positive
 * @param string[] $negative
 * @return string[]
 */
function meel_expand_globs(array $positive, array $negative): array
{
    $root = dirname(__DIR__, 2);
    $exts = [];
    foreach ($positive as $glob) {
        if (preg_match('/\{([a-z0-9,]+)\}$/i', $glob, $m)) {
            foreach (explode(',', $m[1]) as $e) {
                $exts[strtolower($e)] = true;
            }
        }
    }
    if ($exts === []) {
        fwrite(STDERR, "Tidak ada ekstensi yang bisa dibaca dari glob content\n");
        exit(2);
    }

    // Direktori yang dikecualikan, diturunkan dari glob `!./x/**`.
    $excludedDirs = [];
    foreach ($negative as $glob) {
        if (preg_match('#^\./([^/]+)/\*\*$#', $glob, $m)) {
            $excludedDirs[$m[1]] = true;
        }
    }

    $files = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $entry) {
        /** @var SplFileInfo $entry */
        if (!$entry->isFile()) {
            continue;
        }
        $path = $entry->getPathname();
        if (substr($path, 0, strlen($root)) !== $root) {
            continue;
        }
        $rel = './' . ltrim(substr($path, strlen($root)), '/');

        // Yang dikecualikan: path-nya mulai `./<dir>/`.
        $skip = false;
        foreach ($excludedDirs as $dir => $_) {
            if (str_starts_with($rel, './' . $dir . '/')) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        $ext = strtolower($entry->getExtension());
        if (isset($exts[$ext])) {
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

/**
 * Kumpulkan token class dari markup.
 *
 * Hanya `class="..."` / `class='...'` yang diambil. Token yang bukan
 * bentuk class (mis. sisa string PHP atau ekspresi) dibuang, supaya yang
 * dibandingkan memang hanya class yang benar-benar ada di markup.
 *
 * @param string[] $files
 * @return array<string,int> token => jumlah kemunculan
 */
function meel_collect_source_classes(array $files): array
{
    $root = dirname(__DIR__, 2);
    $tokens = [];
    foreach ($files as $rel) {
        $src = (string)file_get_contents($root . '/' . $rel);
        if (preg_match_all('/\bclass\s*=\s*(["\'])(.*?)\1/s', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $set) {
                foreach (preg_split('/\s+/', trim($set[2])) as $tok) {
                    $tok = trim($tok, "\"'");
                    if ($tok === '' || preg_match('/^[A-Za-z0-9:_\-\/\[\]\.#%]+$/', $tok) !== 1) {
                        continue;
                    }
                    // Buang sisa interpolasi PHP (tag short-echo) yang mungkin menempel di token.
//
// CATATAN PENTING: jangan pernah menulis tag short-echo secara harfiah di
// dalam komentar satu-baris. Kemunculan tag tutup short-echo di baris
// `//` mengakhiri mode PHP, dan seluruh sisa file lalu diparse sebagai
// HTML — gejalanya "unclosed brace" yang jauh dari penyebabnya.
                    if (str_contains($tok, '<?') || str_contains($tok, '?>')) {
                        continue;
                    }
                    $tokens[$tok] = ($tokens[$tok] ?? 0) + 1;
                }
            }
        }
    }
    ksort($tokens);
    return $tokens;
}

/**
 * Kumpulkan nama class + deklarasinya dari CSS.
 *
 * Tokenisasi dilakukan pada selector ASLI (yang masih escapes CSS), baru
 * hasilnya di-unescape. Urutannya penting dan bukan pilihan gaya:
 *
 *   - Kalau selector di-unescape DULUAN lalu di-tokenisasi, karakter `:`
 *     (pemisah variant) ikut jadi pembatas dan semua class bertanda variant
 *     runtuh jadi satu token: `hover:border-green-500/30` menjadi `hover`,
 *     `sm:hover:bg-gray-800` menjadi `sm`. AkibatnyaELA check regresi
 *     nyaris tak berguna — `hover` jelas ada di file lain, jadi class yang
 *     benar-benar hilang tidak akan pernah terdeteksi.
 *   - Kalau selector asli yang dipindai, `:` muncul sebagai pasangan escape
 *     (`\:`) dan ikut terbawa ke dalam token dengan benar.
 *
 * Deklarasi dikumpulkan per class dalam bentuk GABUNGAN, bukan per rule.
 * Tailwind menggabungkan selector yang deklarasinya sama ke satu rule; saat
 * isi kelompok berubah, satu class bisa pindah dari rule gabungan ke rule
 * sendiri (atau sebaliknya) tanpa mengubah hasil akhirnya. Membandingkan
 * per rule akan memunculkan palsu di setiap kelompok yang bergeser.
 *
 * @return array<string, string[]> class => deklarasi (diurutkan)
 */
function meel_collect_css_rules(string $css): array
{
    $delims = " \t\n\r,>~+*[](){:";
    $out = [];

    preg_match_all('/([^{}]*)\{([^{}]*)\}/s', $css, $blocks, PREG_SET_ORDER);
    foreach ($blocks as $b) {
        $selText = trim($b[1]);
        if ($selText === '' || $selText[0] === '@') {
            continue;
        }

        $decls = [];
        foreach (explode(';', $b[2]) as $d) {
            $d = trim($d);
            if ($d !== '') {
                $decls[$d] = true;
            }
        }

        foreach (explode(',', $selText) as $sel) {
            $sel = trim($sel);
            if ($sel === '' || $sel[0] === '@') {
                continue;
            }
            foreach (meel_css_classes_in_selector($sel, $delims) as $cls) {
                foreach (array_keys($decls) as $d) {
                    $out[$cls][$d] = true;
                }
            }
        }
    }

    ksort($out);
    foreach ($out as $cls => $set) {
        $list = array_keys($set);
        sort($list);
        $out[$cls] = $list;
    }
    return $out;
}

/**
 * Ambil class dari SUBJEK selector, dengan menghormati escape CSS.
 *
 * Hanya senyawa terakhir selector yang dihitung. Pada
 * `.group:hover .group-hover:text-white`, yang membawa deklarasi adalah
 * `group-hover:text-white`; `group` hanya muncul sebagai leluhur dan tidak
 * punya deklarasi sendiri. Kalau leluhur ikut dihitung, setiap perubahan
 * isi rule-nya dilaporkan sebagai perubahan pada `group` -- derau yang
 * menyesatkan dan menutupi masalah sebenarnya.
 *
 * @return string[]
 */
function meel_css_classes_in_selector(string $sel, string $delims): array
{
    // Buang pseudo-class/pseudo-element, lalu ambil senyawa terakhir
    // (setelah combinator atau spasi).
    $sel = preg_replace('/::?[a-zA-Z-]+(\([^()]*\))?/', ' ', $sel) ?? $sel;
    $parts = preg_split('/\s*[>+~]\s*|\s+/', trim($sel), -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === []) {
        return [];
    }
    $subject = (string)end($parts);

    $out = [];
    $len = strlen($subject);
    for ($i = 0; $i < $len; $i++) {
        if ($subject[$i] !== '.') {
            continue;
        }
        $token = '';
        $j = $i + 1;
        while ($j < $len) {
            $ch = $subject[$j];
            if ($ch === '\\') {
                // Escape: backslash + karakter berikutnya, keduanya bagian token.
                $token .= ($subject[$j + 1] ?? '');
                $j += 2;
                continue;
            }
            if (strpos($delims, $ch) !== false) {
                break;
            }
            $token .= $ch;
            $j++;
        }
        if ($token !== '') {
            $out[$token] = true;
        }
        $i = max($j, $i + 1);
    }
    return array_keys($out);
}

/**
 * Cuma nama class-nya saja.
 *
 * @return string[]
 */
function meel_collect_css_classes(string $css): array
{
    $list = array_keys(meel_collect_css_rules($css));
    sort($list);
    return $list;
}

// ---------------------------------------------------------------------------

[$argNew] = meel_cli_args($argv);

$committedPath = $root . '/assets/css/tailwind.min.css';
if (!is_file($committedPath)) {
    fwrite(STDERR, "assets/css/tailwind.min.css tidak ditemukan\n");
    exit(2);
}
$committed = (string)file_get_contents($committedPath);

$failures = [];
$notes = [];

echo "== Tailwind reproducibility check ==\n\n";

// --- 1. Glob content ------------------------------------------------------
[$positive, $negative] = meel_read_content_globs($root . '/tailwind.config.js');
$files = meel_expand_globs($positive, $negative);

printf("  file dipindai        : %d\n", count($files));
if ($files === []) {
    $failures[] = 'Glob `content` tidak memindai satu pun file — build akan menghasilkan '
        . 'CSS hampir kosong dan TIDAK akan error.';
}

$excludedCount = count($negative);
printf("  pola pengecualian    : %d (%s)\n", $excludedCount, implode(' ', $negative));

// Bukti bahwa pengecualian benar-benar bekerja.
//
// Daftar folder yang WAJIB tidak pernah terpindai sengaja ditulis sebagai
// konstanta di sini, BUKAN diturunkan dari glob di config. Kalau daftar
// itu diambil dari config, pemeriksaan ini membandingkan config dengan
// dirinya sendiri: begitu Someone menghapus `!./tests/**` dari config, folder
// juga hilang dari daftar yang diperiksa — dan tidak ada yang pernah gagal.
// Mutation check membuktikannya: tanpa daftar konstanta, penghapusan
// eksplisit itu lolos begitu saja.
$mustExclude = ['.git', 'vendor', 'node_modules', 'logs', 'temp', 'storage', 'data_drive', 'tests'];
foreach ($mustExclude as $dir) {
    $leaked = array_values(array_filter(
        $files,
        static fn(string $f): bool => str_starts_with($f, './' . $dir . '/')
    ));
    if ($leaked !== []) {
        $failures[] = sprintf(
            'Folder %s/ ikut terpindai (%d file, mis. %s) — class milik test/fixture masuk CSS produksi',
            $dir,
            count($leaked),
            $leaked[0]
        );
    }
}

// --- 2. Class di artefak ter-commit --------------------------------------
$oldRules = meel_collect_css_rules($committed);
$oldClasses = array_keys($oldRules);
printf("  class di CSS ter-commit: %d\n\n", count($oldClasses));

// --- 3. Perbandingan dengan kandidat baru ---------------------------------
if ($argNew === null) {
    $notes[] = 'Tidak ada --new/--rebuild, jadi hanya artefak ter-commit yang diaudit. '
        . 'Jalankan `npm run css:build && php scripts/ci/tailwind-coverage-check.php --rebuild` '
        . 'untuk membandingkan build baru.';
} else {
    if (!is_file($argNew)) {
        fwrite(STDERR, "CSS kandidat tidak ditemukan: {$argNew}\n");
        exit(2);
    }
    $newRules = meel_collect_css_rules((string)file_get_contents($argNew));
    $newClasses = array_keys($newRules);
    printf("  class di CSS kandidat : %d\n\n", count($newClasses));

    $sourceClasses = meel_collect_source_classes($files);
    printf("  token class di markup: %d\n\n", count($sourceClasses));

    $missing = array_values(array_diff($oldClasses, $newClasses));
    $added = array_values(array_diff($newClasses, $oldClasses));

    // Regression: hilang dari build baru TETAP dipakai markup.
    $regressions = [];
    foreach ($missing as $cls) {
        if (isset($sourceClasses[$cls])) {
            $regressions[$cls] = $sourceClasses[$cls];
        }
    }

    // Perubahan nilai: class ada di kedua sisi, tapi deklarasinya beda.
    //
    // Ini kondisi yang TIDAK tertangkap perbandingan nama class saja, dan
    // kasus nyatanya sudah terjadi: `animate-icon-pop` tetap ada setelah
    // build ulang, tapi `cubic-bezier()`-nya berubah dari empat angka jadi
    // tiga — sehingga browser membuang seluruh deklarasi animation dan ikon
    // transcode berhenti "pop". Tidak ada class yang hilang, tidak ada error,
    // halaman tetap tampil.
    $changed = [];
    foreach ($oldClasses as $cls) {
        if (!isset($newRules[$cls])) {
            continue;   // sudah tertangani sebagai regresi / class mati
        }
        $old = $oldRules[$cls];
        $new = $newRules[$cls];
        if ($old !== $new) {
            $changed[$cls] = ['lama' => $old, 'baru' => $new];
        }
    }

    $stale = array_values(array_diff($missing, array_keys($regressions)));

    if ($regressions !== []) {
        echo "REGRESI — class hilang tapi masih dipakai markup:\n";
        foreach ($regressions as $cls => $n) {
            printf("  ✗ %-40s dipakai %d×\n", $cls, $n);
            $failures[] = sprintf('Regresi: class "%s" hilang dari build baru tapi masih dipakai markup (%d×)', $cls, $n);
        }
        echo "\n";
    } else {
        echo "Regresi              : 0 ✓\n";
    }

    if ($changed !== []) {
        echo "PERUBAHAN NILAI — class ada, isi deklarasi berbeda:\n";
        foreach ($changed as $cls => $d) {
            printf("  ✗ %s\n", $cls);
            foreach (array_diff($d['lama'], $d['baru']) as $only) {
                printf("      - %s\n", $only);
            }
            foreach (array_diff($d['baru'], $d['lama']) as $only) {
                printf("      + %s\n", $only);
            }
            $failures[] = sprintf(
                'Nilai class "%s" berubah antara artefak ter-commit dan build baru',
                $cls
            );
        }
        echo "\n";
    } else {
        echo "Perubahan nilai      : 0 ✓\n";
    }

    printf("Class mati dibuang    : %d", count($stale));
    if ($stale !== []) {
        echo " (" . implode(', ', array_slice($stale, 0, 8)) . (count($stale) > 8 ? ', …' : '') . ')';
    }
    echo "\n";
    printf("Class baru           : %d\n", count($added));
    if ($added !== []) {
        echo '  ' . implode(', ', array_slice($added, 0, 12)) . "\n";
    }
    echo "\n";
}

// --- 4. Tema kustom wajib masih ada di config ----------------------------
$configSrc = (string)file_get_contents($root . '/tailwind.config.js');
$required = [
    "bg: '#080b11'",
    "muted: '#455060'",
    "card: '#0e1118'",
    "fontFamily",
    "'DM Sans'",
    "'IBM Plex Mono'",
    "cardIn",
    "iconPop",
    "indeterminate",
];
$lost = [];
foreach ($required as $needle) {
    if (!str_contains($configSrc, $needle)) {
        $lost[] = $needle;
    }
}
if ($lost !== []) {
    $failures[] = 'tailwind.config.js kehilangan nilai tema yang dibutuhkan: ' . implode(', ', $lost);
}

// --- Hasil ----------------------------------------------------------------
if ($notes !== []) {
    echo "Catatan:\n";
    foreach ($notes as $n) {
        echo "  · {$n}\n";
    }
    echo "\n";
}

if ($failures !== []) {
    echo "GAGAL (" . count($failures) . "):\n";
    foreach ($failures as $f) {
        echo "  ✗ {$f}\n";
    }
    exit(1);
}

echo "OK — config utuh, tidak ada regresi.\n";
exit(0);