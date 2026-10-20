<?php
/**
 * Bootstrap bersama untuk kelima halaman di `drive/`.
 *
 * Semua path memakai `__DIR__` supaya tidak bergantung pada CWD, dan semua
 * pemuatan memakai `require_once` supaya `auth/config.php` tidak termuat dua
 * kali. Penjelasan lengkap ada di docs/{id,en}/modules.md.
 */

require_once __DIR__ . '/../auth/auth.php';
require_once __DIR__ . '/../auth/config.php';
require_once __DIR__ . '/../modules/core/helpers.php';
require_once __DIR__ . '/DriveService.php';

$user = DriveUserContext::fromSession($_SESSION);
$user->authorize();
