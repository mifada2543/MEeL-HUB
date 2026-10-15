<?php

use PHPUnit\Framework\TestCase;

/**
 * Penjaga `TranscoderBase::cleanupStalePidFiles()` (T19).
 *
 * MASALAH YANG DIPERBAIKI
 * ------------------------
 * Fungsi ini dulu tidak pernah dipanggil siapa pun -- `scripts/gc.php`
 * menjalankan `GarbageCollector::run()` + `runCliMaintenance()` tapi
 * tidak pernah memanggilnya, padahal `killByPidFile()` membuat berkas
 * `.pid` yang tidak ada yang membersihkannya. Jadi ini celah nyata.
 *
 * Tapi menyambungkannya apa adanya justru MEMBUAT bug baru. Versi lama
 * hanya bergantung pada mtime:
 *
 *     if (time() - filemtime($file) > 1800) unlink($file);
 *
 * `writePidFile()` dipanggil SEKALI di awal job dan tidak pernah
 * di-refresh, jadi mtime berkas = waktu job mulai, bukan waktu terakhir
 * job bergerak. Transcode bisa berjalan jauh melebihi 30 menit -- ambang
 * sweeper antrean sendiri 6 jam -- sehingga job sah yang MASIH HIDUP
 * punya PID file yang terbaca "basi" dan ikut terhapus.
 *
 * Akibatnya `killByPidFile()` tidak menemukan PID-nya, jadi saat
 * shutdown prosesnya tidak bisa direbut: proses yatim yang tak terlihat.
 * Kebersihan yang dibeli dengan kehilangan kemampuan merebut proses.
 *
 * SYARAT YANG BENAR: dua syarat, bukan satu.
 *   1. berkasnya sudah tua, DAN
 *   2. proses di dalamnya sudah tidak hidup.
 *
 * Test di bawah mengunci kedua syarat itu, termasuk kasus yang paling
 * mudah salah: PID file yang umurnya sudah 2 jam tapi prosesnya masih
 * hidup (skenario transcode panjang yang tidak boleh dirusak).
 *
 * @coversNothing
 */
class CleanupStalePidFilesTest extends TestCase
{
    /** Berkas yang dibuat test ini, agar bisa dihapus di tearDown. */
    private array $created = [];

    /**
     * Isi + mtime berkas `.pid` milik sistem yang sudah ada sebelum test
     * berjalan. `cleanupStalePidFiles()` bekerja pada direktori yang sama
     * dengan proses transcode sungguhan, jadi test TIDAK boleh menghapus
     * apa pun milik sistem tanpavertencia. Apa pun yang ikut terhapus
     * dipulihkan kembali di tearDown, mtime termasuk.
     *
     * @var array<string, array{0:string,1:int}>
     */
    private array $preexisting = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('ENV: posix_kill() tidak tersedia');
        }

        require_once MEEL_ROOT . '/modules/core/TranscoderBase.php';
        require_once MEEL_ROOT . '/modules/core/helpers/storage.php';

        foreach (meel_pid_dir_candidates() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.pid') ?: [] as $file) {
                $this->preexisting[$file] = [
                    (string) @file_get_contents($file),
                    (int) @filemtime($file),
                ];
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->created = [];

        foreach ($this->preexisting as $file => [$content, $mtime]) {
            if (!is_file($file)) {
                @file_put_contents($file, $content);
                @touch($file, $mtime);
            }
        }
        $this->preexisting = [];

        parent::tearDown();
    }

    /** PID yang pasti tidak dipakai proses mana pun saat ini. */
    private function deadPid(): int
    {
        $pid = random_int(4_000_001, 4_194_300);
        if (@posix_kill($pid, 0)) {
            $this->markTestSkipped('ENV: PID ' . $pid . ' kebetulan sedang dipakai');
        }
        return $pid;
    }

    /**
     * Buat berkas `.pid` dengan isi dan umur tertentu.
     */
    private function makePidFile(string $name, string $content, int $ageSeconds): string
    {
        $dir = meel_pid_dir();
        $path = $dir . '/' . $name;
        file_put_contents($path, $content);
        touch($path, time() - $ageSeconds);
        $this->created[] = $path;
        return $path;
    }

    private function uid(string $tag): string
    {
        return 'gcprobe_' . $tag . '_' . bin2hex(random_bytes(4));
    }

    /**
     * Kasus inti: berkas umurnya sudah 2 jam (jauh lewat ambang 30 menit)
     * tapi proses di dalamnya masih hidup. Job transcode yang sedang
     * berjalan panjang harus TIDAK dirusak -- kalau PID file-nya hilang,
     * `killByPidFile()` tidak bisa merebut proses saat shutdown.
     */
    public function testKeepsOldPidFileWhileProcessIsStillAlive(): void
    {
        $livePid = getmypid();
        $this->assertIsInt($livePid);
        $this->assertTrue(@posix_kill($livePid, 0), 'PID test sendiri harus hidup');

        $path = $this->makePidFile($this->uid('live') . '.pid', (string) $livePid, 7200);

        TranscoderBase::cleanupStalePidFiles();

        $this->assertFileExists(
            $path,
            'PID file berumur 2 jam dengan proses MASIH HIDUP tidak boleh dihapus. '
            . 'Kalau dihapus, killByPidFile() tidak menemukan PID-nya dan proses '
            . 'transcode yang sah tidak bisa direbut saat shutdown -- proses yatim.'
        );
    }

    /**
     * Pasangan dari kasus di atas: berkas tua + proses sudah mati ->
     * dibersihkan. Tanpa ini, "jangan hapus yang hidup" bisa achieved
     * hanya dengan tidak pernah menghapus apa pun.
     */
    public function testDeletesOldPidFileWhenProcessIsDead(): void
    {
        $path = $this->makePidFile($this->uid('dead') . '.pid', (string) $this->deadPid(), 7200);

        TranscoderBase::cleanupStalePidFiles();

        $this->assertFileDoesNotExist(
            $path,
            'PID file basi dari proses yang sudah mati harus dibersihkan -- '
            . 'justru inilah celah yang gc.php tidak pernah tangani.'
        );
    }

    /**
     * PID file yang baru saja ditulis tidak boleh tersentuh, walau isinya
     * PID yang sudah mati (mis. job gagal di detik pertama lalu crash).
     */
    public function testKeepsFreshPidFileEvenWhenProcessIsDead(): void
    {
        $path = $this->makePidFile($this->uid('fresh') . '.pid', (string) $this->deadPid(), 5);

        TranscoderBase::cleanupStalePidFiles();

        $this->assertFileExists(
            $path,
            'PID file yang baru dibuat belum melewati ambang umur, harus dibiarkan. '
            . 'Sisa window 30 menit itu yang mencegah dua scheduler saling berebut berkas.'
        );
    }

    /**
     * Berkas rusak / kosong tidak bisa dipastikan hidup atau mati, jadi
     * tidak boleh ditahan selamanya hanya karena isinya tak terbaca.
     * Setelah ambang umur, ia dianggap sampah.
     */
    public function testDeletesCorruptOldPidFile(): void
    {
        $path = $this->makePidFile($this->uid('corrupt') . '.pid', 'bukan-angka', 7200);

        TranscoderBase::cleanupStalePidFiles();

        $this->assertFileDoesNotExist(
            $path,
            'PID file rusak yang sudah tua harus dibuang. Menahannya hanya karena '
            . 'isi tak terbaca berarti sampah tidak pernah hilang.'
        );
    }

    /**
     * Ambang umur harus bisa diatur (dipakai test dan cron yang punya
     * kebutuhan berbeda), dan batasnya benar-benar dipatuhi.
     */
    public function testAgeThresholdIsRespected(): void
    {
        $path = $this->makePidFile($this->uid('thresh') . '.pid', (string) $this->deadPid(), 100);

        TranscoderBase::cleanupStalePidFiles(600);
        $this->assertFileExists($path, 'Umur 100 dtk di bawah ambang 600 dtk harus aman');

        TranscoderBase::cleanupStalePidFiles(10);
        $this->assertFileDoesNotExist($path, 'Umur 100 dtk di atas ambang 10 dtk harus dibuang');
    }

    /**
     * Nilai yang dikembalikan harus sama dengan penghapusan yang benar-benar
     * terjadi, dan dua kali jalan pada kondisi yang sama harus idempoten.
     *
     * Perbandingannya memakai seluruh isi direktori PID --_before vs
     * after--, bukan logika "basi atau tidak" yang diduplikasi di test.
     * Menyalin aturan staleness ke sini justru membuat test bisa hijau
     * bersama implementasi yang salah, karena keduanya salah dengan cara
     * yang sama.
     */
    public function testReturnCountMatchesActualDeletionsAndIsIdempotent(): void
    {
        $a = $this->makePidFile($this->uid('cnt1') . '.pid', (string) $this->deadPid(), 7200);
        $b = $this->makePidFile($this->uid('cnt2') . '.pid', (string) $this->deadPid(), 7200);
        $alive = $this->makePidFile($this->uid('cnt3') . '.pid', (string) getmypid(), 7200);

        $before = $this->allPidFiles();
        $cleaned = TranscoderBase::cleanupStalePidFiles();
        $after = $this->allPidFiles();

        $this->assertSame(
            $cleaned,
            count(array_diff($before, $after)),
            'Nilai kembalian harus sama dengan jumlah berkas yang benar-benar hilang. '
            . 'Kalau tidak, log cron melaporkan_cleanup yang tidak terjadi.'
        );

        $this->assertFileDoesNotExist($a, 'PID file basi dari proses mati harus dibuang');
        $this->assertFileDoesNotExist($b, 'PID file basi dari proses mati harus dibuang');
        $this->assertFileExists($alive, 'PID file berproses hidup harus bertahan');

        $before2 = $this->allPidFiles();
        $cleaned2 = TranscoderBase::cleanupStalePidFiles();
        $this->assertSame(
            [],
            array_diff($before2, $this->allPidFiles()),
            'Panggilan kedua harus idempoten'
        );
        $this->assertSame(0, $cleaned2, 'Panggilan kedua harus melaporkan 0');
    }

    /** @return string[] seluruh berkas .pid di semua direktori kandidat */
    private function allPidFiles(): array
    {
        $all = [];
        foreach (meel_pid_dir_candidates() as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob($dir . '/*.pid') ?: [] as $f) {
                $all[] = $f;
            }
        }
        sort($all);
        return $all;
    }

    /**
     * Root cause-nya harus tetap tercatat di kode: kalau suatu saat ada
     * yang "menyederhanakan" lagi syaratnya jadi mtime saja, test di atas
     * akan gagal. Test ini memastikan penjelasannya masih ada supaya
     * penyederhanaan tadi tidak terjadi diam-diam.
     */
    public function testRootCauseIsStillDocumented(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/TranscoderBase.php');

        $this->assertStringContainsString(
            'cleanupStalePidFiles',
            $src,
            'cleanupStalePidFiles() tidak boleh hilang dari TranscoderBase'
        );

        $body = $this->extractMethodBody($src, 'cleanupStalePidFiles');
        $this->assertNotSame('', $body, 'Badan cleanupStalePidFiles() harus bisa dibaca');

        $this->assertMatchesRegularExpression(
            '/pidIsAlive|isProcessAlive/',
            $body,
            'cleanupStalePidFiles() HARUS memeriksa apakah proses masih hidup '
            . 'sebelum menghapus. Versi mtime-saja akan menghapus PID file job '
            . 'transcode yang sedang berjalan lalu membiarkan prosesnya yatim.'
        );
    }

    /**
     * KABLAMENYA SENDIRI harus dijaga.
     *
     * Fungsi ini sudah ada sejak lama dan tetap tidak terpakai selama
     * situasionya belum disambungkan. Plot-nya rapuh: `gc.php` adalah
     * skrip, bukan kelas yang diuji, jadi melepas satu baris panggilan
     * dari sana tidak menggagalkan test mana pun -- persis seperti
     * peta berkas->fungsi di `security_test.php` yang diam-diam berhenti
     * memindai apa pun sambil tetap melaporkan 100/100.
     *
     * Diuji mutasinya: menghapus panggilan → gagal. Memindahkannya
     * setelah blok `--run-only` → gagal.
     */
    public function testGcScriptActuallyCallsCleanup(): void
    {
        $path = MEEL_ROOT . '/scripts/gc.php';
        $this->assertFileExists($path);

        $src = (string) file_get_contents($path);

        $posCall = strpos($src, 'TranscoderBase::cleanupStalePidFiles(');
        $this->assertNotFalse(
            $posCall,
            'scripts/gc.php tidak memanggil TranscoderBase::cleanupStalePidFiles(). '
            . 'Tanpa panggilan itu, PID file yatim dari proses yang mati mendadak '
            . 'tidak pernah dibersihkan -- fungsi yang ada tapi tak terpakai, '
            . 'persis kondisi sebelum T19.'
        );

        $posRunOnly = strpos($src, '[MEeL gc] --run-only: DDL');
        $this->assertNotFalse($posRunOnly, 'Blok early-exit --run-only tidak ditemukan di gc.php');
        $this->assertLessThan(
            $posRunOnly,
            $posCall,
            'Panggilan cleanupStalePidFiles() harus SEBELUM blok early-exit '
            . '--run-only, supaya cron --run-only (tanpa DB) tetap membersihkan '
            . 'PID file yatim. Kalau sesudahnya, exit(0) menyalakannya dulu.'
        );

        $this->assertMatchesRegularExpression(
            '/\$stalePids\s*>\s*0/',
            $src,
            'Jumlah PID file yang dibersihkan harus dilaporkan ke output cron.'
        );
    }

    private function extractMethodBody(string $src, string $method): string
    {
        $pos = strpos($src, 'function ' . $method);
        if ($pos === false) {
            return '';
        }
        $brace = strpos($src, '{', $pos);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $brace, $i - $brace + 1);
                }
            }
        }
        return '';
    }
}

/* reference build: MEeL-D4K8PT1 [cleanup-stale-pid-files] */