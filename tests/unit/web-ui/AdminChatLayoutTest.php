<?php
use PHPUnit\Framework\TestCase;

/** @coversNothing */
class AdminChatLayoutTest extends TestCase
{
    private function render(bool $conversation, string $base = '', bool $admin = true, ?string $back = null): DOMXPath
    {
        $server = $_SERVER;
        $get = $_GET;
        $_SERVER['DOCUMENT_ROOT'] = $base === '' ? MEEL_ROOT : dirname(MEEL_ROOT);
        $_SERVER['SCRIPT_NAME'] = $base . '/admin/chat.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
        $_SERVER['REQUEST_URI'] = $base . '/admin/chat/Example';
        $_GET = $conversation ? ['username' => 'Example'] : [];
        require_once MEEL_ROOT . '/modules/core/Modules.php';
        $cache = new ReflectionProperty(Modules::class, 'cache');
        $cache->setAccessible(true);
        $oldCache = $cache->getValue();
        $cache->setValue(null, ['enabled:arcade' => false]);
        $conn = new class {
            public function prepare($sql) {
                return new class {
                    public function bind_param($type, &$value) {}
                    public function execute() {}
                    public function close() {}
                    public function get_result() {
                        return new class {
                            public function fetch_assoc() {
                                return ['id' => 42, 'username' => 'Example', 'role' => 'user', 'profile_picture' => 'missing.png'];
                            }
                        };
                    }
                };
            }
        };
        ob_start();
        try {
            if ($admin) {
                // Exclude only authentication/config bootstrap; execute the actual template and user lookup.
                $source = file_get_contents(MEEL_ROOT . '/admin/chat.php');
                $source = substr($source, strpos($source, "define('MEEL_ADMIN_CONTEXT'"));
                $source = preg_replace('/define\(\'MEEL_ADMIN_CONTEXT\', true\);/', '', $source, 1);
                eval(str_replace('__DIR__', var_export(MEEL_ROOT . '/admin', true), $source));
            } else {
                $is_admin = false;
                $back_url = $back;
                include MEEL_ROOT . '/admin/header-admin.php';
            }
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
            $_SERVER = $server;
            $_GET = $get;
            $cache->setValue(null, $oldCache);
        }
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return new DOMXPath($dom);
    }

    public function testNestedConversationAssetsAndAdminNavigationAreRootAnchored(): void
    {
        foreach (['', '/MEeL'] as $base) {
            $dom = $this->render(true, $base);
            foreach ($dom->query('//link[@rel="stylesheet"] | //script[@src]') as $asset) {
                $url = $asset->getAttribute($asset->tagName === 'link' ? 'href' : 'src');
                $this->assertStringStartsWith($base . '/assets/', $url);
                $this->assertLessThanOrEqual(1, substr_count($url, '?v='));
                $this->assertStringNotContainsString('?v=?v=', $url);
            }
            $links = $dom->query('//nav//a');
            $this->assertSame($base . '/', $links->item(0)->getAttribute('href'));
            $this->assertSame($base . '/admin/beranda', $links->item(1)->getAttribute('href'));
            $this->assertSame($base . '/admin/beranda', $links->item(2)->getAttribute('href'));
            $this->assertSame(1, $dom->query('//head/title')->length);
            $this->assertSame('Obrolan Admin | MEeL', $dom->evaluate('string(//head/title)'));
        }
    }

    public function testConversationHasAccessibleComposerAndRealAvatarFallback(): void
    {
        $dom = $this->render(true);
        $this->assertSame(1, $dom->query('//body/*[@id="admin-chat-data"]')->length);
        $this->assertSame('/api/chat', $dom->evaluate('string(//*[@id="admin-chat-data"]/@data-api-base)'));
        $this->assertSame('/admin/chat', $dom->evaluate('string(//*[@id="admin-chat-data"]/@data-chat-root)'));
        $this->assertSame(1, $dom->query('//main//*[@id="chat-messages"]')->length);
        $this->assertSame('5000', $dom->evaluate('string(//textarea[@id="chat-input"]/@maxlength)'));
        $this->assertNotSame('', $dom->evaluate('string(//label[@for="chat-input"])'));
        $this->assertNotSame('', $dom->evaluate('string(//button[@id="chat-send-btn"]/@aria-label)'));
        $this->assertSame(1, $dom->query('//*[@id="chat-status"][@role="status"][@aria-live="polite"]')->length);
        $this->assertSame(1, $dom->query('//*[contains(@class,"chat-avatar")]/img/following-sibling::*[1]')->length);
    }

    public function testListKeepsSearchAndRecentResultsInsideMain(): void
    {
        $dom = $this->render(false);
        foreach (['user-search', 'user-results', 'recent-list'] as $id) {
            $this->assertSame(1, $dom->query('//main//*[@id="' . $id . '"]')->length);
        }
        $this->assertNotSame('', $dom->evaluate('string(//label[@for="user-search"])'));
    }

    public function testNonAdminExplicitProfileBackLinkIsNotRewritten(): void
    {
        $dom = $this->render(false, '/MEeL', false, '../profile/Example?tab=music');
        $this->assertSame('../profile/Example?tab=music', $dom->evaluate('string(//a[contains(normalize-space(.), "Kembali")]/@href)'));
    }

    public function testMissingUserAndLegacyRedirectsStayWithinTheDeploymentRoot(): void
    {
        if (!function_exists('AdminChatLayoutFixture\\header')) {
            // Capture the HTTP boundary before the template's exit, without changing its redirect logic.
            eval('namespace AdminChatLayoutFixture; function header($value, $replace = true, $code = 302) { throw new \\RuntimeException($value, $code); }');
        }
        $server = $_SERVER;
        $get = $_GET;
        try {
            foreach (['', '/MEeL'] as $base) {
                $_SERVER['DOCUMENT_ROOT'] = $base === '' ? MEEL_ROOT : dirname(MEEL_ROOT);
                foreach ([
                    [['username' => 'Missing'], null, '/admin/chat', 302],
                    [['user_id' => '42'], ['username' => 'A B/Ü'], '/admin/chat/A%20B%2F%C3%9C', 301],
                    [['user_id' => '-1'], null, '/admin/chat', 302],
                ] as [$query, $row, $destination, $status]) {
                    $_GET = $query;
                    $conn = new class($row) {
                        private $row;
                        public function __construct($row) { $this->row = $row; }
                        public function prepare($sql) { return $this; }
                        public function bind_param($type, &$value) {}
                        public function execute() {}
                        public function close() {}
                        public function get_result() { return $this; }
                        public function fetch_assoc() { return $this->row; }
                    };
                    $source = file_get_contents(MEEL_ROOT . '/admin/chat.php');
                    $source = substr($source, strpos($source, "define('MEEL_ADMIN_CONTEXT'"));
                    $source = preg_replace('/define\(\'MEEL_ADMIN_CONTEXT\', true\);/', '', $source, 1);
                    try {
                        eval('namespace AdminChatLayoutFixture; ' . str_replace('__DIR__', var_export(MEEL_ROOT . '/admin', true), $source));
                        $this->fail('Expected a redirect before rendering the chat page.');
                    } catch (RuntimeException $redirect) {
                        $this->assertSame('Location: ' . $base . $destination, $redirect->getMessage());
                        $this->assertSame($status, $redirect->getCode());
                    }
                }
            }
        } finally {
            $_SERVER = $server;
            $_GET = $get;
        }
    }
}
