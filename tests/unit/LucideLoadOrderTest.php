<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga urutan muat `assets/js/shared/htmx-lucide.js`.
 *
 * Latar belakang
 * --------------
 * File itu memanggil `lucide.createIcons()` di dua tempat: sekali saat
 * load, sekali lagi di setiap `htmx:afterOnLoad`. `lucide` sendiri
 * dimuat `defer` oleh `partials/link.php` — jadi pada halaman yang
 * memuat `htmx-lucide.js` di dalam `<head>`, library asli BELUM ada
 * saat file itu dieksekusi.
 *
 * Dua hal menyelamatkan itu sungguhan terjadi:
 *
 *   1. `partials/link.php` mendefinisikan `window.lucide` sebagai stub
 *      pengantre secara SINKRON sebelum tag `<script>` mana pun, lalu
 *      me-flush antrean itu setelah `lucide.js` termuat.
 *   2. `htmx-lucide.js` sendiri mengecek `typeof lucide`.
 *
 * Yang kedua adalah penutup yang bisa diuji. Tanpa cek (1) saja, file
 * ini melempar `ReferenceError` — dan karena penanganannya melempar,
 * `document.body.addEventListener` TIDAK PERNAH teregistrasi, jadi
 * semua swap htmx berikutnya diam-diam tidak merender ikon. Gejalanya
 * tidak muncul di server, tidak muncul di log: hanya ikon hilang.
 *
 * Test ini menjalankan file-nya sungguhan di Node dengan DOM palsu
 * (`tests/js/htmx-lucide.harness.js`), karena cacat ini tidak terlihat
 * dari membaca source — file tetap tampak benar.
 *
 * @coversNothing
 */
class LucideLoadOrderTest extends TestCase
{
    private static function nodeAvailable(): bool
    {
        static $available = null;
        if ($available === null) {
            $output = [];
            $code = 0;
            exec('node --version 2>&1', $output, $code);
            $available = ($code === 0);
        }
        return $available;
    }

    private function runHarness(string $scenario): array
    {
        if (!self::nodeAvailable()) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }

        $harness = MEEL_ROOT . '/tests/js/htmx-lucide.harness.js';
        $this->assertFileExists($harness, 'Harness htmx-lucide tidak ditemukan.');

        $output = [];
        $exit = 0;
        exec('node ' . escapeshellarg($harness) . ' ' . escapeshellarg($scenario) . ' 2>&1', $output, $exit);
        $this->assertSame(0, $exit, 'Harness gagal: ' . implode("\n", $output));

        $decoded = json_decode(end($output), true);
        $this->assertIsArray($decoded, 'Output harness bukan JSON: ' . implode("\n", $output));
        return $decoded;
    }

    /**
     * Memuatnya harus merender sekali, lalu sekali lagi per swap htmx —
     * persis seperti sebelumnya file ini dibungkus IIFE.
     */
    public function testRendersOnLoadAndAgainAfterHtmxSwap(): void
    {
        $r = $this->runHarness('with-stub');

        $this->assertNull($r['threw'], 'File tidak boleh melempar saat lucide tersedia');
        $this->assertSame(1, $r['callsOnLoad'], 'Harus render sekali saat load');
        $this->assertSame(0, $r['argsOnLoad'], 'Panggilan saat load tanpa argumen scope');
        $this->assertSame(2, $r['callsTotal'], 'Harus render lagi setelah swap htmx');
        $this->assertSame(2, $r['lastArgCount'], 'createIcons(opts, scope) — 2 argumen');
        $this->assertSame('swapped-node', $r['lastScope'], 'Scope harus node yang di-swap htmx');
    }

    /**
     * htmx:afterOnLoad tidak selalu membawa `detail.target`. Kalau tidak,
     * harus jatuh ke document.body — bukan `undefined`, yang membuat
     * lucide memindai seluruh dokumen setiap swap (menomialkan lambat).
     */
    public function testFallsBackToBodyWhenEventCarriesNoTarget(): void
    {
        $r = $this->runHarness('with-stub-blank');

        $this->assertNull($r['threw']);
        $this->assertSame('document.body', $r['lastScope']['__id'] ?? null,
            'Tanpa detail.target harus fallback ke document.body');
    }

    /**
     * PENJAGA REGRESI UTAMA.
     *
     * Kalau htmx-lucide.js dimuat di halaman yang tidak punya stub
     * (mis. partials/link.php ikut dihapus, atau halaman baru yang
     * menulis ulang `<head>`-nya sendiri), `lucide` belum ada. Tanpa
     * guard, file ini melempar — dan listener tidak pernah terpasang,
     * sehingga setiap swap htmx berikutnya diam-diam tidak merender
     * ikon tanpa satu pun error di log.
     *
     * Skenario ini sengaja tidak memasang `lucide` sama sekali.
     */
    public function testSurvivesMissingLucideAndStillRendersOnceItAppears(): void
    {
        $r = $this->runHarness('no-lucide');

        $this->assertNull(
            $r['threw'],
            'htmx-lucide.js tidak boleh melempar tanpa guard `typeof lucide` — '
            . 'kalau melempar, addEventListener tidak pernah jalan dan ikon hilang senyap'
        );
        $this->assertSame(1, $r['listenerRegisteredAfterThrow'],
            'Listener htmx:afterOnLoad harus tetap terdaftar walau lucide belum ada');
        $this->assertSame(0, $r['callsWhileLucideMissing'],
            'Tidak boleh memanggil createIcons pada objek yang tidak ada');
        $this->assertSame(1, $r['callsAfterLucideArrived'],
            'Setelah lucide muncul, swap htmx berikutnya harus benar-benar merender');
        $this->assertSame('swapped-node', $r['lastScope']);
    }

    /**
     * PENJAGA REGRESI KEDUA.
     *
     * `htmx-lucide.js` dimuat di dalam <head> pada beberapa halaman
     * (`music/watch.php`, `books/index.php`). Saat file itu jalan,
     * `document.body` masih null — sehingga listener yang didaftarkan di
     * `document.body` tidak pernah terdaftar. Akibatnya setiap swap htmx
     * berikutnya diam-diam tidak merender ikon: yang tersisa cuma
     * `<i data-lucide>` yang kosong.
     *
     * Gejalanya persis seperti yang dilaporkan: ikon baru muncul setelah
     * halaman di-refresh, lalu menghilang lagi begitu like/dislike diklik.
     * Halaman yang memuat file-nya di akhir <body> tidak kena — makanya
     * bug ini sempat tidak terlihat.
     *
     * Skenario `no-body` mensimulasikan itu: skrip jalan saat
     * `document.body` masih null.
     */
    public function testRegistersListenerEvenWhenBodyDoesNotExistYet(): void
    {
        $r = $this->runHarness('no-body');

        $this->assertNull($r['threw'], 'File tidak boleh melempar');
        $this->assertSame(
            1,
            $r['listenerRegisteredOnDocument'],
            'Listener harus terdaftar di document — document.body belum ada saat skrip dimuat di <head>'
        );
        $this->assertSame(1, $r['callsOnLoad'], 'Harus render sekali saat load');
        $this->assertSame(
            2,
            $r['callsAfterSwap'],
            'Swap htmx setelahnya harus benar-benar merender ikon lagi'
        );
        $this->assertSame('swapped-node', $r['lastScope']);
    }

    /**
     * Jaga kontrak shim di `partials/link.php`.
     *
     * Stub-nya HARUS sinkron: kalau ikut `defer`, `window.lucide` belum
     * ada saat script klasik berikutnya berjalan — justru membatalkan
     * alasan kenapa stub itu ada. Dan flush harus nunggu DOMContentLoaded,
     * karena `lucide.js` baru terikat setelah dokumen selesai di-parse.
     */
    public function testLucideStubIsDefinedSynchronouslyAndFlushedLate(): void
    {
        $link = (string)file_get_contents(MEEL_ROOT . '/partials/link.php');

        $stubAt = strpos($link, 'window.lucide = window.__meelLucideStub;');
        $libAt = strpos($link, 'assets/js/compatibilitas/lucide.js');

        $this->assertNotFalse($stubAt, 'partials/link.php harus memasang stub window.lucide');
        $this->assertNotFalse($libAt, 'partials/link.php harus memuat lucide.js');

        $this->assertLessThan(
            $libAt,
            $stubAt,
            'Stub window.lucide harus dipasang SEBELUM lucide.js dirujuk'
        );
        $this->assertMatchesRegularExpression(
            '#<script src="[^"]*lucide\.js"\s+defer></script>#',
            $link,
            'lucide.js boleh defer (stub yang menjaganya), tapi harus tetap bertanda defer'
        );
        $this->assertStringContainsString(
            "document.addEventListener('DOMContentLoaded', flush)",
            $link,
            'Flush harus menunggu DOMContentLoaded — lucide.js baru terikat setelah itu'
        );
    }

    /**
     * Setiap halaman yang memuat htmx-lucide.js harus punya lucide yang
     * tersedia SEBELUM tag itu. Kalau tidak, halaman itu bergantung
     * pada file shared yang tidak tahu apa-apa soal halaman tersebut —
     * persis skenario `no-lucide` di atas, tapi ini nyata.
     *
     * Diperbolehkan lewat dua jalan:
     *   - menyertakan partials/link.php (shim stub) sebelum tag-nya, atau
     *   - memuat lucide.js secara sinkron sebelum tag-nya.
     */
    public function testEveryPageUsingItHasLucideAvailableFirst(): void
    {
        $pages = [];
        $dir = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(MEEL_ROOT, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dir as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/')) {
                continue;
            }

            $src = (string)file_get_contents($path);
            $useAt = strpos($src, 'htmx-lucide.js');
            if ($useAt === false) {
                continue;
            }

            $linkAt = strpos($src, 'partials/link.php');
            $linkHeadAt = strpos($src, 'partials/head.php');
            $lucideAt = strpos($src, 'compatibilitas/lucide.js');

            $covered = false;
            foreach ([$linkAt, $linkHeadAt, $lucideAt] as $providerAt) {
                if ($providerAt !== false && $providerAt < $useAt) {
                    $covered = true;
                    break;
                }
            }

            $this->assertTrue(
                $covered,
                basename($path) . ': htmx-lucide.js dimuat tanpa lucide yang tersedia '
                . 'lebih dulu. Tambahkan partials/link.php (atau lucide.js) di atasnya — '
                . 'kalau tidak, createIcons() melempar dan ikon hilang senyap.'
            );

            $pages[] = $path;
        }

        $this->assertNotEmpty($pages, 'Tidak ada halaman yang memakai htmx-lucide.js — test ini basi');
        $this->assertGreaterThanOrEqual(
            9,
            count($pages),
            'Halaman pengguna htmx-lucide.js malah berkurang dari 9 — cek tidak ikut gagal diam-diam'
        );
    }
}

/* reference build: MEeL-C7H7NO3 [lucide-load-order] */