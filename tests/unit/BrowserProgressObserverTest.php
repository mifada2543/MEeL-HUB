<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/BrowserProgressObserver.php';

/**
 * Pemetaan stage progres di `BrowserProgressObserver`.
 *
 * DULU
 * ----
 * `onProgress()` adalah satu `switch` dengan sembilan case, masing-masing
 * case merangkai string JS-nya sendiri. Panjang fungsi grew into 66 baris
 * dan menambah stage baru berarti menyunting switch yang sudah panjang.
 *
 * SEKARANG
 * --------
 * Stage dipetakan ke handler lewat `BrowserProgressObserver::STAGE_HANDLERS`.
 * `onProgress()` tinggal mencari handler-nya; tiap stage punya method
 * sendiri yang tidak tahu apa pun tentang stage lain.
 *
 * Test ini menahan:
 *  1. Setiap stage yang benar-benar dipancarkan transcoder harus punya
 *     handler — stage yang dilupakan akan diam-diam hilang.
 *  2. Setiap handler yang dipetakan harus benar-benar ada sebagai method
 *     (perlindungan terhadap rename yang tidak_diikuti pemetaan).
 *  3. String JS yang dipancarkan tidak boleh berubah.
 *
 * @coversNothing
 */
class BrowserProgressObserverTest extends TestCase
{
    private const OBSERVER_PATH = MEEL_ROOT . '/modules/core/BrowserProgressObserver.php';

    /**
     * @return array<string, string>
     */
    private function stageHandlers(): array
    {
        $constant = (new ReflectionClass(BrowserProgressObserver::class))->getConstant('STAGE_HANDLERS');
        $this->assertIsArray($constant, 'STAGE_HANDLERS harus berupa array');
        return $constant;
    }

    /**
     * @return list<string>
     */
    private function emittedStages(): array
    {
        $stages = [];
        $files = glob(MEEL_ROOT . '/modules/transcoder/*.php') ?: [];
        $files[] = MEEL_ROOT . '/modules/core/TranscoderBase.php';

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $src = (string) file_get_contents($file);
            if (preg_match_all("/\\\$this->emit\\('([a-z_]+)'/", $src, $m)) {
                foreach ($m[1] as $stage) {
                    $stages[$stage] = true;
                }
            }
        }

        return array_keys($stages);
    }

    public function testEveryStageTheTranscoderEmitsHasAHandler(): void
    {
        $handlers = $this->stageHandlers();
        $emitted = $this->emittedStages();

        $this->assertNotEmpty($emitted, 'Tidak ada stage yang terdeteksi — sumbernya berubah?');

        foreach ($emitted as $stage) {
            $this->assertArrayHasKey(
                $stage,
                $handlers,
                "Stage '{$stage}' dipancarkan transcoder tapi tidak punya handler — "
                . 'panggilannya akan hilang diam-diam'
            );
        }
    }

    public function testEveryMappedHandlerIsAnExistingMethod(): void
    {
        $reflection = new ReflectionClass(BrowserProgressObserver::class);

        foreach ($this->stageHandlers() as $stage => $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Stage '{$stage}' memetakan ke method '{$method}' yang tidak ada"
            );
            $this->assertTrue(
                $reflection->getMethod($method)->isPrivate(),
                "Handler '{$method}' harus private"
            );
        }
    }

    public function testStageHandlerMapCoversExactlyTheKnownStages(): void
    {
        $handlers = $this->stageHandlers();

        $expected = [
            'download_start',
            'transcode_start',
            'phase',
            'download_progress',
            'transcode_progress',
            'sprite_progress',
            'done',
            'done_transcode',
            'error',
        ];

        $this->assertSame($expected, array_keys($handlers), 'Daftar stage berubah');
    }

    /**
     * Stage `redirect` pernah ada di switch lama, tapi tidak pernah
     * dipancarkan dan cabangnya kosong. Meninggalkannya di peta hanya
     * menyesatkan.
     */
    public function testTheNeverEmittedRedirectStageIsNotHandled(): void
    {
        $this->assertArrayNotHasKey('redirect', $this->stageHandlers());
    }

    public function testUnknownStageProducesNoOutput(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('stage-yang-tidak-dikenal', ['x' => 1]);
        $output = ob_get_clean();

        $this->assertSame('', $output, 'Stage tak dikenal harus diam');
    }

    public function testPhaseEmitsTheExpectedJs(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('phase', ['phase' => 'download']);
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'if(typeof meelPhase==="function"){meelPhase(' . json_encode('download') . ');}',
            $output
        );
    }

    public function testTranscodeAndSpriteProgressCallDifferentFunctions(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('transcode_progress', ['pct' => 55, 'label' => '55% — CONVERTING']);
        $transcode = ob_get_clean();

        ob_start();
        $observer->onProgress('sprite_progress', ['pct' => 66, 'label' => '66% — SPRITE']);
        $sprite = ob_get_clean();

        $this->assertStringContainsString(
            'meelTcPct(' . json_encode(55) . ', ' . json_encode('55% — CONVERTING') . ');',
            $transcode
        );
        $this->assertStringContainsString(
            'meelSpPct(' . json_encode(66) . ', ' . json_encode('66% — SPRITE') . ');',
            $sprite
        );
        $this->assertStringNotContainsString('meelSpPct', $transcode);
        $this->assertStringNotContainsString('meelTcPct', $sprite);
    }

    public function testProgressWithoutLabelOmitsTheSecondArgument(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('transcode_progress', ['pct' => 10]);
        $output = ob_get_clean();

        $this->assertStringContainsString('meelTcPct(' . json_encode(10) . ');', $output);
    }

    public function testDownloadProgressWithEtaSendsEveryField(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('download_progress', [
            'pct' => 42,
            'eta' => '00:10',
            'speed' => '1.2M',
            'size' => '10M',
            'frag' => '3/10',
        ]);
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'meelDlPct(' . json_encode(42) . ',' . json_encode('00:10') . ','
            . json_encode('1.2M') . ',' . json_encode('10M') . ',' . json_encode('3/10') . ');',
            $output
        );
    }

    public function testDownloadProgressWithoutEtaSendsOnlyThePercentage(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('download_progress', ['pct' => 42]);
        $output = ob_get_clean();

        $this->assertStringContainsString('meelDlPct(' . json_encode(42) . ');', $output);
    }

    public function testDownloadProgressWithoutPctIsSilent(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('download_progress', []);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('meelDlPct', $output);
    }

    public function testDoneAndDoneTranscodeEmitTheirOwnFunctions(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('done', ['title' => 'Judul', 'url' => '/a/b']);
        $done = ob_get_clean();

        ob_start();
        $observer->onProgress('done_transcode', ['title' => 'Judul', 'download_link' => '/d/1']);
        $doneTranscode = ob_get_clean();

        $this->assertStringContainsString(
            'meelDone(' . json_encode('Judul') . ', ' . json_encode('/a/b') . ');',
            $done
        );
        $this->assertStringContainsString(
            'meelDoneTranscode(' . json_encode('Judul') . ', ' . json_encode('/d/1') . ');',
            $doneTranscode
        );
    }

    public function testErrorForGuestRedirectsToTheErrorPage(): void
    {
        $observer = new BrowserProgressObserver(false);

        ob_start();
        $observer->onProgress('error', ['message' => 'Gagal keras']);
        $output = ob_get_clean();

        $this->assertStringContainsString(
            'window.location.href="err/index.php?code=server_error";',
            $output
        );
        $this->assertStringNotContainsString('meelError(', $output);
    }

    /**
     * Jalur admin menyuntik overlay, dan `injectOverlay()` membersihkan
     * seluruh output buffer — jadi output-nya hanya bisa dilihat dari luar
     * proses.
     */
    public function testErrorForAdminEmitsTheMessageToTheOverlay(): void
    {
        $script = <<<'PHP'
require %s;
$observer = new BrowserProgressObserver(true);
$observer->onProgress('error', ['message' => 'Gagal keras']);
PHP;
        $code = sprintf($script, var_export(self::OBSERVER_PATH, true));

        $output = [];
        $exit = 0;
        exec(
            PHP_BINARY . ' -d display_errors=0 -r ' . escapeshellarg($code) . ' 2>&1',
            $output,
            $exit
        );

        $this->assertSame(0, $exit, 'Subprocess gagal: ' . implode("\n", $output));
        $this->assertStringContainsString(
            'meelError(' . json_encode('Gagal keras') . ');',
            implode("\n", $output)
        );
        $this->assertStringNotContainsString(
            'err/index.php?code=server_error',
            implode("\n", $output)
        );
    }

    /**
     * Yang dijaga di sini: fungsi dispatch harus tetap pendek dan tidak
     * lagi menjadi switch panjang.
     */
    public function testDispatchIsShortAndNoLongerASwitch(): void
    {
        $src = (string) file_get_contents(self::OBSERVER_PATH);

        $this->assertStringNotContainsString(
            'switch ($stage)',
            $src,
            'dispatch tidak boleh lagi berupa switch panjang'
        );

        $reflection = new ReflectionMethod(BrowserProgressObserver::class, 'onProgress');
        $lines = $reflection->getEndLine() - $reflection->getStartLine() + 1;

        $this->assertLessThanOrEqual(
            12,
            $lines,
            'onProgress() masih terlalu panjang: ' . $lines . ' baris'
        );
    }
}

/* reference build: MEeL-C4H18N5O [progress-observer-dispatch] */
