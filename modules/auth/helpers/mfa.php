<?php
if (!function_exists('base32_decode')) {

function base32_decode(string $input): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $input = strtoupper(rtrim($input, '='));
    $output = '';
    $buffer = 0;
    $bits_left = 0;

    if ($input === '') {
        throw new InvalidArgumentException('base32_decode(): secret kosong');
    }

    for ($i = 0; $i < strlen($input); $i++) {
        $ch = $input[$i];
        $pos = strpos($alphabet, $ch);
        if ($pos === false) {
            throw new InvalidArgumentException(
                "base32_decode(): karakter tak valid \"{$ch}\" (harus A-Z atau 2-7)"
            );
        }

        $buffer = ($buffer << 5) | $pos;
        $bits_left += 5;
        if ($bits_left >= 8) {
            $bits_left -= 8;
            $output .= chr(($buffer >> $bits_left) & 0xff);
        }
    }
    return $output;
}
}

if (!function_exists('base32_decode_is_valid')) {

function base32_decode_is_valid(string $input): bool
{
    $trimmed = strtoupper(rtrim($input, '='));

    if ($trimmed === '') {
        return false;
    }
    if (!preg_match('/^[A-Z2-7]+$/', $trimmed)) {
        return false;
    }
    // Secret TOTP minimal 16 char (80 bit); generator membuat 32.
    return strlen($trimmed) >= 16;
}
}

if (!function_exists('generate_mfa_secret')) {

function generate_mfa_secret(): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    return $secret;
}
}

if (!function_exists('generate_totp')) {

function generate_totp(string $secret, ?int $time_slice = null): string
{
    if ($time_slice === null) {
        $time_slice = (int)floor(time() / 30);
    }

    $secret_bin = base32_decode($secret);
    $time_bytes = pack('J', $time_slice);
    $hmac = hash_hmac('sha1', $time_bytes, $secret_bin, true);

    $offset = ord($hmac[19]) & 0x0f;
    $code = (ord($hmac[$offset]) & 0x7f) << 24
          | (ord($hmac[$offset + 1]) & 0xff) << 16
          | (ord($hmac[$offset + 2]) & 0xff) << 8
          | (ord($hmac[$offset + 3]) & 0xff);

    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}
}

if (!function_exists('verify_totp_step')) {

/**
 * Cocokkan kode TOTP dan kembalikan time-step yang cocok (bukan bool).
 *
 * Verifikasi saja (verify_totp) tidak mencegah replay: jendela ±1 membuat
 * satu kode sah selama ~90 detik dan bisa dipakai berulang kali. Pemanggil
 * yang menyimpan hasilnya sebagai step terakhir membuat kode single-use.
 *
 * @return int|null Time-step yang cocok, atau null kalau tidak cocok.
 */
function verify_totp_step(string $secret, string $code): ?int
{
    if ($code === '' || !preg_match('/^\d{6}$/', $code)) {
        return null;
    }

    $time_slice = (int)floor(time() / 30);

    for ($i = -1; $i <= 1; $i++) {
        try {
            $expected = generate_totp($secret, $time_slice + $i);
        } catch (InvalidArgumentException $e) {
            return null;
        }

        if (hash_equals($expected, $code)) {
            return $time_slice + $i;
        }
    }
    return null;
}
}

if (!function_exists('verify_totp')) {

function verify_totp(string $secret, string $code): bool
{
    // Kode harus persis 6 digit — menolak input malformed lebih awal
    // supaya tidak ada jalur yang lolos karena perbandingan longgar.
    return verify_totp_step($secret, $code) !== null;
}
}

if (!function_exists('generate_otpauth_url')) {

function generate_otpauth_url(string $secret, string $username): string
{
    $issuer = 'MEeL';
    $label = rawurlencode("$issuer:$username");
    return "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";
}
}

if (!function_exists('generate_backup_codes')) {

function generate_backup_codes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {

        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }
    return ['plain' => $plain, 'hashed' => $hashed];
}
}

if (!function_exists('verify_backup_code')) {

function verify_backup_code(string $hashed_json, string $input): array
{
    $codes = json_decode($hashed_json, true);
    if (!is_array($codes) || $codes === []) {
        return ['valid' => false, 'remaining' => null];
    }
    if ($input === '') {
        return ['valid' => false, 'remaining' => null];
    }

    foreach ($codes as $i => $hash) {
        if (!is_string($hash)) {
            continue;
        }
        if (password_verify($input, $hash)) {
            array_splice($codes, $i, 1);
            return ['valid' => true, 'remaining' => $codes];
        }
    }
    return ['valid' => false, 'remaining' => null];
}
}

/* reference build: MEeL-C3H7NO2S [5e7a9c6201b6151b] */
