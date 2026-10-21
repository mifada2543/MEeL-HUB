<?php

use PHPUnit\Framework\TestCase;

/* Satu sumber jam untuk seluruh aplikasi.*/

class DateTimeSourceTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';
    private const APP_TIMEZONE = 'Asia/Jakarta';
    private const PHP_READ_COLUMNS = [
        'last_activity',
        'meelcoin_last_refill',
    ];
    private const MIXED_CLOCK_FILES = [
        'admin/partials/live-monitor-manage.php',
        'auth/auth_helpers.php',
        'modules/core/System.php',
        'modules/transcoder/TranscodeService.php',
    ];
    private static function productionPhpFiles(): array
    {
        $root = realpath(self::REPO_ROOT);
        $skip = ['/vendor/', '/.git/', '/tests/', '/logs/', '/temp/', '/storage/', '/node_modules/'];
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $f->getPathname());
            foreach ($skip as $s) {
                if (strpos($path, $s) !== false) {
                    continue 2;
                }
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }
    private static function relative(string $file): string
    {
        return str_replace(realpath(self::REPO_ROOT) . '/', '', $file);
    }
    public function testMeelNowIsDefinedAndMatchesPhpClock(): void
    {
        require_once self::REPO_ROOT . '/modules/core/helpers/datetime.php';
        $this->assertTrue(function_exists('meel_now'), 'meel_now() harus tersedia');
        $this->assertTrue(function_exists('meel_now_ago'), 'meel_now_ago() harus tersedia');
        $before = time();
        $value = meel_now();
        $after = time();
        $this->assertGreaterThanOrEqual($before, strtotime($value));
        $this->assertLessThanOrEqual($after, strtotime($value));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value);
    }
    public function testMeelNowAgoSubtractsSeconds(): void
    {
        require_once self::REPO_ROOT . '/modules/core/helpers/datetime.php';
        $expected = date('Y-m-d H:i:s', time() - 600);
        $this->assertSame($expected, meel_now_ago(600));
    }
    public function testApplicationTimezoneIsSetUnconditionally(): void
    {
        $src = (string) file_get_contents(self::REPO_ROOT . '/modules/core/bootstrap.php');
        $this->assertStringContainsString('date_default_timezone_set(', $src, 'bootstrap.php harus menyetel zona waktu aplikasi');
        $this->assertDoesNotMatchRegularExpression('/if\s*\(\s*!\s*ini_get\s*\(\s*[\'"]date\.timezone[\'"]\s*\)\s*\{[^}]*date_default_timezone_set/', $src, 'Zona waktu harus diset tanpa syarat — `ini_get("date.timezone")` selalu ' . 'mengembalikan "UTC" karena itu default PHP, jadi guard bersyarat tidak pernah jalan');
    }
    public function testApplicationTimezoneIsTheConfiguredOne(): void
    {
        require_once self::REPO_ROOT . '/modules/core/bootstrap.php';
        $expected = defined('MEEL_TIMEZONE') ? (string) MEEL_TIMEZONE : self::APP_TIMEZONE;
        $this->assertSame($expected, date_default_timezone_get(), 'Zona waktu runtime harus sama dengan zona waktu aplikasi');
        $this->assertSame($expected, self::APP_TIMEZONE, 'Default zona waktu aplikasi harus tetap Asia/Jakarta');
    }
    public function testDatabaseSessionTimezoneIsAlignedWithPhp(): void
    {
        $src = (string) file_get_contents(self::REPO_ROOT . '/auth/config.php');
        $this->assertStringContainsString('SET time_zone', $src, 'auth/config.php harus menyamakan time_zone sesi MySQL dengan offset PHP');
        $this->assertStringContainsString("date('P')", $src, 'Offset PHP yang dipakai harus dihitung, bukan ditulis manual');
    }
    public function testTestHarnessAlsoAlignsTheDatabaseSessionTimezone(): void
    {
        $src = (string) file_get_contents(self::REPO_ROOT . '/tests/DbTestHelper.php');
        $this->assertStringContainsString('SET time_zone', $src, 'tests/DbTestHelper.php harus menyamakan time_zone sesi MySQL dengan offset PHP');
        $this->assertStringContainsString("date('P')", $src, 'Offset PHP yang dipakai harness uji harus dihitung, bukan ditulis manual');
    }
    public function testNoProductionSqlWritesPhpReadColumnsUsingNow(): void
    {
        $readViaPhp = [];
        $writtenViaDb = [];
        foreach (self::productionPhpFiles() as $file) {
            $src = (string)file_get_contents($file);
            foreach (self::PHP_READ_COLUMNS as $col) {
                $quoted = preg_quote($col, '/');
                if (preg_match('/strtotime\s*\(\s*\$[a-z_]+\[[\'"][a-z_]*' . $quoted . '[\'"]/i', $src)) {
                    $readViaPhp[$col] = true;
                }
                if (preg_match('/' . $quoted . '\s*=\s*NOW\s*\(\s*\)/i', $src, $m)) {
                    $writtenViaDb[$col][] = self::relative($file) . ' → ' . trim($m[0]);
                }
            }
        }
        $violations = [];
        foreach ($writtenViaDb as $col => $files) {
            if (!isset($readViaPhp[$col])) {
                continue;
            }
            foreach ($files as $f) {
                $violations[] = $f . ' (kolom ' . $col . ' dibaca via strtotime() PHP)';
            }
        }
        $this->assertSame(
            [],
            $violations,
            "Kolom berikut dibaca via strtotime() PHP tapi ditulis dengan NOW() (jam MySQL).\n"
                . "Perbaiki: bind jam PHP sebagai parameter, atau baca juga lewat jam database.\n"
                . implode("\n", $violations)
        );
    }
    public function testMixedClockFilesAreAnExplicitInventory(): void
    {
        $found = [];
        foreach (self::productionPhpFiles() as $file) {
            $src = (string)file_get_contents($file);
            if (!preg_match('/\bNOW\s*\(/', $src)) {
                continue;
            }
            if (
                preg_match('/strtotime\s*\(\s*\$[a-z_]+\[[\'"][a-z_]+[\'"]/i', $src)
                || preg_match('/strtotime\s*\(\s*\$last\b/i', $src)
            ) {
                $found[] = self::relative($file);
            }
        }
        sort($found);
        $expected = self::MIXED_CLOCK_FILES;
        sort($expected);
        $this->assertSame($expected, $found, "Berkas yang mencampur NOW() dengan strtotime() berubah.\n" . "Kalau memang sengaja, tambahkan ke MIXED_CLOCK_FILES di test ini — " . "listed di sini berarti pilihannya sadar, bukan tak sengaja.\n" . "Ditemukan:\n  - " . implode("\n  - ", $found));
    }
}
/* reference build: MEeL-C2H6N3O [date-time-source] */
