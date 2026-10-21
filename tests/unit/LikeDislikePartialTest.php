<?php
use PHPUnit\Framework\TestCase;

/**
 * Kontainer like/dislike — satu sumber markup.
 *
 * DULU
 * ----
 * Markup tombol like/dislike ditulis tiga kali: di `music/watch.php`,
 * `video/watch.php`, dan `controllers/api/like.php`. Ketiganya melenceng:
 * tombol simpan di halaman pakai ikon `list-plus` + teks "Simpan", sedangkan
 * salinan di API masih `plus` + "Playlist" dan warnanya beda. Karena
 * kontainer itu ditukar lewat `hx-swap="outerHTML"`, yang menang adalah
 * salinan API — sehingga begitu like/dislike diklik, tombolnya berubah
 * bentuk di depan mata.
 *
 * SEKARANG
 * --------
 * Semuanya dirender dari `partials/like_dislike.php`. Halaman dan API
 * memanggil partial yang sama, jadi markup yang dikirim di respons API
 * dijamin sama dengan yang ada di halaman.
 *
 * Test ini menahan supaya salinan tidak muncul lagi.
 *
 * @coversNothing
 */
class LikeDislikePartialTest extends TestCase
{
    private const PARTIAL = MEEL_ROOT . '/partials/like_dislike.php';

    private const CALLERS = [
        'music/watch.php',
        'video/watch.php',
        'controllers/api/like.php',
    ];

    /**
     * @return list<string>
     */
    private function productionPhpFiles(): array
    {
        $root = MEEL_ROOT;
        $skip = ['/vendor/', '/.git/', '/tests/', '/logs/', '/temp/', '/node_modules/'];

        $files = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', $f->getPathname());
            foreach ($skip as $s) {
                if (strpos($path, $s) !== false) {
                    continue 2;
                }
            }
            $files[] = substr($path, strlen($root) + 1);
        }
        sort($files);
        return $files;
    }

    /**
     * Markup kontainer hanya boleh ada di SATU berkas.
     */
    public function testTheContainerMarkupLivesInExactlyOneFile(): void
    {
        $holders = [];
        foreach ($this->productionPhpFiles() as $file) {
            $src = (string) file_get_contents(MEEL_ROOT . '/' . $file);
            if (str_contains($src, 'id="like-dislike-container"')) {
                $holders[] = $file;
            }
        }

        $this->assertSame(
            ['partials/like_dislike.php'],
            $holders,
            "Markup kontainer like/dislike harus hidup di satu berkas saja. "
                . "Salinan kedua akan melenceng dan menimpa markup halaman "
                . "setiap kali htmx menukar kontainer.\n"
                . 'Ditemukan di: ' . implode(', ', $holders)
        );
    }

    /**
     * Semua pemanggil harus memakai partial yang sama.
     */
    public function testEveryCallerUsesTheSharedPartial(): void
    {
        foreach (self::CALLERS as $file) {
            $src = (string) file_get_contents(MEEL_ROOT . '/' . $file);
            $this->assertStringContainsString(
                'partials/like_dislike.php',
                $src,
                "{$file} harus memakai partial bersama"
            );
        }
    }

    /**
     * Render partial dan kembalikan HTML-nya.
     */
    private function render(array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();
        include self::PARTIAL;
        return (string) ob_get_clean();
    }

    public function testThePartialRendersTheCanonicalSaveButton(): void
    {
        $html = $this->render([
            'like_dislike_media_id' => 50,
            'like_dislike_media_type' => 'music',
            'like_dislike_state' => '',
            'like_dislike_likes' => 3,
            'like_dislike_dislikes' => 0,
            'like_dislike_csrf' => 'tok123',
        ]);

        $this->assertStringContainsString('id="like-dislike-container"', $html);
        $this->assertStringContainsString('data-lucide="list-plus"', $html);
        $this->assertStringContainsString('Simpan', $html);
        $this->assertStringNotContainsString('data-lucide="plus"', $html);
        $this->assertStringNotContainsString('>Playlist<', $html);
        $this->assertStringContainsString('data-lucide="thumbs-up"', $html);
        $this->assertStringContainsString('data-lucide="thumbs-down"', $html);
    }

    public function testTheVideoVariantHasNoSaveButton(): void
    {
        $html = $this->render([
            'like_dislike_media_id' => 4,
            'like_dislike_media_type' => 'video',
            'like_dislike_state' => '',
            'like_dislike_likes' => 0,
            'like_dislike_dislikes' => 0,
            'like_dislike_csrf' => 'tok123',
        ]);

        $this->assertStringNotContainsString('data-lucide="list-plus"', $html);
        $this->assertStringContainsString('data-lucide="thumbs-up"', $html);
    }

    /**
     * Musik dan video boleh berbeda pada tiga hal yang disengaja: warna
     * aksen, label ("lagu"/"video"), dan tombol Simpan yang hanya ada di
     * musik. Sisanya harus identik — kalau tidak, lagi ada yang bisa
     * melenceng diam-diam.
     */
    public function testMusicAndVideoDifferOnlyInTheIntendedWays(): void
    {
        $base = [
            'like_dislike_media_id' => 7,
            'like_dislike_state' => 'like',
            'like_dislike_likes' => 2,
            'like_dislike_dislikes' => 1,
            'like_dislike_csrf' => 'tok123',
        ];

        $music = $this->render($base + ['like_dislike_media_type' => 'music']);
        $video = $this->render($base + ['like_dislike_media_type' => 'video']);

        $normalize = static function (string $html): string {
            $html = (string) preg_replace(
                '#<button onclick="document\.getElementById\(\'playlist-modal\'\).*?</button>#s',
                '',
                $html
            );
            $html = str_replace(
                ['bg-orange-500/15 border-orange-500/30 text-orange-400', 'bg-red-600/15 border-red-600/30 text-red-400'],
                'AKSEN',
                $html
            );
            $html = str_replace(
                ['"music"', '"video"', 'Suka lagu', 'Suka video', 'Tidak suka lagu', 'Tidak suka video'],
                'MEDIA',
                $html
            );
            return trim((string) preg_replace('/\s+/', ' ', $html));
        };

        $this->assertSame(
            $normalize($music),
            $normalize($video),
            'Markup musik dan video harus sama selain warna aksen'
        );
    }

    /**
     * Status aktif harus beda antara like dan dislike.
     */
    public function testActiveStateFollowsTheInteraction(): void
    {
        $like = $this->render([
            'like_dislike_media_id' => 7,
            'like_dislike_media_type' => 'music',
            'like_dislike_state' => 'like',
            'like_dislike_likes' => 1,
            'like_dislike_dislikes' => 0,
            'like_dislike_csrf' => 't',
        ]);
        $this->assertStringContainsString('fill-current', $like);
        $this->assertStringContainsString('bg-orange-500/15', $like);

        $dislike = $this->render([
            'like_dislike_media_id' => 7,
            'like_dislike_media_type' => 'music',
            'like_dislike_state' => 'dislike',
            'like_dislike_likes' => 0,
            'like_dislike_dislikes' => 1,
            'like_dislike_csrf' => 't',
        ]);
        $this->assertStringContainsString('bg-white/10 border-white/15 text-white', $dislike);
    }

    /**
     * Rate limit harus memakai partial yang sama, bukan markup terpisah.
     */
    public function testTheRateLimitedStateAlsoComesFromThePartial(): void
    {
        $html = $this->render([
            'like_dislike_media_id' => 7,
            'like_dislike_media_type' => 'music',
            'like_dislike_state' => '',
            'like_dislike_likes' => 0,
            'like_dislike_dislikes' => 0,
            'like_dislike_csrf' => 't',
            'like_dislike_rate_limit' => 30,
        ]);

        $this->assertStringContainsString('id="like-dislike-container"', $html);
        $this->assertStringContainsString('Tunggu 30d', $html);
        $this->assertStringNotContainsString('data-lucide="thumbs-up"', $html);
    }
}

/* reference build: MEeL-C7H25N12O [like-dislike-partial] */
