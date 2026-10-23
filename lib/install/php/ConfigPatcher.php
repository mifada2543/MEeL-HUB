<?php
/**
 * ConfigPatcher - OOP patcher for auth/settings.php
 * Replaces inline Python patching in install.sh
 *
 * Usage:
 *   $patcher = new ConfigPatcher('auth/settings.php', dryRun: false);
 *   $patcher->patchDatabase([...])
 *          ->patchHddBase('/path/to/media')
 *          ->patchEnvironment('production', false)
 *          ->apply();
 *   $patcher->verify();
 */

namespace MEeL\Install;

class ConfigPatcher
{
    private string $settingsPath;
    private bool $dryRun;
    private string $content;
    private bool $loaded = false;
    private array $changes = [];

    public function __construct(string $settingsPath, bool $dryRun = false)
    {
        $this->settingsPath = $settingsPath;
        $this->dryRun = $dryRun;
    }

    /** Load settings.php content */
    private function load(): self
    {
        if ($this->loaded) {
            return $this;
        }

        if (!is_file($this->settingsPath)) {
            throw new \RuntimeException("Settings file not found: {$this->settingsPath}");
        }

        $this->content = file_get_contents($this->settingsPath);
        if ($this->content === false) {
            throw new \RuntimeException("Cannot read settings file: {$this->settingsPath}");
        }

        $this->loaded = true;
        return $this;
    }

    /** Record a change for reporting */
    private function recordChange(string $key, string $oldValue, string $newValue): self
    {
        $this->changes[] = [
            'key' => $key,
            'old' => $oldValue,
            'new' => $newValue,
        ];
        return $this;
    }

    /** Escape value for PHP single-quoted string */
    private function phpStr(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    /** Patch a define() constant - handles string, bare values (true/false/null), and numeric */
    private function patchDefine(string $name, string $value): self
    {
        $this->load();
        $escapedValue = $this->phpStr($value);
        // Match string values, bare values (true/false/null), and numeric literals
        $pattern = "/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*(?:'[^']*'|\"[^\"]*\"|true|false|null|[0-9]+)\s*\)/";

        if (preg_match($pattern, $this->content)) {
            $replacement = "define('{$name}', {$escapedValue})";
            $this->content = preg_replace($pattern, $replacement, $this->content, 1);
            $this->recordChange("define('{$name}')", "placeholder", $escapedValue);
        } else {
            // Try to find commented version
            $commentedPattern = "/\/\/\s*define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*(?:'[^']*'|\"[^\"]*\"|true|false|null|[0-9]+)\s*\)/";
            if (preg_match($commentedPattern, $this->content)) {
                $this->content = preg_replace($commentedPattern, "define('{$name}', {$escapedValue})", $this->content, 1);
                $this->recordChange("define('{$name}') [uncommented]", "commented", $escapedValue);
            } else {
                throw new \RuntimeException("define('{$name}', ...) not found in {$this->settingsPath}");
            }
        }
        return $this;
    }

    /** Patch a variable assignment: $var = 'value'; or $var = "value"; */
    private function patchVariable(string $name, string $value): self
    {
        $this->load();
        $escapedValue = $this->phpStr($value);
        // Match both single and double quoted strings
        $pattern = "/^\\$" . preg_quote($name, '/') . "\\s*=\\s*[\"']([^\"']*)[\"']\\s*;/m";

        if (preg_match($pattern, $this->content)) {
            $replacement = "\${$name} = {$escapedValue};";
            $this->content = preg_replace($pattern, $replacement, $this->content, 1);
            $this->recordChange("\${$name}", "placeholder", $escapedValue);
        } else {
            throw new \RuntimeException("\${$name} = '...'; not found in {$this->settingsPath}");
        }
        return $this;
    }

    /** Patch a commented define to uncomment and set value */
    private function patchDefineUncomment(string $name, string $value): self
    {
        $this->load();
        $escapedValue = $this->phpStr($value);
        // Match commented define with either quoted string or bare value (true/false/null)
        $pattern = "/^\/\/\s*define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*(?:'[^']*'|\"[^\"]*\"|true|false|null)\s*\)\s*;/m";

        if (preg_match($pattern, $this->content)) {
            $replacement = "define('{$name}', {$escapedValue});";
            $this->content = preg_replace($pattern, $replacement, $this->content, 1);
            $this->recordChange("define('{$name}') [uncommented]", "commented", $escapedValue);
        } else {
            // Try non-commented
            return $this->patchDefine($name, $value);
        }
        return $this;
    }

    /** Patch a commented variable to uncomment and set value */
    private function patchVariableUncomment(string $name, string $value): self
    {
        $this->load();
        $escapedValue = $this->phpStr($value);
        // Match both single and double quoted strings
        $pattern = "/^\/\/\s*\\$" . preg_quote($name, '/') . "\\s*=\\s*[\"']([^\"']*)[\"']\\s*;/m";

        if (preg_match($pattern, $this->content)) {
            $replacement = "\${$name} = {$escapedValue};";
            $this->content = preg_replace($pattern, $replacement, $this->content, 1);
            $this->recordChange("\${$name} [uncommented]", "commented", $escapedValue);
        } else {
            return $this->patchVariable($name, $value);
        }
        return $this;
    }

    /** Remove any existing "Written by install.sh" block and append new one */
    private function patchOverrideBlock(array $variables): self
    {
        $this->load();

        // Remove old override block
        $lines = explode("\n", $this->content);
        $newLines = [];
        $inOverrideBlock = false;

        foreach ($lines as $line) {
            if (str_contains($line, '// Written by install.sh') || str_contains($line, '// Ditulis install.sh')) {
                $inOverrideBlock = true;
                continue;
            }
            if ($inOverrideBlock && (str_starts_with(trim($line), '$') || trim($line) === '}')) {
                // Continue skipping until we hit a non-variable line
                if (!str_starts_with(trim($line), '$')) {
                    $inOverrideBlock = false;
                } else {
                    continue;
                }
            }
            if (!$inOverrideBlock) {
                $newLines[] = $line;
            }
        }

        $this->content = implode("\n", $newLines);
        $this->content = rtrim($this->content) . "\n";

        // Append new override block
        $this->content .= "\n// Written by install.sh — do not edit manually.\n";
        foreach ($variables as $name => $value) {
            $this->content .= "\${$name} = {$this->phpStr($value)};\n";
            $this->recordChange("\${$name} [override]", "new", $this->phpStr($value));
        }
        $this->content .= "\n";

        return $this;
    }

    /* ─── Public API ─── */

    /** Patch database credentials: $server, $username, $password, $db */
    public function patchDatabase(array $db): self
    {
        $this->patchVariable('server', $db['host'] ?? 'localhost')
             ->patchVariable('username', $db['user'] ?? 'root')
             ->patchVariable('password', $db['pass'] ?? '')
             ->patchVariable('db', $db['name'] ?? 'MEeL');
        return $this;
    }

    /** Patch MEEL_HDD_BASE and derived constants */
    public function patchHddBase(string $hddBase): self
    {
        $hddBase = rtrim($hddBase, '/');
        $this->patchDefine('MEEL_HDD_BASE', $hddBase);

        // Note: Derived constants (MEEL_HDD_VIDEO_UPLOAD, etc.) in the template
        // use concatenation: MEEL_HDD_BASE . '/video/upload/'
        // These are computed at runtime, so we don't patch them directly.
        // If they exist as simple string defines, patch them; otherwise skip.
        $derived = [
            'MEEL_HDD_VIDEO_UPLOAD' => $hddBase . '/video/upload/',
            'MEEL_HDD_VIDEO_DIR'    => $hddBase . '/video/upload/video/',
            'MEEL_HDD_THUMB_DIR'    => $hddBase . '/video/upload/thumbnail/',
            'MEEL_HDD_MUSIC_UPLOAD' => $hddBase . '/music/upload/',
            'MEEL_HDD_BOOKS_UPLOAD' => $hddBase . '/books/upload/',
            'MEEL_HDD_DRIVE'        => $hddBase . '/drive/',
        ];

        foreach ($derived as $const => $value) {
            try {
                // Only patch if it's a simple string define (not concatenation)
                $this->patchSimpleDefine($const, $value);
            } catch (\RuntimeException) {
                // Constant might not exist as simple string (e.g., uses concatenation) — that's OK
            }
        }
        return $this;
    }

    /** Patch a define only if it has a simple string value (not concatenation) */
    private function patchSimpleDefine(string $name, string $value): self
    {
        $this->load();
        $escapedValue = $this->phpStr($value);
        // Match only simple string values, not concatenation
        $pattern = "/define\(\s*'" . preg_quote($name, '/') . "'\s*,\s*'[^']*'\s*\)/";

        if (preg_match($pattern, $this->content)) {
            $replacement = "define('{$name}', {$escapedValue})";
            $this->content = preg_replace($pattern, $replacement, $this->content, 1);
            $this->recordChange("define('{$name}')", "placeholder", $escapedValue);
        } else {
            throw new \RuntimeException("Simple define('{$name}', '...') not found");
        }
        return $this;
    }

    /** Patch MEEL_ENV and APP_DEBUG */
    public function patchEnvironment(string $env, bool $debug): self
    {
        $this->patchDefineUncomment('MEEL_ENV', $env);
        $this->patchDefineUncomment('APP_DEBUG', $debug ? 'true' : 'false');
        return $this;
    }

    /** Patch binary paths */
    public function patchBinaries(array $binaries): self
    {
        $map = [
            'MEEL_FFMPEG_PATH' => $binaries['ffmpeg'] ?? '',
            'MEEL_FFPROBE_PATH' => $binaries['ffprobe'] ?? '',
            'MEEL_NODE_PATH' => $binaries['node'] ?? '',
            'MEEL_YTDLP_PATH' => $binaries['ytdlp'] ?? '',
        ];

        foreach ($map as $const => $value) {
            if ($value !== '') {
                $this->patchDefine($const, $value);
            }
        }
        return $this;
    }

    /** Patch proxy settings */
    public function patchProxy(bool $trust, array $trustedProxies = []): self
    {
        $this->patchDefineUncomment('MEEL_TRUST_PROXY_HEADERS', $trust ? 'true' : 'false');
        if ($trust && !empty($trustedProxies)) {
            $this->patchDefine('MEEL_TRUSTED_PROXIES', implode(',', $trustedProxies));
        }
        return $this;
    }

    /** Patch X-Sendfile setting */
    public function patchXSendfile(bool $enable): self
    {
        $this->patchDefine('MEEL_USE_XSENDFILE', $enable ? 'true' : 'false');
        return $this;
    }

    /** Patch canonical host settings */
    public function patchCanonicalHost(string $host, string $trustedHosts): self
    {
        if ($host !== '') {
            $this->patchDefine('MEEL_CANONICAL_HOST', $host);
        }
        if ($trustedHosts !== '') {
            $this->patchDefine('MEEL_TRUSTED_HOSTS', $trustedHosts);
        }
        return $this;
    }

    /** Patch rate limit constants */
    public function patchRateLimits(array $limits): self
    {
        $map = [
            'MEEL_MAX_DOWNLOAD_BYTES' => $limits['max_download_bytes'] ?? '',
            'MEEL_MAX_DOWNLOAD_SECONDS' => $limits['max_download_seconds'] ?? '',
            'MEEL_MAX_CONCURRENT_QUEUES' => $limits['max_concurrent_queues'] ?? '',
        ];

        foreach ($map as $const => $value) {
            if ($value !== '') {
                $this->patchDefine($const, (string)$value);
            }
        }
        return $this;
    }

    /** Write the patched content to file */
    public function apply(): bool
    {
        if ($this->dryRun) {
            echo "[DRY-RUN] Would write to {$this->settingsPath}\n";
            echo "Changes:\n";
            foreach ($this->changes as $change) {
                echo "  {$change['key']}: {$change['old']} → {$change['new']}\n";
            }
            return true;
        }

        $result = file_put_contents($this->settingsPath, $this->content);
        if ($result === false) {
            throw new \RuntimeException("Failed to write {$this->settingsPath}");
        }
        return true;
    }

    /** Verify the patched file can be loaded and has expected values */
    public function verify(array $expected = []): bool
    {
        if ($this->dryRun) {
            echo "[DRY-RUN] Would verify {$this->settingsPath}\n";
            return true;
        }

        // Load and check syntax
        $result = @include $this->settingsPath;
        if ($result === false) {
            throw new \RuntimeException("Settings file has syntax errors or cannot be loaded");
        }

        // Check expected constants/variables
        foreach ($expected as $key => $value) {
            $actual = null;
            if (defined($key)) {
                $actual = constant($key);
            } elseif (isset($GLOBALS[$key])) {
                $actual = $GLOBALS[$key];
            } elseif (isset($$key)) {
                $actual = $$key;
            }

            if ($actual !== $value && $actual !== null) {
                throw new \RuntimeException("Verification failed: {$key} expected '{$value}', got '{$actual}'");
            }
        }

        return true;
    }

    /** Get list of changes made */
    public function getChanges(): array
    {
        return $this->changes;
    }

    /** Static factory: create from template */
    public static function fromTemplate(string $templatePath, string $targetPath, bool $dryRun = false): self
    {
        if (!is_file($templatePath)) {
            throw new \RuntimeException("Template not found: {$templatePath}");
        }
        if (!is_file($targetPath) || $dryRun) {
            if (!$dryRun) {
                copy($templatePath, $targetPath);
            }
        }
        return new self($targetPath, $dryRun);
    }
}

/** Convenience function for CLI usage */
function patch_settings_cli(array $args): int
{
    $settingsPath = $args['settings'] ?? 'auth/settings.php';
    $dryRun = $args['dry-run'] ?? false;

    try {
        $patcher = new ConfigPatcher($settingsPath, $dryRun);

        if (!empty($args['db'])) {
            $patcher->patchDatabase($args['db']);
        }
        if (!empty($args['hdd'])) {
            $patcher->patchHddBase($args['hdd']);
        }
        if (!empty($args['env'])) {
            $patcher->patchEnvironment($args['env'], $args['debug'] ?? false);
        }
        if (!empty($args['binaries'])) {
            $patcher->patchBinaries($args['binaries']);
        }
        if (isset($args['trust_proxy'])) {
            $patcher->patchProxy($args['trust_proxy'], $args['trusted_proxies'] ?? []);
        }
        if (isset($args['xsendfile'])) {
            $patcher->patchXSendfile($args['xsendfile']);
        }
        if (!empty($args['canonical_host'])) {
            $patcher->patchCanonicalHost($args['canonical_host'], $args['trusted_hosts'] ?? '');
        }

        $patcher->apply();
        $patcher->verify();

        if (!$dryRun) {
            echo "✅ Settings patched and verified: {$settingsPath}\n";
        }
        return 0;
    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ ConfigPatcher error: " . $e->getMessage() . "\n");
        return 1;
    }
}

// CLI entry point
if (php_sapi_name() === 'cli' && isset($argv[0]) && basename($argv[0]) === 'ConfigPatcher.php') {
    // Show help if no args or --help
    if (count($argv) === 1 || in_array($argv[1], ['--help', '-h'])) {
        echo "Usage: php ConfigPatcher.php [options]\n\n";
        echo "Options:\n";
        echo "  --db-host=HOST        Database host (default: localhost)\n";
        echo "  --db-user=USER        Database user (default: root)\n";
        echo "  --db-pass=PASS        Database password (default: empty)\n";
        echo "  --db-name=NAME        Database name (default: MEeL)\n";
        echo "  --hdd=PATH            MEEL_HDD_BASE path (required)\n";
        echo "  --env=production|development  Environment (default: production)\n";
        echo "  --debug=true|false    Enable debug mode (default: false)\n";
        echo "  --trust-proxy         Enable MEEL_TRUST_PROXY_HEADERS\n";
        echo "  --no-trust-proxy      Disable MEEL_TRUST_PROXY_HEADERS\n";
        echo "  --trusted-proxies=IPs Comma-separated trusted proxy IPs\n";
        echo "  --xsendfile           Enable MEEL_USE_XSENDFILE\n";
        echo "  --no-xsendfile        Disable MEEL_USE_XSENDFILE\n";
        echo "  --canonical-host=HOST Production domain\n";
        echo "  --trusted-hosts=HOSTS Comma-separated trusted hosts\n";
        echo "  --ffmpeg=PATH         FFmpeg binary path\n";
        echo "  --ffprobe=PATH        FFprobe binary path\n";
        echo "  --node=PATH           Node binary path\n";
        echo "  --ytdlp=PATH          yt-dlp binary path\n";
        echo "  --settings=PATH       Settings file path (default: auth/settings.php)\n";
        echo "  --dry-run             Preview changes without writing\n";
        echo "  --help, -h            Show this help\n";
        exit(0);
    }

    $args = [];
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (str_starts_with($arg, '--')) {
            $key = substr($arg, 2);
            if (str_contains($key, '=')) {
                [$key, $value] = explode('=', $key, 2);
                $args[$key] = $value;
            } else {
                // Handle boolean flags
                if ($key === 'trust-proxy' || $key === 'xsendfile') {
                    $args[$key] = true;
                } elseif ($key === 'no-trust-proxy') {
                    $args['trust-proxy'] = false;
                } elseif ($key === 'no-xsendfile') {
                    $args['xsendfile'] = false;
                } else {
                    $args[$key] = true;
                }
            }
        }
    }

    // Parse complex args
    if (isset($args['db-host'])) $args['db']['host'] = $args['db-host'];
    if (isset($args['db-user'])) $args['db']['user'] = $args['db-user'];
    if (isset($args['db-pass'])) $args['db']['pass'] = $args['db-pass'];
    if (isset($args['db-name'])) $args['db']['name'] = $args['db-name'];

    // Parse binary paths
    $binaries = [];
    if (isset($args['ffmpeg'])) $binaries['ffmpeg'] = $args['ffmpeg'];
    if (isset($args['ffprobe'])) $binaries['ffprobe'] = $args['ffprobe'];
    if (isset($args['node'])) $binaries['node'] = $args['node'];
    if (isset($args['ytdlp'])) $binaries['ytdlp'] = $args['ytdlp'];
    if (!empty($binaries)) $args['binaries'] = $binaries;

    // Parse settings file
    if (isset($args['settings'])) {
        $settingsPath = $args['settings'];
    } else {
        $settingsPath = 'auth/settings.php';
    }

    // Parse dry-run
    $dryRun = isset($args['dry-run']);
    if ($dryRun) unset($args['dry-run']);

    // Parse trusted proxies string to array
    if (isset($args['trusted-proxies']) && is_string($args['trusted-proxies'])) {
        $args['trusted_proxies'] = array_map('trim', explode(',', $args['trusted-proxies']));
    }

    // Create patcher with settings path
    $patcher = new ConfigPatcher($settingsPath, $dryRun);

    // Apply patches based on args
    try {
        if (!empty($args['db'])) {
            $patcher->patchDatabase($args['db']);
        }
        if (!empty($args['hdd'])) {
            $patcher->patchHddBase($args['hdd']);
        }
        if (!empty($args['env'])) {
            $patcher->patchEnvironment($args['env'], $args['debug'] ?? false);
        }
        if (!empty($args['binaries'])) {
            $patcher->patchBinaries($args['binaries']);
        }
        if (isset($args['trust-proxy'])) {
            $proxies = $args['trusted_proxies'] ?? ($args['trusted-proxies'] ?? []);
            if (is_string($proxies)) {
                $proxies = array_map('trim', explode(',', $proxies));
            }
            $patcher->patchProxy($args['trust-proxy'], $proxies);
        }
        if (isset($args['xsendfile'])) {
            $patcher->patchXSendfile($args['xsendfile']);
        }
        if (!empty($args['canonical-host'])) {
            $patcher->patchCanonicalHost($args['canonical-host'], $args['trusted-hosts'] ?? '');
        }
        if (!empty($args['rate-limits'])) {
            $patcher->patchRateLimits($args['rate-limits']);
        }

        $patcher->apply();
        $patcher->verify();

        if (!$dryRun) {
            echo "✅ Settings patched and verified: {$settingsPath}\n";
        }
        exit(0);
    } catch (\Throwable $e) {
        fwrite(STDERR, "❌ ConfigPatcher error: " . $e->getMessage() . "\n");
        exit(1);
    }
}