<?php
use PHPUnit\Framework\TestCase;


class VideoThumbnailNamingTest extends TestCase
{
    private static function uploaderSource(): string
    {
        return (string) file_get_contents(MEEL_ROOT . '/modules/core/Uploader.php');
    }

    public function testThumbNamesDerivedFromUniqueFolderName(): void
    {
        $src = self::uploaderSource();

        $this->assertStringContainsString(
            '$sm_name = $folder_name . "_thumb_sm.webp";',
            $src,
            'Thumbnail kecil harus memakai folder_name yang unik'
        );
        $this->assertStringContainsString(
            '$t_name = $folder_name . "_thumb.webp";',
            $src,
            'Thumbnail kustom harus memakai folder_name yang unik'
        );
        $this->assertStringContainsString(
            '$thumb_name = $folder_name . "_thumb.webp";',
            $src,
            'Thumbnail hasil ffmpeg harus memakai folder_name yang unik'
        );

        $this->assertStringNotContainsString(
            '$clean_name . "_thumb',
            $src,
            'Nama thumbnail tidak boleh lagi diturunkan dari $clean_name'
        );
    }

    public function testTwoSameTitlesYieldDistinctFoldersAndThumbnails(): void
    {
        require_once MEEL_ROOT . '/modules/core/helpers/upload.php';

        $root = MEEL_ROOT . '/temp/thumb_naming_' . uniqid();
        $videoDir = $root . '/video/';
        @mkdir($videoDir, 0755, true);

        try {
            $base = 'same-title';
            $folder1 = meel_allocate_unique_dir($videoDir, $base);
            mkdir($videoDir . $folder1, 0755, true);
            $folder2 = meel_allocate_unique_dir($videoDir, $base);

            $this->assertNotSame($folder1, $folder2, 'Folder harus unik untuk judul yang sama');

            $thumb1 = $folder1 . '_thumb.webp';
            $thumb2 = $folder2 . '_thumb.webp';
            $this->assertNotSame($thumb1, $thumb2, 'Nama thumbnail harus ikut unik');
        } finally {
            if (is_dir($root)) {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($it as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($root);
            }
        }
    }
}

/* reference build: MEeL-C10H15N [02506250325] */
