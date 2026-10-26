<?php
use PHPUnit\Framework\TestCase;

/**
 * T6 — status antrean harus jujur.
 *
 * `releaseQueue()` dulu dipanggil SEBELUM finalize, sehingga baris ditandai
 * `completed` walau finalize lalu gagal — user melihat upload sukses padahal
 * tidak ada file dan tidak ada baris di DB, dan MEeLCoin yang dipotong tidak
 * dikembalikan.
 *
 * Test ini menutup dua hal sekaligus:
 *  1. Guard statis — releaseQueue harus dipanggil setelah finalize, dan
 *     statusnya harus bergantung pada hasilnya.
 *  2. Perilaku releaseQueue() — hanya boleh menulis status yang valid.
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
        $body = $this->methodBody('processDownload');
        $finalizeVideo = strpos($body, '$this->finalizeVideo(');
        $finalizeMusic = strpos($body, '$this->finalizeMusic(');
        $release = strpos($body, '$this->releaseQueue($queue_id, \'completed\')');

        $this->assertNotFalse($finalizeVideo);
        $this->assertNotFalse($finalizeMusic);
        $this->assertNotFalse($release);
        $this->assertGreaterThan(
            $finalizeVideo,
            $release,
            'releaseQueue(completed) untuk video harus dipanggil SESUDAH finalizeVideo().'
        );
        $this->assertGreaterThan(
            $finalizeMusic,
            $release,
            'releaseQueue(completed) untuk musik juga harus setelah finalizeMusic()'
        );
    }

    /**
     * Status hanya boleh 'completed' kalau finalize berhasil; kegagalan harus
     * lewat failQueue() supaya hold ikut dilepas.
     */
    public function testReleaseStatusDependsOnFinalizeOutcome(): void
    {
        $body = $this->methodBody('processDownload');

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$finalized_ok\s*\)\s*\{\s*'
            . '\$this->releaseQueue\(\s*\$queue_id\s*,\s*\'completed\'\s*\);\s*'
            . '\}\s*else\s*\{\s*\$this->failQueue\(\s*\$queue_id\s*\);\s*\}/s',
            $body,
            'Status antrean harus bergantung pada $finalized_ok: completed hanya setelah '
            . 'finalize sukses, sedangkan kegagalan wajib lewat failQueue() agar hold dilepas.'
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
     * Setiap jalur gagal sebelum finalize harus lewat failQueue(), yang
     * melepas hold dan baru kemudian menandai baris failed.
     */
    public function testEveryEarlyExitSettlesThroughFailQueue(): void
    {
        $body = $this->methodBody('processDownload');
        $failCalls = substr_count($body, '$this->failQueue($queue_id)');

        $this->assertGreaterThanOrEqual(
            3,
            $failCalls,
            "Ada {$failCalls} jalur failQueue() di processDownload(). Setiap kegagalan "
            . '(metadata, download, disk, exception) harus melepas hold lewat jalur yang sama.'
        );
        $this->assertStringNotContainsString(
            "releaseQueue(\$queue_id, 'failed')",
            $body,
            'processDownload() tidak boleh menulis status failed langsung — gunakan '
            . 'failQueue() agar hold/refund selalu diselesaikan lebih dulu.'
        );
    }

    /**
     * failQueue() tidak boleh menandai captured hold sebagai gagal: media
     * sudah sah dan coin memang sudah dipakai.
     */
    public function testFailQueueKeepsCapturedHoldAsCompleted(): void
    {
        $body = $this->methodBody('failQueue');

        $this->assertStringContainsString(
            "=== 'captured'",
            $body,
            'failQueue() harus mendeteksi hold yang sudah captured'
        );
        $this->assertMatchesRegularExpression(
            '/releaseQueue\(\s*\$queue_id\s*,\s*\'completed\'\s*\)/',
            $body,
            'Hold yang captured berarti media sudah sah, jadi antrean harus completed'
        );
        $this->assertMatchesRegularExpression(
            '/releaseQueue\(\s*\$queue_id\s*,\s*\'failed\'\s*\)/',
            $body,
            'Jalur gagal biasa tetap harus menandai antrean failed'
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