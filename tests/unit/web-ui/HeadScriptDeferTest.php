<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga rantai `defer` di halaman root (`index.php`).
 *
 * Halaman root punya 6 script eksternal di `<head>` yang semuanya blocking.
 * Semuanya kini di-defer; `admin/index.php` sengaja hanya men-defer
 * `chart.umd.min.js` karena konsumennya dipanggil dari listener
 * `DOMContentLoaded` — test ini tidak memaksakan aturan "harus semua defer".
 *
 * Yang dikunci adalah invarian yang membuatnya bekerja:
 *
 *  1. Script defer dijalankan SETELAH parsing selesai, dalam urutan dokumen.
 *  2. Script INLINE di `<head>` berjalan saat parsing — yaitu SEBELUM script
 *     defer. Inline head di halaman ini justru men-*define* global.
 *  3. Script inline tidak boleh menyentuh global milik pustaka yang di-defer.
 *
 * @coversNothing
 */
class HeadScriptDeferTest extends TestCase
{
    /** @var string sumber index.php */
    private string $page;

    /**
     * index.php dengan blok PHP diganti spasi, TANPA menghapus baris baru.
     *
     * Spasi menjaga jumlah baris, jadi nomor baris tetap sebanding antar dua
     * sumber. Dan kenapa bukan offset karakter: normalisasi memendekkan string,
     * jadi offset pada string ternormalisasi dan pada string mentah tidak
     * sebanding — perbandingannya terlihat masuk akal tapi tidak bermakna.
     */
    private string $masked;

    /** @var string sumber partials/head.php */
    private string $head;

    /**
     * Dua file dibaca TERPISAH, lalu urutan render dipulihkan lewat posisi
     * `include` — bukan dengan menyalin isi partial ke dalam halaman.
     *
     * Yang dijaga:
     *   - partials/head.php meng-inline MEEL_BASE & MEEL_CSRF
     *   - partials/head.php di-include SEBELUM tag script pertama di index.php
     *   - script anti-FOUC tema juga ada di head.php, dan tema itu harus
     *     mendahului tag `theme.js` milik index.php
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->page = (string)file_get_contents(MEEL_ROOT . '/index.php');
        $this->head = (string)file_get_contents(MEEL_ROOT . '/partials/head.php');
        $this->masked = (string) preg_replace_callback(
            '/<\?(?:php|=).*?\?>/s',
            static fn(array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $this->page
        );

        $this->assertMatchesRegularExpression(
            "/include\s+(?:__DIR__\s*\.\s*)?['\"][^'\"]*partials\/head\.php['\"]\s*;/",
            $this->page,
            'index.php harus meng-include partials/head.php — pola include berubah'
        );
    }

    /** Nomor baris tag script EKSTERNAL pertama di index.php (1-indexed). */
    private function firstExternalScriptLine(): int
    {
        foreach (explode("\n", $this->masked) as $i => $line) {
            if (preg_match('/<script\b[^>]*\bsrc\s*=/', $line)) {
                return $i + 1;
            }
        }
        $this->fail('index.php tidak punya script eksternal');
    }

    /** Nomor baris `include partials/head.php` di index.php (1-indexed). */
    private function headIncludeLine(): int
    {
        foreach (explode("\n", $this->page) as $i => $line) {
            if (preg_match("/include\s+(?:__DIR__\s*\.\s*)?['\"][^'\"]*partials\/head\.php['\"]\s*;/", $line)) {
                return $i + 1;
            }
        }
        $this->fail('include partials/head.php tidak ketemu di index.php');
    }

    /**
     * Tag script eksternal beserta nama file dan status defer.
     *
     * Blok PHP dinormalkan lebih dulu: atribut `src` di repo ini hampir selalu
     * berisi short-echo, dan karakter `>` di dalam `?>` adalah penutup tag bagi
     * pola `[^>]*>` — sehingga tag terpotong tepat SEBELUM `defer` dan setiap
     * script terbaca blocking walau jelas bertanda defer.
     *
     * @return array<int, array{0:int, 1:string, 2:bool}> [baris, nama, defer]
     */
    private function externalScripts(): array
    {
        preg_match_all('/<script\b[^>]*\bsrc\s*=[^>]*>/s', $this->masked, $m, PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($m[0] as [$tag, $offset]) {
            $src = preg_match('/\bsrc\s*=\s*["\']([^"\']+)/', $tag, $s) ? $s[1] : '';
            $name = basename(explode('?', str_replace('PHPHASH', '', $src))[0]);
            $out[] = [
                substr_count(substr($this->page, 0, $offset), "\n") + 1,
                $name,
                (bool) preg_match('/\bdefer\b|\basync\b/', $tag),
            ];
        }
        return $out;
    }

    /**
     * @return string[] barcode dari script inline
     */
    private function headInlineScriptLines(): array
    {
        preg_match_all('/<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)<\/script>/s', $this->page, $m);
        $lines = [];
        foreach ($m[0] as $chunk) {
            $lines[] = substr_count(substr($this->page, 0, strpos($this->page, $chunk)), "\n") + 1;
        }
        return $lines;
    }

    public function testEveryExternalScriptOnRootIsDeferred(): void
    {
        $scripts = $this->externalScripts();

        $this->assertGreaterThanOrEqual(
            7,
            count($scripts),
            'Halaman root tiba-tiba punya lebih sedikit script eksternal — cek tidak ikut gagal diam-diam'
        );

        $blocking = array_values(array_filter($scripts, static fn(array $s): bool => !$s[2]));
        $this->assertSame(
            [],
            $blocking,
            "Script berikut blocking dan akan menunda render:\n  - "
            . implode("\n  - ", array_map(
                static fn(array $s): string => "{$s[1]} (baris {$s[0]})",
                $blocking
            ))
            . "\n\nKalau hanya satu yang perlu di-defer, pastikan konsumennya dipanggil dari "
            . 'DOMContentLoaded — bukan dari script yang jalan saat parsing.'
        );
    }

    /**
     * Script defer mengikuti urutan dokumen. Mengacak urutan = memindahkan
     * `sweetalert2` sebelum `lucide`, atau `theme.js` sebelum library yang
     *XNdipakai — dan itu tidak menghasilkan error, hanya fitur yang hilang.
     */
    public function testDeferChainKeepsDependencyOrder(): void
    {
        $names = array_map(static fn(array $s): string => $s[1], $this->externalScripts());

        $expected = [
            'lucide.js',
            'sweetalert2.all.min.js',
            'script.min.js',
            'state-keys.js',
            'health-reminder.js',
            'theme.js',
            'index-hub.js',
        ];

        $this->assertSame(
            $expected,
            $names,
            'Urutan script eksternal di halaman root berubah. Script defer dieksekusi '
            . 'dalam urutan dokumen; health-reminder dan index-hubartanpa Swal, dan '
            . 'index-hub tanpa MEELTheme, akan gagal.'
        );
    }

    /**
     * Script inline di `<head>` berjalan SEBELUM script defer. Jadi inline
     * tidak boleh menyentuh global milik pustaka yang di-defer.
     *
     * Inline yang ADA di halaman ini justru mendefinisikan
     * `MEEL_BASE` / `MEEL_CSRF` dan menjalankan anti-FOUC — itu aman
     * karena berlawanan arah: inline memberi, defer mengonsumsi.
     */
    public function testHeadInlineScriptsDoNotTouchDeferredLibraries(): void
    {
        preg_match_all(
            '/<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)<\/script>/s',
            $this->head,
            $m,
            PREG_OFFSET_CAPTURE
        );
        $this->assertNotEmpty($m, 'partials/head.php harus punya script inline');

        $offenders = [];
        foreach ($m[1] as [$body, $offset]) {
            foreach (['lucide.createIcons', 'Swal.fire', 'new Chart(', 'MEELTheme.'] as $needle) {
                if (str_contains($body, $needle)) {
                    $line = substr_count(substr($this->head, 0, $offset), "\n") + 1;
                    $offenders[] = sprintf('partials/head.php:%d memakai %s', $line, $needle);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Script inline di <head> berjalan lebih dulu, sebelum pustaka defer termuat:\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nKalau memang dibutuhkan, pindahkan ke listener DOMContentLoaded."
        );
    }

    /**
     * Anti-FOUC harus tetap blocking.
     *
     * `theme.js` sengaja di-defer, justru KARENA tidak lagi menerapkan
     * tema saat load. Yang mencegah kilat tema adalah script inline kecil
     * yang membaca localStorage lalu langsung menulis `data-theme`. Kalau
     * inline itu ikut di-defer (atau dihapus), user melihat tema terang
     * selama beberapa ratus milidetik sebelum tema gelap terpasang — dan
     * tidak ada error apa pun untuk menandainya.
     */
    public function testAntiFlickerThemeScriptStaysBlockingAndBeforeThemeJs(): void
    {
        $this->assertMatchesRegularExpression(
            "/<script(?![^>]*\\bsrc\\s*=)[^>]*>((?:(?!<\\/script>).)*?meel_theme(?:(?!<\\/script>).)*?)<\\/script>/s",
            $this->head,
            'Script anti-FOUC tema (membaca localStorage "meel_theme") tidak ditemukan di '
            . 'partials/head.php -- tanpa itu tema berkedip saat halaman dimuat'
        );

        $antiFlickerAt = (int) strpos($this->head, 'meel_theme');
        $segment = substr($this->head, $antiFlickerAt, 900);
        $this->assertMatchesRegularExpression(
            '/documentElement\s*\.\s*setAttribute\(\s*["\']data-theme["\']/',
            $segment,
            'Script anti-FOUC harus benar-benar menulis data-theme ke <html>'
        );

        $this->assertLessThan(
            $this->firstExternalScriptLine(),
            $this->headIncludeLine(),
            'partials/head.php harus di-include SEBELUM script eksternal pertama -- '
            . 'kalau tidak, anti-FOUC ikut terlambat dan kilat tema sudah terjadi'
        );
    }

    /**
     * `index-hub.js` (defer) mengonsumsi `MEEL_BASE` dan `MEEL_CSRF` yang
     * di-inline di `<head>`. Inline berjalan saat parsing, defer setelahnya —
     * jadi aman. Tapi kalau inline itu dihapus atau dipindah setelah tag
     * script, nilainya jadi `undefined` dan semua URL relatif di halaman
     * itu ikut rusak tanpa error yang mencolok.
     */
    public function testBaseUrlGlobalsAreInlinedBeforeDeferredConsumers(): void
    {
        $this->assertMatchesRegularExpression(
            '/window\.MEEL_BASE\s*=/',
            $this->head,
            'window.MEEL_BASE harus di-inline di partials/head.php'
        );
        $this->assertMatchesRegularExpression(
            '/window\.MEEL_CSRF\s*=/',
            $this->head,
            'window.MEEL_CSRF harus di-inline di partials/head.php'
        );

        $this->assertLessThan(
            strpos($this->head, 'window.MEEL_CSRF'),
            strpos($this->head, 'window.MEEL_BASE'),
            'MEEL_BASE ditulis sebelum MEEL_CSRF'
        );

        $this->assertLessThan(
            $this->firstExternalScriptLine(),
            $this->headIncludeLine(),
            'MEEL_BASE/CSRF hanya berguna kalau partial/head.php tampil sebelum script eksternal'
        );
    }
}

/* reference build: MEeL-C9H8NO2 [head-script-defer] */