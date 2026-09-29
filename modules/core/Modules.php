<?php

/**
 * Gate modul opsional MEeL — fail-closed (gagal → nonaktif), aman dipanggil tanpa DB/session/autoloader, tidak pernah melempar exception. Lapisan: FISIK (marker), FLAG (.disabled, diabaikan di dev), TOGGLE (site_settings).
 */

final class Modules
{
    private const OPTIONAL = [
        'arcade' => [
            'marker'     => 'arcade/index.php',
            'flag_file'  => 'arcade/.disabled',
            'setting'    => 'modules_arcade',
            'home_route' => 'arcade/beranda',
        ],
    ];

    private static array $cache = [];

    private function __construct() {}

    public static function exists(string $module): bool
    {
        $key = 'exists:' . $module;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        return self::$cache[$key] = is_file(self::root() . '/' . self::config($module)['marker']);
    }

    /** Modul boleh tampil & dilayani? (fisik ∧ ¬flag ∧ ¬toggle-off) */
    public static function enabled(string $module): bool
    {
        $key = 'enabled:' . $module;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        return self::$cache[$key] = self::exists($module) && !self::flaggedOff($module) && self::toggleOn($module);
    }

    /** Home route modul (mis. "arcade/beranda"), null bila modul tidak dikenal. */
    public static function homeRoute(string $module): ?string
    {
        return self::OPTIONAL[$module]['home_route'] ?? null;
    }

    /** Guard halaman: modul nonaktif → redirect ke HUB, lalu exit. */
    public static function guardRedirect(string $module): void
    {
        if (self::enabled($module)) {
            return;
        }
        require_once __DIR__ . '/base_url.php';
        header('Location: ' . meel_base_url_path() . '/', true, 302);
        exit;
    }

    /**
     * Guard endpoint API: modul nonaktif → balas JSON 404 lalu exit; aktif → kembali normal tanpa efek.
     */
    public static function guardJson(string $module): void
    {
        if (self::enabled($module)) {
            return;
        }
        self::guardJson404();
    }

    /** Balas JSON 404 lalu exit (tanpa cek — hanya dipakai di cabang nonaktif). */
    public static function guardJson404(string $message = 'Module not available'): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => $message]));
    }

    private static function flaggedOff(string $module): bool
    {
        if (defined('MEEL_ENV') && MEEL_ENV === 'development') {
            return false;
        }
        $flag = self::root() . '/' . self::config($module)['flag_file'];
        return is_file($flag);
    }

    /** Toggle runtime dari admin panel (site_settings) — default ON. */
    private static function toggleOn(string $module): bool
    {
        // Entry point minim (router.php, sitemap.php) tidak memuat helpers/settings.php — muat sendiri, aman dipanggil berulang.
        require_once __DIR__ . '/helpers/settings.php';

        $conn = self::tryConnect();
        if ($conn === null) {
            return true; // tanpa DB, jangan blok — deteksi fisik & flag sudah cukup
        }
        // Tabel site_settings belum ada (setup baru/test) → jangan blok.
        try {
            $value = get_site_setting($conn, self::config($module)['setting'], '1');
        } catch (\Throwable) {
            return true;
        }
        // Koneksi TIDAK ditutup di sini: bisa jadi koneksi global $conn milik auth/config.php yang masih dipakai request — ditutup otomatis di akhir.
        return $value !== '0';
    }

    /** Koneksi mysqli lazim; null bila tidak memungkinkan (tanpa error). */
    private static function tryConnect(): ?\mysqli
    {
        static $conn = null;
        static $resolved = false;
        if ($resolved) {
            return $conn;
        }
        $resolved = true;

        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof \mysqli) {
            $existing = $GLOBALS['conn'];
            if (empty($existing->connect_error)) {
                return $conn = $existing;
            }
            return null;
        }

        $settings = self::root() . '/auth/settings.php';
        if (!is_file($settings)) {
            return null;
        }
        try {
            require $settings;
            if (empty($server) || empty($db)) {
                return null;
            }
            $candidate = @new \mysqli($server, $username ?? '', $password ?? '', $db);
            if ($candidate->connect_error) {
                return null;
            }
            $candidate->set_charset('utf8mb4');
            return $conn = $candidate; // koneksi milik gate — dipakai ulang, tak perlu ditutup manual
        } catch (\Throwable) {
            return null;
        }
    }

    private static function config(string $module): array
    {
        return self::OPTIONAL[$module] ?? ['marker' => "\0none", 'flag_file' => "\0none", 'setting' => "\0none", 'home_route' => null];
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}

/* reference build: MEeL-C4H9NO2 [5879204a5a3bd92d] */
