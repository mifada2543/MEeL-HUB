<?php

use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/core/Router.php';
/**
 * Keputusan pengalihan pada `MeelRouter`.
 * Test ini menahan:
 *  1. Aturan pengalihan profil tidak boleh berubah.
 *  2. Rute yang terdaftar harus menunjuk berkas yang benar-benar ada.
 *  3. `dispatch()` harus tetap pendek dan memakai helper yang dinamai.
 *
 * @coversNothing
 */
class RouterDispatchTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['u'], $_GET['tab']);
    }
    public function testProfileWithUsernameQueryRedirectsToTheCleanPath(): void
    {
        $_GET['u'] = 'john_doe';
        $this->assertSame(MeelRouter::basePath() . '/profile/john_doe',MeelRouter::profileAliasRedirect('profile'));
    }
    public function testProfileWithUsernameAndTabKeepsTheTab(): void
    {
        $_GET['u'] = 'john_doe';
        $_GET['tab'] = 'video';
        $this->assertSame(MeelRouter::basePath() . '/profile/john_doe?tab=video',MeelRouter::profileAliasRedirect('profile'));
    }
    /**
     * @dataProvider tabProvider
     */
    public function testOnlyTheThreeKnownTabsSurviveTheRedirect(string $tab): void
    {
        $_GET['u'] = 'john_doe';
        $_GET['tab'] = $tab;
        $expected = in_array($tab, ['all', 'video', 'music'], true)
            ? MeelRouter::basePath() . '/profile/john_doe?tab=' . $tab
            : MeelRouter::basePath() . '/profile/john_doe';
        $this->assertSame($expected, MeelRouter::profileAliasRedirect('profile'));
    }
    public static function tabProvider(): array
    {
        return [
            'all' => ['all'],
            'video' => ['video'],
            'music' => ['music'],
            'tidak dikenal' => ['bogus'],
            'kosong' => [''],
            'huruf besar' => ['VIDEO'],
            'pakai spasi' => [' video'],
        ];
    }
    public function testUsernameIsUrlEncoded(): void
    {
        $_GET['u'] = 'a b&c';
        $this->assertSame(
            MeelRouter::basePath() . '/profile/a%20b%26c',
            MeelRouter::profileAliasRedirect('profile')
        );
    }
    public function testEmptyUsernameIsNotAnAlias(): void
    {
        $_GET['u'] = '';
        $this->assertNull(MeelRouter::profileAliasRedirect('profile'));
    }
    public function testProfileWithoutUsernameIsNotAnAlias(): void
    {
        $this->assertNull(MeelRouter::profileAliasRedirect('profile'));
    }
    /**
     * @dataProvider tabInPathProvider
     */
    public function testTabInsideThePathRedirectsToTheQueryForm(string $path, bool $isAlias): void
    {
        $result = MeelRouter::profileAliasRedirect($path);
        if (!$isAlias) {
            $this->assertNull($result, "{$path} bukan alias profil");
            return;
        }
        $this->assertNotNull($result);
        $this->assertStringContainsString('/profile/', (string) $result);
        $this->assertStringContainsString('tab=', (string) $result);
    }
    public static function tabInPathProvider(): array
    {
        return [
            'video' => ['profile/john_doe/video', true],
            'all' => ['profile/john_doe/all', true],
            'music' => ['profile/john_doe/music', true],
            'tab tak dikenal' => ['profile/john_doe/bogus', false],
            'terlalu dalam' => ['profile/john_doe/video/extra', false],
            'tanpa username' => ['profile//video', false],
        ];
    }
    public function testOrdinaryPathsAreNotAliases(): void
    {
        foreach (['', 'video/watch', 'music/playlist', 'admin/edit-video', 'profile/manage'] as $path) {
            $this->assertNull(
                MeelRouter::profileAliasRedirect($path),
                "'{$path}' bukan alias profil"
            );
        }
    }
    public function testEveryRegisteredRoutePointsAtAFileThatExists(): void
    {
        $root = MEEL_ROOT;
        $missing = [];
        foreach (['ROUTES', 'OPTIONAL_ROUTES'] as $constant) {
            $routes = (new ReflectionClass(MeelRouter::class))->getConstant($constant);
            $this->assertIsArray($routes, "{$constant} harus berupa array");
            foreach ($routes as $path => $route) {
                $handler = $root . '/' . $route['handler'];
                if (!is_file($handler)) {
                    $missing[] = "{$path} -> {$route['handler']}";
                }
            }
        }
        $this->assertSame([], $missing, "Rute menunjuk berkas yang tidak ada:\n" . implode("\n", $missing));
    }
    public function testDispatchStaysShortAndDelegatesToNamedHelpers(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/Router.php');
        foreach (['profileAliasRedirect', 'guardOptionalRoute', 'runHandler'] as $helper) {
            $this->assertStringContainsString('self::' . $helper . '(',$src,"dispatch() harus memakai helper {$helper}");
        }
        $method = new ReflectionMethod(MeelRouter::class, 'dispatch');
        $lines = $method->getEndLine() - $method->getStartLine() + 1;
        $this->assertLessThanOrEqual(25,$lines,'dispatch() masih terlalu panjang: ' . $lines . ' baris');
    }
    public function testOptionalRouteGuardsAreStillDistinct(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/modules/core/Router.php');
        $this->assertStringContainsString('Modules::guardJson404();', $src);
        $this->assertStringContainsString("Modules::guardRedirect('arcade');", $src);
        $this->assertStringContainsString("private const OPTIONAL_API_PREFIXES = ['arcade/rhythm/api/'];",$src);
    }
}
/* reference build: MEeL-C5H22N9O [router-dispatch] */
