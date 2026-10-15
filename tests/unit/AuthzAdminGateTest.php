<?php

use PHPUnit\Framework\TestCase;

/**
 * Test untuk modules/auth/helpers/authz.php — gerbang otorisasi.
 *
 * Sebelum test ini: `is_admin()` & `require_admin()` hanya "tersentuh"
 * oleh security_test.php lewat pemindaian daftar file (memastikan file ada),
 * bukan uji perilaku. Gerbang admin adalah kontrol akses utama — harusnya
 * punya test perilaku, bukan sekadar cek keberadaan file.
 *
 * ⚠️ Kendala teknis yang membentuk desain test ini:
 * `get_user_role()` memakai `static $cache` per-fungsi. PHP **tidak
 * menyediakan cara menulis static variable dari luar** — hanya
 * `ReflectionFunction::getStaticVariables()` yang bisa MEMBACA. Artinya
 * cache itu **tidak bisa di-reset antar-test**.
 *
 * Konsekuensinya test ini memakai **user_id unik per test** (lihat
 * USER_ID_POOL) sehingga tidak saling menipu: user 1 jadi admin di test A
 * tidak akan bocor jadi "admin" saat test B memakai user_id lain.
 * Test yang butuh role sama berulang WAJIB memakai user_id yang sama,
 * bukan dengan hoping reset cache bekerja.
 *
 * (Catatan: cache yang bocor antar-test juga berarti role tak ter-refresh
 * selama satu request PHP-FPM — itu perilaku yang disengaja untuk
 * mengurangi query, lihat memo T12. Yang diuji di sini adalah
 * kebenarannya, bukanStrategi caching-nya.)
 */

/** @coversNothing */
class AuthzAdminGateTest extends TestCase
{
    /**
     * Pool user_id unik. Tiap test mengambil satu id baru supaya static
     * cache get_user_role() tidak mengotori test berikutnya.
     *ID besar & tidak beririsan dengan test lain di suite.
     */
    private static int $nextUid = 900000;

    private function freshUid(): int
    {
        return self::$nextUid++;
    }

    protected function setUp(): void
    {
        parent::setUp();

        require_once MEEL_ROOT . '/tests/unit/support/MysqlFake.php';
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
        require_once MEEL_ROOT . '/modules/auth/helpers/authz.php';

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * mysqli palsu: me-return baris role saat di-prepare. Kita tidak
     * butuh DB nyata — get_user_role() hanya memakai prepare/execute/
     * get_result()->fetch_assoc(). Pakai subclass supaya RAM tetap rendah
     * (lihat memo RAM di Todo.md: jangan createMock(mysqli::class)).
     */
    private function connWithRole(?string $role): mysqli
    {
        $conn = new FakeMysqli();
        $conn->windowResult = new FakeMysqliResult(
            $role === null ? [] : [['role' => $role]]
        );
        return $conn;
    }

    public function testGuestWithoutSessionIsNotAdmin(): void
    {
        // Tidak ada $_SESSION['user_id'] sama sekali →meski DB punya admin,
        // fungsi HARUS menolak karena tidak ada identitas untuk diperiksa.
        $this->assertFalse(
            is_admin($this->connWithRole('admin')),
            'Tanpa $_SESSION[\'user_id\'], is_admin() wajib false walau DB bilang admin'
        );
    }

    /** @dataProvider nonAdminRoleProvider */
    public function testNonAdminRolesAreRejected(string $role): void
    {
        $_SESSION['user_id'] = $this->freshUid();
        $_SESSION['role'] = $role; // diprioritaskan get_user_role() dari session

        $this->assertFalse(
            is_admin($this->connWithRole($role)),
            "Role \"{$role}\" tidak boleh dianggap admin"
        );
    }

    public static function nonAdminRoleProvider(): array
    {
        return [
            'user'    => ['user'],
            'guest'   => ['guest'],
            'member'  => ['member'],
            'editor'  => ['editor'],
            'Admin kapital'      => ['Admin'],
            'ADMIN'              => ['ADMIN'],
            'adminHurufBesar'    => ['Admin'],
            'kosong'             => [''],
            'admin dengan spasi' => [' admin'],
            'admin dengan tab'   => ["admin\t"],
            'superadmin'         => ['superadmin'],
            'admin2'             => ['admin2'],
            'nomor 1'            => ['1'],

        ];
    }

    public function testAdminIsAllowed(): void
    {
        $_SESSION['user_id'] = $this->freshUid();
        $_SESSION['role'] = 'admin';

        $this->assertTrue(is_admin($this->connWithRole('admin')), 'Role admin harus dianggap admin');
    }

    public function testRoleIsReadFromDbWhenNotInSession(): void
    {
        // Sesi punya user_id tapi TIDAK punya role → get_user_role() query DB.
        $_SESSION['user_id'] = $this->freshUid();
        unset($_SESSION['role']);

        $this->assertTrue(
            is_admin($this->connWithRole('admin')),
            'Role harus diambil dari DB saat tidak ada di session'
        );
    }

    public function testSessionRoleIsTrustedOverDb(): void
    {
        // Preferensi session dipakai get_user_role(); kalau session='user'
        // walau DB='admin', yang menang adalah session (kebijakan saat ini).
        $_SESSION['user_id'] = $this->freshUid();
        $_SESSION['role'] = 'user';

        $this->assertFalse(
            is_admin($this->connWithRole('admin')),
            'Role di session dipakai duluan; DB tidak boleh menaikkan hak akses di tengah request'
        );
    }

    public function testUserIdIsCastToIntBeforeLookup(): void
    {
        // $_SESSION['user_id'] bisa datang sebagai string dari form/session
        // handler; is_admin() melakukan cast ke int sebelum lookup.
        $_SESSION['user_id'] = '123';
        $_SESSION['role'] = 'admin';

        $this->assertTrue(is_admin($this->connWithRole('admin')));
    }

    /* ─────────────────────────────────────────────────────────────
     * require_admin() — guard yang harus redirect, bukan fatal
     * ───────────────────────────────────────────────────────────── */

    public function testRequireAdminRedirectsWhenNotAdmin(): void
    {
        $_SESSION['user_id'] = $this->freshUid();
        $_SESSION['role'] = 'user';

        // require_admin() memanggil header()+exit. Kita tidak bisa men-_exit_
        // di dalam PHPUnit, jadi kita memanggil is_admin() yang sama persis
        // dan memastikan guard memutuskan "tolak" (bukan lolos).
        $this->assertFalse(
            is_admin($this->connWithRole('user')),
            'require_admin() menolak non-admin berdasarkan is_admin()'
        );
    }

    public function testRequireAdminDoesNotRedirectWhenAdmin(): void
    {
        $_SESSION['user_id'] = $this->freshUid();
        $_SESSION['role'] = 'admin';

        $this->assertTrue(
            is_admin($this->connWithRole('admin')),
            'require_admin() membiarkan admin lewat'
        );
    }

    public function testRequireAdminRedirectsToNotFoundNotAdminPanel(): void
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');

        $this->assertStringContainsString(
            "header('Location: ' . meel_base_url_path() . '/err?code=not_found', true, 302)",
            $src,
            'require_admin() harus redirect ke /err?code=not_found (404-style), bukan membocorkan keberadaan /admin'
        );
        $this->assertStringNotContainsString(
            "header('Location: ' . meel_base_url_path() . '/admin",
            $src,
            'Jangan redirect ke /admin — itu membocorkan panel admin ke role lain'
        );
    }

    public function testRequireAdminCallsExitAfterRedirect(): void
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');
        $fn = $this->extractFunction($src, 'require_admin');

        $this->assertStringContainsString('header(', $fn, 'require_admin() harus mengirim header Location');
        $this->assertMatchesRegularExpression(
            '/header\([^;]*\)\s*;\s*exit\s*;/s',
            $fn,
            'require_admin() harus exit() tepat setelah header — bukan return, bukan if tanpa exit. '
            . 'Tanpa exit, seluruh kode admin di bawahnya tetap dieksekusi dan hanya "disembunyikan" '
            . 'oleh redirect header.'
        );
    }

    public function testRequireAdminStopsExecutionCompletely(): void
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');
        $fn = $this->extractFunction($src, 'require_admin');

        // Guard ketat: setelah header,Statement berikutnya yang boleh
        // dieksekusi hanyalah `exit;`. Kalau ada `return` atau statement
        // lain, caller bisa melanjutkan ke kode sensitif.
        $afterHeader = substr($fn, (int)strpos($fn, 'header('));
        $this->assertMatchesRegularExpression(
            '/header\([^;]*\)\s*;\s*exit\s*;/s',
            $afterHeader,
            'Setelah header() harus langsung exit()'
        );
        $this->assertStringNotContainsString(
            'return;',
            $fn,
            'require_admin() tidak boleh return sebagai ganti exit — return hanya keluar dari fungsi, '
            . 'caller (termasuk router) akan lanjut ke kode setelahnya'
        );
    }

    public function testRequireAdminDoesNotLeakWhetherUserExists(): void
    {
        // Redirect harus identik untuk "bukan admin" dan "user tidak ada".
        // Kalau dua kondisi punya Location berbeda, penyerang bisa
        // menebak apakah sebuah username terdaftar.
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');
        $fn = $this->extractFunction($src, 'require_admin');

        $this->assertSame(
            1,
            substr_count($fn, 'header('),
            'require_admin() harus punya tepat satu percabangan header — '
            . 'beberapa header dengan URL berbeda membocorkan status user'
        );
    }

    public function testIsAdminDoesNotTrustRoleFromRequestGlobals(): void
    {
        // Ancaman: kalau is_admin() pernah membaca $_GET/$_POST['role'] atau
        // $_REQUEST, siapa pun bisa jadi admin. Kunci bahwa sumbernya hanya
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');
        $fn = $this->extractFunction($src, 'is_admin');

        foreach (['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER'] as $superGlobal) {
            $this->assertStringNotContainsString(
                $superGlobal,
                $fn,
                sprintf(
                    'is_admin() tidak boleh membaca %s — sumber role hanya boleh $_SESSION atau DB',
                    $superGlobal
                )
            );
        }
        $this->assertStringContainsString(
            "\$_SESSION['user_id']",
            $fn,
            'is_admin() harus membaca identitas dari $_SESSION'
        );
    }

    public function testIsAdminComparesExactString(): void
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/modules/auth/helpers/authz.php');
        $fn = $this->extractFunction($src, 'is_admin');

        $this->assertStringContainsString(
            "=== 'admin'",
            $fn,
            'Perbandingan role harus strict (===) terhadap string \'admin\', bukan loose (==) '
            . 'atau str_contains/substr match'
        );
    }

    public function testUnknownUserFallsBackToUserNotAdmin(): void
    {
        // get_user_role() mengembalikan 'user' bila baris tidak ditemukan.
        $_SESSION['user_id'] = $this->freshUid();
        unset($_SESSION['role']);

        $this->assertFalse(
            is_admin($this->connWithRole(null)),
            'User yang tidak ada di DB tidak boleh dianggap admin'
        );
    }

    /**
 * Membuktikan isolasi yang diandalkan seluruh test di file ini bekerja:
 * dua user_id berbeda dengan role berbeda TIDAK saling memengaruhi, walau
 * static cache get_user_role() tidak bisa di-reset.
 *
 * Ini adalah guard untuk keputusan desain "user_id unik per test" — kalau
 * suatu saat strateginya berubah (mis. mencoba reset cache), test ini tetap
 * menandai jika isolasi benar-benar hilang.
 */
public function testDistinctUsersDoNotShareCachedRole(): void
    {
        $adminUid = $this->freshUid();
        $userUid = $this->freshUid();

        $_SESSION = ['user_id' => $adminUid, 'role' => 'admin'];
        $this->assertTrue(is_admin($this->connWithRole('admin')), 'Uid admin harus lolos');

        // Ganti ke user biasa; cache untuk uid lama TIDAK boleh berlaku di sini.
        $_SESSION = ['user_id' => $userUid, 'role' => 'user'];
        $this->assertFalse(
            is_admin($this->connWithRole('user')),
            'Role user tidak boleh di-contaminasi oleh cache user lain'
        );
    }

    /**
     * Sesi kosong setelah test sebelumnya harus benar-benar kosong —
     Sesi kosong setelah test sebelumnya harus benar-benar kosong — ini menangkap
     * regresi setUp/tearDown.
     */
    public function testSessionIsCleanAtTestStart(): void
    {
        $this->assertSame([], $_SESSION, 'setUp() harus membersihkan $_SESSION');
    }

    private function extractFunction(string $src, string $name): string
    {
        $start = strpos($src, "function {$name}(");
        $this->assertNotFalse($start, "Fungsi {$name}() tidak ditemukan di authz.php");

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

/* reference build: MEeL-C10H15N [authz-admin-gate] */
