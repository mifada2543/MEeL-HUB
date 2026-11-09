<?php
use PHPUnit\Framework\TestCase;

class KillByPidFileTest extends TestCase
{
    private string $fakeFfmpeg = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_kill') || !is_readable('/proc/self/cmdline')) {
            $this->markTestSkipped('ENV: posix_kill() atau /proc tidak tersedia');
        }

        require_once MEEL_ROOT . '/modules/core/TranscoderBase.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';

        $this->fakeFfmpeg = $this->makeFakeTranscoder();
    }

    private function makeFakeTranscoder(): string
    {
        $dir = MEEL_ROOT . '/temp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $candidates = array_filter([
            '/bin/sleep',
            '/usr/bin/sleep',
            trim((string) @shell_exec('command -v sleep 2>/dev/null')),
        ], static fn(string $p): bool => $p !== '' && is_executable($p));

        if ($candidates === []) {
            fwrite(STDERR, "[ENV] Tidak ada binary 'sleep' yang executable — test PID dilewati.\n");
            $this->markTestSkipped('ENV: binary sleep tidak tersedia');
        }

        $path = MEEL_ROOT . '/temp/fake_ffmpeg_' . bin2hex(random_bytes(4));
        if (!@symlink((string) reset($candidates), $path)) {
            fwrite(STDERR, "[ENV] Gagal membuat symlink proses tiruan di {$path}\n");
            $this->markTestSkipped('ENV: symlink proses tiruan gagal dibuat');
        }

        return $path;
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
        if (!is_resource($proc)) {
            fwrite(STDERR, "[ENV] proc_open() gagal untuk: {$cmd[0]}\n");
            $this->markTestSkipped('ENV: proc_open() tidak dapat menjalankan proses tiruan');
        }
        $pid = (int) proc_get_status($proc)['pid'];

        usleep(150000);
        if (!proc_get_status($proc)['running']) {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            @proc_close($proc);
            fwrite(STDERR, "[ENV] Proses tiruan {$cmd[0]} langsung mati — test PID dilewati.\n");
            $this->markTestSkipped('ENV: proses tiruan gagal bertahan hidup');
        }

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