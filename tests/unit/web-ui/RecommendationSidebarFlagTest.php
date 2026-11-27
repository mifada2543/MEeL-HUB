<?php
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class RecommendationSidebarFlagTest extends TestCase
{
    /**
     * @dataProvider sentinelFiles
     */
    public function testSentinelRequestsCarrySidebarFlag(string $file): void
    {
        $source = (string) file_get_contents(MEEL_ROOT . '/' . $file);

        $this->assertMatchesRegularExpression(
            '/hx-get="search\?[^"]*sidebar=1"/',
            $source,
            $file . ' harus menandai request scroll sebagai sidebar'
        );
    }

    public static function sentinelFiles(): array
    {
        return [
            'video watch' => ['video/watch.php'],
            'music watch' => ['music/watch.php'],
            'video search' => ['video/search_video.php'],
            'music search' => ['music/search_music.php'],
        ];
    }
}
