<?php
require_once dirname(__DIR__, 2) . '/core/proxy.php';

if (!function_exists('meel_ban_target_allowed')) {
    /**
     * Target ban harus berupa IP publik yang valid. Hostname/localhost,
     * alamat loopback, dan IP unspecified (0.0.0.0) ditolak.
     */
    function meel_ban_target_allowed(string $ip): bool
    {
        $normalized = meel_normalize_ip($ip);
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        return !meel_is_loopback_ip($ip);
    }
}
