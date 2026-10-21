<?php

use PHPUnit\Framework\TestCase;

class DateTimeSourceTest extends TestCase
{
    private const REPO_ROOT = __DIR__ . '/../..';

    private const PHP_READ_COLUMNS = [
        'last_activity',
        'meelcoin_last_refill',
    ];

    private static function productionPhpFiles(): array
    {
        $root = realpath(self::REPO_ROOT);
        $skip = ['/vendor/', '/.git/', '/tests/', '/logs/', '/temp/', '/storage/', '/node_modules/'];

        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
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
    /* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
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

    public function testNoProductionSqlWritesPhpReadColumnsUsingNow(): void
    {
        $violations = [];

        foreach (self::productionPhpFiles() as $file) {
            $src = (string)file_get_contents($file);
            foreach (self::PHP_READ_COLUMNS as $col) {
                if (preg_match('/' . preg_quote($col, '/') . '\s*=\s*NOW\s*\(\s*\)/i', $src, $m)) {
                    $violations[] = str_replace(realpath(self::REPO_ROOT) . '/', '', $file) . ' → ' . trim($m[0]);
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Kolom berikut dibaca via strtotime()/time() PHP tapi ditulis dengan NOW() (jam MySQL).\n"
                . "Perbaiki: bind meel_now() sebagai parameter, atau pakai helper yang ada.\n"
                . implode("\n", $violations)
        );
    }

    public function testFilesComparingTimeDoNotAlsoWriteNowForSameTable(): void
    {
        $violations = [];

        foreach (self::productionPhpFiles() as $file) {
            $src = (string)file_get_contents($file);
            if (!preg_match('/\btime\s*\(\s*\)/', $src)) {
                continue;
            }
            if (!preg_match('/=\s*NOW\s*\(\s*\)/i', $src)) {
                continue;
            }
            if (
                preg_match('/strtotime\s*\(\s*\$[a-z_]+\[[\'"](?:[a-z_]*_at|last_activity|meelcoin_last_refill)/i', $src)
                || preg_match('/strtotime\s*\(\s*\$last\b/i', $src)
            ) {
                $violations[] = str_replace(realpath(self::REPO_ROOT) . '/', '', $file);
            }
        }

        $this->assertLessThanOrEqual(
            3,
            count($violations),
            "File berikut masih mencampur NOW() dengan strtotime():\n" . implode("\n", $violations)
        );
    }
}
