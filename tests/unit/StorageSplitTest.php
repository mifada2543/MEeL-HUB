<?php

use PHPUnit\Framework\TestCase;

/**
 * Penjaga hasil pemecahan `modules/core/helpers/storage.php` (T27).
 *
 * LATAR BELAKANG
 * --------------
 * `storage.php` tadinya 941 baris / 37 fungsi dalam satu file. Dipecah
 * menjadi empat per domain, mengikuti konvensi yang sudah dipakai folder
 * `helpers/` (`media.php`, `audio.php`, `upload.php`, …):
 *
 *   storage_paths.php     base path, thumbnail, PID, guard mount   (14 fn)
 *   storage_disk.php      kapasitas disk, cache file, audit log     (8 fn)
 *   storage_xsendfile.php deteksi & header X-Sendfile             (11 fn)
 *   storage_stream.php    validator cache + serving media          (4 fn)
 *
 * `storage.php` sengaja TETAP ada sebagai aggregator supaya
 * `helpers/main.php` (satu baris) dan 25+ pemanggil tidak pecah.
 *
 * BUG SENYAP YANG SUDAH TERJADI SEBELUM TEST INI
 * ----------------------------------------------
 * `tests/security_test.php` punya peta "berkas → fungsi berbahaya yang
 * diizinkan". Entri `storage.php => ['shell_exec']` masih menunjuk ke
 * agregator — yang kini hanya 40 baris dan tidak punya `shell_exec`
 * sama sekali. Test itu **tetap melaporkan 100/100** sementara
 * `dir_size()` yang memanggil `du -sb` via `shell_exec` tidak lagi
 * dipindai sama sekali.
 *
 * Itu kelas bug yang paling berbahaya: check yangreports hijau karena
 * tidak lagi memeriksa apa pun. Test di bawah menutupnya.
 *
 * @coversNothing
 */
class StorageSplitTest extends TestCase
{
    /** @var array<string, string> berkas pecahan => peran */
    private const PARTS = [
        'storage_paths.php'     => 'path',
        'storage_disk.php'      => 'disk',
        'storage_xsendfile.php' => 'xsendfile',
        'storage_stream.php'    => 'stream',
    ];

    private string $helpersDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->helpersDir = MEEL_ROOT . '/modules/core/helpers';
    }

    private function aggregatorSource(): string
    {
        return (string) file_get_contents($this->helpersDir . '/storage.php');
    }

    /**
     * Inti guard: agregator TIDAK BOLEH mendefinisikan fungsi.
     *
     * Kalau ada satu saja, file itu menjadi tempat bersembunyi yang tidak
     * dipindai `security_test` maupun `functional_test` — dua check yang
     * keduanya memetakan fungsi ke nama berkas secara literal.
     */
    public function testAggregatorDefinesNoFunctions(): void
    {
        $src = $this->aggregatorSource();

        $this->assertDoesNotMatchRegularExpression(
            '/^\s*function\s+[a-zA-Z_]/m',
            $src,
            'storage.php sekarang harus aggregator murni. Fungsi yang didefinisikan '
            . 'di sini akan luput dari security_test (peta berkas->fungsi berbahaya) '
            . 'dan functional_test (peta fungsi->berkas).'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/shell_exec|proc_open|exec\s*\(|popen\s*\(/',
            $src,
            'agregator tidak boleh menjalankan perintah sistem — dan kalau boleh, '
            . 'security_test tidak akan memindanya karena tidak ada di petanya'
        );
    }

    /**
     * Semua fungsi yang dulu ada di storage.php harus masih ada, di salah
     * satu dari empat berkas pecahan.
     */
    public function testEveryOriginalFunctionStillExists(): void
    {
        $defined = [];
        foreach (array_keys(self::PARTS) as $part) {
            $src = (string) file_get_contents($this->helpersDir . '/' . $part);
            preg_match_all('/^\s*function\s+([a-zA-Z_]\w*)/m', $src, $m);
            foreach ($m[1] as $fn) {
                $other = $defined[$fn] ?? '(tidak diketahui)';
                $this->assertArrayNotHasKey(
                    $fn,
                    $defined,
                    "fungsi {$fn} terdefinisi ganda: di {$other} dan di {$part}"
                );
                $defined[$fn] = $part;
            }
        }

        $expected = [
            'meel_media_base_path', 'video_thumbnail_sm_name', 'video_thumbnail_sm',
            'meel_is_default_thumbnail', 'music_thumbnail_url', 'meel_storage_base_path',
            'meel_storage_marker_path', 'meel_storage_ready', 'meel_storage_path_in_volume',
            'meel_pid_dir_candidates', 'meel_pid_dir', 'meel_pid_filename',
            'meel_storage_guard', 'meel_drive_base_path',
            'check_disk_space', 'require_disk_space', 'dir_size', 'invalidate_dir_size_cache',
            'meel_sweep_stale_cache_tmp', 'meel_write_cache_file', 'meel_read_cache_file',
            'log_drive_operation',
            'meel_xsendfile_enabled', 'meel_xsendfile_config_files', 'meel_xsendfile_flag_from_text',
            'meel_xsendfile_config_data', 'meel_xsendfile_roots', 'meel_xsendfile_server_flag',
            'meel_xsendfile_htaccess_dirs', 'meel_xsendfile_merge_flag',
            'meel_xsendfile_effective_flag', 'meel_xsendfile_ready', 'meel_xsendfile_header',
            'meel_cache_is_fresh', 'meel_media_etag', 'meel_media_cache_control',
            'meel_media_type_map', 'meel_resolve_media_path', 'meel_apply_hls_gate',
            'meel_resolve_byte_range', 'meel_stream_media_bytes',
            'meel_serve_media_file',
        ];

        $missing = array_values(array_diff($expected, array_keys($defined)));
        $this->assertSame(
            [],
            $missing,
            'fungsi storage hilang saat pemecahan: ' . implode(', ', $missing)
        );
        $this->assertCount(
            count($expected),
            $defined,
            'ada fungsi baru di berkas storage yang tidak tercatat di test ini — '
            . 'tambahkan ke daftar $expected kalau memang disengaja'
        );
    }

    /**
     * Urutan require mengikuti arah ketergantungan. Mengacaknya tidak
     * merusak definisi fungsi (PHP menundanya), TETAPI merusak satu-satunya
     * efek samping saat muat: blok `MEEL_HDD_CHECKED` memanggil
     * `meel_storage_ready()`, yang hanya ada setelah `storage_paths.php`.
     */
    public function testAggregatorRequiresPartsInDependencyOrder(): void
    {
        $src = $this->aggregatorSource();

        $order = [];
        preg_match_all("/require_once __DIR__ \. '\/([a-z_]+\.php)'/", $src, $m);
        foreach ($m[1] as $file) {
            $order[] = $file;
        }

        $this->assertSame(
            ['storage_paths.php', 'storage_disk.php', 'storage_xsendfile.php', 'storage_stream.php'],
            $order,
            'urutan require di storage.php harus paths -> disk -> xsendfile -> stream. '
            . 'Bloc MEEL_HDD_CHECKED di storage_paths.php memanggil meel_storage_ready(); '
            . 'kalau file itu dimuat belakangan, efek sampingnya hilang diam-diam.'
        );

        foreach ($order as $file) {
            $this->assertFileExists(
                $this->helpersDir . '/' . $file,
                "storage.php me-require {$file} yang tidak ada"
            );
        }
    }

    /**
     * Berkas pecahan tidak boleh monster — kalau salah satu mulai tumbuh
     * kembali ke ratusan baris, pemecahan ini hanya kosmetik.
     */
    public function testEachPartStaysWithinASaneSize(): void
    {
        foreach (self::PARTS as $part => $role) {
            $lines = substr_count(
                (string) file_get_contents($this->helpersDir . '/' . $part),
                "\n"
            );
            $this->assertLessThanOrEqual(
                320,
                $lines,
                "{$part} ({$role}) tumbuh jadi {$lines} baris — pemecahan ini cuma kosmetik"
            );
            $this->assertGreaterThan(50, $lines, "{$part} ({$role}) suspiciously small");
        }
    }

    /**
     * `MediaCacheHeadersTest` mengunci urutan blok lewat posisi string di
     * `meel_serve_media_file()`. Kalau fungsi itu pindah lagi, test itu akan
     * membaca berkas yang salah dan gagal diam-diam — atau, lebih buruk,
     * lolos karena tidak menemukan apa pun. Assert ini memastikan file yang
     * dibaca benar-benar memuat ketiga penanda itu.
     */
    public function testStreamPartStillHoldsTheServeFunctionAndItsOrdering(): void
    {
        $src = (string) file_get_contents($this->helpersDir . '/storage_stream.php');

        $posIsPartial = strpos($src, '$isPartial = false;');
        $pos304 = strpos($src, 'http_response_code(304)');
        $posPartialTrue = strpos($src, '$isPartial = true;');

        $this->assertNotFalse($posIsPartial, 'inisialisasi $isPartial harus ada di storage_stream.php');
        $this->assertNotFalse($pos304, 'penanganan 304 harus ada di storage_stream.php');
        $this->assertNotFalse($posPartialTrue, 'penandaan range harus ada di storage_stream.php');

        $this->assertGreaterThan(
            $posIsPartial,
            $pos304,
            'blok 304 harus setelah inisialisasi $isPartial'
        );
        $this->assertGreaterThan(
            $posPartialTrue,
            $pos304,
            'blok 304 harus setelah $isPartial assignment'
        );
        $this->assertStringContainsString(
            'if (!$isPartial && meel_cache_is_fresh($_SERVER, $etag, $mtime)) {',
            $src,
            'blok 304 wajib dijaga agar tidak jalan pada Range request'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($src, "header('ETag: ' . \$etag);"),
            'kedua jalur (X-Sendfile & streaming) harus mengirim ETag'
        );
    }

    /**
     * Setiap check di `security_test.php` / `functional_test.php` menunjuk
     * berkas lewat path literal. After a split those maps point at the aggregator and
     * stop covering anything.
     *
     * Test ini menelusuri KEDUA peta dan memastikan setiap berkas yang
     * benar-benar memakai perintah sistem atau fungsi yang diperiksa masih terdaftar.
     */
    public function testSecurityAndFunctionalMapsStillCoverTheRealFiles(): void
    {
        $security = (string) file_get_contents(MEEL_ROOT . '/tests/security_test.php');
        $functional = (string) file_get_contents(MEEL_ROOT . '/tests/functional_test.php');

        $mapNames = implode('|', array_map(
            static fn(string $v): string => preg_quote($v, '/'),
            ['risky', 'expected', 'allowed']
        ));
        preg_match_all('/\$(?:' . $mapNames . ')\s*=\s*\[(.*?)\];/s', $security, $allBlocks);
        $this->assertNotEmpty($allBlocks[1], 'peta allowlist di security_test tidak terbaca');

        $riskyBlock = implode("\n", $allBlocks[1]);

        foreach (array_keys(self::PARTS) as $part) {
            $rel = 'modules/core/helpers/' . $part;
            $src = (string) file_get_contents($this->helpersDir . '/' . $part);

            if (!preg_match('/\b(shell_exec|exec|proc_open|popen|system|passthru)\s*\(/', $src)) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($rel, '/') . "'\s*=>\s*\[[^\]]*\]/",
                $riskyBlock,
                "{$part} memakai perintah sistem tapi TIDAK terdaftar di peta allowlist "
                . 'security_test — pemindaiannya akan dilewati diam-diam'
            );
        }

        $this->assertMatchesRegularExpression(
            '/function\s+testCommandInjection\s*\(\)\s*:\s*void\s*\{.*?getPhpFiles\s*\(\)/s',
            $security,
            'testCommandInjection harus menemukan berkasnya sendiri lewat getPhpFiles() — '
            . 'kalau hanya memindai peta literal, pemindahan kode ke berkas baru akan lolos tanpa diperiksa'
        );

        preg_match_all(
            "/'([a-z_0-9]+)'\s*=>\s*'(modules\/core\/helpers\/[a-z_]+\.php)'/",
            $functional,
            $m,
            PREG_SET_ORDER
        );
        $this->assertNotEmpty($m, 'fungsi tidak lagi dipetakan ke berkas di functional_test');

        foreach ($m as $set) {
            [, $fn, $rel] = $set;
            $full = MEEL_ROOT . '/' . $rel;
            $this->assertFileExists($full, "functional_test menunjuk {$rel} yang tidak ada");
            $this->assertStringContainsString(
                "function {$fn}(",
                (string) file_get_contents($full),
                "functional_test memetakan {$fn}() ke {$rel}, tapi fungsi itu tidak ada di sana"
            );
        }
    }
}

/* reference build: MEeL-C10H7NO2 [storage-split] */