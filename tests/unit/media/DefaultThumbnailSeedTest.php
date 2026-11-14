<?php
use PHPUnit\Framework\TestCase;

/**
 * Fallback thumbnail harus benar-benar ada sebelum namanya disimpan.
 *
 * @coversNothing
 */
class DefaultThumbnailSeedTest extends TestCase
{
    private string $original = '';
    private bool $existed = false;
    private int $originalMtime = 0;

    protected function setUp(): void
    {
        $path = meel_default_thumbnail_path();
        $this->existed = is_file($path);
        if ($this->existed) {
            $this->original = (string) file_get_contents($path);
            $this->originalMtime = (int) filemtime($path);
        }
    }

    protected function tearDown(): void
    {
        $path = meel_default_thumbnail_path();
        if ($this->existed) {
            file_put_contents($path, $this->original);
            touch($path, $this->originalMtime);
        } elseif (is_file($path)) {
            unlink($path);
        }
        clearstatcache(true, $path);
    }

    private static function helperSource(): string
    {
        return (string) file_get_contents(MEEL_ROOT . '/modules/core/helpers/thumbnail_defaults.php');
    }

    public function testHelperIsLoadedByMainEntryPoint(): void
    {
        $main = (string) file_get_contents(MEEL_ROOT . '/modules/core/helpers/main.php');

        $this->assertStringContainsString(
            "require_once __DIR__ . '/thumbnail_defaults.php';",
            $main,
            'Helper fallback thumbnail harus dimuat dari main.php'
        );
    }

    public function testPackagedAssetIsAValidWebp(): void
    {
        $asset = meel_default_thumbnail_asset();

        $this->assertFileExists($asset, 'Sumber fallback harus dipaketkan di repo');
        $this->assertTrue(
            meel_is_valid_webp($asset),
            'Sumber fallback harus berupa WebP yang valid, bukan hanya bernama .webp'
        );
    }

    public function testResolveNeverReturnsAbsentFile(): void
    {
        $name = meel_resolve_fallback_thumbnail();

        if ($name === '') {
            $this->assertFalse(
                meel_is_valid_webp(meel_default_thumbnail_path()),
                'Fallback kosong hanya sah jika tidak ada fallback WebP yang valid'
            );
            return;
        }

        $this->assertSame(
            'default_thumb.webp',
            $name,
            'Nama fallback harus memakai nama baku yang dilindungi meel_is_default_thumbnail()'
        );
        $this->assertFileExists(
            meel_default_thumbnail_path(),
            'Nama fallback hanya boleh dikembalikan kalau filenya benar-benar ada'
        );
        $this->assertTrue(
            meel_is_valid_webp(meel_default_thumbnail_path()),
            'Fallback yang tersimpan harus WebP yang valid'
        );
    }

    public function testSeedingIsIdempotentAndDoesNotOverwrite(): void
    {
        $path = meel_default_thumbnail_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->assertTrue(meel_ensure_default_thumbnail(), 'Seeding harus berhasil');
        $this->assertFileExists($path);

        $before = @file_get_contents($path);
        $mtime = time() - 3600;
        touch($path, $mtime);
        $this->assertTrue(meel_ensure_default_thumbnail(), 'Seeding kedua harus tetap berhasil');
        $after = @file_get_contents($path);
        clearstatcache(true, $path);
        $this->assertSame($mtime, filemtime($path), 'Seeding tidak boleh mengganti file valid');

        $this->assertSame(
            $before,
            $after,
            'Seeding ulang tidak boleh menimpa file yang sudah valid'
        );
        $this->assertTrue(meel_is_valid_webp($path));
    }

    public function testSeedingReplacesAnInvalidFile(): void
    {
        $path = meel_default_thumbnail_path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Simulasikan file rusak: byte JPEG di bawah nama .webp.
        file_put_contents($path, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 64));
        $this->assertFalse(meel_is_valid_webp($path));

        $this->assertTrue(meel_ensure_default_thumbnail(), 'File rusak harus diperbaiki');
        $this->assertTrue(
            meel_is_valid_webp($path),
            'Setelah seeding, fallback harus berupa WebP yang valid'
        );
    }

    public function testDefaultThumbnailNameIsProtectedFromDeletion(): void
    {
        $this->assertTrue(
            meel_is_default_thumbnail(meel_default_thumbnail_name()),
            'Fallback harus dilindungi dari penghapusan oleh meel_is_default_thumbnail()'
        );
    }

    public function testMissingPackagedSourceReturnsEmptyWithoutWritingStorage(): void
    {
        // Proses terpisah mengisolasi konstanta root/storage dari bootstrap suite.
        $root = MEEL_ROOT . '/temp/missing-thumbnail-source-' . uniqid();
        $script = 'define("MEEL_ROOT", ' . var_export($root, true) . ');'
            . 'define("MEEL_HDD_VIDEO_UPLOAD", ' . var_export($root . '/storage', true) . ');';
        foreach (['media_binary', 'storage_paths', 'upload', 'thumbnail_defaults'] as $helper) {
            $script .= 'require ' . var_export(MEEL_ROOT . '/modules/core/helpers/' . $helper . '.php', true) . ';';
        }
        $script .= 'echo json_encode([meel_resolve_fallback_thumbnail(), is_dir(MEEL_HDD_VIDEO_UPLOAD)]);';
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>/dev/null', $output, $status);
        $this->assertSame(0, $status);
        $this->assertSame(['', false], json_decode(implode("\n", $output), true));
    }

    public function testUnavailableStorageReturnsEmptyWithValidPackagedSource(): void
    {
        $dir = MEEL_ROOT . '/temp/blocked-thumbnail-storage-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/thumbnail', 'bukan direktori');
        try {
            $script = 'define("MEEL_ROOT", ' . var_export(MEEL_ROOT, true) . ');'
                . 'define("MEEL_HDD_VIDEO_UPLOAD", ' . var_export($dir, true) . ');';
            foreach (['media_binary', 'storage_paths', 'upload', 'thumbnail_defaults'] as $helper) {
                $script .= 'require ' . var_export(MEEL_ROOT . '/modules/core/helpers/' . $helper . '.php', true) . ';';
            }
            $script .= 'echo json_encode([meel_resolve_fallback_thumbnail(), file_get_contents(MEEL_HDD_VIDEO_UPLOAD . "/thumbnail")]);';
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>/dev/null', $output, $status);
            $this->assertSame(0, $status);
            $this->assertSame(['', 'bukan direktori'], json_decode(implode("\n", $output), true));
        } finally {
            unlink($dir . '/thumbnail');
            rmdir($dir);
        }
    }

    public function testNoLeftoverTemporaryFilesAfterSeeding(): void
    {
        meel_ensure_default_thumbnail();

        $dir = dirname(meel_default_thumbnail_path());
        $leftovers = glob($dir . '/default_thumb.webp.tmp-*') ?: [];
        $this->assertSame(
            [],
            $leftovers,
            'Seeding tidak boleh meninggalkan berkas sementara di direktori tujuan'
        );
    }

    public function testHelperSourceDoesNotHardcodeStoragePath(): void
    {
        $src = self::helperSource();

        $this->assertStringNotContainsString(
            '/media/',
            $src,
            'Helper tidak boleh meng-hardcode path storage absolut'
        );
        $this->assertStringContainsString(
            'meel_media_base_path(',
            $src,
            'Path storage harus lewat meel_media_base_path()'
        );
    }
}

/* reference build: MEeL-C10H16N [4e7b1c9a5d2e] */
