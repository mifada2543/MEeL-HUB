<?php
use PHPUnit\Framework\TestCase;

class PhpVersionDocumentationTest extends TestCase
{
    public function testReadmeAdvertisesPhp82(): void
    {
        foreach (['README.md', 'README-en.md'] as $file) {
            $src = (string) file_get_contents(MEEL_ROOT . '/' . $file);
            $this->assertStringContainsString('PHP-8.2%2B', $src, $file . ' badge harus 8.2+');
            $this->assertStringContainsString('PHP 8.2+', $src, $file . ' harus menyebut PHP 8.2+');
            $this->assertStringNotContainsString('PHP-8.1%2B', $src, $file . ' badge lama masih ada');
            $this->assertStringNotContainsString('PHP 8.1+', $src, $file . ' klaim 8.1+ masih ada');
        }
    }
    public function testInstallerWarnsPhp82(): void
    {
        $src = '';
        foreach (['install.sh', 'lib/install/deps.sh'] as $file) {
            $path = MEEL_ROOT . '/' . $file;
            if (is_file($path)) {
                $src .= (string) file_get_contents($path) . "\n";
            }
        }
        $this->assertStringContainsString('PHP 8.2+', $src);
        $this->assertStringNotContainsString('PHP 8.1+', $src);
    }
    public function testDocsAndLandingPageDoNotClaimPhp81(): void
    {
        $checked = 0;
        foreach ([
            'docs/en/index.md',
            'docs/id/index.md',
            'docs/en/installation.md',
            'docs/id/installation.md',
            'index.html',
        ] as $file) {
            $path = MEEL_ROOT . '/' . $file;
            if (!is_file($path)) {
                fwrite(STDERR, "[skip] $file tidak ada (di-gitignore, hanya ada di mesin lokal)\n");
                continue;
            }
            $checked++;
            $src = (string) file_get_contents($path);
            $this->assertStringNotContainsString('PHP 8.1', $src, $file . ' masih menyebut PHP 8.1');
        }
        $this->assertGreaterThan(
            0,
            $checked,
            'Tidak ada satu pun file yang diperiksa — test ini akan selalu hijau tanpa makna'
        );
    }
    public function testComposerRequiresPhp82(): void
    {
        $json = json_decode((string) file_get_contents(MEEL_ROOT . '/composer.json'), true);
        $this->assertSame('>=8.2', $json['require']['php'] ?? null);
    }
}
/* reference build: MEeL-C10H15N [602550037] */