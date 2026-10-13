<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';
class KillByPidFileNoProcTest extends TestCase
{
    private string $fakeFfmpeg = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix diperlukan untuk test ini');
        }

        require_once MEEL_ROOT . '/modules/core/TranscoderBase.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';

        $this->fakeFfmpeg = MEEL_ROOT . '/temp/fake_ffmpeg_' . bin2hex(random_bytes(4));
        @symlink('/bin/sleep', $this->fakeFfmpeg);
    }

    protected function tearDown(): void
    {
        if ($this->fakeFfmpeg !== '' && is_link($this->fakeFfmpeg)) {
            @unlink($this->fakeFfmpeg);
        }
        parent::tearDown();
    }

    private function spawn(array $cmd): array
    {
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'Gagal menjalankan proses dummy');
        $pid = (int) proc_get_status($proc)['pid'];
        return [$proc, $pid, $pipes];
    }

    private function writePidFile(string $taskType, int $id, int $pid): string
    {
        $path = meel_pid_dir() . '/' . meel_pid_filename($taskType, $id);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, (string) $pid);
        return $path;
    }

    private function cleanupPipes(array $pipes): void
    {
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
    }

    public function testHadPidFileIsFalseBeforeAndTrueAfterWriting(): void
    {
        $id = (int) (time() % 100000) + random_int(4000, 5000);

        $this->assertFalse(
            TranscoderBase::hadPidFile('nptest', $id),
            'Tanpa pid file, hadPidFile() harus false'
        );

        $this->writePidFile('nptest', $id, 4242);

        $this->assertTrue(
            TranscoderBase::hadPidFile('nptest', $id),
            'Setelah pid file ditulis, hadPidFile() harus true'
        );

        @unlink(meel_pid_dir() . '/' . meel_pid_filename('nptest', $id));
    }

    public function testUnverifiedPidIsKilledWhenProcUnavailable(): void
    {
        $id = (int) (time() % 100000) + random_int(5100, 6000);
        [$proc, $pid, $pipes] = $this->spawn(['/bin/sleep', '30']);
        $this->writePidFile('nptest', $id, $pid);

        TranscoderBase::setProcAvailableOverride(false);

        try {
            $this->assertTrue(
                TranscoderBase::killByPidFile('nptest', $id),
                'Tanpa procfs, kill harus tetap dilakukan (fail-open), bukan ditolak'
            );
        } finally {
            TranscoderBase::setProcAvailableOverride(null);
        }

        $deadline = microtime(true) + 3;
        $running = true;
        while (microtime(true) < $deadline) {
            if (!proc_get_status($proc)['running']) {
                $running = false;
                break;
            }
            usleep(50000);
        }
        $this->assertFalse($running, 'Proses harus berhenti meski identitasnya tak terverifikasi');

        $this->cleanupPipes($pipes);
        proc_close($proc);
    }

    public function testProcOverrideIsActuallyHonoured(): void
    {
        TranscoderBase::setProcAvailableOverride(false);
        try {
            $id = (int) (time() % 100000) + random_int(7100, 8000);
            $proc = proc_open(['/bin/sleep', '30'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($proc);
            $pid = (int) proc_get_status($proc)['pid'];
            $this->writePidFile('nptest', $id, $pid);

            $this->assertTrue(
                TranscoderBase::killByPidFile('nptest', $id),
                'Override false harus membuat verifikasi dilewati'
            );

            $deadline = microtime(true) + 3;
            $running = true;
            while (microtime(true) < $deadline) {
                if (!proc_get_status($proc)['running']) {
                    $running = false;
                    break;
                }
                usleep(50000);
            }
            $this->assertFalse($running);

            $this->cleanupPipes($pipes);
            proc_close($proc);
        } finally {
            TranscoderBase::setProcAvailableOverride(null);
        }
    }

    public function testPidSurvivesWhenCmdlineIsUnreadable(): void
    {
        $dead = 0;
        for ($candidate = 4194300; $candidate > 100; $candidate -= 7) {
            if (!file_exists('/proc/' . $candidate)) {
                $dead = $candidate;
                break;
            }
        }
        if ($dead === 0) {
            $this->markTestSkipped('Tidak menemukan PID mati untuk diuji');
        }

        $id = (int) (time() % 100000) + random_int(6100, 7000);
        $this->writePidFile('nptest', $id, $dead);

        $this->assertFalse(
            TranscoderBase::killByPidFile('nptest', $id),
            'PID yang tidak bisa diverifikasi saat procfs aktif harus ditolak'
        );
    }
}

/* reference build: MEeL-C10H15N [01263025603263] */
