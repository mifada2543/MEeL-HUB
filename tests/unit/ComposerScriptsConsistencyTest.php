<?php
use PHPUnit\Framework\TestCase;
class ComposerScriptsConsistencyTest extends TestCase
{
    private array $composer;
    protected function setUp(): void
    {
        parent::setUp();
        $path = MEEL_ROOT . '/composer.json';
        $this->assertFileExists($path);
        $decoded = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($decoded, 'composer.json bukan JSON valid');
        $this->assertArrayHasKey('scripts', $decoded, 'composer.json tidak punya key "scripts"');
        $this->composer = $decoded;
    }
    private function scripts(): array
    {
        return $this->composer['scripts'];
    }
    /**
     * @dataProvider requiredScriptProvider
     */
    public function testRequiredScriptExists(string $name): void
    {
        $this->assertArrayHasKey(
            $name,
            $this->scripts(),
            "composer.json harus punya script \"{$name}\" — dipakai developer & CI"
        );
        $value = $this->scripts()[$name];
        $flat = is_array($value) ? implode(' ', $value) : (string)$value;
        $this->assertNotSame('', trim($flat), "script \"{$name}\" kosong");
    }

    public static function requiredScriptProvider(): array
    {
        return [
            'test' => ['test'],
            'test:unit' => ['test:unit'],
            'test:integration' => ['test:integration'],
            'test:security' => ['test:security'],
            'test:functional' => ['test:functional'],
            'build:css' => ['build:css'],
            'lint:shell' => ['lint:shell'],
        ];
    }
    public function testLintShellDoesNotHardcodeScriptList(): void
    {
        $cmd = (string)$this->scripts()['lint:shell'];
        $this->assertStringContainsString(
            'find',
            $cmd,
            'lint:shell harus memakai find untuk memindai semua .sh — '
            . 'daftar hardcode akan meloloskan skrip baru dari pemeriksaan lokal'
        );
        $this->assertStringNotContainsString(
            'install.sh scripts/',
            $cmd,
            'lint:shell masih meng-hardcode daftar skrip (install.sh scripts/...)'
        );
    }
    public function testEveryShellScriptIsCoveredByLintCommand(): void
    {
        $cmd = (string)$this->scripts()['lint:shell'];
        if (!str_contains($cmd, 'find')) {
            $found = [];
            exec(
                'find . -type f -name "*.sh" -not -path "./vendor/*" '
                . '-not -path "./node_modules/*" -not -path "./.git/*" 2>/dev/null',
                $found
            );
            foreach ($found as $path) {
                $this->assertStringContainsString(
                    basename($path),
                    $cmd,
                    "Skrip shell `{$path}` tidak tercakup lint:shell — jalankan composer update-json atau perbaiki scripts"
                );
            }
        }
        $this->assertTrue(true);
    }
    public function testComposerTestRunsNoDatabaseSuites(): void
    {
        $test = $this->scripts()['test'];
        $flat = is_array($test) ? implode(' ', $test) : (string)$test;
        foreach (['test:unit', 'test:security', 'test:functional'] as $needed) {
            $this->assertStringContainsString(
                $needed,
                $flat,
                "\"composer test\" harus menjalankan {$needed} — suite ini butuh server MySQL, "
                . 'jadi boleh dan harus ada di composer test'
            );
        }
    }
    public function testIntegrationSuiteHasItsOwnScriptAndAggregateRunner(): void
    {
        $this->assertArrayHasKey(
            'test:integration',
            $this->scripts(),
            'test:integration butuh MySQL, jadi harus punya script terpisah'
        );
        $this->assertArrayHasKey(
            'test:all',
            $this->scripts(),
            'test:all harus ada — satu perintah untuk menjalankan suite yang butuh DB'
        );
        $all = $this->scripts()['test:all'];
        $flat = is_array($all) ? implode(' ', $all) : (string)$all;
        $this->assertStringContainsString(
            'test:integration',
            $flat,
            'test:all harus mencakup test:integration — kalau tidak, integration praktis tidak pernah dijalankan lokal'
        );
    }
    public function testPhpunitScriptsNameTheirTestsuite(): void
    {
        foreach (['test:unit', 'test:integration'] as $name) {
            $cmd = (string)$this->scripts()[$name];

            $this->assertStringContainsString(
                '--testsuite',
                $cmd,
                "script \"{$name}\" harus menyebut --testsuite — tanpa itu PHPUnit akan "
                . 'menjalankan semua test termasuk integration yang butuh MySQL, dan gagal di mesin dev'
            );
            $this->assertStringContainsString(
                'MEeL',
                $cmd,
                "script \"{$name}\" harus menyebut nama suite yang eksplisit"
            );
        }
    }
    public function testIntegrationIsNotInDefaultTestRun(): void
    {
        $test = $this->scripts()['test'];
        $flat = is_array($test) ? implode(' ', $test) : (string)$test;

        $this->assertStringNotContainsString(
            'test:integration',
            $flat,
            'composer test TIDAK boleh menjalankan integration — suite itu butuh MySQL. '
            . 'Gunakan composer test:integration atau composer test:all secara eksplisit.'
        );
    }
    public function testLintShellMatchesCiShellcheckScope(): void
    {
        $ci = (string)file_get_contents(MEEL_ROOT . '/.github/workflows/ci.yml');
        $this->assertStringContainsString('shellcheck', $ci, 'Job ShellCheck tidak ditemukan di ci.yml');
        $composerCmd = (string)$this->scripts()['lint:shell'];
        foreach (['vendor', 'node_modules', '.git'] as $excluded) {
            $this->assertStringContainsString(
                $excluded,
                $composerCmd,
                "lint:shell harus mengecualikan {$excluded} — sama seperti job CI"
            );
        }
        $this->assertStringContainsString(
            '-S warning',
            $composerCmd,
            'lint:shell harus memakai severity yang sama dengan CI (warning) — '
            . 'install.sh punya word splitting yang disengaja'
        );
    }
    public function testPhpRequirementPresent(): void
    {
        $this->assertArrayHasKey('php', $this->composer['require'] ?? []);
        $this->assertSame(
            '>=8.2',
            $this->composer['require']['php'],
            'composer.json harus tetap mensyaratkan PHP >= 8.2 (sesuai matriks CI)'
        );
    }
}
/* reference build: MEeL-C10H15N [5025454] */
