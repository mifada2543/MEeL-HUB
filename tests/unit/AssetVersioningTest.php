<?php

use PHPUnit\Framework\TestCase;

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */
class AssetVersioningTest extends TestCase
{
    private const SKIP_DIRS = ['/vendor/', '/.git/', '/node_modules/', '/logs/', '/temp/', '/storage/', '/tests/', '/data_drive/'];

    private static function phpFiles(): array
    {
        $root = realpath(MEEL_ROOT);
        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') continue;
            $path = str_replace('\\', '/', $f->getPathname());
            foreach (self::SKIP_DIRS as $s) {
                if (strpos($path, $s) !== false) continue 2;
            }
            $files[] = $path;
        }
        sort($files);
        return $files;
    }

    public function testNoUnversionedAssetUrls(): void
    {
        $violations = [];
        $pattern = '/(?:src|href)="((?:\.\.\/)*assets\/[^"]+?\.(?:css|js))"/';

        foreach (self::phpFiles() as $file) {
            $src = (string)file_get_contents($file);
            if (!preg_match_all($pattern, $src, $m)) continue;
            foreach ($m[1] as $url) {
                $violations[] = str_replace(realpath(MEEL_ROOT) . '/', '', $file) . ' → ' . $url;
            }
        }

        $this->assertSame([], $violations,
            "URL aset berikut tidak punya cache-busting '?v='.\n"
            . "Perbaiki: src=\"...<?= meel_asset_version('assets/...') ?>\"\n"
            . implode("\n", $violations));
    }

    public function testAssetVersionHelperIsAvailable(): void
    {
        require_once MEEL_ROOT . '/modules/core/helpers/url.php';
        $this->assertTrue(function_exists('meel_asset_version'));
        $this->assertMatchesRegularExpression('/^\?v=\d+$/', meel_asset_version('assets/css/up.css'));
    }

    public function testDirVersionEntrypointsPropagateVersionToChildren(): void
    {
        $entrypoints = [
            'assets/js/video/watch/main.js',
            'assets/js/music/index/main.js',
            'assets/js/music/watch/main.js',
        ];

        foreach ($entrypoints as $entry) {
            $path = MEEL_ROOT . '/' . $entry;
            $this->assertFileExists($path, "Entrypoint graph-aware harus ada: $entry");
            $src = (string)file_get_contents($path);

            $this->assertStringContainsString(
                'document.currentScript',
                $src,
                "$entry harus membaca src-nya sendiri untuk mengambil ?v="
            );
            $this->assertMatchesRegularExpression(
                '/\[?&\]v=/',
                $src,
                "$entry harus mem-parse query ?v= dari src-nya"
            );
        }
    }

    public function testDirVersionCallersMatchThoseEntrypoints(): void
    {
        $root = realpath(MEEL_ROOT);
        $callers = [];
        foreach (self::phpFiles() as $file) {
            $src = (string)file_get_contents($file);
            if (preg_match_all("/meel_asset_dir_version\('([^']+)'\)/", $src, $m)) {
                foreach ($m[1] as $dir) $callers[$dir] = true;
            }
        }

        $actual = array_keys($callers);
        sort($actual);
        $this->assertSame(
            ['assets/js/music/index', 'assets/js/music/watch', 'assets/js/video/watch'],
            $actual,
            'Hanya entrypoint graph-aware boleh memakai meel_asset_dir_version();'
            . ' Untuk file biasa pakai meel_asset_version() (per-file).'
        );
    }
}
