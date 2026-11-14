<?php
use PHPUnit\Framework\TestCase;

/** @coversNothing */
class AdminChatJsTest extends TestCase
{
    public function testAdminChatBehaviorHarness(): void
    {
        exec('node --version 2>&1', $version, $available);
        if ($available !== 0) {
            $this->markTestSkipped('Node.js tidak tersedia di lingkungan ini.');
        }
        $harness = MEEL_ROOT . '/tests/js/admin-chat.harness.js';
        exec('node ' . escapeshellarg($harness) . ' 2>&1', $output, $exit);
        $log = implode("\n", $output);
        $this->assertSame(0, $exit, $log);

        // Kode keluar 0 saja tidak cukup: harness yang tidak menjalankan
        // skenario pun keluar 0. Tiap skenario harus benar-benar hijau.
        $scenarios = ['polling', 'errors', 'readingDuringFetch', 'deleteDuringPoll', 'send', 'deletion', 'search', 'recent'];
        foreach ($scenarios as $scenario) {
            $this->assertStringContainsString('PASS ' . $scenario, $log, "Skenario harness gagal: {$scenario}");
        }
        $this->assertStringNotContainsString('FAIL', $log);
    }
}
