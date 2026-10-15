<?php
use PHPUnit\Framework\TestCase;
class EnginePhaseJsTest extends TestCase
{
    private static function nodeAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $output = [];
            $code = 0;
            exec('node --version 2>&1', $output, $code);
            $available = ($code === 0);
        }
        return $available;
    }

    private function runHarness(string $scenario): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/engine-phase.harness.js';
        $this->assertFileExists($harness, 'Harness tidak ditemukan.');

        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));

        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }

    public function testShimIsInstalledBeforeEngineScriptsLoad(): void
    {
        $r = $this->runHarness('race');

        $this->assertTrue($r['shimInstalledAtBoot'], 'meelPhase() harus terpasang begitu main.js jalan');
        $this->assertCount(
            4,
            $r['engineScriptsRequested'],
            'main.js tetap harus memuat 4 file engine'
        );
    }

    public function testPhaseCalledBeforeEngineReadyIsQueuedThenReplayed(): void
    {
        $r = $this->runHarness('race');

        $this->assertSame(
            1,
            $r['queuedBeforeEngineReady'],
            'Panggilan yang mendahului core.js harus diantre, bukan hilang'
        );
        $this->assertSame(
            0,
            $r['queuedAfterBind'],
            'Antrean harus kosong setelah core.js bind'
        );
        $this->assertTrue(
            $r['overlayVisible'],
            'Overlay (#meel-overlay display:none di CSS) WAJIB_visible — inilah penyebab layar putih'
        );
        $this->assertSame(
            ['download'],
            $r['activePhases'],
            'Phase yang diantre harus diputar dan ditampilkan'
        );
    }
    public function testEncodePhaseHasItsOwnElement(): void
    {
        $r = $this->runHarness('encode');
        $this->assertTrue($r['hasEncodeElement'], 'Element meel-phase-encode harus ada');
        $this->assertSame(['download'], $r['duringDownload']);
        $this->assertSame(
            ['encode'],
            $r['duringEncode'],
            'music harus punya phase encode sendiri — sebelumnya meelPhase("encode") tidak menemukan apa pun sehingga card kosong'
        );
        $this->assertTrue($r['overlayVisible']);
    }

    public function testLastPhaseWinsAfterQueueIsReplayed(): void
    {
        $r = $this->runHarness('order');

        $this->assertSame(0, $r['queuedAfterBind']);
        $this->assertSame(
            ['done'],
            $r['activePhases'],
            'Phase terakhir yang antre harus yang tampil, sisanya tidak boleh menumpuk'
        );
    }

    public function testEncodePartialIsRegisteredInOverlay(): void
    {
        $partial = MEEL_ROOT . '/partials/engine/encode.php';
        $this->assertFileExists($partial, 'partials/engine/encode.php harus ada');

        ob_start();
        include $partial;
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'id="meel-phase-encode"',
            $html,
            'Partial harus menghasilkan element ber-id meel-phase-encode'
        );

        $ui = (string) file_get_contents(MEEL_ROOT . '/partials/ui.php');
        $this->assertStringContainsString(
            "engine/encode.php",
            $ui,
            'partials/ui.php harus menyertakan partial encode, kalau tidak element-nya tidak pernah ada di DOM'
        );
    }

    public function testEncodeEtaFollowsThemeInsteadOfHardcodedWhite(): void
    {
        ob_start();
        include MEEL_ROOT . '/partials/engine/encode.php';
        $html = (string) ob_get_clean();

        $this->assertMatchesRegularExpression(
            '/<span id="meel-en-eta"[^>]*><\/span>/',
            $html,
            'meel-en-eta harus polos tanpa inline style supaya mewarisi .meel-pct '
                . 'yang sudah di-override light-theme.css. Inline putih akan '
                . 'tidak terbaca saat html[data-theme="light"].'
        );
        $this->assertStringNotContainsString(
            'rgba(255,255,255,.15)',
            $html,
            'Jangan pakai warna putih hardcode di phase encode — tidak ada rule light-theme untuk id ini'
        );
    }

    public function testInjectedOverlayCarriesLightTheme(): void
    {
        $hadScript = array_key_exists('SCRIPT_NAME', $_SERVER);
        $oldScript = $_SERVER['SCRIPT_NAME'] ?? null;
        $hadHost = array_key_exists('HTTP_HOST', $_SERVER);
        $oldHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/MEeL/upload_advanced.php';
        $_SERVER['HTTP_HOST'] = 'localhost';
        try {
            ob_start();
            include MEEL_ROOT . '/partials/ui.php';
            $html = (string) ob_get_clean();
        } finally {
            if ($hadScript) {
                $_SERVER['SCRIPT_NAME'] = $oldScript;
            } else {
                unset($_SERVER['SCRIPT_NAME']);
            }
            if ($hadHost) {
                $_SERVER['HTTP_HOST'] = $oldHost;
            } else {
                unset($_SERVER['HTTP_HOST']);
            }
        }
        $this->assertStringContainsString(
            'assets/css/shared/light-theme.css',
            $html,
            'Fragmen overlay harus memuat light-theme.css — overlay di-inject sebagai '
                . 'dokumen POST terpisah, sehingga <head> halaman asli tidak pernah terkirim'
        );
        $this->assertStringContainsString(
            "localStorage.getItem('meel_theme')",
            $html,
            'Fragmen harus memulihkan tema dari localStorage, kalau tidak html[data-theme] '
                . 'tidak pernah di-set dan light theme mustahil muncul'
        );
        $this->assertStringContainsString(
            "setAttribute('data-theme'",
            $html,
            'Atribut data-theme harus di-set pada documentElement'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<html/i',
            $html,
            'ui.php harus tetap fragmen — bukan dokumen penuh'
        );
    }
    public function testCoreJsKnowsEncodePhase(): void
    {
        $core = (string) file_get_contents(MEEL_ROOT . '/assets/js/engine/core.js');
        $this->assertStringContainsString(
            '"encode"',
            $core,
            'core.js harus tahu phase encode agar bisa mengaktifkan element-nya'
        );
    }
    public function testObserverGuardsMeelPhaseCalls(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/BrowserProgressObserver.php');
        $this->assertStringNotContainsString(
            "'<script>meelPhase(",
            $src,
            'Panggilan meelPhase dari observer tidak boleh polos — ReferenceError membuat overlay tidak pernah tampil'
        );
        $this->assertStringContainsString(
            'if(typeof meelPhase==="function")',
            $src,
            'Semua pemanggilan dari observer harus dipagari typeof'
        );
    }
}
/* reference build: MEeL-C10H15N [engine-phase-test] */
