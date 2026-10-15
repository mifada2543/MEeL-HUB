<?php

use PHPUnit\Framework\TestCase;

/**
 * Penjaga peta pola pemeriksaan transcoder di `security_test.php`.
 *
 * THE PROBLEM
 * -----------
 * Pola keamanan adalah string yang mengikat ke BENTUK kode, bukan ke
 * perilakunya. Awalnya dua di antaranya mengikat pada nama variabel:
 *
 *   'proc_open array (finalizeVideo)'  => '/proc_open\(\$hls_cmd/'
 *   'stderr pipe untuk progress FFmpeg' => '/\$hls_pipes\[2\]/'
 *
 * Waktu `finalizeVideo()` dipecah (T19) dan siklus hidup prosesnya
 * pindah ke `runParallelFfmpeg()`, kedua pola itu tidak lagi cocok. Gerbang
 * laporkan 99/100 dengan dua kegagalan -- hasil yang BAik, karena memaksa
 * seseorang melacak letaknya.
 *
 * Yang buruk adalah arah yang lain: pola yang mati dipoles atau dihapus supaya
 * gerbang hijau lagi, tanpa pernah ditinjau ulang. Tidak ada error, tidak
 * ada skor yang turun, dan penjaga yang sebelumnya aktif kini tidak
 * memeriksa apa pun.
 *
 * Test ini menutup arah yang itu: setiap pola di peta WAJIB cocok dengan
 * kode yang ada hari ini. Pola mati gagal di sini, di unit test, bukan
 bukan diam-diam menunggu produksi.
 *
 * Catatan jujur: pola memeriksa bentuk, bukan hasil. Pemeriksaan security
 * yang sesungguhnya tetap butuh uji perilaku. Yang bisa dijaga di sini
 * adalah tidak adanya penjaga yang hilang dalam perjalanan refactor.
 *
 * @coversNothing
 */
class TranscoderCheckPatternsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/tests/lib/transcoder_checks.php';
    }

    private function combinedSource(): string
    {
        $out = '';
        foreach (['/modules/core/Transcoder.php', '/modules/core/TranscoderBase.php'] as $rel) {
            if (is_file(MEEL_ROOT . $rel)) {
                $out .= (string) file_get_contents(MEEL_ROOT . $rel) . "\n";
            }
        }
        foreach (glob(MEEL_ROOT . '/modules/transcoder/*.php') ?: [] as $f) {
            $out .= (string) file_get_contents($f) . "\n";
        }
        return $out;
    }

    /**
     * Peta yang sama persis dengan yang dipakai `security_test.php`.
     * Kalau keduanya menyimpang, test ini memeriksa hal yang tidak
     * sedang dijalankan gerbang.
     */
    public function testSecurityTestUsesThisExactMap(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/tests/security_test.php');

        $this->assertStringContainsString(
            'meel_transcoder_check_patterns()',
            $src,
            'security_test.php harus memakai peta dari tests/lib/transcoder_checks.php. '
            . 'Kalau inline lagi, test ini memeriksa peta yang tidak sedang dijalankan.'
        );

        $this->assertStringContainsString(
            "/lib/transcoder_checks.php",
            $src,
            'security_test.php tidak memuat berkas peta polanya.'
        );
    }

    /**
     * Setiap pola harus cocok dengan kode hari ini.
     */
    public function testEveryPatternStillMatchesRealCode(): void
    {
        $src = $this->combinedSource();
        $this->assertNotSame('', $src, 'Sumber transcoder kosong -- test tidak berarti');

        $patterns = meel_transcoder_check_patterns();
        $this->assertNotEmpty($patterns, 'Peta pola kosong');

        $dead = [];
        foreach ($patterns as $name => $pattern) {
            if (@preg_match($pattern, $src) !== 1) {
                $dead[] = sprintf('  %s  =>  %s', $name, $pattern);
            }
        }

        $this->assertSame(
            [],
            $dead,
            "Pola pemeriksaan ini sudah tidak cocok dengan kode mana pun:\n"
            . implode("\n", $dead)
            . "\n\nDua kemungkinan, dan keduanya perlu ditangani, bukan dibiarkan:\n"
            . "  (a) kodenya berubah bentuk -- pola harus ditulis ulang ke bentuk\n"
            . "      yang baru, dan PENJAGAANNYA ditinjau ulang supaya masih\n"
            . "      memeriksa hal yang sama; atau\n"
            . "  (b) menjaganya dihapus -- hapus juga entri polanya, jangan hanya\n"
            . "      dibiarkan tidak cocok, karena itu membuat gerbang hijau\n"
            . "      tanpa memeriksa apa pun."
        );
    }

    /**
     * Pola harus valid regex. Regex rusak kembali men-WARNING lewat
     * `preg_match` yang mengembalikan false -- dan `false !== 1`, jadi
     * test di atas akan melaporkan "pola mati" untuk alasan yang salah
     * dan mengaratkan orang ke tempat yang keliru.
     */
    public function testEveryPatternIsAValidRegex(): void
    {
        $broken = [];
        foreach (meel_transcoder_check_patterns() as $name => $pattern) {
            set_error_handler(static fn(): bool => true);
            $r = @preg_match($pattern, '');
            restore_error_handler();
            if ($r === false) {
                $broken[] = sprintf('  %s  =>  %s', $name, $pattern);
            }
        }

        $this->assertSame(
            [],
            $broken,
            "Regex tidak valid:\n" . implode("\n", $broken)
            . "\n\nTanpa ini, pola rusak dilaporkan sebagai 'pola mati', yang "
            . 'menyesatkan: masalahnya sintaks regex, bukan kode yang berubah.'
        );
    }

    /**
     * Penjaga tidak boleh menyusut diam-diam. Dua pemeriksaan yang rusak saat
     * refactor T19 DIGANTI, bukan dihapus -- jumlahnya justru naik. Mematok
     * jumlahnya membuat "beres-beres" berikutnya yang diam-diam menjatuhkan
     * sebuah penjaga jadi kelihatan.
     */
    public function testNoCheckIsSilentlyDropped(): void
    {
        $patterns = meel_transcoder_check_patterns();

        foreach (['proc_open array (finalizeVideo)', 'proc_open array (transcodeVideo)'] as $wajib) {
            $this->assertArrayHasKey(
                $wajib,
                $patterns,
                "Pemeriksaan wajib hilang: {$wajib}"
            );
        }

        $this->assertGreaterThanOrEqual(
            10,
            count($patterns),
            'Jumlah pemeriksaan turun drastis. Kalau sebuah penjaga memang tidak '
            . 'perlu lagi, hapus dengan sengaja dan jelaskan -- jangan diam-diam sampai '
            . 'sisanya berkurang.'
        );
    }
}

/* reference build: MEeL-T5N8KD3 [transcoder-check-patterns] */