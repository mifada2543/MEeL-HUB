<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga pemecahan `finalizeVideo()` (T19).
 *
 * Fase supervise dua anak proses ffmpeg dipindah ke `runParallelFfmpeg()`,
 * private method di kelas yang SAMA — bukan kelas baru. Semua hook yang
 * dibutuhkan ber-`protected` di `TranscoderBase` dan daftar anak proses
 * adalah state per-instance yang dipindai `terminateAllProcesses()` saat
 * shutdown; kelas terpisah tidak bisa menjangkau itu tanpa melebarkannya
 * jadi `public`.
 *
 * @coversNothing
 */
class FinalizeVideoSplitTest extends TestCase
{
    private const SERVICE = '/modules/transcoder/DownloadService.php';

    private function serviceSource(): string
    {
        $path = MEEL_ROOT . self::SERVICE;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    /**
     * The whole point of the refactor. `finalizeVideo()` should read like
     * a table of contents, not like an essay.
     */
    public function testFinalizeVideoIsNoLongerAMonolith(): void
    {
        $body = $this->extractMethod($this->serviceSource(), 'finalizeVideo');

        $this->assertNotSame('', $body, 'finalizeVideo() tidak ditemukan');

        $lines = substr_count($body, "\n");
        $this->assertLessThan(
            190,
            $lines,
            "finalizeVideo() masih {$lines} baris. Targetnya di bawah 190 supaya "
            . 'orkestrator benar-benar mengorkestrasi fase, bukan memikulnya.'
        );
    }

    /**
     * The extracted method must exist and must own the process lifecycle
     * that used to be inline.
     */
    public function testParallelRunnerOwnsTheProcessLifecycle(): void
    {
        $src = $this->serviceSource();
        $body = $this->extractMethod($src, 'runParallelFfmpeg');

        $this->assertNotSame(
            '',
            $body,
            'runParallelFfmpeg() tidak ditemukan. Fase paralel harus dipisah, '
            . 'bukan hanya dipindah posisinya.'
        );

        foreach (['proc_open', 'stream_select', 'proc_close'] as $call) {
            $this->assertStringContainsString(
                $call,
                $body,
                "runParallelFfmpeg() harus memuat {$call}() -- itu inti dari method ini."
            );
        }

        $final = $this->extractMethod($src, 'finalizeVideo');
        foreach (['proc_open', 'stream_select', 'proc_close'] as $call) {
            $this->assertStringNotContainsString(
                $call,
                $final,
                "finalizeVideo() masih memanggil {$call}() langsung. Pemecahan harus "
                . 'memindahkan seluruh siklus hidup proses ke runParallelFfmpeg(); kalau '
                . 'tidak, method itu hanya dipotong di tempat lain dan god-method-nya '
                . 'utuh.'
            );
        }
    }

    /**
     * The supervision loop is where "did this actually work" lives. Three
     * failure modes it must not lose:
     *
     *  1. a child that never gets reaped (zombie + a PID file nobody removes)
     *  2. a timeout that leaves the child running
     *  3. the sprite being treated as fatal when it is meant to be optional
     *
     * These are assertions about the SHAPE of the code, not about behaviour.
     */
    public function testSupervisionFailureModesSurvivedTheMove(): void
    {
        $src = $this->serviceSource();

        $runner = $this->extractMethod($src, 'runParallelFfmpeg');
        $this->assertNotSame('', $runner);

        $this->assertStringContainsString(
            'untrackChildProcess',
            $runner,
            'Anak proses harus dilepas dari daftar anak. Kalau ini hilang di sini, '
            . 'terminateAllProcesses() akan mengejar proses yang sudah selesai, dan '
            . 'handle proses yang sudah selesai tak bisa di-rebut.'
        );

        $this->assertStringContainsString(
            'terminateChildProcess',
            $runner,
            'Jalur timeout harus mematikan anak proses, bukan membiarkannya berjalan. '
            . 'ffmpeg yang terus berjalan setelah timeout akan memakan CPU dan, lebih '
            . 'buruk, mensegel berkas yang seharusnya sudah dibersihkan.'
        );

        $this->assertStringContainsString(
            'removePidFile',
            $runner,
            'PID file harus dihapus setelah proses di-reap. Job yang selesai tapi PID '
            . 'file-nya tertinggal akan dihapus cron sebagai "yatim" padahal job-nya '
            . 'sehat -- dan cron itu milik job yang sedang jalan.'
        );

        $this->assertStringContainsString(
            'writePidFile',
            $runner,
            'PID file harus ditulis saat proses start; tanpa itu, shutdown tidak '
            . 'punya cara menemukan ffmpeg yang masih berjalan.'
        );

        $final = $this->extractMethod($src, 'finalizeVideo');
        $this->assertMatchesRegularExpression(
            '/sprite[^\n]*exit\s*!==?\s*0|tidak\s*lanjut/i',
            $final,
            'Kegagalan sprite harus tetap ditoleransi (video jalan tanpa sprite). '
            . 'Kalau sprite jadi fatal, video tanpa thumbnail sprite tidak akan pernah '
            . 'selesai padahal seharusnya.'
        );
    }

    /**
     * Guards against a future "simplification" that folds the runner back
     * into the caller. The reason for the split has to stay on the record
     * next to the code, or the next person who finds 268 lines will
     * reasonably conclude there is no better shape.
     */
    public function testTheReasonForTheSplitIsDocumented(): void
    {
        $src = $this->serviceSource();
        $doc = $this->extractDocBlock($src, 'runParallelFfmpeg');

        $this->assertNotSame(
            '',
            $doc,
            'runParallelFfmpeg() harus punya docblock. Di dalamnya ada penjelasan '
            . 'KENAPA proses ini tidak diekstrak jadi kelas baru -- tanpa alasan itu '
            . 'di sana, orang berikutnya akan mengusulkannya lagi.'
        );

        $this->assertStringContainsString(
            '@param',
            $doc,
            'Bentuk job yang diterima harus terdokumentasi.'
        );
        $this->assertStringContainsString(
            '@return',
            $doc,
            'Nilai kembalian harus terdokumentasi -- caller harus tahu proses mana '
            . 'yang benar-benar sempat jalan sebelum mempercayai exit code-nya.'
        );

        $this->assertStringContainsString(
            'TranscoderBase',
            $doc,
            'Docblock harus menyebut host yang memegang bookkeeping proses anak.'
        );
    }

    /**
     * Docblock tepat sebelum tanda tangan method.
     *
     * Penting: tag `@param` berada di docblock, yang mendahului tanda
     * tangan fungsi -- bukan di dalam badan. Assertion yang mencari
     * `@param` di badan akan gagal padahal dokumentasinya lengkap.
     */
    private function extractDocBlock(string $src, string $method): string
    {
        $pos = strpos($src, 'function ' . $method . '(');
        if ($pos === false) {
            return '';
        }
        $before = substr($src, 0, $pos);
        $end = strrpos($before, '/**');
        if ($end === false) {
            return '';
        }
        $close = strpos($before, '*/', $end);
        if ($close === false) {
            return '';
        }

        $between = trim(substr($before, $close + 2));
        if (!preg_match('/^(?:(?:final|private|protected|public|static|abstract)\s*)*$/', $between)) {
            return '';
        }
        return substr($before, $end, $close + 2 - $end);
    }

    /**
     * Extract a method body by brace matching. Regex with `.*?` is not
     * reliable here: ffmpeg command arrays and error strings contain
     * braces that have nothing to do with the function boundary.
     */
    private function extractMethod(string $src, string $method): string
    {
        $pos = strpos($src, 'function ' . $method . '(');
        if ($pos === false) {
            return '';
        }
        $brace = strpos($src, '{', $pos);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }
        return '';
    }
}

/* reference build: MEeL-P9V3MX1 [finalize-video-split] */