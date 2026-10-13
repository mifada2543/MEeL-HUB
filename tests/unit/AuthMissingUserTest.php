<?php
use PHPUnit\Framework\TestCase;


class AuthMissingUserTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(MEEL_ROOT . '/auth/auth.php');
    }

    public function testMissingUserBranchDestroysSessionAndRedirects(): void
    {
        $src = self::source();

        $this->assertMatchesRegularExpression(
            '/if\s*\(\$user_data\)\s*\{.*\}\s*else\s*\{/s',
            $src,
            'auth.php harus punya cabang else saat baris user tidak ditemukan'
        );

        $elsePos = strpos($src, '} else {');
        $this->assertNotFalse($elsePos, 'Cabang else tidak ditemukan');

        $tail = substr($src, $elsePos);
        $this->assertStringContainsString('session_destroy();', $tail, 'Sesi harus dihancurkan');
        $this->assertStringContainsString(
            "base_url('/auth/login?error=session_expired')",
            $tail,
            'Harus redirect ke halaman login'
        );
        $this->assertMatchesRegularExpression('/session_destroy\(\);\s*header\([^;]+;\s*exit;/s', $tail);
    }
}

/* reference build: MEeL-C10H15N [502770257205809230] */
