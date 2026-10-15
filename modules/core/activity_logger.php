<?php
require_once __DIR__ . '/proxy.php';
require_once __DIR__ . '/helpers/datetime.php';

function validate_and_format_ip(string $ip): array
{
    $ip = trim($ip);
    if (strpos($ip, '127.') === 0) {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (IPv4)', 'is_local' => true, 'version' => 'ipv4'];
    }
    if ($ip === '::1') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (IPv6)', 'is_local' => true, 'version' => 'ipv6'];
    }

    if (strpos($ip, '::ffff:') === 0) {
        $ipv4 = substr($ip, 7);
        if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ip' => $ipv4, 'display' => $ipv4 . ' (IPv4-mapped)', 'is_local' => false, 'version' => 'ipv4-mapped'];
        }
    }

    if ($ip === 'localhost') {
        return ['ip' => 'LOCAL', 'display' => 'Local Access (localhost)', 'is_local' => true, 'version' => 'hostname'];
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return ['ip' => $ip, 'display' => $ip . ' (IPv6)', 'is_local' => false, 'version' => 'ipv6'];
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return ['ip' => $ip, 'display' => $ip . ' (IPv4)', 'is_local' => false, 'version' => 'ipv4'];
    }

    return ['ip' => 'Unknown', 'display' => 'Unknown', 'is_local' => false, 'version' => 'unknown'];
}

function get_access_method()
{
    $trusted = trust_proxy_headers();
    if ($trusted && isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
        if (strpos($_SERVER["HTTP_HOST"] ?? '', 'trycloudflare.com') !== false) {
            return 'Cloudflare Tunnel';
        }
        return 'Cloudflare CDN';
    }
    if ($trusted && isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
        return 'Proxy/Forwarded';
    }
    if ($trusted && isset($_SERVER["HTTP_X_REAL_IP"])) {
        return 'Nginx Proxy';
    }
    if ($trusted && isset($_SERVER["HTTP_VIA"])) {
        return 'HTTP Proxy';
    }
    return 'Direct';
}

function get_connection_protocol()
{
    $ip = get_real_ip();

    if (strpos($ip, ':') !== false) {
        return 'IPv6';
    }

    return 'IPv4';
}

if (!function_exists('log_activity')) {

    function log_activity(mysqli $conn, int $user_id, string $action, string $media_type = '', ?int $media_id = null): void
    {
        $ip = '0.0.0.0';
        if (PHP_SAPI !== 'cli' && function_exists('get_real_ip')) {
            $ip = get_real_ip();
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        if ($media_id === null) {
            $stmt = $conn->prepare(
                "INSERT INTO activity_log (user_id, action, media_type, ip_address, created_at)
                 VALUES (?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("isss", $user_id, $action, $media_type, $ip);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO activity_log (user_id, action, media_type, media_id, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            if ($stmt) {
                $stmt->bind_param("issis", $user_id, $action, $media_type, $media_id, $ip);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

class ActivityLogger
{

    public const TOUCH_THROTTLE_SECONDS = 60;

    private const SESSION_KEY = '_meel_touch';

    private static bool $hasRun = false;

    public static function onRequest(mysqli $conn): void
    {
        if (self::$hasRun) {
            return;
        }
        self::$hasRun = true;

        if (!isset($_SESSION['role'])) {

            self::enforceIpBan($conn, self::clientIp());
            return;
        }

        $ip = self::clientIp();

        self::enforceIpBan($conn, $ip);

        if (!isset($_SESSION['user_id'])) {
            if (!self::shouldSkipTelemetry()) {
                self::touchGuest($conn, $ip);
            }
            return;
        }

        $uid = (int)$_SESSION['user_id'];
        $status = self::enforceSingleSession($conn, $uid);

        if (self::shouldSkipTelemetry()) {
            return;
        }

        self::touchUser($conn, $uid, $ip, $status);
    }

    private static function clientIp(): string
    {
        if (PHP_SAPI !== 'cli' && function_exists('get_real_ip')) {
            return get_real_ip();
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function shouldSkipTelemetry(): bool
    {
        if (isset($_SERVER['HTTP_HX_REQUEST'])) {
            return true;
        }

        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        if ($accept !== ''
            && stripos($accept, 'text/html') === false
            && stripos($accept, '*/*') === false) {
            return true;
        }

        $self = basename((string)($_SERVER['PHP_SELF'] ?? ''));
        return in_array($self, ['stream.php', 'file.php'], true);
    }

    private static function enforceIpBan(mysqli $conn, string $ip): void
    {
        if (basename(dirname((string)($_SERVER['PHP_SELF'] ?? ''))) === 'err') {
            return;
        }
        if (($_SESSION['role'] ?? null) === 'admin') {
            return;
        }

        $stmt = $conn->prepare("SELECT reason FROM ip_ban WHERE ip_address = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return;
        }

        $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
        $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
        header("Location: " . $relative_base . "/err/?code=banned&reason=" . urlencode($row['reason']));
        exit();
    }

    private static function enforceSingleSession(mysqli $conn, int $uid): array
    {
        $stmt = $conn->prepare("SELECT last_session_id, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $status = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if (basename(dirname((string)($_SERVER['PHP_SELF'] ?? ''))) === 'err') {
            return $status;
        }

        $currentSid = session_id();
        $role = $_SESSION['role'] ?? null;
        if (($status['role'] ?? null) !== 'admin'
            && !empty($status['last_session_id'])
            && $status['last_session_id'] !== $currentSid) {
            session_unset();
            session_destroy();

            $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/../..'));
            $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
            $relative_base = rtrim('/' . ltrim(str_replace($doc_root, '', $root_dir), '/'), '/');
            header("Location: " . $relative_base . "/err/?code=revoked");
            exit();
        }

        return $status;
    }

    private static function touchUser(mysqli $conn, int $uid, string $ip, array $status): void
    {
        $currentSid = session_id();
        if (empty($status['last_session_id'])) {
            $sidStmt = $conn->prepare("UPDATE users SET last_session_id = ? WHERE id = ?");
            $sidStmt->bind_param("si", $currentSid, $uid);
            $sidStmt->execute();
            $sidStmt->close();
        }

        $payload = self::buildPayload($conn, $ip);
        if (self::isThrottled($payload)) {
            return;
        }

        $now = meel_now();
        $stmt = $conn->prepare(
            "UPDATE users
                SET last_page = ?, user_agent = ?, access_via = ?, ip_address = ?, last_activity = ?
              WHERE id = ?"
        );
        $stmt->bind_param(
            "sssssi",
            $payload['last_page'],
            $payload['device'],
            $payload['access_via'],
            $payload['ip_address'],
            $now,
            $uid
        );
        $stmt->execute();
        $stmt->close();

        self::markThrottled($payload);
    }

    private static function touchGuest(mysqli $conn, string $ip): void
    {
        $payload = self::buildPayload($conn, $ip);
        if (self::isThrottled($payload)) {
            return;
        }

        $guest_id = 'g_' . substr(md5(session_id()), 0, 10);

        $guest_pass = bin2hex(random_bytes(24));
        $stmt = $conn->prepare(
            "INSERT INTO users (username, password, role, last_page, user_agent, access_via, ip_address, last_activity)
             VALUES (?, ?, 'guest', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                last_page = VALUES(last_page),
                user_agent = VALUES(user_agent),
                access_via = VALUES(access_via),
                ip_address = VALUES(ip_address),
                last_activity = VALUES(last_activity)"
        );
        if (!$stmt) {
            return;
        }
        $now = meel_now();
        $stmt->bind_param(
            "ssssssss",
            $guest_id,
            $guest_pass,
            $payload['last_page'],
            $payload['device'],
            $payload['access_via'],
            $payload['ip_address'],
            $now
        );
        $stmt->execute();
        $stmt->close();

        self::markThrottled($payload);
    }

    private static function buildPayload(mysqli $conn, string $ip): array
    {
        return [
            'last_page'  => self::resolveCurrentPage($conn),
            'device'     => self::detectDevice((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown')),
            'access_via' => get_access_method(),
            'ip_address' => $ip,
        ];
    }

    private static function signature(array $payload): string
    {
        return implode("\0", [
            $payload['last_page'],
            $payload['device'],
            $payload['access_via'],
            $payload['ip_address'],
        ]);
    }

    private static function isThrottled(array $payload): bool
    {
        $last = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($last) || !isset($last['sig'], $last['ts'])) {
            return false;
        }
        return $last['sig'] === self::signature($payload)
            && (time() - (int)$last['ts']) < self::TOUCH_THROTTLE_SECONDS;
    }

    private static function markThrottled(array $payload): void
    {
        $_SESSION[self::SESSION_KEY] = ['sig' => self::signature($payload), 'ts' => time()];
    }

    private static function detectDevice(string $ua): string
    {
        if (strpos($ua, 'Android') !== false) return 'Smartphone';
        if (strpos($ua, 'Linux') !== false) return 'Linux PC';
        if (strpos($ua, 'Windows') !== false) return 'Windows PC';
        if (strpos($ua, 'Macintosh') !== false) return 'Mac';
        if (strpos($ua, 'iPhone') !== false) return 'iPhone';
        return 'Unknown';
    }

    private static function resolveCurrentPage(mysqli $conn): string
    {
        $self = (string)($_SERVER['PHP_SELF'] ?? '');
        $currentPage = basename($self);
        $dirName = basename(dirname($self));
        $id = $_GET['id'] ?? $_GET['v'] ?? null;

        if ($id) {
            if ($currentPage === 'watch.php') {
                return (($dirName === 'music') ? 'Listening: ' : 'Watching: ')
                    . (self::lookupTitle($conn, $dirName === 'music' ? 'music' : 'video', (int)$id) ?? '');
            }
            if ($currentPage === 'read.php') {
                return 'Reading: ' . (self::lookupTitle($conn, 'books', (int)$id) ?? '');
            }
            if ($currentPage === 'stream.php' && $dirName === 'music') {
                if (($_SESSION['_last_stream_id'] ?? null) !== $id) {
                    $_SESSION['_last_stream_id'] = $id;
                    $title = self::lookupTitle($conn, 'music', (int)$id);
                    if ($title !== null) {
                        $page = 'Streaming: ' . $title;
                        $_SESSION['_last_stream_page'] = $page;
                        return $page;
                    }
                }
                return (string)($_SESSION['_last_stream_page'] ?? $currentPage);
            }
            if ($currentPage === 'index.php' && $dirName === 'profile') {
                return 'Viewing Profile: ' . htmlspecialchars((string)($_GET['u'] ?? 'Someone'), ENT_QUOTES);
            }
        } elseif ($currentPage === 'index.php') {
            return [
                'video'   => 'Browsing Video Library',
                'music'   => 'Browsing Music Library',
                'books'   => 'Browsing Books Library',
                'arcade'  => 'Browsing Arcade',
                'drive'   => 'Browsing Drive',
                'profile' => 'Browsing Profiles',
            ][$dirName] ?? 'Browsing HUB';
        }

        return $currentPage;
    }

    private static function lookupTitle(mysqli $conn, string $table, int $id): ?string
    {
        if (!in_array($table, ['music', 'video', 'books'], true)) {
            return null;
        }
        $stmt = $conn->prepare("SELECT title FROM `$table` WHERE id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['title'] ?? null;
    }
}

if (PHP_SAPI === 'cli') {
    return;
}

if (isset($conn) && $conn instanceof mysqli) {
    ActivityLogger::onRequest($conn);
}

/* reference build: MEeL-C2H5NO2 [a0d28a942a0fe642] */
