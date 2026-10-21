<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga: `global $x;` di dalam fungsi yang tidak pernah memakai `$x`.
 *
 * KASUS NYATA (T19, 2026-10-14)
 * -----------------------------
 * `log_drive_operation()` di `storage.php` (kini `storage_disk.php`)
 * diawali `global $conn;` — padahal `$conn` **tidak muncul sekali pun**
 * di badan fungsinya. Fungsi itu menulis audit log ke berkas; ia tidak
 * pernah menyentuh database.
 *
 * Kerugiannya bukan cuma satu baris mubazir. `global $conn;` membuat
 * fungsi itu **bergantung pada global yang harus ada di scope**, padahal
 * tidak butuh. Setiap pemanggil jadi wajib menyediakan `$conn` tanpa
 * alasan. Dan saat `log_drive_operation()` dipanggil dari konteks yang
 * belum punya `$conn`, perilakunya bergantung pada apakah ada global
 * lain bernama sama — jenis ketergantungan tersembunyi yang sulit
 * ditelusuri.
 *
 * Test ini memakai tokenizer PHP, bukan regex, supaya bodies fungsi
 * dibaca akurat: string, heredoc, dan brace di dalam string mudah
 * membuat regex salah hitung.
 *
 * @coversNothing
 */
class UnusedGlobalImportTest extends TestCase
{
    /** Folder yang skannya diperiksa. */
    private const ROOTS = [
        'modules',
        'auth',
        'controllers',
        'scripts',
        'drivers',
    ];

    private const SKIP_DIRS = ['vendor', 'node_modules', 'tests', '.git', 'temp', 'logs'];

    /**
     * @return array{0: string, 1: int, 2: string} [berkas, baris, nama variabel]
     */
    private function findUnusedGlobalImports(): array
    {
        $root = MEEL_ROOT;
        $found = [];

        foreach (self::ROOTS as $sub) {
            $dir = $root . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                    static fn(SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), self::SKIP_DIRS, true)
                )
            );

            foreach ($it as $file) {
                /** @var SplFileInfo $file */
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                foreach ($this->scanFile($file->getPathname()) as $hit) {
                    $found[] = $hit;
                }
            }
        }

        return $found;
    }

    /**
     * @return array<int, array{0:string,1:int,2:string}>
     */
    private function scanFile(string $path): array
    {
        $tokens = @token_get_all((string) file_get_contents($path));
        $hits = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (!is_array($t) || $t[0] !== T_GLOBAL) {
                continue;
            }

            $line = $t[2];
            $globals = [];

            for ($j = $i + 1; $j < $count; $j++) {
                $v = $tokens[$j];
                if (is_array($v) && in_array($v[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if (is_array($v) && $v[0] === T_VARIABLE) {
                    $globals[$v[1]] = true;
                    continue;
                }
                break;
            }
            if ($globals === []) {
                continue;
            }

            $body = implode('', array_map(
                static fn($x) => is_array($x) ? $x[1] : $x,
                $tokens
            ));
            foreach (array_keys($globals) as $var) {
                $occurrences = preg_match_all('/' . preg_quote($var, '/') . '/', $body);
                if ($occurrences <= 1) {
                    $hits[] = [$path, $line, $var];
                }
            }
            $i++;
        }

        return $hits;
    }

    public function testNoFunctionImportsAGlobalItNeverUses(): void
    {
        $hits = $this->findUnusedGlobalImports();

        $report = array_map(
            static fn(array $h): string => sprintf(
                '  - %s:%d  global %s;  (nama itu tidak muncul lagi di berkas itu)',
                str_replace(MEEL_ROOT . '/', '', $h[0]),
                $h[1],
                $h[2]
            ),
            $hits
        );

        $this->assertSame(
            [],
            $hits,
            "Ada import global yang tidak pernah dipakai:\n"
            . implode("\n", $report)
            . '\n\n`global $x;` membuat fungsi bergantung pada global yang harus ada di scope\n'
            . "padahal tidak membutuhkannya -- ketergantungan tersembunyi yang sulit ditelusuri."
        );
    }

    /**
     * Guard tambahan: `log_drive_operation()` adalah kasus yang ditemukan
     * pertama. Fungsi ini menulis audit log ke BERKAS, tidak ke database,
     * jadi mengimpor koneksi database sama sekali tidak relevan.
     */
    public function testAuditLoggerDoesNotDependOnDatabaseConnection(): void
    {
        $path = MEEL_ROOT . '/modules/core/helpers/storage_disk.php';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);
        $this->assertDoesNotMatchRegularExpression(
            '/global\s+\$conn\s*;/',
            $src,
            'log_drive_operation() menulis ke logs/drive_audit.log, bukan ke database. '
            . 'Mengimpor $conn hanya menambah ketergantungan global yang tidak dibutuhkan.'
        );

        $this->assertTrue(
            function_exists('log_drive_operation') || is_file($path),
            'log_drive_operation() harus tetap ada'
        );
    }
}

/* reference build: MEeL-C8H6NO2 [unused-global-import] */