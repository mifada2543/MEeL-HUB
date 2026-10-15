<?php
use PHPUnit\Framework\TestCase;

class LoginNowVariableTest extends TestCase
{
    private static function loginSource(): string
    {
        return (string) file_get_contents(MEEL_ROOT . '/auth/login.php');
    }

    public function testNowDefinedExactlyOnceBeforeMfaBranch(): void
    {
        $src = self::loginSource();

        $this->assertSame(
            1,
            substr_count($src, '$now = meel_now();'),
            '$now harus didefinisikan tepat sekali (dipakai jalur MFA dan non-MFA)'
        );

        $nowPos = strpos($src, '$now = meel_now();');
        $mfaPos = strpos($src, "if (!empty(\$u['mfa_secret'])");
        $this->assertNotFalse($nowPos, 'Definisi $now tidak ditemukan');
        $this->assertNotFalse($mfaPos, 'Cabang MFA tidak ditemukan');
        $this->assertLessThan(
            $mfaPos,
            $nowPos,
            '$now harus didefinisikan sebelum percabangan MFA agar jalur non-MFA tidak undefined'
        );
    }

    public function testLastSessionUpdateDoesNotAssumeSuccess(): void
    {
        $src = self::loginSource();

        $this->assertStringContainsString(
            '$session_saved = $upd->execute();',
            $src,
            'Hasil execute() UPDATE last_session_id harus diperiksa'
        );
        $this->assertMatchesRegularExpression(
            '/if \(\$session_saved\) \{\s*header\("Location: \.\.\/"\);\s*exit;/s',
            $src,
            'Redirect sukses hanya boleh terjadi bila UPDATE berhasil'
        );
    }

    public function testMeelNowReturnsValidTimestampString(): void
    {
        require_once MEEL_ROOT . '/modules/core/helpers/datetime.php';

        $now = meel_now();
        $this->assertIsString($now);
        $this->assertNotSame('', $now);
        $this->assertNotFalse(strtotime($now), 'meel_now() harus berupa string waktu yang valid');
    }
}

/* reference build: MEeL-C10H15N [01323202358] */
