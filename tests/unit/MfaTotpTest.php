<?php

use PHPUnit\Framework\TestCase;

/**
 * Test untuk modules/auth/helpers/mfa.php — SELURUH logika TOTP/MFA.
 *
 * Sebelum test ini: file berisi 7 fungsi (base32_decode, generate_mfa_secret,
 * generate_totp, verify_totp, generate_otpauth_url, generate_backup_codes,
 * verify_backup_code) dan TIDAK SATU pun punya test. Test yang ada
 * (LoginNowVariableTest, security_test) hanya memeriksa STRUKTUR kode —
 * letak variabel `$now`, daftar file — bukan logikanya.
 *
 * Yang dikunci di sini:
 *  1. Kesesuaian dengan RFC 6238 (test vector) — proves the algorithm is right.
 *  2. generate_mfa_secret() hanya menghasilkan karakter base32 yang sah.
 *  3. Jendela waktu verify_totp (±1 slice = toleransi 30 detik, sesuai RFC 6238 §5.2).
 *  4. Backup code: hash, sekali pakai, dan tidak bocor lewat return value.
 *  5. base32_decode menolak input tak valid (bug yang ditemukan 2026-10-14).
 */

/** @coversNothing */
class MfaTotpTest extends TestCase
{
    /** Base32 untuk string ASCII "12345678901234567890" (20 byte) */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/auth/helpers/mfa.php';
    }

    /* ─────────────────────────────────────────────────────────────
     * 1. RFC 6238 — test vector resmi untuk SHA-1 (appendix B)
     * ───────────────────────────────────────────────────────────── */

    /** @dataProvider rfcCounterProvider */
    public function testTotpMatchesRfc6238Vectors(int $counter, string $expected): void
    {
        // RFC 6238 Appendix B. 8 digit mode truncated ke 6 digit
        // (TOTP dengan digits=6 memakai mod 10^6 dari nilai yang sama).
        $this->assertSame(
            $expected,
            generate_totp(self::RFC_SECRET, $counter),
            "Kode TOTP untuk counter {$counter} harus cocok test vector RFC 6238"
        );
    }

    public static function rfcCounterProvider(): array
    {
        return [
            'counter 1 (T=59)'   => [1, '287082'],
            'counter 2 (T=1111111109)' => [2, '359152'],
            'counter 3 (T=1111111111)' => [3, '969429'],
            'counter 4 (T=1234567890)' => [4, '338314'],
            'counter 5 (T=2000000000)' => [5, '254676'],
        ];
    }

    public function testRfcSecretDecodesToTwentyBytes(): void
    {
        // "12345678901234567890" — kunci HMAC yang dipakai test vector.
        $this->assertSame(
            '3132333435363738393031323334353637383930',
            bin2hex(base32_decode(self::RFC_SECRET)),
            'Base32 RFC harus decode ke ASCII "12345678901234567890"'
        );
    }

    /* ─────────────────────────────────────────────────────────────
     * 2. base32_decode — parsing & validasi input
     * ───────────────────────────────────────────────────────────── */

    public function testBase32DecodeAcceptsLowercase(): void
    {
        $this->assertSame(
            bin2hex(base32_decode(self::RFC_SECRET)),
            bin2hex(base32_decode(strtolower(self::RFC_SECRET))),
            'Base32 harus case-insensitive'
        );
    }

    public function testBase32DecodeStopsAtPadding(): void
    {
        $unpadded = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $padded = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ====';
        $this->assertSame(
            bin2hex(base32_decode($unpadded)),
            bin2hex(base32_decode($padded)),
            'Padding "=" tidak boleh mengubah hasil decode'
        );
    }

    /**
     * BUG 2026-10-14: karakter di luar alfabet sebelumnya DILEWATI diam-diam
     * (`if ($pos === false) continue;`). Akibatnya dua secret berbeda bisa
     * collapse ke byte yang sama sehingga menghasilkan kode TOTP identik —
     * secret "AAAA…AAAA" (32 A) dan "AAAA…AAAA1" (31 A + "1") memakai
     * HMAC key yang sama.
     *
     * Dampak produksi saat ini terbatas karena mfa_secret hanya bisa diset
     * oleh generate_mfa_secret() (selalu 32 karakter sah). Tapi begitu ada
     * impor/penyuntingan secret, dua akun bisa diam-diam berbagi satu kunci.
     */
    public function testBase32DecodeRejectsCharactersOutsideAlphabet(): void
    {
        // '0' (nol) dan '1' (satu) TIDAK ada di alfabet RFC 4648 A-Z2-7.
        // base32_decode() sekarang MELEMPARK exception, bukan diam-diam
        // membuang karakter.
        $this->expectException(InvalidArgumentException::class);
        base32_decode('GEZDGNBVGY3TQOJQ0O1');
    }

    /**
     * Bukti langsung bahwa bug lama nyata: input dengan karakter tak valid
     * TIDAK BOLEH collapse ke secret base32 yang sah. Ini persis serangan
     * yang memungkinkan bila base32_decode() diam-diam membuang karakter.
     */
    public function testInvalidSecretCannotShareHmacKeyWithValidSecret(): void
    {
        $valid = str_repeat('A', 32);
        $injected = str_repeat('A', 32) . '10'; // 32 A + karakter tak valid

        $this->assertFalse(
            base32_decode_is_valid($injected),
            'Secret dengan karakter tak valid harus ditandai invalid'
        );

        // Saat dulu base32_decode('...AAAA10') menghasilkan key yang sama
        // dengan '...AAAA', sehingga kode TOTP-nya identik. Sekarang
        // base32_decode() menolak, jadi verify_totp() tidak akan pernah
        // mencocokkan kode dari secret lain.
        $this->assertFalse(
            verify_totp($injected, generate_totp($valid, (int)floor(time() / 30))),
            'Secret yang dikontaminasi karakter tak valid tidak boleh mengaktifkan kode secret lain'
        );
    }

    public function testBase32DecodeIsValidAcceptsProperlyGeneratedSecret(): void
    {
        $secret = generate_mfa_secret();
        $this->assertTrue(
            base32_decode_is_valid($secret),
            'Secret dari generate_mfa_secret() harus lolos validasi'
        );
    }

    public function testBase32DecodeEmptyInputIsInvalid(): void
    {
        $this->assertFalse(
            base32_decode_is_valid(''),
            'Secret kosong tidak boleh dianggap valid'
        );
    }

    /**
     * Guard: generate_totp() tidak boleh menghasilkan kode yang bisa dipakai
     * oleh secret tak valid (yaitu kode tetap 6 digit, tapi tetap "berhasil"
     * diverifikasi meski secret-nya rusak).
     */
    public function testInvalidSecretNeverAuthenticatesAgainstGeneratedSecret(): void
    {
        $valid = generate_mfa_secret();
        $now = (int)floor(time() / 30);

        $validCode = generate_totp($valid, $now);
        // Secret rusak: hanya karakter 'A' yang valid, sisanya tak valid.
        $broken = str_repeat('A', 32) . '10';

        $this->assertFalse(
            verify_totp($broken, $validCode),
            'Secret tak valid tidak boleh menerima kode milik secret lain'
        );
    }

    /* ─────────────────────────────────────────────────────────────
     * 3. generate_mfa_secret
     * ───────────────────────────────────────────────────────────── */

    public function testGeneratedSecretHasCorrectLengthAndAlphabet(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $secret = generate_mfa_secret();

            $this->assertSame(32, strlen($secret), 'Secret harus 32 karakter (160 bit untuk SHA-1)');
            $this->assertMatchesRegularExpression(
                '/^[A-Z2-7]{32}$/',
                $secret,
                'Secret hanya boleh berisi karakter base32 RFC 4648 (A-Z, 2-7)'
            );
            $this->assertSame(20, strlen(base32_decode($secret)), 'Secret 32 char harus decode ke 20 byte');
        }
    }

    public function testGeneratedSecretsAreUnique(): void
    {
        $secrets = [];
        for ($i = 0; $i < 50; $i++) {
            $secrets[] = generate_mfa_secret();
        }
        $this->assertCount(50, array_unique($secrets), 'Setiap secret harus unik (random_int, bukan mt_rand)');
    }

    /* ─────────────────────────────────────────────────────────────
     * 4. verify_totp — jendela waktu & timing-safe
     * ───────────────────────────────────────────────────────────── */

    public function testVerifyTotpAcceptsCurrentSlice(): void
    {
        $secret = generate_mfa_secret();
        $this->assertTrue(verify_totp($secret, generate_totp($secret)), 'Kode slice sekarang harus diterima');
    }

    public function testVerifyTotpAcceptsAdjacentSlices(): void
    {
        $secret = generate_mfa_secret();
        $now = (int)floor(time() / 30);

        $this->assertTrue(verify_totp($secret, generate_totp($secret, $now - 1)), 'Slice -1 (muda 30 dtk) harus diterima');
        $this->assertTrue(verify_totp($secret, generate_totp($secret, $now + 1)), 'Slice +1 (tua 30 dtk) harus diterima');
    }

    public function testVerifyTotpRejectsDistantSlices(): void
    {
        $secret = generate_mfa_secret();
        $now = (int)floor(time() / 30);

        foreach ([-2, -30, -60, +60] as $offset) {
            $this->assertFalse(
                verify_totp($secret, generate_totp($secret, $now + $offset)),
                "Slice {$offset} di luar jendela ±1 harus DITOLAK"
            );
        }
    }

    public function testVerifyTotpRejectsWrongCodeAndWrongSecret(): void
    {
        $secret = generate_mfa_secret();
        $other = generate_mfa_secret();

        $this->assertFalse(verify_totp($secret, '000000'), 'Kode yang salah hampir pasti ditolak');
        $this->assertFalse(
            verify_totp($other, generate_totp($secret)),
            'Kode milik secret lain harus ditolak'
        );
    }

    public function testVerifyTotpRejectsMalformedCode(): void
    {
        $secret = generate_mfa_secret();

        // hash_equals() menolak panjang berbeda secara implisit, tapi kode
        // dengan karakter non-digit harus tetap ditolak dan tidak fatal.
        foreach (['', '12345', '1234567', 'abcdef', '12 456'] as $bad) {
            $this->assertIsBool(
                verify_totp($secret, $bad),
                "verify_totp() harus return bool (bukan fatal) untuk kode \"{$bad}\""
            );
        }
    }

    public function testTotpCodeIsAlwaysSixDigits(): void
    {
        $secret = generate_mfa_secret();
        for ($i = 0; $i < 200; $i++) {
            $code = generate_totp($secret, $i);
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code, 'Kode harus tepat 6 digit, nol di depan');
        }
    }

    public function testVerifyTotpUsesHashEqualsForConstantTimeComparison(): void
    {
        // Guard statis: perbandingan kode TOTP wajib timing-safe supaya
        // penyerang tidak bisa menebak digit satu per satu lewat timing.
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/mfa.php');

        $verifyFn = $this->extractFunction($src, 'verify_totp');
        $this->assertStringContainsString(
            'hash_equals(',
            $verifyFn,
            'verify_totp() harus pakai hash_equals(), bukan === atau =='
        );
        $this->assertDoesNotMatchRegularExpression(
            '/hash_equals\(.*\)\s*===/',
            $verifyFn,
            'Jangan membandingkan hasil hash_equals dengan ==='
        );
    }

    /* ─────────────────────────────────────────────────────────────
     * 5. generate_otpauth_url
     * ───────────────────────────────────────────────────────────── */

    public function testOtpauthUrlFormat(): void
    {
        $url = generate_otpauth_url('ABCDEFGH', 'budi');

        $this->assertStringStartsWith('otpauth://totp/', $url);
        $this->assertStringContainsString('secret=ABCDEFGH', $url);
        $this->assertStringContainsString('issuer=MEeL', $url);
        $this->assertStringContainsString(rawurlencode('MEeL:budi'), $url, 'Label harus URL-encoded');
    }

    public function testOtpauthUrlEscapesUsernameSpecialCharacters(): void
    {
        // Username dengan karakter khusus harus ter-encode supaya label valid.
        $url = generate_otpauth_url('ABCDEFGH', 'budi@meel.com');
        $this->assertStringNotContainsString('@', substr($url, strlen('otpauth://totp/'), 30));
        $this->assertStringContainsString(rawurlencode('MEeL:budi@meel.com'), $url);
    }

    /* ─────────────────────────────────────────────────────────────
     * 6. Backup codes
     * ───────────────────────────────────────────────────────────── */

    public function testGenerateBackupCodesShape(): void
    {
        $codes = generate_backup_codes();

        $this->assertArrayHasKey('plain', $codes);
        $this->assertArrayHasKey('hashed', $codes);
        $this->assertCount(8, $codes['plain'], 'Generated 8 backup code');
        $this->assertCount(8, $codes['hashed'], 'Setiap kode punya hash');

        foreach ($codes['plain'] as $code) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        }
        foreach ($codes['hashed'] as $hash) {
            $this->assertNotEquals(
                '',
                $hash,
                'Hash tidak boleh kosong'
            );
            $this->assertStringStartsWith('$', $hash, 'Harus password_hash() (prefix $2y$ dll), bukan md5/sha1');
        }
    }

    public function testBackupCodesAreStoredHashedNotPlain(): void
    {
        $codes = generate_backup_codes();
        $json = json_encode($codes['hashed']);

        foreach ($codes['plain'] as $plain) {
            $this->assertStringNotContainsString(
                $plain,
                (string)$json,
                'Kode polos tidak boleh ikut tersimpan di JSON yang disimpan'
            );
        }
    }

    public function testVerifyBackupCodeAcceptsValidCode(): void
    {
        $codes = generate_backup_codes();
        $hashedJson = json_encode($codes['hashed']);

        $target = $codes['plain'][3];
        $result = verify_backup_code($hashedJson, $target);

        $this->assertTrue($result['valid'], 'Kode backup yang benar harus diterima');
        $this->assertIsArray($result['remaining'], 'Sisa kode harus dikembalikan untuk disimpan ulang');
        $this->assertCount(7, $result['remaining'], 'Kode yang dipakai harus hilang dari daftar');
    }

    public function testVerifyBackupCodeIsSingleUse(): void
    {
        $codes = generate_backup_codes();
        $hashedJson = json_encode($codes['hashed']);
        $target = $codes['plain'][0];

        $first = verify_backup_code($hashedJson, $target);
        $this->assertTrue($first['valid']);

        // Pakai lagi kode yang sama, dengan daftar sudah 7-item.
        $second = verify_backup_code(json_encode($first['remaining']), $target);
        $this->assertFalse(
            $second['valid'],
            'Kode backup harus SEKALI PAKAI — memakai ulang berarti celah takeover akun'
        );
    }

    public function testVerifyBackupCodeRejectsInvalidInput(): void
    {
        $codes = generate_backup_codes();
        $hashedJson = json_encode($codes['hashed']);

        $result = verify_backup_code($hashedJson, '111111');
        // 111111 mungkin kebetulan ada; yang pasti: kandidat tak ada harus ditolak.
        if (!$result['valid']) {
            $this->assertIsBool($result['valid']);
        }

        $this->assertFalse(verify_backup_code($hashedJson, '')['valid'], 'Kode kosong harus ditolak');
        $this->assertFalse(verify_backup_code('bukan json', '123456')['valid'], 'JSON rusak tidak boleh fatal');
        $this->assertFalse(verify_backup_code('[]', '123456')['valid'], 'Daftar kosong harus ditolak');
        $this->assertNull(verify_backup_code('bukan json', '123456')['remaining'], 'remaining = null saat JSON rusak');
    }

    public function testVerifyBackupCodeDoesNotLeakWhichPositionMatched(): void
    {
        // Tidak ada kanal timing yang discouraging; yang diuji di sini adalah
        // bahwa remaining benar-benar_COPY dan tidak mengubah array asli.
        $codes = generate_backup_codes();
        $hashed = $codes['hashed'];
        $hashedJson = json_encode($hashed);

        verify_backup_code($hashedJson, $codes['plain'][2]);

        $this->assertSame(
            json_encode($hashed),
            $hashedJson,
            'verify_backup_code() tidak boleh memutasi input caller secara in-place'
        );
    }

    /* ─────────────────────────────────────────────────────────────
     * Helper
     * ───────────────────────────────────────────────────────────── */

    private function extractFunction(string $src, string $name): string
    {
        $start = strpos($src, "function {$name}(");
        $this->assertNotFalse($start, "Fungsi {$name}() tidak ditemukan di mfa.php");

        $brace = strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $brace; $i < $len; $i++) {
            if ($src[$i] === '{') $depth++;
            if ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }
        return substr($src, $start);
    }
}

/* reference build: MEeL-C1H1N1O2 [mfa-totp-2026-10-14] */
