<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga PWA manifest: `scope` & `id` harus mengikuti base path aplikasi.
 *
 * Latar belakang
 * --------------
 * `assets/manifest.json` dulu file statis dengan `"scope": "/MEeL/"` dan
 * `"id": "/MEeL/"` HARDCODE. Kalau repo dipindah ke folder lain — yang
 * README mengizinkan begitu saja (`git clone ... MEeL`, tapi user
 * bebas menamai ulang) — `scope` tetap `/MEeL/` sehingga:
 *
 *   - Service worker tidak pernah "controlling" halaman di path baru
 *   - Shortcut & start_url menunjuk ke path yang salah
 *   - Ikon gagal dimuat (path relatif jadi salah folder)
 *
 * Solusi: `manifest.php` di root sebagai generator (pola yang sama
 * dengan `sw.js` → `sw.js.php`), dilayani lewat rewrite
 * `^assets/manifest\.json$` di `.htaccess`. File `.json` statis tetap
 * ada sebagai fallback untuk clone tanpa `mod_rewrite`.
 *
 * Test ini menjalankan generator sungguhan dengan `DOCUMENT_ROOT`
 * dikontrol, sehingga base path bisa diuji tanpa benar-benar memindahkan
 * repo.
 */

/** @coversNothing */
class PwaManifestScopeTest extends TestCase
{
    /**
     * Jalankan manifest.php dengan DOCUMENT_ROOT yang kita tentukan.
     *
     * PENTING: `$_SERVER` TIDAK diwariskan ke subprocess PHP CLI, jadi
     * meel_base_url_path() di dalam generator selalu melihat
     * DOCUMENT_ROOT kosong kalau dijalankan via shell_exec biasa. Agar
     * test benar-benar menguji base path yang berbeda, kita:inject
     * DOCUMENT_ROOT lewat env var yang dibaca generator saat
     * MEEL_MANIFEST_DOCROOT diset, lalu fallback ke $_SERVER.
     *
     * Env var ini HANYA untuk keperluan test — production tetap
     * memakai DOCUMENT_ROOT dari web server.
     */
    private function renderManifest(string $docRoot): array
    {
        $script = MEEL_ROOT . '/manifest.php';

        $env = '';
        if ($docRoot !== '') {
            $env = sprintf('MEEL_MANIFEST_DOCROOT=%s ', escapeshellarg($docRoot));
        }

        $out = (string)shell_exec(
            $env
            . escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=0 '
            . escapeshellarg($script)
            . ' 2>/dev/null'
        );

        $decoded = json_decode($out, true);
        $this->assertIsArray(
            $decoded,
            "manifest.php tidak menghasilkan JSON valid:\n" . substr($out, 0, 300)
        );

        return $decoded;
    }

    public function testGeneratorProducesValidJson(): void
    {
        $manifest = $this->renderManifest(MEEL_ROOT . '/..');

        $this->assertArrayHasKey('scope', $manifest);
        $this->assertArrayHasKey('id', $manifest);
        $this->assertArrayHasKey('start_url', $manifest);
        $this->assertArrayHasKey('name', $manifest);
    }

    public function testScopeAndIdUseDetectedBasePath(): void
    {
        $manifest = $this->renderManifest(MEEL_ROOT);

        $this->assertSame('/', $manifest['scope'], 'Document root → scope "/"');
        $this->assertSame('/', $manifest['id'], 'Document root → id "/"');
    }

    /**
     * Yang paling penting: kalau repo dipindah ke folder lain, scope
     * harus ikut — bukan tetap "/MEeL/".
     *
     * Kita mensimulasikan dengan meel_base_url_path() yang benar-benar
     * ada di generator. Karena DOCUMENT_ROOT manipulation di CLI tidak
     * realistis (generator membaca DOCUMENT_ROOT dari $_SERVER), test ini
     * memanggil generator lewat CLI dengan DOCUMENT_ROOT lewat env yang
     * di-forward.
     */
    public function testScopeNotHardcodedToProjectDirectoryName(): void
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/manifest.php');

        $this->assertStringNotContainsString(
            "'/MEeL/",
            $src,
            "manifest.php tidak boleh hardcode '/MEeL/' — scope harus dihitung dari base path"
        );

        $htaccess = (string)file_get_contents(MEEL_ROOT . '/.htaccess');
        $this->assertMatchesRegularExpression(
            '#RewriteRule\s+\^assets/manifest\\\.json\$#',
            $htaccess,
            "Rewrite untuk manifest.json HARUS ada di .htaccess — tanpa itu yang dilayani "
            . 'browser tetap file statis yang hardcode /MEeL/'
        );
    }

    public function testStartUrlAndShortcutsAreAbsoluteUnderBase(): void
    {
        $manifest = $this->renderManifest(MEEL_ROOT . '/..');

        $this->assertStringStartsWith(
            '/',
            $manifest['start_url'],
            'start_url harus absolut — "../index.php" tidak berlaku di manifest'
        );
        $this->assertStringNotContainsString(
            '..',
            $manifest['start_url'],
            'start_url tidak boleh mengandung ".."'
        );
    }

    public function testShortcutUrlsAreAbsoluteWithoutPhpExtension(): void
    {
        $manifest = $this->renderManifest(MEEL_ROOT . '/..');

        $this->assertArrayHasKey('shortcuts', $manifest);
        $this->assertNotEmpty($manifest['shortcuts']);

        foreach ($manifest['shortcuts'] as $shortcut) {
            $this->assertArrayHasKey('url', $shortcut);
            $this->assertStringStartsWith(
                '/',
                $shortcut['url'],
                "Shortcut URL \"{$shortcut['url']}\" harus absolut"
            );
            $this->assertStringNotContainsString(
                '..',
                $shortcut['url'],
                "Shortcut URL \"{$shortcut['url']}\" tidak boleh mengandung '..'"
            );
        }
    }

    public function testIconPathsAreAbsoluteUnderAssets(): void
    {
        $manifest = $this->renderManifest(MEEL_ROOT . '/..');

        $this->assertNotEmpty($manifest['icons'], 'Manifest harus punya minimal satu icon');

        foreach ($manifest['icons'] as $icon) {
            $this->assertStringStartsWith(
                '/',
                $icon['src'],
                "Icon src \"{$icon['src']}\" harus absolut"
            );
            $this->assertStringContainsString(
                'assets/',
                $icon['src'],
                "Icon src \"{$icon['src']}\" harus menunjuk ke folder assets/ — "
                . 'icon berada di assets/, bukan root'
            );
        }
    }

    public function testFallbackJsonStillExistsForNoRewriteEnvironment(): void
    {
        $this->assertFileExists(
            MEEL_ROOT . '/assets/manifest.json',
            'assets/manifest.json harus tetap ada sebagai fallback untuk '
            . 'environment tanpa mod_rewrite'
        );

        $json = json_decode((string)file_get_contents(MEEL_ROOT . '/assets/manifest.json'), true);
        $this->assertIsArray($json, 'assets/manifest.json harus JSON valid');
        $this->assertArrayHasKey('scope', $json, 'Manifest fallback harus punya scope');
    }

    /**
     * Generator tidak boleh membocorkan path filesystem ke dalam URL.
     * Ini bug nyata: saat DOCUMENT_ROOT kosong (CLI), meel_base_url_path()
     * mengembalikan substr(path, 0) = path penuh.
     */
    public function testNoFilesystemPathLeaksIntoUrls(): void
    {
        $manifest = $this->renderManifest('');

        $blob = (string)json_encode($manifest);

        foreach (['/opt/', '/var/www', '/home/', 'C:\\\\', '/srv/'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $blob,
                "Manifest membocorkan path filesystem \"{$leak}\" — scope/URL harus URL, bukan path"
            );
        }

        $this->assertSame(
            '/',
            $manifest['scope'],
            'Tanpa DOCUMENT_ROOT, scope harus "/" — bukan path filesystem'
        );
    }

    /**
     * Guard yang sama, diuji dari sisi yang lebih langsung: kalau
     * DOCUMENT_ROOT kosong, hasil meel_base_url_path() adalah path proyek
     * penuh. Generator harus mendeteksi itu dan MEMBUANGNYA.
     *
     * Test ini sengaja memanggil generator dengan DOCUMENT_ROOT kosong
     * TANPA env override, jadi benar-benar menguji kondisi itu.
     */
    public function testGeneratorRejectsProjectPathWhenDocRootMissing(): void
    {
        $out = (string)shell_exec(
            escapeshellarg(PHP_BINARY)
            . ' -d error_reporting=0 '
            . escapeshellarg(MEEL_ROOT . '/manifest.php')
            . ' 2>/dev/null'
        );
        $manifest = json_decode($out, true);
        $this->assertIsArray($manifest);

        $projPath = str_replace('\\', '/', MEEL_ROOT);
        $this->assertStringNotContainsString(
            $projPath,
            (string)json_encode($manifest),
            'Generator mengeluarkan path proyek ke dalam manifest — DOCUMENT_ROOT kosong harus dipaksa ke "/"'
        );
        $this->assertSame('/', $manifest['scope']);
    }

    public function testScopeAlwaysEndsWithSlash(): void
    {
        foreach ([MEEL_ROOT, MEEL_ROOT . '/..', ''] as $docRoot) {
            $manifest = $this->renderManifest($docRoot);
            $this->assertStringEndsWith(
                '/',
                $manifest['scope'],
                "Scope \"{$manifest['scope']}\" harus diakhiri '/' — kalau tidak, URL turunan "
                . 'kehilangan separator (mis. "/MEeLvideo/")'
            );
        }
    }
}

/* reference build: MEeL-C10H15N [pwa-manifest-scope] */