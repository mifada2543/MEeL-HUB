<?php
/**
 * Resolusi IP klien di balik proxy tepercaya (allowlist MEEL_TRUSTED_PROXIES;
 * perilaku lengkap di docs/{id,en}/configuration.md).
 */

function meel_normalize_ip(string $ip): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return '';
    }
    if (stripos($ip, '::ffff:') === 0) {
        $v4 = substr($ip, 7);
        if (filter_var($v4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $v4;
        }
    }
    return strtolower($ip);
}

function meel_trusted_proxies(): array
{
    if (defined('MEEL_TRUSTED_PROXIES') && is_array(MEEL_TRUSTED_PROXIES)) {
        return MEEL_TRUSTED_PROXIES;
    }
    return ['127.0.0.1', '::1'];
}

function meel_ip_in_cidr(string $ip, string $cidr): bool
{
    $parts = explode('/', $cidr, 2);
    if (count($parts) !== 2 || !is_numeric($parts[1])) {
        return false;
    }
    $bits   = (int) $parts[1];
    $ipBin  = @inet_pton($ip);
    $subBin = @inet_pton(trim($parts[0]));
    if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
        return false;
    }
    $maxBits = strlen($ipBin) * 8;
    if ($bits < 0 || $bits > $maxBits) {
        return false;
    }
    if ($bits === 0) {
        return true;
    }
    $bytes    = intdiv($bits, 8);
    $remainder = $bits % 8;
    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
        return false;
    }
    if ($remainder === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainder)) & 0xFF;
    return ((ord($ipBin[$bytes]) ^ ord($subBin[$bytes])) & $mask) === 0;
}

function meel_is_trusted_proxy(string $ip): bool
{
    $ip = meel_normalize_ip($ip);
    if ($ip === '') {
        return false;
    }
    foreach (meel_trusted_proxies() as $proxy) {
        if (!is_string($proxy) || $proxy === '') {
            continue;
        }
        if (strpos($proxy, '/') !== false) {
            if (meel_ip_in_cidr($ip, $proxy)) {
                return true;
            }
            continue;
        }
        if ($ip === meel_normalize_ip($proxy)) {
            return true;
        }
    }
    return false;
}

function trust_proxy_headers(): bool
{
    if (!defined('MEEL_TRUST_PROXY_HEADERS') || MEEL_TRUST_PROXY_HEADERS !== true) {
        return false;
    }
    return meel_is_trusted_proxy((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function meel_is_loopback_ip(string $ip): bool
{
    $ip = meel_normalize_ip($ip);
    if ($ip === '' || $ip === 'localhost') {
        return false;
    }
    if ($ip === '::1') {
        return true;
    }
    return strpos($ip, '127.') === 0;
}

/**
 * IP klien asli. Tanpa proxy tepercaya → selalu REMOTE_ADDR (spoof-proof).
 * Di balik proxy tepercaya → CF-Connecting-IP, lalu X-Forwarded-For
 * kanan-ke-kiri (Cloudflare menaruh IP klien asli di ujung kanan).
 */
function get_real_ip(): string
{
    $valid = function ($value): bool {
        return is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false;
    };

    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!trust_proxy_headers()) {
        return $valid($remote) ? $remote : '0.0.0.0';
    }

    if (isset($_SERVER['HTTP_CF_CONNECTING_IP']) && $valid($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $chain = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
        foreach (array_reverse($chain) as $candidate) {
            $candidate = trim($candidate);
            if (!$valid($candidate) || meel_is_trusted_proxy($candidate)) {
                continue;
            }
            return $candidate;
        }
    }

    return $valid($remote) ? $remote : '0.0.0.0';
}
