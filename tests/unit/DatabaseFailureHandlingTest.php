<?php

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class DatabaseFailureHandlingTest extends TestCase
{
    private function source(string $relative): string
    {
        $path = MEEL_ROOT . '/' . $relative;
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }
    public function testConfigSuppressesDriverWarningsButKeepsCleanErrorPath(): void
    {
        $src = $this->source('auth/config.php');
        $this->assertStringContainsString(
            '@new mysqli(',
            $src,
            'Koneksi harus memakai @new mysqli() agar warning mysqlnd (mis. DNS gagal) tidak tercetak sebelum error ditangani'
        );
        $this->assertStringContainsString('meel_database_unavailable', $src);
        $this->assertStringContainsString('error_log(', $src);
        $this->assertStringContainsString('Koneksi ke database gagal', $src);
    }
    public function testDatabaseFailureDoesNotLeakWarningToCliOutput(): void
    {
        if (!is_file(MEEL_ROOT . '/auth/settings.php')) {
            $this->markTestSkipped('auth/settings.php tidak ada (lingkungan tanpa konfigurasi lokal)');
        }
        $code = 'require ' . var_export(MEEL_ROOT . '/auth/config.php', true) . '; echo "SHOULD_NOT_PRINT";';
        $output = [];
        $exit = 0;
        exec(
            'MEEL_DB_HOST=db.invalid ' . escapeshellarg(PHP_BINARY)
                . ' -d display_errors=1 -d log_errors=0 -r ' . escapeshellarg($code) . ' 2>&1',
            $output,
            $exit
        );
        $text = implode("\n", $output);
        $this->assertSame(1, $exit, 'Koneksi gagal harus keluar dengan kode 1');
        $this->assertStringNotContainsString('Warning', $text);
        $this->assertStringNotContainsString('Fatal error', $text);
        $this->assertStringNotContainsString('SHOULD_NOT_PRINT', $text);
    }
    public function testErrorPageHasDatabaseUnavailableVariant(): void
    {
        $src = $this->source('err/index.php');
        $this->assertStringContainsString("'db_unavailable' => [", $src);
        $this->assertStringContainsString('503_Database_Unavailable', $src);
        $this->assertMatchesRegularExpression(
            "/'db_unavailable' => \\[[\\s\\S]{0,400}?'status' => 503/",
            $src,
            'Varian db_unavailable harus memakai status HTTP 503'
        );
    }
    public function testConfigHonorsDocumentedEnvironmentOverrides(): void
    {
        $src = $this->source('auth/config.php');

        foreach (['MEEL_DB_HOST', 'MEEL_DB_USER', 'MEEL_DB_PASS', 'MEEL_DB_NAME'] as $key) {
            $this->assertStringContainsString($key, $src, "Override {$key} yang didokumentasikan harus dibaca");
        }
    }
    public function testModulesDoesNotReloadSettingsWhenAlreadyLoaded(): void
    {
        $src = $this->source('modules/core/Modules.php');

        $this->assertMatchesRegularExpression(
            "/if \\(!defined\\('MEEL_TIMEZONE'\\)\\) \\{\\s*require \\\$settings;/",
            $src,
            'settings.php tidak boleh dimuat ulang setelah konstanta terdefinisi'
        );
    }
    public function testFallbackConstantsAreGuardedInSettingsTemplate(): void
    {
        $src = $this->source('auth/settings.example.php');

        $this->assertStringContainsString("if (!defined('MEEL_MAX_DOWNLOAD_BYTES'))", $src);
        $this->assertStringContainsString("if (!defined('MEEL_MAX_DOWNLOAD_SECONDS'))", $src);
    }
}
/* reference build: MEeL-C4H9NO2 [5879204a5a3bd92d] */