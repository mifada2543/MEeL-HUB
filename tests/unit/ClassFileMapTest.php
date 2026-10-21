<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga setiap peta "nama -> berkas" yang ada di repo.
 *
 * Peta-peta ini memetakan nama (kelas atau fungsi) ke berkas tempat nama
 * itu HARUS ada. Kalau pemecahan berkas memindahkan sesuatu dan peta tidak
 * ikut diperbarui, pemeriksa tidak menggagalkan — ia hanya mencari di
 * berkas yang sekarang kosong, tidak menemukan apa-apa, lalu melaporkannya
 * aman. Skor tetap 100/100.
 *
 * Test ini menutup kelas bug itu secara umum: setiap entri peta wajib
 * (a) berkasnya ada, dan (b) isinya benar-benar mendeklarasikan nama
 * yang dipetakan ke sana.
 *
 * @coversNothing
 */
class ClassFileMapTest extends TestCase
{
    /**
     * Peta di `modules/autoload.php`: kelas -> berkas.
     *
     * @return array<int, array{0:string, 1:string}> [nama, relpath]
     */
    private function autoloadMap(): array
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/autoload.php');
        preg_match_all(
            "/'([A-Za-z_]\\w*)'\\s*=>\\s*__DIR__\\s*\\.\\s*'([^']+\\.php)'/",
            $src,
            $m,
            PREG_SET_ORDER
        );
        return array_map(static fn(array $s): array => [$s[1], 'modules' . $s[2]], $m);
    }

    /**
     * Peta di `tests/functional_test.php`. Ada DUA, dan keduanya wajib dijaga
     * tapi dengan aturan berbeda: kalau keduanya diparse sebagai satu, setiap
     * entri fungsi dilaporkan "mati" padahal berkasnya benar — dan laporan
     * salah yang palsu tenggelem di antara laporan yang benar.
     *
     * @return array{classes: array<int, array{0:string,1:string}>, functions: array<int, array{0:string,1:string}>}
     */
    private function functionalTestMaps(): array
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/tests/functional_test.php');

        $grab = static function (string $var) use ($src): string {
            $m = preg_match('/\$' . $var . '\s*=\s*\[(.*?)\n[ \t]*\];/s', $src, $blk);
            if (!$m) {
                throw new RuntimeException("Peta \${$var} tidak ditemukan di functional_test.php");
            }
            return $blk[1];
        };

        $parse = static function (string $blob): array {
            preg_match_all(
                "/'([A-Za-z_]\\w*)'\\s*=>\\s*'(modules\\/[^']+\\.php)'/",
                $blob,
                $m,
                PREG_SET_ORDER
            );
            return array_map(static fn(array $s): array => [$s[1], $s[2]], $m);
        };

        return [
            'classes'   => $parse($grab('classes')),
            'functions' => $parse($grab('functions')),
        ];
    }

    /**
     * Penjaga parser. Kalau regex-nya usang dan gagal membaca apa pun,
     * seluruh test di kelas ini memeriksa nol entri dan selalu hijau --
     * bentuk paling berbahaya dari test yang tidak menguji apa pun.
     */
    public function testMapParsersActuallyFindEntries(): void
    {
        $auto = $this->autoloadMap();
        $maps = $this->functionalTestMaps();

        $this->assertGreaterThan(
            10,
            count($auto),
            'Peta di modules/autoload.php tidak terbaca. Kalau regex-nya usang, test lain '
            . 'di kelas ini memeriksa nol entri dan selalu hijau.'
        );
        $this->assertGreaterThan(
            10,
            count($maps['classes']),
            'Peta $classes di functional_test.php tidak terbaca.'
        );
        $this->assertGreaterThan(
            5,
            count($maps['functions']),
            'Peta $functions di functional_test.php tidak terbaca.'
        );

        $namaKelas = array_column($maps['classes'], 0);
        $this->assertNotContains(
            'log_activity',
            $namaKelas,
            'Peta kelas ikut menelan entri fungsi. Dua peta harus dibaca terpisah.'
        );
    }

    /**
     * Setiap kelas yang dipetakan harus benar-benar dideklarasikan di
     * berkas yang dipetakan menunjuknya.
     */
    public function testEveryMappedClassIsActuallyDeclaredInItsFile(): void
    {
        $entries = $this->autoloadMap();
        foreach ($this->functionalTestMaps()['classes'] as $e) {
            $entries[] = $e;
        }

        $problems = [];
        foreach ($entries as [$class, $rel]) {
            $abs = MEEL_ROOT . '/' . $rel;

            if (!is_file($abs)) {
                $problems[] = sprintf('  %s => %s  -- BERKAS TIDAK ADA', $class, $rel);
                continue;
            }

            $src = (string) file_get_contents($abs);
            $pola = '/^[ \t]*(?:final\s+|abstract\s+|readonly\s+)*'
                . '(?:class|interface|trait)\s+' . preg_quote($class, '/') . '\b/m';

            if (!preg_match($pola, $src)) {
                $problems[] = sprintf(
                    '  %s => %s  -- PETA MATI: berkas itu tidak mendeklarasikan kelas %s',
                    $class,
                    $rel,
                    $class
                );
            }
        }

        $this->assertSame([], $problems, $this->explain($problems, 'kelas'));
    }

    /**
     * Setiap fungsi yang dipetakan harus benar-benar terdefinisi di
     * berkas yang dipetakan menunjuknya.
     *
     * Inilah penjaga langsung untuk kejadian T27, ketika peta
     * `storage.php => shell_exec` diam-diam berhenti memindai apa pun.
     */
    public function testEveryMappedFunctionIsActuallyDefinedInItsFile(): void
    {
        $entries = $this->functionalTestMaps()['functions'];

        $problems = [];
        foreach ($entries as [$fn, $rel]) {
            $abs = MEEL_ROOT . '/' . $rel;

            if (!is_file($abs)) {
                $problems[] = sprintf('  %s() => %s  -- BERKAS TIDAK ADA', $fn, $rel);
                continue;
            }

            $src = (string) file_get_contents($abs);

            $pola = '/(?:^|[;{}\s])function\s+&?' . preg_quote($fn, '/') . '\s*\(/m';

            if (!preg_match($pola, $src)) {
                $problems[] = sprintf(
                    '  %s() => %s  -- PETA MATI: berkas itu tidak mendefinisikan fungsi %s()',
                    $fn,
                    $rel,
                    $fn
                );
            }
        }

        $this->assertSame([], $problems, $this->explain($problems, 'fungsi'));
    }

    /**
     * Bila `functional_test.php` dan `autoload.php` berbeda pendapat soal
     * berkas sebuah kelas, salah satunya sudah salah -- dan tidak jelas
     * mana. Test ini tidak menebak mana yang benar; ia hanya memastikan
     * keduanya tidak boleh berbeda diam-diam.
     */
    public function testAutoloadAndFunctionalTestMapAgree(): void
    {
        $auto = [];
        foreach ($this->autoloadMap() as [$class, $rel]) {
            $auto[$class] = $rel;
        }

        $conflicts = [];
        foreach ($this->functionalTestMaps()['classes'] as [$class, $rel]) {
            if (!isset($auto[$class])) {
                continue;
            }
            if ($auto[$class] !== $rel) {
                $conflicts[] = sprintf(
                    '  %s: autoload.php -> %s, functional_test.php -> %s',
                    $class,
                    $auto[$class],
                    $rel
                );
            }
        }

        $this->assertSame([], $conflicts, $this->explain($conflicts, 'berkas kelas'));
    }

    /**
     * `MediaLibrary.php` tidak boleh kembali jadi aggregator yang
     * menelan dua kelas lain. Kalau ia menyembunyikan `BookUploader`
     * di dalamnya, setiap halaman yang cuma butuh `MediaLibrary` ikut
     * memuat kelas yang tidak dibutuhkannya, dan pemecahan T19 jadi
     * tidak berarti apa-apa.
     */
    public function testMediaLibraryIsNotASilentAggregatorAgain(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/media/MediaLibrary.php');

        foreach (['BookRepository', 'BookUploader'] as $other) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[ \t]*class\s+' . $other . '\b/m',
                $src,
                "MediaLibrary.php kembali memuat kelas $other. Kalau disembunyikan "
                . 'di dalam aggregator, halaman yang hanya butuh MediaLibrary ikut '
                . 'memuat kelas yang tidak dibutuhkannya, dan pemecahan T19 jadi '
                . 'tidak berarti apa-apa.'
            );
        }
    }

    /**
     * Berkas hasil pecahan harus ada, dan masing-masing memuat tepat
     * satu kelas yang dijanjikan.
     */
    public function testSplitFilesExistAndDeclareExactlyOneClass(): void
    {
        $expected = [
            'modules/media/MediaLibrary.php'   => 'MediaLibrary',
            'modules/media/BookRepository.php' => 'BookRepository',
            'modules/media/BookUploader.php'   => 'BookUploader',
        ];

        foreach ($expected as $rel => $class) {
            $abs = MEEL_ROOT . '/' . $rel;
            $this->assertFileExists($abs, "Berkas hasil pecahan T19 hilang: {$rel}");

            $src = (string) file_get_contents($abs);
            $this->assertMatchesRegularExpression(
                '/^[ \t]*class\s+' . $class . '\b/m',
                $src,
                "{$rel} tidak mendeklarasikan kelas {$class}"
            );

            $jumlah = preg_match_all('/^[ \t]*(?:final\s+|abstract\s+)?class\s+\w+\b/m', $src);
            $this->assertSame(
                1,
                $jumlah,
                "{$rel} harusnya memuat tepat satu kelas, ditemukan {$jumlah}. "
                . 'Kelas kedua berarti pemecahan hanya memindah baris, bukan '
                . 'memisahkan tanggung jawab.'
            );
        }
    }

    /** @param string[] $problems */
    private function explain(array $problems, string $jenis): string
    {
        return "Ada entri peta {$jenis}->berkas yang tidak boleh dipercaya:\n"
            . implode("\n", $problems)
            . "\n\nPeta seperti ini gagal SENYAP. Pemeriksa tidak error; ia hanya "
            . "mencari di berkas yang salah, tidak menemukan apa-apa, lalu melapor "
            . "aman. Skornya tetap 100/100 sementara tidak ada yang benar-benar "
            . "diperiksa. Sudah terjadi dua kali di repo ini: T27 (peta "
            . "storage.php -> shell_exec) dan T19 (ArchiveGuard + pola nama file "
            . "yang sebenarnya ada di BookUploader).";
    }
}

/* reference build: MEeL-K2R7WQ9 [class-file-map] */