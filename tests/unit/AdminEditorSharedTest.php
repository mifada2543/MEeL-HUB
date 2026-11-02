<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga penyatuan editor admin musik & video.
 *
 * `admin/edit-music.php` dan `admin/edit-video.php` dulunya salinan satu
 * sama lain, termasuk blok unggah thumbnail. Logikanya kini ada di
 * `admin/includes/media_editor.php`.
 *
 * Test ini menahan dua hal:
 *  1. Kode tidak boleh diduplikasi balik ke kedua halaman.
 *  2. Blok yang disatukan tidak boleh kehilangan pemeriksa keamanannya.
 *
 * @coversNothing
 */
class AdminEditorSharedTest extends TestCase
{
    private string $music;
    private string $video;
    private string $helper;
    private string $uploadHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->music = $this->read('admin/edit-music.php');
        $this->video = $this->read('admin/edit-video.php');
        $this->helper = $this->read('admin/includes/media_editor.php');
        $this->uploadHelper = $this->read('modules/core/helpers/upload.php');
    }

    private function read(string $rel): string
    {
        $path = MEEL_ROOT . '/' . $rel;
        $this->assertFileExists($path, $rel . ' tidak ada');
        return (string) file_get_contents($path);
    }

    public function testBothPagesUseTheSharedEditorHelper(): void
    {
        foreach (['edit-music.php' => $this->music, 'edit-video.php' => $this->video] as $page => $src) {
            $this->assertStringContainsString(
                "require_once __DIR__ . '/includes/media_editor.php';",
                $src,
                $page . ' harus memuat helper editor yang sama'
            );
            $this->assertStringContainsString(
                'meel_editor_media_config(',
                $src,
                $page . ' harus mengambil konfigurasi per jenis media'
            );
            $this->assertStringContainsString(
                'meel_editor_upload_thumbnail(',
                $src,
                $page . ' harus memakai helper unggah thumbnail yang sama'
            );
        }
    }

    /**
     * Blok unggah tidak boleh hidup lagi di halaman — kalau ia berduplikasi
     * lagi, validasi MIME dan pemanggilan ffmpeg akan punya dua salinan.
     */
    public function testThumbnailUploadBlockIsNotDuplicatedInThePages(): void
    {
        foreach (['edit-music.php' => $this->music, 'edit-video.php' => $this->video] as $page => $src) {
            $this->assertStringNotContainsString(
                "finfo_open(FILEINFO_MIME_TYPE)",
                $src,
                $page . ' tidak boleh memuat validasi MIME sendiri — itu harus di helper'
            );
            $this->assertStringNotContainsString(
                'exec($cmd, $out, $ret)',
                $src,
                $page . ' tidak boleh memanggil ffmpeg sendiri — itu harus di helper'
            );
            $this->assertStringNotContainsString(
                'meel_reserve_unique_filename(',
                $src,
                $page . ' tidak boleh memesan nama thumbnail sendiri — itu harus di helper'
            );
        }

        $this->assertStringNotContainsString(
            'exec(',
            $this->helper,
            'helper editor tidak boleh menjalankan ffmpeg/ImageMagick sendiri — konversi ada di meel_convert_image_to_webp()'
        );
        $this->assertStringContainsString(
            'meel_convert_image_to_webp(',
            $this->helper,
            'helper editor harus memakai satu jalur konversi bersama'
        );
        $this->assertSame(
            1,
            substr_count($this->uploadHelper, 'function meel_convert_image_to_webp('),
            'tidak boleh ada salinan meel_convert_image_to_webp di tempat lain'
        );
    }

    /**
     * Helper bersama tidak boleh kehilangan pemeriksa keamanan.
     */
    public function testSharedHelperKeepsTheUploadSafetyChecks(): void
    {
        $this->assertStringContainsString(
            'meel_image_mime_allowlist()',
            $this->helper,
            'helper harus memakai matriks MIME bersama, bukan daftar sendiri'
        );
        foreach (['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp', 'image/x-icon', 'image/vnd.microsoft.icon'] as $mime) {
            $this->assertStringContainsString(
                "'" . $mime . "'",
                $this->uploadHelper,
                'matriks MIME bersama harus memuat ' . $mime
            );
        }
        $this->assertStringContainsString(
            '5 * 1024 * 1024',
            $this->helper,
            'batas ukuran unggahan harus tetap ada di helper'
        );
        $this->assertGreaterThanOrEqual(
            2,
            substr_count($this->uploadHelper, 'escapeshellarg('),
            'path ke ffmpeg/ImageMagick dan berkas tujuan harus tetap di-escape'
        );
        $this->assertStringContainsString(
            "UPLOAD_ERR_OK",
            $this->helper,
            'unggahan hanya boleh diproses kalau UPLOAD_ERR_OK'
        );
    }

    /**
     * Both pages must keep their own redirect targets — the shared helper
     * derives the slug from the media type, and a wrong slug would send an
     * admin to the wrong editor.
     */
    public function testContextRedirectStillPointsAtTheRightPage(): void
    {
        $this->assertStringContainsString(
            "'music' ? 'edit-music' : 'edit-video'",
            $this->helper,
            'slug halaman harus diturunkan dari jenis media'
        );
        $this->assertStringContainsString(
            "meel_editor_context_redirect('music',",
            $this->music,
            'halaman musik harus memanggil helper dengan jenis music'
        );
        $this->assertStringContainsString(
            "meel_editor_context_redirect('video',",
            $this->video,
            'halaman video harus memanggil helper dengan jenis video'
        );
    }

    /**
     * Sidebar bersama.
     */
    public function testSharedSidebarPartialsAreUsed(): void
    {
        foreach (['edit-music.php' => $this->music, 'edit-video.php' => $this->video] as $page => $src) {
            $this->assertStringContainsString(
                "/partials/edit_media_uploader_card.php",
                $src,
                $page . ' harus memakai partial kartu uploader yang sama'
            );
            $this->assertStringContainsString(
                "/partials/edit_media_stats.php",
                $src,
                $page . ' harus memakai partial statistik yang sama'
            );
            $this->assertStringNotContainsString(
                '<div class="uploader-card">',
                $src,
                $page . ' tidak boleh menyalin markup kartu uploader secara inline'
            );
            $this->assertStringNotContainsString(
                '<div class="stats-strip">',
                $src,
                $page . ' tidak boleh menyalin markup statistik secara inline'
            );
        }
    }

    /**
     * Partial memakai `$record` supaya keduanya benar-benar sama, bukan
     * dua salinan yang kebetulan mirip.
     */
    public function testSharedPartialsUseOneRecordVariable(): void
    {
        foreach (['edit_media_uploader_card', 'edit_media_stats'] as $partial) {
            $src = $this->read('admin/partials/' . $partial . '.php');
            $this->assertStringContainsString(
                "\$record[",
                $src,
                $partial . ' harus memakai $record'
            );
            $this->assertStringNotContainsString(
                "\$music[",
                $src,
                $partial . ' tidak boleh memakai $music'
            );
            $this->assertStringNotContainsString(
                "\$video[",
                $src,
                $partial . ' tidak boleh memakai $video'
            );
        }
    }

    /**
     * Perilaku yang TIDAK sama antara kedua halaman harus tetap berbeda.
     *
     * Musik menyimpan perubahan walau unggah thumbnail gagal; video tidak
     * menyimpan apa pun kalau ada error. Perbedaan ini bukan disengaja saat
     * disatukan — ia muncul dari dua salinan yang terpisah lama. Menyatukan
     * kode tidak boleh diam-diam mengubahnya; kalau memang mau disamakan,
     * itu keputusan produk dan harus lewat review.
     */
    public function testTheSaveBehaviourDifferenceIsStillExplicit(): void
    {
        $this->assertStringContainsString(
            'if ($title === \'\') {',
            $this->music,
            'halaman musik harus menyimpan walau ada error'
        );
        $this->assertStringContainsString(
            "} elseif (\$error_message === '') {",
            $this->video,
            'halaman video harus membatalkan simpan kalau ada error'
        );
    }
}

/* reference build: MEeL-C7H14N2Q [admin-editor-shared] */
