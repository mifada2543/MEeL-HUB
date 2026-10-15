<?php
use PHPUnit\Framework\TestCase;

class PendingDeleteLockTest extends TestCase
{
    private string $pendingFile;
    private string $lockFile;
    private ?string $pendingBackup = null;
    private ?string $lockBackup = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pendingFile = MEEL_ROOT . '/temp/pending_delete.json';
        $this->lockFile = $this->pendingFile . '.lock';

        if (is_file($this->pendingFile)) {
            $this->pendingBackup = (string) file_get_contents($this->pendingFile);
        }
        if (is_file($this->lockFile)) {
            $this->lockBackup = (string) file_get_contents($this->lockFile);
        }

        @unlink($this->pendingFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->pendingFile);
        @unlink($this->lockFile);
        if ($this->pendingBackup !== null) {
            @file_put_contents($this->pendingFile, $this->pendingBackup);
        }
        if ($this->lockBackup !== null) {
            @file_put_contents($this->lockFile, $this->lockBackup);
        }
        parent::tearDown();
    }

    public function testParallelSavesProduceNoLostUpdates(): void
    {
        require_once MEEL_ROOT . '/modules/core/helpers/pending_delete.php';

        $helper = MEEL_ROOT . '/modules/core/helpers/pending_delete.php';
        $code = 'require ' . var_export($helper, true) . ';'
            . ' savePendingDeletions(["timestamp" => time(), "files" => []]);';

        $processes = 20;
        $procs = [];
        for ($i = 0; $i < $processes; $i++) {
            $proc = proc_open(
                [PHP_BINARY, '-r', $code],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($proc)) {
                $this->fail('Gagal menjalankan proses uji #' . $i);
            }
            $procs[] = [$proc, $pipes];
        }

        foreach ($procs as [$proc, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }

        $this->assertFileExists($this->pendingFile, 'File antrean harus dibuat');

        $decoded = json_decode((string) file_get_contents($this->pendingFile), true);
        $this->assertIsArray($decoded, 'Antrean harus JSON array valid');
        $this->assertCount(
            $processes,
            $decoded,
            'Setiap penulisan paralel harus tercatat (tidak ada lost update)'
        );
    }
}

/* reference build: MEeL-C10H15N [251895201] */