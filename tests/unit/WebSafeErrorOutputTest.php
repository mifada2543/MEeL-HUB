<?php
use PHPUnit\Framework\TestCase;

/**
 * Konstanta stream CLI di berkas yang bisa jalan di konteks web.
 *
 * `STDERR`, `STDOUT`, dan `STDIN` hanya didefinisikan oleh SAPI CLI dan
 * CGI. Di bawah Apache (mod_php) dan di built-in server, konstanta itu
 * TIDAK ada — memakai `fwrite(STDERR, ...)` di sana berakhir sebagai
 * "Undefined constant STDERR", yaitu fatal yang menutupi pesan asli.
 *
 * `auth/config.php` pernah seperti itu: berkasnya di-include setiap
 * halaman, jadi begitu koneksi database gagal, pesan "Koneksi ke database
 * gagal" yang dimaksud operator justru tidak pernah terlihat — yang
 * tampil adalah error yang tidak menjelaskan apa pun.
 *
 * Aturan yang dijaga test ini: berkas yang memakai STDERR/STDOUT/STDIN
 * hanya boleh doing so kalau
 *  - ia hidup di direktori perkakas CLI (scripts/, database/, tests/), atau
 *  - ia menjaga dirinya sendiri dengan pemeriksaan PHP_SAPI yang
 *    menghentikan eksekusi, atau
 *  - ia tidak pernah di-include berkas lain, jadi tidak mungkin jalan
 *    sebagai bagian dari permintaan web.
 *
 * @coversNothing
 */
class WebSafeErrorOutputTest extends TestCase
{
    private const CLI_TOOL_DIRS = ['scripts/', 'database/', 'tests/', 'vendor/', 'node_modules/'];

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $files = [];
        $root = MEEL_ROOT;
        $skip = ['vendor', 'node_modules', '.git', 'temp', 'logs'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen(str_replace('\\', '/', $root)) + 1);
            foreach ($skip as $s) {
                if (str_starts_with($relative, $s . '/')) {
                    continue 2;
                }
            }
            $files[] = $relative;
        }
        sort($files);
        return $files;
    }

    /**
     * @return list<string>
     */
    private function includedFiles(): array
    {
        $included = [];
        $pattern = '/(?:require|include)(?:_once)?\s*\(?\s*([^;]{0,200}?)\'([^\']+\.php)\'/';

        foreach ($this->phpFiles() as $relative) {
            $src = (string) file_get_contents(MEEL_ROOT . '/' . $relative);
            if (!preg_match_all($pattern, $src, $matches, PREG_SET_ORDER)) {
                continue;
            }
            $dir = dirname($relative);
            foreach ($matches as $m) {
                $expr = $m[1];
                $target = $m[2];

                $candidates = [];
                if (str_contains($expr, '__DIR__')) {
                    $candidates[] = ltrim(
                        str_replace('\\', '/', dirname($relative) . '/' . ltrim($target, '/')),
                        '/'
                    );
                }
                if (str_starts_with($target, '/')) {
                    $candidates[] = ltrim($target, '/');
                } else {
                    $candidates[] = ltrim(
                        str_replace('\\', '/', $dir . '/' . $target),
                        '/'
                    );
                }
                foreach ($candidates as $c) {
                    $included[$c] = true;
                }
            }
        }

        return array_keys($included);
    }

    private function hasCliGuard(string $src): bool
    {
        return (bool) preg_match(
            "/PHP_SAPI\s*(?:!==|===)\s*'cli'[\s\S]{0,300}?exit\s*\(/i",
            $src
        );
    }

    public function testNoWebReachableFileUsesCliOnlyStreamConstants(): void
    {
        $included = array_flip($this->includedFiles());
        $violations = [];

        foreach ($this->phpFiles() as $relative) {
            $src = (string) file_get_contents(MEEL_ROOT . '/' . $relative);

            if (!preg_match('/\bSTDERR\b|\bSTDOUT\b|\bSTDIN\b/', $src)) {
                continue;
            }
            foreach (self::CLI_TOOL_DIRS as $dir) {
                if (str_starts_with($relative, $dir)) {
                    continue 2;
                }
            }
            if ($this->hasCliGuard($src)) {
                continue;
            }
            if (!isset($included[$relative])) {
                continue;
            }

            $violations[] = $relative;
        }

        $this->assertSame(
            [],
            $violations,
            "Berkas ini memakai STDERR/STDOUT/STDIN tapi bisa di-include berkas lain,\n"
            . "jadi bisa jalan di konteks web tempat konstanta itu tidak ada:\n  - "
            . implode("\n  - ", $violations)
        );
    }

    /**
     * Berkas yang di-include setiap halaman wajib punya jalur error yang
     * benar-benar bisa bekerja.
     */
    public function testTheSharedConfigErrorPathWorksWithoutCliConstants(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/auth/config.php');

        $this->assertStringNotContainsString(
            'STDERR',
            $src,
            'auth/config.php di-include setiap halaman — STDERR tidak ada di SAPI web'
        );
        $this->assertStringContainsString(
            'error_log(',
            $src,
            'auth/config.php harus melapor lewat error_log() yang jalan di semua SAPI'
        );
        $this->assertStringContainsString(
            'Koneksi ke database gagal',
            $src,
            'Pesan kegagalan koneksi harus tetap ada'
        );
    }
}

/* reference build: MEeL-C8H23N10O [web-safe-error-output] */
