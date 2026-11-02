<?php
use PHPUnit\Framework\TestCase;

/**
 * Upload thumbnail yang gagal di level PHP tidak boleh kembali tanpa pesan.
 * Kasus nyata: file melebihi upload_max_filesize, PHP mengisi
 * UPLOAD_ERR_INI_SIZE, lalu halaman edit hanya menampilkan sukses.
 *
 * @coversNothing
 */
class ThumbnailUploadErrorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once MEEL_ROOT . '/admin/includes/media_editor.php';
    }

    public function testUploadErrorMessagesAreExplicit(): void
    {
        $this->assertStringContainsString('terlalu besar', meel_upload_error_message(UPLOAD_ERR_INI_SIZE, 'File cover'));
        $this->assertStringContainsString('terlalu besar', meel_upload_error_message(UPLOAD_ERR_FORM_SIZE, 'File cover'));
        $this->assertStringContainsString('sebagian', meel_upload_error_message(UPLOAD_ERR_PARTIAL, 'File cover'));
        $this->assertStringContainsString('sementara', meel_upload_error_message(UPLOAD_ERR_NO_TMP_DIR, 'File cover'));
        $this->assertStringContainsString('disk', meel_upload_error_message(UPLOAD_ERR_CANT_WRITE, 'File cover'));
        $this->assertStringContainsString('ekstensi', meel_upload_error_message(UPLOAD_ERR_EXTENSION, 'File cover'));
    }

    public function testIniSizeErrorKeepsOldThumbnailAndReturnsMessage(): void
    {
        $files = [
            'thumbnail' => [
                'name' => 'cover-besar.jpg',
                'tmp_name' => '',
                'size' => 0,
                'error' => UPLOAD_ERR_INI_SIZE,
            ],
        ];

        $result = meel_editor_upload_thumbnail(
            $files,
            meel_editor_media_config('music'),
            'Judul Lagu',
            'music_default.png'
        );

        $this->assertSame('music_default.png', $result['url']);
        $this->assertNotSame('', $result['error']);
        $this->assertStringContainsString('terlalu besar', $result['error']);
    }

    public function testNoFileIsNotAnError(): void
    {
        $files = [
            'thumbnail' => [
                'name' => '',
                'tmp_name' => '',
                'size' => 0,
                'error' => UPLOAD_ERR_NO_FILE,
            ],
        ];

        $result = meel_editor_upload_thumbnail(
            $files,
            meel_editor_media_config('music'),
            'Judul Lagu',
            'music_default.png'
        );

        $this->assertSame('music_default.png', $result['url']);
        $this->assertSame('', $result['error']);
    }

    public function testPostMaxSizeIsDetectedWhenPhpDiscardsTheWholePost(): void
    {
        $oldPost = $_POST;
        $oldFiles = $_FILES;
        $oldMethod = $_SERVER['REQUEST_METHOD'] ?? null;
        $oldLength = $_SERVER['CONTENT_LENGTH'] ?? null;

        try {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SERVER['CONTENT_LENGTH'] = '9000000';
            $_POST = [];
            $_FILES = [];
            $this->assertTrue(meel_post_upload_too_large());

            $_POST = ['update' => '1'];
            $this->assertFalse(meel_post_upload_too_large());
        } finally {
            $_POST = $oldPost;
            $_FILES = $oldFiles;
            if ($oldMethod === null) {
                unset($_SERVER['REQUEST_METHOD']);
            } else {
                $_SERVER['REQUEST_METHOD'] = $oldMethod;
            }
            if ($oldLength === null) {
                unset($_SERVER['CONTENT_LENGTH']);
            } else {
                $_SERVER['CONTENT_LENGTH'] = $oldLength;
            }
        }
    }
}
