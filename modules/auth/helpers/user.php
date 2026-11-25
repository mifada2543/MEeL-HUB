<?php
if (!defined('MEEL_GUEST_OFFLINE_SECONDS')) {
    define('MEEL_GUEST_OFFLINE_SECONDS', 300);
}
if (!function_exists('get_user_usage')) {
    function get_user_usage(string $username): int|float
    {
        $path = meel_drive_base_path() . '/private_admins/' . $username;
        if (!is_dir($path)) return 0;

        return dir_size($path);
    }
}

if (!function_exists('get_user_role')) {
    function get_user_role(mysqli $conn, int $user_id): string
    {
        static $cache = [];
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }

        $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return 'user';
        }
        $role = (string)$row['role'];
        $cache[$user_id] = $role;
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $user_id) {
            $_SESSION['role'] = $role;
        }
        return $role;
    }
}
if (!function_exists('invalidate_user_role_cache')) {
    function invalidate_user_role_cache(): void
    {
        unset($_SESSION['role']);
    }
}
if (!function_exists('get_user_active')) {
    function get_user_active(mysqli $conn, int $user_id): bool
    {
        static $cache = [];
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row === null) {
            return false;
        }
        return $cache[$user_id] = (int)$row['is_active'] === 1;
    }
}
if (!function_exists('purge_inactive_guests')) {
    function purge_inactive_guests(mysqli $conn, ?int $offlineSeconds = null): ?int
    {
        $seconds = $offlineSeconds ?? (defined('MEEL_GUEST_OFFLINE_SECONDS') ? MEEL_GUEST_OFFLINE_SECONDS : 300);
        $stmt = $conn->prepare(
            "DELETE FROM users WHERE role = 'guest'
             AND (is_active = 0 OR last_activity < DATE_SUB(NOW(), INTERVAL ? SECOND))"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $seconds);
        $ok = $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            return null;
        }
        return $deleted;
    }
}

if (!function_exists('purge_guest_users')) {
    function purge_guest_users(mysqli $conn): ?int
    {
        return purge_inactive_guests($conn);
    }
}
/* reference build: MEeL-C6H9N3O3 [51ee4fcba4b9d24a] */
