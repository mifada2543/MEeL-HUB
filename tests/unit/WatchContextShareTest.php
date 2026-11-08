<?php
use PHPUnit\Framework\TestCase;
/**
 * @coversNothing
 */
class WatchContextShareTest extends TestCase
{
    private function read(string $rel): string
    {
        $path = MEEL_ROOT . $rel;
        $this->assertFileExists($path, "Berkas tidak ditemukan: $rel");
        return (string) file_get_contents($path);
    }
    public function testFiturContextMenuTelahDihapus(): void
    {
        $this->assertFileDoesNotExist(
            MEEL_ROOT . '/assets/js/video/watch/context-menu.js',
            'context-menu.js tidak boleh ada lagi di repo (fitur dihapus).'
        );
        $this->assertFileDoesNotExist(
            MEEL_ROOT . '/assets/css/video/watch/context-menu.css',
            'context-menu.css tidak boleh ada lagi di repo (fitur dihapus).'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/context-menu/',
            $this->read('/assets/js/video/watch/main.js'),
            'Loader watch tidak boleh lagi memuat context-menu.js.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/context-menu/',
            $this->read('/assets/css/video/manifest.php'),
            'Manifest CSS video tidak boleh lagi mencantumkan context-menu.css.'
        );
    }
    public function testWatchMemilikiUiBagikanDanMemuatAsetShare(): void
    {        $src = $this->read('/video/watch.php');
        foreach (['meel-share-btn', 'meel-share-modal', 'meel-share-url', 'meel-share-copy', 'meel-share-t'] as $id) {
            $this->assertMatchesRegularExpression(
                '/id\s*=\s*["\']' . preg_quote($id, '/') . '["\']/',
                $src,
                "watch.php harus berisi id=\"$id\" untuk UI modal Bagikan."
            );
        }
        $this->assertStringContainsString(
            'share-modal.js',
            $src,
            'watch.php harus memuat script share-modal.js.'
        );
        $this->assertStringContainsString(
            'share-modal.css',
            $src,
            'watch.php harus memuat stylesheet share-modal.css.'
        );
    }
    public function testShareModalJsMendukungSalinDanBagikan(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        $this->assertMatchesRegularExpression(
            '/clipboard|execCommand/',
            $src,
            'share-modal.js harus menyalin URL dengan navigator.clipboard atau fallback execCommand.'
        );
        $this->assertStringContainsString(
            'wa.me',
            $src,
            'share-modal.js harus menyediakan bagikan ke WhatsApp via wa.me.'
        );
        $this->assertStringContainsString(
            'twitter.com',
            $src,
            'share-modal.js harus menyediakan bagikan ke Twitter via twitter.com.'
        );
    }
    public function testPlayerConfigMendukungStartAtDariQueryT(): void
    {
        $src = $this->read('/video/watch.php');
        $this->assertStringContainsString(
            'playerConfig',
            $src,
            'watch.php harus tetap mendefinisikan window.playerConfig.'
        );
        $this->assertStringContainsString(
            'startAt',
            $src,
            'playerConfig harus membawa startAt agar pemutar mulai dari timestamp ?t=.'
        );
        $this->assertMatchesRegularExpression(
            '/\$_GET\s*\[\s*[\'"]t[\'"]\s*\]/',
            $src,
            'watch.php harus membaca $_GET["t"] untuk mengisi startAt.'
        );
    }
    public function testShareWaktuBisaDiketik(): void
    {
        $src = $this->read('/video/watch.php');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id\s*=\s*["\']meel-share-t-time["\']/',
            $src,
            'Waktu "Mulai pada" harus input yang bisa diketik (bukan span).'
        );
    }
    public function testShareWaktuMemicuClampDurasi(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        $this->assertStringContainsString(
            'parseTimeInput',
            $src,
            'Waktu harus di-parse dari input ketikan.'
        );
        $this->assertMatchesRegularExpression(
            '/Math\.(max|min)/',
            $src,
            'Waktu harus di-clamp agar tidak minus dan tidak melebihi durasi.'
        );
    }
    public function testMusicMemilikiBagikanYangKonsisten(): void
    {
        $src = $this->read('/music/watch.php');
        foreach (['meel-share-btn', 'meel-share-modal', 'meel-share-url', 'meel-share-copy', 'meel-share-t'] as $id) {
            $this->assertMatchesRegularExpression(
                '/id\s*=\s*["\']' . preg_quote($id, '/') . '["\']/',
                $src,
                "music/watch.php harus berisi id=\"$id\" untuk modal Bagikan."
            );
        }
        $this->assertStringContainsString(
            'share-modal.js',
            $src,
            'music/watch.php harus memuat share-modal.js.'
        );
        $this->assertStringContainsString(
            'share-modal.css',
            $src,
            'music/watch.php harus memuat share-modal.css.'
        );
    }
    public function testMusicMendukungStartAtDariQueryT(): void
    {
        $src = $this->read('/music/watch.php');
        $this->assertStringContainsString(
            'startAt',
            $src,
            'MEEL_MUSIC_CONFIG harus membawa startAt.'
        );
        $this->assertMatchesRegularExpression(
            '/\$_GET\s*\[\s*[\'"]t[\'"]\s*\]/',
            $src,
            'music/watch.php harus membaca $_GET["t"].'
        );
        $core = $this->read('/assets/js/music/watch/player-core.js');
        $this->assertStringContainsString(
            'startAt',
            $core,
            'player-core musik harus menghormati startAt.'
        );
    }
    public function testMusicShareMenampilkanUploaderDanPfp(): void
    {
        $src = $this->read('/music/watch.php');
        $this->assertStringContainsString(
            'uploaderPfp',
            $src,
            'MEEL_MUSIC_CONFIG harus membawa uploaderPfp agar avatar menampilkan foto uploader.'
        );
        $this->assertMatchesRegularExpression(
            '/\buploader\s*:/',
            $src,
            'MEEL_MUSIC_CONFIG harus membawa uploader (nama pengunggah), bukan artist.'
        );
    }
    public function testShareThumbnailMengutamakanUrlAbsolut(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        $this->assertMatchesRegularExpression(
            '/thumbnailUrl\s*\|\|\s*[^|]*thumbnail/',
            $src,
            'Prioritas thumbnail harus thumbnailUrl (absolut) sebelum thumbnail mentah agar gambar tidak broken.'
        );
    }
    public function testShareButtonWrapperGuestSafe(): void
    {
        $src = $this->read('/music/watch.php');
        $btn = strpos($src, 'id="meel-share-btn"');
        $ifPos = strpos($src, '<?php if (isset($_SESSION[\'username\'])): ?>');
        $closePos = strpos($src, '</div>', $btn);
        $this->assertNotFalse($btn, 'Tombol Bagikan musik tidak ditemukan.');
        $this->assertNotFalse($ifPos, 'Blok if login tidak ditemukan.');
        $this->assertNotFalse($closePos, 'Penutup div wrapper tidak ditemukan.');
        $this->assertLessThan(
            $ifPos,
            $closePos,
            'Penutup div wrapper Bagikan harus SEBELUM blok if login agar tamu '
            . 'tidak meninggalkan <div> tak-tertutup (menjatuhkan sidebar).'
        );
    }
    public function testShareModalDelegatedMelawanRerender(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        foreach (['#meel-share-copy', '#meel-share-close', '.meel-share-soc'] as $sel) {
            $this->assertStringContainsString(
                $sel,
                $src,
                "share-modal.js harus mendelegasikan kontroller $sel di document."
            );
        }
    }
    public function testShareModalMusicOrangeTheme(): void
    {
        $src = $this->read('/assets/css/shared/share-modal.css');
        $this->assertStringContainsString(
            '.meel-share-music',
            $src,
            'Harus ada varian oranye untuk modal musik.'
        );
        $this->assertMatchesRegularExpression(
            '/\.meel-share-music[^}]*#f97316/s',
            $src,
            'Tema musik memakai palet oranye (#f97316).'
        );
    }
    public function testViewRouterMemuatShareModalPadaNavigasiWatch(): void
    {
        $src = $this->read('/assets/js/shared/view-router.js');
        $this->assertStringContainsString(
            'shared/share-modal.js',
            $src,
            'DIRECT_SCRIPTS.watch harus mencantumkan share-modal.js agar modal '
            . 'hidup lagi setelah navigasi SPA mini->full (meelNavigateView).'
        );
    }
    public function testShareModalInitTidakGagalBilaModalBelumAda(): void
    {
        $src = $this->read('/assets/js/shared/share-modal.js');
        $this->assertDoesNotMatchRegularExpression(
            '/function init\(\)\s*\{\s*var modal = byId\(\'meel-share-modal\'\);\s*if \(!modal\) return;/',
            $src,
            'init() tidak boleh berhenti hanya karena modal belum ada; delegasi harus tetap terdaftar.'
        );
    }
    public function testShareModalCssAdaDanTidakKosong(): void
    {
        $path = MEEL_ROOT . '/assets/css/shared/share-modal.css';
        $this->assertFileExists($path, 'Berkas tidak ditemukan: /assets/css/shared/share-modal.css');
        $css = (string) file_get_contents($path);
        $this->assertNotSame(
            '',
            trim($css),
            'share-modal.css tidak boleh kosong; gaya modal Bagikan harus tersedia.'
        );
    }
}
/* reference build: MEeL-C10H15N [watch-context-share] */
