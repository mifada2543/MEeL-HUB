<?php
/**
 * ConfigPatcherTest - Unit tests for ConfigPatcher
 * Run: php lib/install/php/ConfigPatcherTest.php
 */

require_once __DIR__ . '/ConfigPatcher.php';

use MEeL\Install\ConfigPatcher;

$passed = 0;
$failed = 0;

function assert_true(string $test, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✅ $test\n";
        $passed++;
    } else {
        echo "  ❌ $test\n";
        $failed++;
    }
}

function assert_equals(string $test, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        echo "  ✅ $test\n";
        $passed++;
    } else {
        echo "  ❌ $test — expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . "\n";
        $failed++;
    }
}

function assert_throws(string $test, callable $fn, string $expectedMessage = ''): void
{
    global $passed, $failed;
    try {
        $fn();
        echo "  ❌ $test — expected exception but none thrown\n";
        $failed++;
    } catch (\Throwable $e) {
        if ($expectedMessage === '' || str_contains($e->getMessage(), $expectedMessage)) {
            echo "  ✅ $test\n";
            $passed++;
        } else {
            echo "  ❌ $test — wrong message: " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

// ─── Test setup ───
$tempDir = sys_get_temp_dir() . '/meel_config_patcher_test_' . uniqid();
mkdir($tempDir, 0755, true);

// Create a mock settings.example.php
$template = <<<'PHP'
<?php
// auth/settings.example.php

$server   = "localhost";
$username = "root";
$password = "";
$db       = "MEeL";

define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');
define('MEEL_HDD_VIDEO_UPLOAD', MEEL_HDD_BASE . '/video/upload/');
define('MEEL_HDD_VIDEO_DIR', MEEL_HDD_VIDEO_UPLOAD . 'video/');
define('MEEL_HDD_THUMB_DIR', MEEL_HDD_VIDEO_UPLOAD . 'thumbnail/');
define('MEEL_HDD_MUSIC_UPLOAD', MEEL_HDD_BASE . '/music/upload/');
define('MEEL_HDD_BOOKS_UPLOAD', MEEL_HDD_BASE . '/books/upload/');
define('MEEL_HDD_DRIVE', MEEL_HDD_BASE . '/drive/');

// define('MEEL_ENV', 'production');
// define('APP_DEBUG', false);

define('MEEL_FFMPEG_PATH', '');
define('MEEL_FFPROBE_PATH', '');
define('MEEL_NODE_PATH', '');
define('MEEL_YTDLP_PATH', '');

define('MEEL_TRUST_PROXY_HEADERS', false);
define('MEEL_TRUSTED_PROXIES', '127.0.0.1,::1');

define('MEEL_USE_XSENDFILE', false);

define('MEEL_CANONICAL_HOST', '');
define('MEEL_TRUSTED_HOSTS', 'localhost,127.0.0.1,::1');

define('MEEL_MAX_DOWNLOAD_BYTES', 2147483648);
define('MEEL_MAX_DOWNLOAD_SECONDS', 7200);
define('MEEL_MAX_CONCURRENT_QUEUES', 2);
PHP;

$templatePath = $tempDir . '/settings.example.php';
$targetPath = $tempDir . '/settings.php';
file_put_contents($templatePath, $template);
copy($templatePath, $targetPath);

echo "🧪 ConfigPatcher Tests\n";
echo "Temp dir: $tempDir\n\n";

// ─── Test 1: Basic load and patchDatabase ───
echo "Test 1: patchDatabase()\n";
$patcher = new ConfigPatcher($targetPath);
$patcher->patchDatabase([
    'host' => 'db.example.com',
    'user' => 'meel_user',
    'pass' => 'secret123',
    'name' => 'meel_db',
])->apply();

$content = file_get_contents($targetPath);
assert_equals('patchDatabase $server', 'db.example.com', extract_var($content, 'server'));
assert_equals('patchDatabase $username', 'meel_user', extract_var($content, 'username'));
assert_equals('patchDatabase $password', 'secret123', extract_var($content, 'password'));
assert_equals('patchDatabase $db', 'meel_db', extract_var($content, 'db'));

// ─── Test 2: patchHddBase ───
echo "\nTest 2: patchHddBase()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchHddBase('/mnt/meel/media')->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_HDD_BASE', '/mnt/meel/media', extract_define($content, 'MEEL_HDD_BASE'));
// Derived constants use concatenation in template, so they're NOT patched (computed at runtime)
assert_equals('MEEL_HDD_VIDEO_UPLOAD (concatenation)', 'MEEL_HDD_BASE . \'/video/upload/\'', extract_define_raw($content, 'MEEL_HDD_VIDEO_UPLOAD'));
assert_equals('MEEL_HDD_VIDEO_DIR (concatenation)', 'MEEL_HDD_VIDEO_UPLOAD . \'video/\'', extract_define_raw($content, 'MEEL_HDD_VIDEO_DIR'));

// ─── Test 3: patchEnvironment ───
echo "\nTest 3: patchEnvironment()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchEnvironment('development', true)->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_ENV', 'development', extract_define($content, 'MEEL_ENV'));
assert_equals('APP_DEBUG', 'true', extract_define_raw($content, 'APP_DEBUG'));

// Test production/false
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchEnvironment('production', false)->apply();
$content = file_get_contents($targetPath);
assert_equals('MEEL_ENV (prod)', 'production', extract_define($content, 'MEEL_ENV'));
assert_equals('APP_DEBUG (false)', 'false', extract_define_raw($content, 'APP_DEBUG'));

// ─── Test 4: patchBinaries ───
echo "\nTest 4: patchBinaries()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchBinaries([
    'ffmpeg' => '/usr/bin/ffmpeg',
    'ffprobe' => '/usr/bin/ffprobe',
    'node' => '/usr/bin/node',
    'ytdlp' => '/usr/local/bin/yt-dlp',
])->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_FFMPEG_PATH', '/usr/bin/ffmpeg', extract_define($content, 'MEEL_FFMPEG_PATH'));
assert_equals('MEEL_FFPROBE_PATH', '/usr/bin/ffprobe', extract_define($content, 'MEEL_FFPROBE_PATH'));
assert_equals('MEEL_NODE_PATH', '/usr/bin/node', extract_define($content, 'MEEL_NODE_PATH'));
assert_equals('MEEL_YTDLP_PATH', '/usr/local/bin/yt-dlp', extract_define($content, 'MEEL_YTDLP_PATH'));

// ─── Test 5: patchProxy ───
echo "\nTest 5: patchProxy()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchProxy(true, ['10.0.0.1', '192.168.1.1'])->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_TRUST_PROXY_HEADERS', 'true', extract_define_raw($content, 'MEEL_TRUST_PROXY_HEADERS'));
assert_equals('MEEL_TRUSTED_PROXIES', '10.0.0.1,192.168.1.1', extract_define($content, 'MEEL_TRUSTED_PROXIES'));

// ─── Test 6: patchXSendfile ───
echo "\nTest 6: patchXSendfile()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchXSendfile(true)->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_USE_XSENDFILE', 'true', extract_define_raw($content, 'MEEL_USE_XSENDFILE'));

// ─── Test 7: patchCanonicalHost ───
echo "\nTest 7: patchCanonicalHost()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchCanonicalHost('meel.example.com', 'meel.example.com,localhost')->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_CANONICAL_HOST', 'meel.example.com', extract_define($content, 'MEEL_CANONICAL_HOST'));
assert_equals('MEEL_TRUSTED_HOSTS', 'meel.example.com,localhost', extract_define($content, 'MEEL_TRUSTED_HOSTS'));

// ─── Test 8: patchRateLimits ───
echo "\nTest 8: patchRateLimits()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchRateLimits([
    'max_download_bytes' => 5368709120,
    'max_download_seconds' => 14400,
    'max_concurrent_queues' => 4,
])->apply();

$content = file_get_contents($targetPath);
assert_equals('MEEL_MAX_DOWNLOAD_BYTES', '5368709120', extract_define($content, 'MEEL_MAX_DOWNLOAD_BYTES'));
assert_equals('MEEL_MAX_DOWNLOAD_SECONDS', '14400', extract_define($content, 'MEEL_MAX_DOWNLOAD_SECONDS'));
assert_equals('MEEL_MAX_CONCURRENT_QUEUES', '4', extract_define($content, 'MEEL_MAX_CONCURRENT_QUEUES'));

// ─── Test 8b: patchRateLimits() with guarded/expression defines ───
echo "\nTest 8b: patchRateLimits() with expression defines\n";
$expressionPath = $tempDir . '/settings-expression.php';
file_put_contents($expressionPath, <<<'PHP'
<?php
if (!defined('MEEL_MAX_DOWNLOAD_BYTES')) {
    define('MEEL_MAX_DOWNLOAD_BYTES', 2 * 1024 * 1024 * 1024);
}
if (!defined('MEEL_MAX_DOWNLOAD_SECONDS')) {
    define('MEEL_MAX_DOWNLOAD_SECONDS', 2 * 60 * 60);
}
define('MEEL_MAX_CONCURRENT_QUEUES', 2);
PHP);

$patcher = new ConfigPatcher($expressionPath);
$patcher->patchRateLimits([
    'max_download_bytes' => 5368709120,
    'max_download_seconds' => 14400,
    'max_concurrent_queues' => 4,
])->apply();

$content = file_get_contents($expressionPath);
assert_equals('MEEL_MAX_DOWNLOAD_BYTES (expression)', '5368709120', extract_define($content, 'MEEL_MAX_DOWNLOAD_BYTES'));
assert_equals('MEEL_MAX_DOWNLOAD_SECONDS (expression)', '14400', extract_define($content, 'MEEL_MAX_DOWNLOAD_SECONDS'));
assert_equals('MEEL_MAX_CONCURRENT_QUEUES (expression)', '4', extract_define($content, 'MEEL_MAX_CONCURRENT_QUEUES'));

// ─── Test 9: verify() method ───
echo "\nTest 9: verify()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchDatabase(['host' => 'localhost', 'user' => 'root', 'pass' => '', 'name' => 'MEeL'])
        ->patchHddBase('/tmp/test')
        ->patchEnvironment('production', false)
        ->apply();

assert_true('verify() returns true', $patcher->verify());

// ─── Test 10: fromTemplate() static factory ───
echo "\nTest 10: fromTemplate()\n";
$newTarget = $tempDir . '/settings2.php';
$patcher = ConfigPatcher::fromTemplate($templatePath, $newTarget);
assert_true('fromTemplate creates file', is_file($newTarget));

// ─── Test 11: Dry-run mode ───
echo "\nTest 11: Dry-run mode\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath, dryRun: true);
$patcher->patchDatabase(['host' => 'dryrun.db', 'user' => 'u', 'pass' => 'p', 'name' => 'd'])
        ->apply();
$content = file_get_contents($targetPath);
// In dry-run, file should NOT be modified
assert_equals('dry-run does not modify file', 'localhost', extract_var($content, 'server'));

// ─── Test 12: Error handling - missing define ───
echo "\nTest 12: Error handling\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
// Test via public method that calls patchDefine internally
assert_throws('patchHddBase throws on missing constant', function() use ($patcher) {
    // Use reflection to test private method, or test via a constant that doesn't exist
    $reflection = new ReflectionClass($patcher);
    $method = $reflection->getMethod('patchDefine');
    $method->setAccessible(true);
    $method->invoke($patcher, 'NONEXISTENT_CONSTANT', 'value');
    $patcher->apply();
}, 'not found');

// ─── Test 13: Special characters in values ───
echo "\nTest 13: Special characters escaping\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
// In PHP double-quoted strings: \$ = $, \" = ", \' = '
$testPass = "pa\$s'w\"ord";  // Evaluates to: pa$s'w"ord
$testUser = "user's";        // Evaluates to: user's
$patcher->patchDatabase([
    'host' => "localhost",
    'user' => $testUser,
    'pass' => $testPass,
    'name' => "db",
])->apply();

$content = file_get_contents($targetPath);
// Debug: show what was actually written
$actualPass = extract_var($content, 'password');
$actualUser = extract_var($content, 'username');
echo "  DEBUG: password input='$testPass' stored='$actualPass'\n";
echo "  DEBUG: username input='$testUser' stored='$actualUser'\n";

// In single-quoted PHP strings, only \ and ' are escaped
$expectedPass = "pa\$s'w\"ord";  // $ and " are literal, ' is escaped
$expectedUser = "user's";        // ' is escaped to \' in storage, unescaped back to '
assert_equals('escaped password', $expectedPass, $actualPass);
assert_equals('escaped username', $expectedUser, $actualUser);

// ─── Test 14: Chained calls ───
echo "\nTest 14: Method chaining\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$result = $patcher->patchDatabase(['host' => 'a', 'user' => 'b', 'pass' => 'c', 'name' => 'd'])
    ->patchHddBase('/x')
    ->patchEnvironment('production', false);

assert_true('chaining returns self', $result === $patcher);

// ─── Test 15: getChanges() ───
echo "\nTest 15: getChanges()\n";
copy($templatePath, $targetPath);
$patcher = new ConfigPatcher($targetPath);
$patcher->patchDatabase(['host' => 'changetest', 'user' => 'u', 'pass' => 'p', 'name' => 'd'])
        ->apply();
$changes = $patcher->getChanges();
assert_true('getChanges returns array', is_array($changes));
assert_true('getChanges has entries', count($changes) > 0);

// ─── Cleanup ───
echo "\nCleaning up...\n";
exec("rm -rf " . escapeshellarg($tempDir));

// ─── Summary ───
echo "\n" . str_repeat("=", 50) . "\n";
echo "Results: $passed passed, $failed failed\n";
if ($failed > 0) {
    exit(1);
}
echo "✅ All tests passed!\n";

/** Helper to extract $var = 'value'; from content (handles escaped quotes in single-quoted strings) */
function extract_var(string $content, string $name): string
{
    if (preg_match("/^\\$" . preg_quote($name, '/') . "\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/m", $content, $m)) {
        // Unescape for comparison
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $m[1]);
    }
    if (preg_match('/^\$' . preg_quote($name, '/') . '\s*=\s*"((?:[^"\\\\]|\\\\.)*)"/m', $content, $m)) {
        return str_replace(['\\\\', '\\"'], ['\\', '"'], $m[1]);
    }
    return '';
}

/** Helper to extract define('CONST', 'value'); from content */
function extract_define(string $content, string $name): string
{
    if (preg_match("/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*'([^']*)'/", $content, $m)) {
        return $m[1];
    }
    return '';
}

/** Helper to extract raw define value (including concatenation) */
function extract_define_raw(string $content, string $name): string
{
    if (preg_match("/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*([^;]+)\)/", $content, $m)) {
        return trim($m[1]);
    }
    return '';
}