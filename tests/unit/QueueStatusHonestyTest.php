<?php
use PHPUnit\Framework\TestCase;

/**
 * T6 — status antrean harus jujur.
 *
 * Bug yang ditutup: `releaseQueue()` dulu dipanggil SEBELUM
 * `finalizeVideo()`/`finalizeMusic()`, sehingga baris antrean ditandai
 * `completed` walau finalize lalu gagal. Akibatnya user melihat upload
 * sukses padahal tidak ada file di storage dan tidak ada baris di DB —
 * dan MEeLCoin yang sudah dipotong tidak dikembalikan.
 *
 * Perbaikan sudah ada di produksi (releaseQueue pindah ke SESUDAH
 * finalize, statusnya ditentukan hasil finalize). Yang belum ada adalah
 * **jaminan**-nya: tidak ada satu pun test yang mengunci urutan itu, atau
 * kondisi penentuan statusnya.
 *
 * Test ini menutup dua hal sekaligus:
 *  1. Guard statis — releaseQueue harus dipanggil setelah finalize, dan
 *     statusnya harus bergantung pada hasilnya (bukan hardcode).
 *  2. Perilaku releaseQueue() — hanya boleh menulis status yang valid,
 *     dan harus benar-benar menandai baris yang dituju.
 *
 * Kenapa statis + behavior, bukan hanya behavior: urutan pemanggilan
 * sulit diamati dari luar tanpa menjalankan download sungguhan (ffmpeg,
 * network). Guard statis menutupnya secara deterministik — pola yang sama
 * dipakai InteractionsFkSourceTest untuk FK database.
 */

/** @coversNothing */
class QueueStatusHonestyTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        parent::setUp();
        $path = MEEL_ROOT . '/modules/transcoder/DownloadService.php';
        $this->assertFileExists($path);
        $this->src = (string)file_get_contents($path);
    }

    /**
     * Ekstrak badan sebuah method privat dari source.
     */
    private function methodBody(string $name): string
    {
        $start = strpos($this->src, "function {$name}(");
        $this->assertNotFalse($start, "Method {$name}() tidak ditemukan di DownloadService.php");

        $brace = strpos($this->src, '{', $start);
        $this->assertNotFalse($brace);

        $depth = 0;
        $len = strlen($this->src);
        for ($i = $brace; $i < $len; $i++) {
            if ($this->src[$i] === '{') {
                $depth++;
            } elseif ($this->src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($this->src, $start, $i - $start + 1);
                }
            }
        }
        $this->fail("Method {$name}() tidak tertutup kurungawal — source rusak?");
    }

    /**
     * Posisi karakter sebuah pola di dalam source.
     */
    private function posOf(string $needle): int
    {
        $pos = strpos($this->src, $needle);
        $this->assertNotFalse($pos, "Pola \"{$needle}\" tidak ditemukan");
        return $pos;
    }

    public function testReleaseQueueHappensAfterFinalizeInBothBranches(): void
    {
        $finalizeVideo = $this->posOf('$this->finalizeVideo(');
        $release = $this->posOf('$this->releaseQueue($queue_id, $finalized_ok');

        $this->assertGreaterThan(
            $finalizeVideo,
            $release,
            'releaseQueue() untuk video harus dipanggil SESUDAH finalizeVideo(). '
            . 'Kalau dibalik, antrean ditandai completed walau finalize gagal '
            . '(bug T6 yang asli).'
        );

        $finalizeMusic = $this->posOf('$this->finalizeMusic(');
        $this->assertGreaterThan(
            $finalizeMusic,
            $release,
            'releaseQueue() untuk musik juga harus setelah finalizeMusic()'
        );
    }

    /**
     * Status tidak boleh hardcode 'completed' — harus bergantung pada
     * hasil finalize.
     */
    public function testReleaseStatusDependsOnFinalizeOutcome(): void
    {
        $this->assertMatchesRegularExpression(
            '/\$this->releaseQueue\(\s*\$queue_id\s*,\s*\$finalized_ok\s*\?\s*\'completed\'\s*:\s*\'failed\'\s*\)/',
            $this->src,
            "releaseQueue() harus memakai \$finalized_ok ? 'completed' : 'failed' — "
            . 'status hardcode berarti upload bisa ditandai sukses padahal finalize gagal'
        );

        $this->assertMatchesRegularExpression(
            '/\$finalized_ok\s*=\s*str_starts_with\(\s*\$result\s*,\s*\'ENCODE_MUSIC:\'\s*\)/',
            $this->src,
            '$finalized_ok (musik) harus diturunkan dari awalan hasil finalizeMusic()'
        );
        $this->assertMatchesRegularExpression(
            '/\$finalized_ok\s*=\s*str_starts_with\(\s*\$result\s*,\s*\'DONE:\'\s*\)/',
            $this->src,
            '$finalized_ok (video) harus diturunkan dari awalan hasil finalizeVideo()'
        );
    }

    /**
     * Setiap jalur gagal sebelum finalize harus menandai 'failed'.
     */
    public function testEveryEarlyExitMarksQueueFailed(): void
    {
        $body = $this->methodBody('processDownload');

        $failedCalls = substr_count($body, "releaseQueue(\$queue_id, 'failed')");
        $this->assertGreaterThanOrEqual(
            3,
            $failedCalls,
            "Ada {$failedCalls} jalur 'failed' di processDownload(). Dulu ada jalur gagal "
            . '(download error, disk penuh, exception) yang HARUS menandai antrean failed — '
            . 'kalau tidak, baris menggantung selamanya dan user tidak dapat refund.'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/releaseQueue\(\s*\\\$queue_id\s*,\s*'completed'\s*\)/",
            $body,
            "processDownload() tidak boleh pernah menandai 'completed' secara langsung — "
            . "hanya lewat \$finalized_ok setelah finalize benar-benar sukses"
        );
    }

    public function testReleaseQueueWhitelistsStatus(): void
    {
        $body = $this->methodBody('releaseQueue');

        $this->assertStringContainsString(
            "['completed', 'failed']",
            $body,
            'releaseQueue() harus punya daftar putih status'
        );
        $this->assertStringContainsString(
            'in_array(',
            $body,
            'releaseQueue() harus memvalidasi status terhadap daftar putih'
        );
        $this->assertMatchesRegularExpression(
            '/in_array\(\s*\$status\s*,\s*\$allowed\s*,\s*true\s*\)/',
            $body,
            'Perbandingan status harus strict (true) — "==0" vs "==completed" bisa lolos lewat'
        );

        $this->assertMatchesRegularExpression(
            '/function releaseQueue\(\s*int \$queue_id\s*,\s*string \$status\s*=\s*\'failed\'\s*\)/',
            $body,
            "Default releaseQueue() harus 'failed' — kalau default 'completed', "
            . 'panggilan tanpa argumen status akan menandai upload sukses secara diam-diam'
        );
    }

    /**
     * Guard: jangan pernah menulis status di luar enum kolom.
     */
    public function testNoInvalidStatusEverWrittenToQueue(): void
    {
        preg_match_all(
            '/releaseQueue\(\s*\$queue_id\s*,\s*([^)]+)\)/',
            $this->src,
            $m
        );

        $this->assertNotEmpty($m[0], 'Tidak ada pemanggilan releaseQueue() yang ditemukan');

        foreach ($m[1] as $arg) {
            $arg = trim($arg);
            $isKnownSafe = in_array($arg, ["'failed'", "'completed'", '$finalized_ok ? \'completed\' : \'failed\''], true)
                || str_starts_with($arg, '$finalized_ok');
            $this->assertTrue(
                $isKnownSafe,
                "releaseQueue() dipanggil dengan argumen \"{$arg}\" yang tidak dikenal — "
                . 'hanya boleh \'completed\', \'failed\', atau ekspresi dari $finalized_ok'
            );
        }
    }

    /**
     * Baris yang ditandai 'failed' oleh DownloadService juga boleh
     * direfund sweeper — tapi T6 tidak boleh menandai 'completed' lalu
     * dibiarkan menggantung.
     */
    public function testFailedRowsRemainRefundableBySweeper(): void
    {
        $gc = (string)file_get_contents(MEEL_ROOT . '/modules/core/GarbageCollector.php');

        $this->assertMatchesRegularExpression(
            "/status IN \('pending', 'processing'\)/",
            $gc,
            'Sweeper harus mengambil baris pending/processing'
        );

        $this->assertStringNotContainsString(
            'coin_refunded',
            $this->methodBody('releaseQueue'),
            "releaseQueue() tidak boleh menyentuh coin_refunded — refund hanya boleh dilakukan "
            . 'satu kali oleh MeelCoin::refund() di jalur gagal upload, atau oleh sweeper T17a'
        );
    }

    /**
     * Kolom coin_refunded hanya di-set di jalur refund, dan jalur refund
     * harus berada di luar releaseQueue (yang hanya mengubah status).
     */
    public function testReleaseQueueOnlyTouchesStatusColumn(): void
    {
        $body = $this->methodBody('releaseQueue');

        $this->assertMatchesRegularExpression(
            '/UPDATE upload_queue SET status = \? WHERE id = \?/',
            $body,
            'releaseQueue() harus hanya mengubah kolom status — kalau ia ikut mengubah '
            . 'kolom lain (coin_refunded, coin_cost), bisa merusak Accounting refund'
        );

        $this->assertSame(
            1,
            substr_count($body, 'UPDATE'),
            'releaseQueue() harus punya tepat satu statement UPDATE'
        );
    }
}

/* reference build: MEeL-C10H15N [queue-status-honesty-t6] */