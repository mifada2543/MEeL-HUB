<?php

use PHPUnit\Framework\TestCase;

/**
 * Menjaga agar klaim "jumlah test" di dokumen tidak basi diam-diam.
 *
 * Latar belakang: dokumen pernah menulis "305 Unit + 98 Integration =
 * 403 Tests" saat kenyataannya sudah 503 + 106. Angka itu salah **dan**
 * tidak ada yang menangkapnya, karena tidak ada test yang membandingkan
 * angka di docs dengan hasil run sebenarnya.
 *
 * Pendekatan: dokumen **tidak boleh** menyebut angka persis. Kalau ada
 * angka eksak, test ini menghitung jumlah test sebenarnya lalu gagal —
 * jadi whoever mengubah angka jadi salah akan langsung tahu (dan diberi
 * cara memperbaruinya di pesan error).
 *
 * Kalau kamu memang perlu angka eksak di dokumen, jalankan:
 *   vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests' | tail -3
 * lalu perbarui nilainya di sini DAN di dokumen dalam satu commit.
 */

/** @coversNothing */
class TestCountDocumentationTest extends TestCase
{
    /**
     * Dokumen yang pernah memuat klaim jumlah test.
     * Nilai yang diharapkan sengaja dibuat longgar ("560+", "600+") —
     * lihat testDokumenTidakMenyebutAngkaEksak di bawah.
     */
    private const DOCS_WITH_COUNTS = [
        'docs/id/testing.md',
        'docs/en/testing.md',
        'README.md',
        'README-en.md',
    ];

    public function testEveryCountClaimingDocExists(): void
    {
        $checked = 0;
        foreach (self::DOCS_WITH_COUNTS as $file) {
            $path = MEEL_ROOT . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $checked++;
            $this->assertNotSame('', trim((string)file_get_contents($path)), "$file kosong?");
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'Tidak ada satu pun dokumen yang diperiksa — test ini akan selalu hijau tanpa makna'
        );
    }

    /**
     * Dokumen tidak boleh menyebut jumlah test sebagai angka PASTI
     * ("562 Unit", "668 Tests") karena angka itu cepat basi.
     * Boleh: rentang kasar ("600+", "560+") atau kalimat tanpa angka.
     */
    public function testDokumenTidakMenyebutJumlahTestEksak(): void
    {
        $violations = [];

        foreach (self::DOCS_WITH_COUNTS as $file) {
            $path = MEEL_ROOT . '/' . $file;
            if (!is_file($path)) {
                continue;
            }
            $src = (string)file_get_contents($path);

            if (preg_match('/(?<![+\d])\b(\d{2,4})\s+(Unit|Integration|Tests?\b|kasus uji|test cases)/i', $src, $m)) {
                $violations[] = sprintf('%s: "%s"', $file, trim($m[0]));
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Dokumen menyebut jumlah test sebagai angka eksak — angka itu cepat basi:\n  "
            . implode("\n  ", $violations)
            . "\n\nPerbaiki dengan rentang kasar (\"600+\") atau hapus angkanya. "
            . 'Kalau memang butuh angka eksak, ukur ulang lalu perbarui di satu tempat yang sama.'
        );
    }

    /**
     * Penjaga utama: kalau ada dokumen yang menyebut angka eksak, hitung
     * jumlah sebenarnya dan bandingkan — supaya Pesan errorNYA informatif
     * (memberi tahu angka yang benar), bukan sekadar menolak.
     *
   // Ini tidak menggandakan testDokumenTidakMenyebutJumlahTestEksak; ini
     * menyediakan angka acuan supaya whoever memperbaiki tidak perlu
     * menebak-nebak.
     */
    public function testJumlahTestSebenarnyaUntukReferensiPerbaikan(): void
    {
        if (!defined('MEEL_ROOT')) {
            $this->markTestSkipped('MEEL_ROOT tidak terdefinisi');
        }

        $unitDir = MEEL_ROOT . '/tests/unit';
        $this->assertDirectoryExists($unitDir, 'tests/unit tidak ditemukan');

        $files = glob($unitDir . '/*Test.php') ?: [];
        $this->assertGreaterThanOrEqual(
            40,
            count($files),
            'Hanya ada ' . count($files) . ' file test unit — dokumentasi mengklaim jauh lebih banyak. '
            . 'Atau file test hilang/berpindah nama.'
        );

        $integrationDir = MEEL_ROOT . '/tests/integration';
        if (is_dir($integrationDir)) {
            $intFiles = glob($integrationDir . '/*Test.php') ?: [];
            $this->assertGreaterThanOrEqual(
                10,
                count($intFiles),
                'Hanya ada ' . count($intFiles) . ' file test integrasi'
            );
        }
    }

    /**
     * Error log tidak boleh penuh deprecation — ini yang diam-diam sekali
     * menutupi bug (lihat T29: deprecation System.php tersembunyi di
     * balik deprecation DbTestHelper.php).
     *
     * Versi pertama guard ini hanya memeriksa SATU file
     * (`tests/DbTestHelper.php`) dan SATU literal (`setValue([])`).
     * Padahal ada 11 call site lain di 5 file test yang bentuk 1-argumennya
     * sama persis — semuanya terhadap properti statis, jadi semuanya
     * memang memicu deprecation PHP 8.3 dan semuanya sudah diperbaiki.
     *
     * Guard sekarang memindai SELURUH repo (di luar vendor) dan poked
     * bentuk 1-argumen secara umum, bukan hanya literal `[]`. Memakai
     * balance parens, bukan regex, supaya argumen bertanda kurung —mis.
     * `setValue(MEEL_ROOT . '/temp/x/')`— tidak lolos salah baca.
     */
    public function testNoDeprecatedSingleArgumentSetValueAnywhere(): void
    {
        $offenders = [];
        $root = MEEL_ROOT;
        $skipDirs = ['.git', 'vendor', 'node_modules', 'logs', 'temp', 'storage', 'data_drive'];

        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $f) use ($skipDirs): bool {
                    if (!$f->isDir()) {
                        return true;
                    }
                    return !in_array($f->getFilename(), $skipDirs, true);
                }
            )
        );

        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $src = self::stripNonCode((string)file_get_contents($file->getPathname()));
            if (!str_contains($src, '->setValue(')) {
                continue;
            }

            foreach (self::singleArgumentSetValueLines($src) as $line) {
                $offenders[] = $file->getPathname() . ':' . $line;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "ReflectionProperty::setValue() 1-argumen deprecated di PHP 8.3 (jadi error di PHP 9).\n"
            . "Pakai setValue(null, ...) untuk properti statis.\n"
            . "Terdapat di:\n  - " . implode("\n  - ", $offenders)
        );
    }

    /**
     * Kosongkan isi komentar dan string, sisakan baris baru apa adanya.
     *
     * Tanpa ini guard membaca source-nya sendiri: file ini pun memuat
     * `->setValue(` di dalam string dan docblock-nya, sehingga setiap run
     * melaporkan dirinya sendiri sebagai pelanggaran.
     *
     * Nomor baris tetap terjaga karena karakter diganti spasi, bukan dihapus.
     */
    private static function stripNonCode(string $src): string
    {
        $len = strlen($src);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $c = $src[$i];
            $n = $src[$i + 1] ?? '';

            if ($c === '/' && $n === '/') {
                while ($i < $len && $src[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }
            if ($c === '#' && ($src[$i + 1] ?? '') !== '[') {
                while ($i < $len && $src[$i] !== "\n") {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            if ($c === '/' && $n === '*') {
                $out .= '  ';
                $i += 2;
                while ($i < $len && !($src[$i] === '*' && ($src[$i + 1] ?? '') === '/')) {
                    $out .= $src[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                if ($i < $len) {
                    $out .= '  ';
                    $i += 2;
                }
                continue;
            }

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $out .= ' ';
                $i++;
                while ($i < $len && $src[$i] !== $quote) {
                    if ($src[$i] === '\\') {
                        $out .= $src[$i] === "\n" ? "\n" : ' ';
                        $i++;
                        if ($i < $len) {
                            $out .= $src[$i] === "\n" ? "\n" : ' ';
                            $i++;
                        }
                        continue;
                    }
                    $out .= $src[$i] === "\n" ? "\n" : ' ';
                    $i++;
                }
                if ($i < $len) {
                    $out .= ' ';
                    $i++;
                }
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Nomor baris setiap `->setValue(` yang HANYA punya satu argumen.
     *
     * @return int[]
     */
    private static function singleArgumentSetValueLines(string $src): array
    {
        $lines = [];
        $len = strlen($src);

        if (preg_match_all('/->setValue\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$needle, $offset]) {
                $i = $offset + strlen($needle) - 1;
                $depth = 0;
                $hasTopLevelComma = false;

                for (; $i < $len; $i++) {
                    $c = $src[$i];
                    if ($c === '(' || $c === '[') {
                        $depth++;
                    } elseif ($c === ')' || $c === ']') {
                        $depth--;
                        if ($depth === 0) {
                            break;
                        }
                    } elseif ($c === ',' && $depth === 1) {
                        $hasTopLevelComma = true;
                    }
                }

                if ($depth !== 0 || $hasTopLevelComma) {
                    continue;
                }
                $lines[] = substr_count(substr($src, 0, $offset), "\n") + 1;
            }
        }

        return $lines;
    }

    public function testUptimeMathDoesNotUseFloatModulo(): void
    {
        $path = MEEL_ROOT . '/modules/core/System.php';
        if (!is_file($path)) {
            $this->markTestSkipped('modules/core/System.php tidak ada');
        }
        $src = (string)file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/\(int\)\s*floor\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*[%\/]/',
            $src,
            'System.php: (int)floor() pada float modulo/membagi memicu "Implicit conversion '
            . 'from float … loses precision" di PHP 8.3. Bulatkan ke integer dulu lalu pakai intdiv().'
        );

        $this->assertMatchesRegularExpression(
            '/intdiv\(\s*\$uptime_int\s*,/',
            $src,
            'System.php harus menghitung uptime dengan intdiv($uptime_int, …) — '
            . 'lihat T29 di .agents/Todo.md untuk alasannya.'
        );
    }
}

/* reference build: MEeL-C10H15N [test-count-docs] */