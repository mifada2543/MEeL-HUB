<?php
use PHPUnit\Framework\TestCase;

/**
 * Regresi: upload musik di upload_advanced.php sukses di UI tapi baris
 * upload_queue tetap 'processing'.
 *
 * Akar: facade Transcoder::encodeMusic() tidak meneruskan $queueId ke
 * EncodeService, sehingga settleQueue() no-op. Plus banyak cabang error
 * di EncodeService tidak settle 'failed'.
 *
 * Test ini mengunci kontrak bentuk kode (seperti security_test.php).
 *
 * @coversNothing
 */
class UploadQueueSettlementTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }

    public function testEncodeMusicFacadeForwardsQueueId(): void
    {
        $src = $this->read('/modules/core/Transcoder.php');
        $this->assertMatchesRegularExpression(
            '/function\s+encodeMusic\s*\([^)]*\)\s*:\s*array\s*\{.*?\$this->encodeService->encodeMusic\s*\([^\\)]*\$attemptId\s*,\s*\$queueId/s',
            $src,
            'Transcoder::encodeMusic() harus meneruskan $queueId ke EncodeService. '
            . 'Tanpa itu $currentQueueId=0 dan settleQueue() tidak pernah jalan '
            . 'sehingga queue musik menggantung di processing.'
        );
    }

    public function testEncodeServiceSettlesQueueOnFailurePaths(): void
    {
        $src = $this->read('/modules/transcoder/EncodeService.php');
        $count = substr_count($src, "settleQueue('failed')");
        $this->assertGreaterThanOrEqual(
            5,
            $count,
            "EncodeService harus settle queue 'failed' di semua jalur terminal error "
            . "(token, file, lock, disconnect, thumbnail, transaksi, catch-all). "
            . "Ditemukan hanya $count."
        );
    }

    public function testVideoPersistUpdatesQueueInTransaction(): void
    {
        $src = $this->read('/modules/transcoder/DownloadService.php');
        // persistVideoToHdd() harus menyentuh upload_queue (settlement dalam
        // transaksi yang sama dengan insert video).
        $start = strpos($src, 'function persistVideoToHdd');
        $this->assertNotFalse($start, 'persistVideoToHdd() tidak ditemukan');
        $end = strpos($src, 'function rollbackFinalizeVideo', (int) $start);
        $body = $end === false ? substr($src, (int) $start) : substr($src, (int) $start, $end - (int) $start);
        $this->assertStringContainsString(
            'upload_queue',
            $body,
            'persistVideoToHdd() harus memperbarui upload_queue di dalam transaksi yang sama '
            . 'dengan insert video, agar tidak ada jendela commit-media-tapi-queue-belum.'
        );
    }

    public function testInsertMusicRowAccessIsGuarded(): void
    {
        $src = $this->read('/modules/transcoder/EncodeService.php');
        // Akses $ins[0]/$ins[1] harus diguard (mis. ?? ) agar tidak memicu
        // "Undefined array key 0" yang tersebar ke UI via meelError().
        $this->assertMatchesRegularExpression(
            '/\$ins\s*\[\s*0\s*\]\s*\?\?/',
            $src,
            'Akses $ins[0] harus diguard dengan ?? untuk menghindari "Undefined array key 0".'
        );
    }
}

/* reference build: MEeL-T6N8KD4 [upload-queue-settlement] */
