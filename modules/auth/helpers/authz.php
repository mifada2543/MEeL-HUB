<?php


if (!function_exists('is_admin')) {
function is_admin(mysqli $conn): bool
{
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    return get_user_role($conn, (int)$_SESSION['user_id']) === 'admin';
}
}


if (!function_exists('require_admin')) {
function require_admin(mysqli $conn): void
{
    if (!is_admin($conn)) {
        header('Location: ' . meel_base_url_path() . '/err?code=not_found', true, 302);
        exit;
    }
}
}

/* reference build: MEeL-C9H11NO2 [7df6a3f561d511bf] */
