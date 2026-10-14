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

            // Pola: angka yang langsung diikuti kata test/Unit/Integration/kasus uji.
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
   ��� Ini tidak menggandakan testDokumenTidakMenyebutJumlahTestEksak; ini
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

        // Hitung file test sebagai cara kasar yang stabil & cepat —
        // angkaPasti dari PHPUnit bisa berubah karena filter/skip, dan
        // tujuankan di sini bukan pengukuran, tapi bukti bahwa repo punya
        // test dalam jumlah yang jauh dari "0".
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
     */
    public function testNoDeprecatedSingleArgumentSetValueInTestHelper(): void
    {
        $path = MEEL_ROOT . '/tests/DbTestHelper.php';
        if (!is_file($path)) {
            $this->markTestSkipped('tests/DbTestHelper.php tidak ada');
        }
        $src = (string)file_get_contents($path);

        $this->assertDoesNotMatchRegularExpression(
            '/->setValue\(\s*\[\]/',
            $src,
            'ReflectionProperty::setValue() 1-argumen deprecated di PHP 8.3. '
            . 'Pakai setStaticPropertyValue() (PHP >= 8.3) atau setValue(null, ...).'
        );
    }

    public function testUptimeMathDoesNotUseFloatModulo(): void
    {
        $path = MEEL_ROOT . '/modules/core/System.php';
        if (!is_file($path)) {
            $this->markTestSkipped('modules/core/System.php tidak ada');
        }
        $src = (string)file_get_contents($path);

        // Bentuk yang memicu deprecation PHP 8.3:
        //   $days = (int) floor($uptime_sec / 86400);
        //   $hours = (int) floor(($uptime_sec % 86400) / 3600);
        // atau bentuk float-modulo dengan nama variabel apa pun:
        //   $x = (int) floor($someFloat % 86400);
        //
        // Pola yang benar: bulatkan ke integer dulu, lalu intdiv():
        //   $uptime_int = (int) $uptime_sec;
        //   $days = intdiv($uptime_int, 86400);
        $this->assertDoesNotMatchRegularExpression(
            '/\(int\)\s*floor\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*[%\/]/',
            $src,
            'System.php: (int)floor() pada float modulo/membagi memicu "Implicit conversion '
            . 'from float … loses precision" di PHP 8.3. Bulatkan ke integer dulu lalu pakai intdiv().'
        );

        // Sebaliknya, pastikan pola yang benar benar-benar dipakai.
        $this->assertMatchesRegularExpression(
            '/intdiv\(\s*\$uptime_int\s*,/',
            $src,
            'System.php harus menghitung uptime dengan intdiv($uptime_int, …) — '
            . 'lihat T29 di .agents/Todo.md untuk alasannya.'
        );
    }
}

/* reference build: MEeL-C10H15N [test-count-docs] */
