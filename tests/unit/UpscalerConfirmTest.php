<?php
use PHPUnit\Framework\TestCase;

class UpscalerConfirmTest extends TestCase
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

    private function runHarness(): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/upscaler-confirm.harness.js';
        $this->assertFileExists($harness, 'Harness tidak ditemukan.');

        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));

        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }

    private function upscalerSource(): string
    {
        $path = MEEL_ROOT . '/assets/js/video/watch/upscaler.js';
        $this->assertFileExists($path, 'upscaler.js tidak ditemukan.');
        return (string) file_get_contents($path);
    }

    public function testEnableNeedsConfirmButDisableDoesNot(): void
    {
        $r = $this->runHarness();

        $this->assertTrue($r['enable']['on'], 'Mengaktifkan upscaler harus ber-alert.');
        $this->assertFalse($r['enable']['off'], 'Mematikan upscaler tidak boleh ber-alert.');
    }

    public function testModelChangeAlwaysNeedsConfirm(): void
    {
        $r = $this->runHarness();

        $this->assertTrue($r['model']['switch'], 'Ganti model harus ber-alert.');
        $this->assertFalse($r['model']['same'], 'Model yang sama tidak boleh ber-alert.');
        $this->assertFalse($r['model']['empty'], 'Model kosong tidak boleh ber-alert.');
    }

    public function testScaleChangeToFixedScaleNeedsConfirm(): void
    {
        $r = $this->runHarness();

        $this->assertTrue($r['scale']['autoTo15'], 'auto -> 1.5 harus ber-alert.');
        $this->assertTrue($r['scale']['autoTo2'], 'auto -> 2 harus ber-alert.');
        $this->assertTrue($r['scale']['up'], '1.5 -> 2 harus ber-alert.');
        $this->assertTrue($r['scale']['down'], '2 -> 1.5 harus ber-alert.');
        $this->assertFalse($r['scale']['toAuto'], 'Kembali ke auto tidak boleh ber-alert.');
        $this->assertFalse($r['scale']['same'], 'Skala yang sama tidak boleh ber-alert.');
    }

    public function testGpuLoadOrderIsAnime4kThenMeelScaleThenMeelSharp(): void
    {
        $r = $this->runHarness();

        $this->assertSame(
            ['anime4k', 'meelscale', 'meelsharp'],
            array_keys($r['gpuLoad']),
            'Urutan model pada peta beban GPU berubah.'
        );
        $this->assertGreaterThan($r['gpuLoad']['meelscale'], $r['gpuLoad']['anime4k']);
        $this->assertGreaterThan($r['gpuLoad']['meelsharp'], $r['gpuLoad']['meelscale']);
    }

    public function testToggleRowRoutesEnableThroughConfirm(): void
    {
        $src = $this->upscalerSource();

        $this->assertStringContainsString(
            'requestEnable()',
            $src,
            'Toggle AI Upscale harus lewat requestEnable() supaya konfirmasi tidak bisa dilewati.'
        );
        $this->assertStringContainsString(
            'needsEnableConfirm(want)',
            $src,
            'Aturan konfirmasi enable harus dipakai toggle, bukan logika manual.'
        );
        $this->assertStringNotContainsString(
            'setEnabled(b.getAttribute("aria-checked") !== "true"',
            $src,
            'Toggle lama memanggil setEnabled langsung dan melewati konfirmasi.'
        );
    }

    public function testPublicEnableApiAlsoConfirms(): void
    {
        $src = $this->upscalerSource();

        $this->assertMatchesRegularExpression(
            '/enable:\s*function\s*\(\)\s*\{\s*requestEnable\(\);/',
            $src,
            'MEEL_UPSCALER.enable() harus lewat requestEnable().'
        );
        $this->assertSame(
            1,
            preg_match('/function buildToggleRow\(\).*?\n  \}/s', $src, $m),
            'Blok buildToggleRow tidak ditemukan.'
        );
        $this->assertStringNotContainsString(
            'setEnabled(true',
            $m[0],
            'Toggle tidak boleh menyalakan upscaler langsung — konfirmasi akan dilewati.'
        );
    }

    public function testModelAndScalePickersRouteThroughConfirm(): void
    {
        $src = $this->upscalerSource();

        $this->assertStringContainsString(
            'requestSelectModel(id)',
            $src,
            'Daftar model harus lewat requestSelectModel().'
        );
        $this->assertStringContainsString(
            'requestSelectScale(sc.id)',
            $src,
            'Daftar skala harus lewat requestSelectScale().'
        );
        $this->assertMatchesRegularExpression(
            '/function requestSelectModel\(id\).*?selectModel\(id\);/s',
            $src,
            'requestSelectModel harus memanggil selectModel() setelah konfirmasi diizinkan.'
        );
        $this->assertMatchesRegularExpression(
            '/function requestSelectScale\(id\).*?selectScale\(id\);/s',
            $src,
            'requestSelectScale harus memanggil selectScale() setelah konfirmasi diizinkan.'
        );
    }

    public function testCancelKeepsPreviousStateAndResyncsRadios(): void
    {
        $src = $this->upscalerSource();

        $this->assertMatchesRegularExpression(
            '/function requestSelectModel\(id\).*?if \(!ok\) \{\s*updateUI\(\);/s',
            $src,
            'Batal pada pilihan model harus memanggil updateUI() supaya centang radio kembali.'
        );
        $this->assertMatchesRegularExpression(
            '/function requestSelectScale\(id\).*?if \(!ok\) \{\s*updateUI\(\);/s',
            $src,
            'Batal pada pilihan skala harus memanggil updateUI() supaya centang radio kembali.'
        );
    }

    public function testConfirmTextsArePresent(): void
    {
        $src = $this->upscalerSource();

        $this->assertStringContainsString(
            'Mengaktifkan upscaler akan membuat GPU bekerja lebih keras, ingin lanjutkan?',
            $src,
            'Teks peringatan toggle hilang.'
        );
        foreach (['anime4k', 'meelscale', 'meelsharp'] as $id) {
            $this->assertMatchesRegularExpression(
                '/' . $id . ':\s*\n?\s*"[^"]{20,}/',
                $src,
                'Peringatan model ' . $id . ' hilang.'
            );
        }
        $this->assertMatchesRegularExpression(
            '/"1\.5":\s*\n?\s*"[^"]{20,}/',
            $src,
            'Peringatan skala 1.5 hilang.'
        );
        $this->assertMatchesRegularExpression(
            '/"2":\s*\n?\s*"[^"]{20,}/',
            $src,
            'Peringatan skala 2 hilang.'
        );
    }

    public function testFullscreenIsLeftBeforeDialogAndRestoredAfter(): void
    {
        $src = $this->upscalerSource();

        $this->assertMatchesRegularExpression(
            '/function confirmUpscale\(opts\).*?leaveFullscreen\(\)/s',
            $src,
            'confirmUpscale harus keluar dari layar penuh sebelum dialog tampil.'
        );
        $this->assertMatchesRegularExpression(
            '/function confirmUpscale\(opts\).*?enterFullscreen\(\)/s',
            $src,
            'confirmUpscale harus mengembalikan layar penuh setelah dialog dijawab.'
        );
        $this->assertStringContainsString(
            'if (currentVideoId() !== videoId) return result;',
            $src,
            'Layar penuh tidak boleh dikembalikan kalau videonya sudah berganti.'
        );
        $this->assertStringContainsString(
            'if (!wasFullscreen || isPlayerFullscreen()) return result;',
            $src,
            'Layar penuh hanya boleh dikembalikan kalau memang sebelumnya dalam layar penuh.'
        );
    }

    public function testDialogUsesTopLayerAndFallsBackToNativeConfirm(): void
    {
        $src = $this->upscalerSource();

        $this->assertStringContainsString(
            'if (canUseTopLayer()) cfg.topLayer = true;',
            $src,
            'Dialog harus pakai topLayer supaya terlihat di layar penuh.'
        );
        $this->assertStringContainsString(
            'window.confirm(opts.text || opts.title || "Lanjutkan?")',
            $src,
            'Harus ada fallback window.confirm bila SweetAlert2 tidak tersedia.'
        );
    }
}
