<?php
use PHPUnit\Framework\TestCase;

/**
 * T8 — akuntansi refund MEeLCoin pada baris antrean.
 *
 * `upload_queue.coin_cost` dan `coin_refunded` tidak pernah terisi:
 * `setPendingCoinCost()` hanya dipanggil dari alir yang justru tidak pernah
 * membuat baris `upload_queue`, jadi `coin_cost` selalu 0 dan sweeper tidak
 * pernah bisa mengembalikan biaya yang sebenarnya.
 *
 * Bug kedua: `upload_advanced.php` menandai baris `processing` yang basi jadi
 * `failed` tanpa refund, dan baris `failed` tidak lagi dipilih sweeper.
 *
 * Test ini mengunci kedua sisi: nilai yang dicatat harus benar, dan setiap
 * jalur yang melepas baris macet harus lewat mekanisme refund yang sama.
 *
 * @coversNothing
 */
class QueueRefundAccountingTest extends TestCase
{
    private string $advanced;
    private string $gc;
    private string $uploadHelper;
    private string $downloadService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->advanced = $this->read('upload_advanced.php');
        $this->gc = $this->read('modules/core/GarbageCollector.php');
        $this->uploadHelper = $this->read('modules/core/helpers/upload.php');
        $this->downloadService = $this->read('modules/transcoder/DownloadService.php');
    }

    private function read(string $rel): string
    {
        $path = MEEL_ROOT . '/' . $rel;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * Biaya yang dipotong harus dicatat di baris antrean, bukan hanya
     * dikembalikan ke saldo.
     */
    public function testAdvancedUploadRecordsTheChargedAmountInTheQueueRow(): void
    {
        $spendPos = strpos($this->advanced, 'MeelCoin::spend(');
        $this->assertNotFalse($spendPos, 'upload_advanced.php harus memanggil MeelCoin::spend()');

        $recordPos = strpos($this->advanced, 'DownloadService::setPendingCoinCost(');
        $this->assertNotFalse(
            $recordPos,
            'upload_advanced.php harus memanggil DownloadService::setPendingCoinCost() setelah '
            . 'MeelCoin::spend() — tanpa itu, upload_queue.coin_cost tetap 0 dan sweeper '
            . 'tidak pernah bisa mengembalikan biaya yang sebenarnya'
        );
        $this->assertGreaterThan(
            $spendPos,
            $recordPos,
            'setPendingCoinCost() harus dipanggil SETELAH spend(), bukan sebelumnya'
        );
    }

    /**
     * `lockQueue()` adalah satu-satunya INSERT ke `upload_queue`, jadi apa pun
     * yang ia tulis ke `coin_cost` adalah nilai yang akan direfund.
     */
    public function testQueueRowCostComesFromThePendingCostNotAFallback(): void
    {
        $this->assertMatchesRegularExpression(
            '/INSERT INTO upload_queue[^;]*coin_cost/s',
            $this->downloadService,
            'lockQueue() harus menulis kolom coin_cost saat INSERT'
        );
        $this->assertStringContainsString(
            'self::$pendingCoinCost',
            $this->downloadService,
            'lockQueue() harus memakai nilai yang dicatat lewat setPendingCoinCost()'
        );
        $this->assertStringContainsString(
            'DownloadService::setPendingCoinCost($coin_cost)',
            $this->uploadHelper,
            'jalur upload biasa harus mencatat biaya yang dipotong'
        );
    }

    /**
     * Tidak boleh ada jalur yang menandai baris `failed` tanpa refund.
     */
    public function testNoRawUpdateMarksStaleQueueRowsFailedWithoutRefund(): void
    {
        $this->assertStringNotContainsString(
            "UPDATE upload_queue SET status = 'failed'",
            $this->advanced,
            'upload_advanced.php tidak boleh menandai baris macet jadi failed tanpa refund — '
            . 'baris failed tidak lagi dipilih sweeper, jadi biaya MEeLCoin hilang permanen'
        );
        $this->assertStringContainsString(
            'GarbageCollector::releaseStaleQueueRows(',
            $this->advanced,
            'pembersihan baris macet harus lewat jalur yang sama dengan sweeper'
        );
    }

    /**
     * Satu implementasi, dua pemanggil — supaya tidak bisa lagi berbeda
     * perilaku.
     */
    public function testSweeperAndStaleCleanupShareOneImplementation(): void
    {
        $this->assertStringContainsString(
            'public static function releaseStaleQueueRows(',
            $this->gc,
            'releaseStaleQueueRows() harus ada dan publik'
        );
        $this->assertMatchesRegularExpression(
            '/function sweepStuckQueues\(.*?releaseStaleQueueRows\(/s',
            $this->gc,
            'sweepStuckQueues() harus mendelegasikan ke releaseStaleQueueRows()'
        );
    }

    /**
     * Refund memakai biaya yang tercatat di baris itu, bukan harga saat ini.
     * Harga MEeLCoin bisa berubah setelah user membayar.
     */
    public function testRefundUsesTheCostRecordedOnTheRow(): void
    {
        $this->assertMatchesRegularExpression(
            '/SELECT id, user_id, coin_cost FROM/',
            $this->gc,
            'baris macet harus dibaca lengkap dengan coin_cost-nya'
        );
        $this->assertStringNotContainsString(
            'getCost(',
            $this->gc,
            'GarbageCollector tidak boleh mengambil harga saat ini — yang direfund adalah '
            . 'jumlah yang benar-benar dipotong saat baris dibuat'
        );
    }

    /**
     * Refund harus Exactly-once: baris yang sudah `coin_refunded = 1` tidak
     * boleh direfund lagi, dan penandanya hanya boleh ditulis setelah coins
     * benar-benar kembali.
     */
    public function testRefundIsGuardedByTheCoinRefundedFlag(): void
    {
        $this->assertStringContainsString(
            'coin_refunded = 0',
            $this->gc,
            'sweeper hanya boleh mengambil baris yang belum direfund'
        );
        $this->assertStringContainsString(
            'FOR UPDATE',
            $this->gc,
            'baris harus dikunci FOR UPDATE selama refund, supaya dua sweeper '
            . 'concurrent tidak bisa merefund baris yang sama'
        );

        $refundPos = strpos($this->gc, 'MeelCoin::refund(');
        $markPos = strpos($this->gc, 'SET coin_refunded = 1');
        $this->assertNotFalse($refundPos);
        $this->assertNotFalse($markPos);
        $this->assertGreaterThan(
            $refundPos,
            $markPos,
            'penanda coin_refunded = 1 harus ditulis SETELAH refund berhasil, bukan sebelumnya'
        );
    }

    /**
     * Invarian lama T6 tetap berlaku: `releaseQueue()` hanya boleh mengubah
     * status. Kalau ia ikut menyentuh `coin_refunded`, baris yang sudah
     * direfund jalur normal bisa direfund lagi.
     */
    public function testReleaseQueueStillOnlyTouchesStatus(): void
    {
        $start = strpos($this->downloadService, 'function releaseQueue(');
        $this->assertNotFalse($start, 'releaseQueue() tidak ditemukan');

        $brace = strpos($this->downloadService, '{', $start);
        $depth = 0;
        $body = '';
        for ($i = $brace; $i < strlen($this->downloadService); $i++) {
            $body .= $this->downloadService[$i];
            if ($this->downloadService[$i] === '{') {
                $depth++;
            } elseif ($this->downloadService[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
        }

        $this->assertStringNotContainsString(
            'coin_refunded',
            $body,
            'releaseQueue() tidak boleh menyentuh coin_refunded — refund hanya boleh '
            . 'dilakukan satu kali, oleh jalur gagal upload ATAU oleh sweeper'
        );
    }
}

/* reference build: MEeL-C8H12N4O [queue-refund-accounting-t8] */
