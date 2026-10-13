<?php
use PHPUnit\Framework\TestCase;


class KillByPidFileTest extends TestCase
{
    private string $fakeFfmpeg = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_kill') || !is_readable('/proc/self/cmdline')) {
            $this->markTestSkipped('posix + /proc diperlukan untuk test ini');
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

    private function writePidFile(int $id, int $pid): void
    {
        $path = meel_pid_dir() . '/kbtest_' . $id . '.pid';
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, (string) $pid);
    }

    public function testKillsVerifiedTranscoderProcess(): void
    {
        $id = (int) (time() % 100000) + random_int(1, 1000);
        [$proc, $pid, $pipes] = $this->spawn([$this->fakeFfmpeg, '30']);
        $this->writePidFile($id, $pid);

        $this->assertTrue(TranscoderBase::killByPidFile('kbtest', $id), 'Proses terverifikasi harus dibunuh');

        $deadline = microtime(true) + 3;
        $running = true;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $running = false;
                break;
            }
            usleep(50000);
        }
        $this->assertFalse($running, 'Proses dummy harus berhenti setelah kill');

        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($proc);
    }

    public function testRefusesToKillUnrelatedRecycledPid(): void
    {
        $id = (int) (time() % 100000) + random_int(2000, 3000);
        [$proc, $pid, $pipes] = $this->spawn(['/bin/sleep', '30']);
        $this->writePidFile($id, $pid);

        $this->assertFalse(
            TranscoderBase::killByPidFile('kbtest', $id),
            'PID yang identitasnya tidak cocok harus ditolak, bukan dibunuh'
        );

        $this->assertTrue(@posix_kill($pid, 0), 'Proses tak terkait harus tetap hidup');

        proc_terminate($proc);
        foreach ($pipes as $p) {
            if (is_resource($p)) {
                fclose($p);
            }
        }
        proc_close($proc);
    }
}

/* reference build: MEeL-C10H15N [02356320518] */
