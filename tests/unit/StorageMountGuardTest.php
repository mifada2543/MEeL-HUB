<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/drive/DriveService.php';

class StorageMountGuardTest extends TestCase
{
    private string $workDir = '';

    protected function setUp(): void
    {
        $this->workDir = MEEL_ROOT . '/temp/storage_guard_' . bin2hex(random_bytes(4));
        @mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    private function runPhp(string $code): array
    {
        $output = [];
        $exit = 0;
        exec(
            PHP_BINARY . ' -d display_errors=0 -d error_log=/dev/null -r '
            . escapeshellarg($code) . ' 2>&1',
            $output,
            $exit
        );
        return [$exit, implode("\n", $output)];
    }

    private function helpersRequire(): string
    {
        $helpers = realpath(MEEL_ROOT . '/modules/core/helpers.php');
        $this->assertNotFalse($helpers, 'modules/core/helpers.php tidak ditemukan');
        return 'require ' . var_export($helpers, true) . ';';
    }

    private function driveRequire(): string
    {
        $drive = realpath(MEEL_ROOT . '/drive/DriveService.php');
        $this->assertNotFalse($drive, 'drive/DriveService.php tidak ditemukan');
        return 'require ' . var_export($drive, true) . ';';
    }

    private function defineBase(string $base): string
    {
        return 'define("MEEL_HDD_BASE", ' . var_export($base, true) . ');'
            . 'define("MEEL_HDD_DRIVE", MEEL_HDD_BASE . "/drive/");';
    }

    public function testStorageIsReadyWithoutHddBaseConstant(): void
    {
        $code = $this->helpersRequire()
            . 'echo (meel_storage_ready() ? "READY" : "NOT_READY") . "|" . meel_storage_marker_path();';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('READY|', trim($out));
    }

    public function testStorageNotReadyWhenBaseMissing(): void
    {
        $missing = $this->workDir . '/belum_ter_mount';
        $code = $this->defineBase($missing)
            . $this->helpersRequire()
            . 'echo meel_storage_ready() ? "READY" : "NOT_READY";';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('NOT_READY', trim($out));
        $this->assertDirectoryDoesNotExist($missing, 'Folder storage tidak boleh dibuat saat belum ter-mount');
    }

    public function testStorageNotReadyWhenEmptyDirWithoutMarker(): void
    {
        $empty = $this->workDir . '/sisa_mountpoint';
        $this->assertTrue(mkdir($empty, 0755, true));

        $code = $this->defineBase($empty)
            . $this->helpersRequire()
            . 'echo meel_storage_ready() ? "READY" : "NOT_READY";';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('NOT_READY', trim($out));
    }

    public function testStorageReadyWhenMarkerExists(): void
    {
        $base = $this->workDir . '/ter_mount';
        $this->assertTrue(mkdir($base, 0755, true));
        $this->assertNotFalse(file_put_contents($base . '/.meel_mount', date('c')));

        $code = $this->defineBase($base)
            . $this->helpersRequire()
            . 'echo meel_storage_ready() ? "READY" : "NOT_READY";';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('READY', trim($out));
    }

    public function testStorageReadyForLegacyDirWithContentAndNoMarker(): void
    {
        $base = $this->workDir . '/volume_lama';
        $this->assertTrue(mkdir($base . '/drive', 0755, true));
        $this->assertNotFalse(file_put_contents($base . '/drive/legacy.txt', 'isi'));

        $code = $this->defineBase($base)
            . $this->helpersRequire()
            . 'echo meel_storage_ready() ? "READY" : "NOT_READY";';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('READY', trim($out));
    }

    public function testStorageGuardPassesWhenReady(): void
    {
        $base = $this->workDir . '/siap';
        $this->assertTrue(mkdir($base, 0755, true));
        $this->assertNotFalse(file_put_contents($base . '/.meel_mount', date('c')));

        $code = $this->defineBase($base)
            . $this->helpersRequire()
            . 'meel_storage_guard(); echo "LANJUT";';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('LANJUT', trim($out));
    }

    public function testStorageGuardBlocksWhenNotMounted(): void
    {
        $missing = $this->workDir . '/tidak_ada';
        $code = $this->defineBase($missing)
            . $this->helpersRequire()
            . 'try { meel_storage_guard(); echo "LANJUT"; } catch (RuntimeException $e) { echo "DIBLOKIR"; }';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertSame('DIBLOKIR', trim($out));
    }

    public function testListFilesThrowsWithoutCreatingGarbageFolders(): void
    {
        $missing = $this->workDir . '/volume_kosong';
        $code = $this->defineBase($missing)
            . $this->helpersRequire()
            . $this->driveRequire()
            . '$u = DriveUserContext::fromSession(["user_id" => 1, "role" => "admin", "username" => "admin"]);'
            . '$s = new DriveStorage(DriveStorage::defaultBasePath(), $u);'
            . 'try { $s->listFilesByType("video", "public"); echo "TANPA_EXCEPTION"; }'
            . 'catch (StorageNotMountedException $e) { echo "THREW"; }'
            . 'catch (Throwable $e) { echo "OTHER:" . get_class($e); }';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('THREW', $out, $out);
        $this->assertDirectoryDoesNotExist(
            $missing . '/drive/public',
            'Folder tidak boleh dibuat di filesystem saat volume belum ter-mount'
        );
        $this->assertDirectoryDoesNotExist($missing);
    }

    public function testListFilesCreatesFoldersWhenMounted(): void
    {
        $base = $this->workDir . '/volume_aktif';
        $this->assertTrue(mkdir($base, 0755, true));
        $this->assertNotFalse(file_put_contents($base . '/.meel_mount', date('c')));

        $code = $this->defineBase($base)
            . $this->helpersRequire()
            . $this->driveRequire()
            . '$u = DriveUserContext::fromSession(["user_id" => 1, "role" => "admin", "username" => "admin"]);'
            . '$s = new DriveStorage(DriveStorage::defaultBasePath(), $u);'
            . 'try { $files = $s->listFilesByType("video", "public"); echo "LIST:" . count($files); }'
            . 'catch (Throwable $e) { echo "GAGAL:" . get_class($e) . ":" . $e->getMessage(); }';

        [$exit, $out] = $this->runPhp($code);

        $this->assertSame(0, $exit, $out);
        $this->assertStringContainsString('LIST:0', $out, $out);
        $this->assertDirectoryExists($base . '/drive/public/video');
        $this->assertFalse(is_link($base . '/drive/public/video'), 'Folder storage harus folder nyata, bukan symlink.');
    }

    public function testErrPageRegistersStorageType(): void
    {
        $source = file_get_contents(MEEL_ROOT . '/err/index.php');
        $this->assertNotFalse($source);
        $this->assertStringContainsString("'storage' => [", $source);
        $this->assertStringContainsString("503_Storage_Unavailable", $source);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
