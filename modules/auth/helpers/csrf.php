<?php


if (!function_exists('get_csrf_token')) {
function get_csrf_token(): string
{
    return $_SESSION['csrf_token'] ?? '';
}
}


if (!function_exists('verify_csrf_token')) {
function verify_csrf_token(?string $token = null): bool
{
    if ($token === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
    }
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}
}

/* reference build: MEeL-C10H12N2O [63409192b250ddf1] */
