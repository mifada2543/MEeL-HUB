<?php
use PHPUnit\Framework\TestCase;

/**
 * Akuntansi MEeLCoin pada baris antrean upload lanjutan.
 *
 * Alur baru memakai hold: saldo total tidak berubah sampai media dan file
 * terverifikasi. `coin_cost` tetap dicatat supaya baris legacy dan sweeper
 * tahu berapa yang harus diselesaikan, sedangkan `hold_id` menunjuk
 * reservasi yang harus dilepas kalau job gagal.
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
     * Upload lanjutan harus mereservasi hold, bukan memotong saldo di awal.
     * Biaya dan hold tetap dicatat di baris antrean untuk penyelesaian.
     */
    public function testAdvancedUploadReservesBeforeRecordingQueueCost(): void
    {
        $this->assertStringNotContainsString(
            'MeelCoin::spend(',
            $this->advanced,
            'upload_advanced.php tidak boleh memotong saldo sebelum media terverifikasi'
        );

        $holdPos = strpos($this->advanced, 'MeelCoin::hold(');
        $this->assertNotFalse($holdPos, 'upload_advanced.php harus memanggil MeelCoin::hold()');

        $attachPos = strpos($this->advanced, 'UploadAttempt::attachHold(');
        $this->assertNotFalse($attachPos, 'hold harus diikat ke sesi upload');
        $this->assertGreaterThan($holdPos, $attachPos, 'attachHold() harus setelah hold dibuat');

        $recordPos = strpos($this->advanced, 'DownloadService::setPendingCoinCost(');
        $this->assertNotFalse(
            $recordPos,
            'upload_advanced.php harus mencatat biaya ke DownloadService setelah hold terikat'
        );
        $this->assertGreaterThan(
            $attachPos,
            $recordPos,
            'setPendingCoinCost() harus dipanggil setelah hold terikat ke attempt'
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
            $this->advanced,
            'upload lanjutan harus mencatat biaya sebelum membuat baris antrean'
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
     * Penyelesaian memakai biaya/hold yang tercatat di baris itu, bukan
     * harga saat ini. Harga MEeLCoin bisa berubah setelah job dibuat.
     */
    public function testSettlementUsesTheCostAndHoldRecordedOnTheRow(): void
    {
        $this->assertStringContainsString(
            'SELECT id, user_id, coin_cost,',
            $this->gc,
            'baris macet harus dibaca lengkap dengan coin_cost-nya'
        );
        $this->assertStringContainsString(
            'hold_id',
            $this->gc,
            'sweeper harus membaca hold_id untuk jalur hold'
        );
        $this->assertStringContainsString(
            'releaseHoldInTransaction(',
            $this->gc,
            'baris ber-hold harus diselesaikan dengan melepas hold, bukan refund tunai'
        );
        $this->assertStringNotContainsString(
            'getCost(',
            $this->gc,
            'GarbageCollector tidak boleh mengambil harga saat ini — yang diselesaikan adalah '
            . 'jumlah yang benar-benar dicatat saat baris dibuat'
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
