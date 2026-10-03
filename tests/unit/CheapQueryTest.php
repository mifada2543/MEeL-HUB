<?php

use PHPUnit\Framework\TestCase;

/* reference build: MEeL-C1H1N1O2 [2f6a0d5b8c41e937] */

class CheapQueryTest extends TestCase
{
    private function source(string $rel): string
    {
        $path = MEEL_ROOT . '/' . $rel;
        $src = @file_get_contents($path);
        if ($src === false) {
            $this->fail("File tidak terbaca: $rel");
        }
        return (string) $src;
    }

    public function testCountActiveQueuesExistsAndIsPublic(): void
    {
        require_once MEEL_ROOT . '/modules/core/System.php';
        $this->assertTrue(
            method_exists('System', 'countActiveQueues'),
            'System::countActiveQueues() harus ada'
        );
    }

    public function testIsServerBusyDoesNotCallGetActiveQueues(): void
    {
        $src = $this->source('modules/core/System.php');

        $isBusy = $this->methodBody($src, 'isServerBusy');
        $this->assertStringNotContainsString(
            'getActiveQueues',
            $isBusy,
            'isServerBusy() harus pakai COUNT(*), bukan menarik seluruh baris antrean'
        );
        $this->assertStringContainsString('countActiveQueues', $isBusy);
    }

    public function testCountActiveQueuesUsesCountNotRowFetch(): void
    {
        $src = $this->source('modules/core/System.php');
        $body = $this->methodBody($src, 'countActiveQueues');

        $this->assertStringContainsString('COUNT(*)', $body, 'harus menghitung, bukan menarik baris');
        $this->assertStringNotContainsString('JOIN users', $body, 'COUNT tidak butuh JOIN');
        $this->assertStringNotContainsString('ORDER BY', $body, 'COUNT tidak butuh pengurutan');
    }

    public function testMediaViewerConstructorUsesCachedHelpers(): void
    {
        $src = $this->source('modules/media/MediaViewer.php');
        $ctor = $this->methodBody($src, '__construct');

        $this->assertStringNotContainsString(
            'SELECT is_active, role FROM users',
            $ctor,
            'constructor MediaViewer tidak boleh query users langsung (T12) — pakai helper cached'
        );
        $this->assertStringContainsString('get_user_role', $ctor);
        $this->assertStringContainsString('get_user_active', $ctor);
    }

    public function testGetUserActiveIsCachedAndReturnsBool(): void
    {
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';

        $this->assertTrue(function_exists('get_user_active'));

        $src = $this->source('modules/auth/helpers/user.php');
        $body = $this->methodBody($src, 'get_user_active');

        $this->assertStringContainsString(
            'static $cache',
            $body,
            'helper harus cached — tanpa itu, T12 justru menambah query'
        );
        $this->assertMatchesRegularExpression(
            '/function\s+get_user_active\s*\([^)]*\)\s*:\s*bool/',
            $src,
            'return type bool, supaya pemanggil tidak perlu membandingkan string'
        );
        $this->assertStringContainsString('LIMIT 1', $body);
        $this->assertStringContainsString('$stmt->close()', $body);
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)\s*(?::\s*[^\{]+)?\{/';
        if (!preg_match($pattern, $src, $m, PREG_OFFSET_CAPTURE)) {
            $this->fail("Method $method() tidak ditemukan");
        }
        $start = $m[0][1] + strlen($m[0][0]);
        $depth = 1;
        $len = strlen($src);
        for ($i = $start; $i < $len && $depth > 0; $i++) {
            if ($src[$i] === '{') $depth++;
            elseif ($src[$i] === '}') $depth--;
        }
        return substr($src, $start, $i - $start - 1);
    }
}
