<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class MetadataAsciiFastPathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/japanese.php';
    }
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testAsciiDetectionHelper(): void
    {
        $this->assertTrue(meel_japanese_is_ascii_only('Hello World'));
        $this->assertTrue(meel_japanese_is_ascii_only('FIRE BIRD (10th anniv. ver.)'));
        $this->assertFalse(meel_japanese_is_ascii_only('夜に駆ける'));
        $this->assertFalse(meel_japanese_is_ascii_only('Hello 夜'));
        $this->assertFalse(meel_japanese_is_ascii_only('café'));
    }
    public function testAsciiTitleProducesSlugWithoutMecab(): void
    {
        $this->assertSame(
            ['romaji' => 'hello-world', 'english' => ''],
            analyzeJapaneseText('Hello World'),
            'Judul ASCII harus jadi slug langsung, tanpa gloss (tidak ada alias Latin).'
        );
        $this->assertSame('hello-world', getRomajiName('Hello World'));
    }
    public function testAsciiSlugKeepsInnerDigitsTogether(): void
    {
        $this->assertSame('fire-bird-10th-anniv-ver', getRomajiName('FIRE BIRD (10th anniv. ver.)'));
    }
    public function testJapaneseTextStillUsesMecabPath(): void
    {
        if (!function_exists('meel_mecab_available') || !meel_mecab_available()) {
            $this->markTestSkipped('mecab tidak tersedia');
        }
        $result = analyzeJapaneseText('夜に駆ける');
        $this->assertSame('yoru-ni-kakeru', $result['romaji']);
        $this->assertNotSame('', $result['english']);
    }
    public function testFastPathIsCheckedBeforeMecabIsSpawned(): void
    {
        $src = $this->read('/modules/core/japanese.php');
        foreach (['function analyzeJapaneseText', 'function getRomajiName'] as $fn) {
            $start = strpos($src, $fn);
            $this->assertNotFalse($start, "$fn() tidak ditemukan");
            $body = substr($src, (int) $start, 4000);
            $fast = strpos($body, 'meel_japanese_is_ascii_only');
            $mecab = strpos($body, 'getMecabPath()');
            $this->assertNotFalse($fast, "$fn() harus punya fast-path ASCII");
            $this->assertNotFalse($mecab, "$fn() harus tetap memakai mecab untuk teks Jepang");
            $this->assertLessThan(
                $mecab,
                $fast,
                "$fn() harus memeriksa fast-path SEBELUM menjalankan mecab"
            );
        }
    }
}
/* reference build: MEeL-T9N1JP2 [metadata-ascii-fast-path] */
