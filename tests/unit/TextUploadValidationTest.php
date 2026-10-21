<?php
use PHPUnit\Framework\TestCase;

/**
 * Validasi berkas teks yang diunggah (lirik & subtitle).
 *
 * DULU
 * ----
 * `validate_lyrics_file()` (helpers/lyrics.php) dan
 * `validate_subtitle_file()` (helpers/subtitle.php) punya badan fungsi
 * yang identik byte per byte: cek ukuran 2 MB, tolak byte NUL, tolak tag
 * PHP. Dua salinan dari aturan keamanan berarti satu salinan bisa
 * diam-diam lolos pemeriksaan sementara yang lain sudah diperketat.
 *
 * SEKARANG
 * --------
 * Keduanya memanggil `meel_validate_text_upload()` di
 * `modules/core/helpers/text_upload.php`.
 *
 * Test ini menahan dua hal:
 *  1. Ketiga fungsi harus berperilaku sama persis pada semua masukan.
 *  2. Aturannya tidak boleh ditulis ulanginline di file aslinya.
 *
 * @coversNothing
 */
class TextUploadValidationTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    private function makeFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'meeltext');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    public static function contentProvider(): array
    {
        return [
            'teks biasa' => ["[00:12.00]Halo dunia\n", true],
            'teks kosong' => ['', true],
            'tag php' => ["<?php echo 1; ?>\n", false],
            'tag php short' => ['<?= $x ?>' . "\n", false],
            'tag php huruf besar' => ["<?PHP echo 1;\n", false],
            'byte nul' => ["abc\x00def", false],
            'bukan php tapi mengandung kurung' => ["a <? b\n", true],
        ];
    }

    /** @dataProvider contentProvider */
    public function testSharedHelperRejectsAndAcceptsAsExpected(string $contents, bool $expected): void
    {
        $path = $this->makeFile($contents);
        $this->assertSame(
            $expected,
            meel_validate_text_upload($path),
            'hasil helper bersama untuk isi: ' . var_export($contents, true)
        );
    }

    /**
     * Ketiga fungsi harus memberikan hasil yang sama persis — wrapper tidak boleh
     * menambah atau mengubah aturan apa pun.
     *
     * @dataProvider contentProvider
     */
    public function testBothWrappersBehaveExactlyLikeTheSharedHelper(string $contents, bool $expected): void
    {
        $path = $this->makeFile($contents);
        $shared = meel_validate_text_upload($path);
        $this->assertSame($shared, validate_lyrics_file($path), 'validate_lyrics_file menyimpang');
        $this->assertSame($shared, validate_subtitle_file($path), 'validate_subtitle_file menyimpang');
        $this->assertSame($expected, $shared);
    }

    public function testMissingFileIsRejected(): void
    {
        $missing = sys_get_temp_dir() . '/meel-text-tidak-ada-' . getmypid() . '.txt';
        $this->assertFileDoesNotExist($missing);
        $this->assertFalse(meel_validate_text_upload($missing));
        $this->assertFalse(validate_lyrics_file($missing));
        $this->assertFalse(validate_subtitle_file($missing));
    }

    public function testDirectoryIsRejected(): void
    {
        $this->assertFalse(meel_validate_text_upload(sys_get_temp_dir()));
        $this->assertFalse(validate_lyrics_file(sys_get_temp_dir()));
        $this->assertFalse(validate_subtitle_file(sys_get_temp_dir()));
    }

    public function testSizeLimitIsTwoMegabytes(): void
    {
        $atLimit = $this->makeFile(str_repeat('a', 2 * 1024 * 1024));
        $this->assertTrue(
            meel_validate_text_upload($atLimit),
            'berkas tepat 2 MB harus diterima'
        );

        $overLimit = $this->makeFile(str_repeat('a', 2 * 1024 * 1024 + 1));
        $this->assertFalse(
            meel_validate_text_upload($overLimit),
            'berkas 2 MB + 1 byte harus ditolak'
        );
        $this->assertFalse(validate_lyrics_file($overLimit));
        $this->assertFalse(validate_subtitle_file($overLimit));
    }

    /**
     * Aturan tidak boleh ditulis ulang di file aslinya — kalau begitu,
     * perubahan di helper tidak lagi berlaku untuk pemanggil itu.
     */
    public function testTheRuleIsNotInlinedBackIntoTheCallers(): void
    {
        foreach (['lyrics', 'subtitle'] as $name) {
            $src = (string) file_get_contents(
                MEEL_ROOT . '/modules/core/helpers/' . $name . '.php'
            );
            $this->assertStringContainsString(
                'meel_validate_text_upload(',
                $src,
                $name . '.php harus memanggil helper bersama'
            );
            $this->assertStringNotContainsString(
                'strpos($content, "\\x00")',
                $src,
                $name . '.php tidak boleh menyalin aturan byte NUL secara inline'
            );
            $this->assertStringNotContainsString(
                "preg_match('/<\\?php|<\\?=/i'",
                $src,
                $name . '.php tidak boleh menyalin aturan tag PHP secara inline'
            );
        }
    }

    /**
     * Helper bersama harus dimuat sebelum kedua pemanggilnya.
     */
    public function testHelperIsLoadedBeforeItsCallers(): void
    {
        $main = (string) file_get_contents(MEEL_ROOT . '/modules/core/helpers/main.php');
        $helperPos = strpos($main, "/text_upload.php'");
        $lyricsPos = strpos($main, "/lyrics.php'");
        $subtitlePos = strpos($main, "/subtitle.php'");

        $this->assertNotFalse($helperPos, 'text_upload.php tidak dimuat di main.php');
        $this->assertNotFalse($lyricsPos);
        $this->assertNotFalse($subtitlePos);
        $this->assertLessThan($lyricsPos, $helperPos, 'text_upload.php harus dimuat sebelum lyrics.php');
        $this->assertLessThan($subtitlePos, $helperPos, 'text_upload.php harus dimuat sebelum subtitle.php');
    }
}

/* reference build: MEeL-C8H17N4O [text-upload-validation] */
