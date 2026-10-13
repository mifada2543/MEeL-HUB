<?php
use PHPUnit\Framework\TestCase;
class UserRoleCacheNotFoundTest extends TestCase
{
    private const SUBJECT_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
        unset($_SESSION['user_id'], $_SESSION['role']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['user_id'], $_SESSION['role']);
        parent::tearDown();
    }

    private function connReturning(?array $row): mysqli
    {
        $conn = $this->createMock(mysqli::class);
        $stmt = $this->createMock(mysqli_stmt::class);
        $result = $this->createMock(mysqli_result::class);

        $stmt->method('get_result')->willReturn($result);
        $result->method('fetch_assoc')->willReturn($row);
        $conn->method('prepare')->willReturn($stmt);

        return $conn;
    }

    public function testMissingUserIsNotMemoizedAsUser(): void
    {
        $this->assertSame('user', get_user_role($this->connReturning(null), self::SUBJECT_ID));

        $this->assertSame(
            'admin',
            get_user_role($this->connReturning(['role' => 'admin']), self::SUBJECT_ID),
            'Role asli harus terbaca, tidak boleh memakai cache dari lookup negatif sebelumnya'
        );
    }

    public function testMissingUserIsNotMemoizedAsInactive(): void
    {
        $this->assertFalse(get_user_active($this->connReturning(null), self::SUBJECT_ID));

        $this->assertTrue(
            get_user_active($this->connReturning(['is_active' => '1']), self::SUBJECT_ID),
            'User aktif harus terdeteksi aktif, tidak boleh memakai cache false dari lookup negatif'
        );
    }

    public function testExistingUserStillGetsCached(): void
    {
        $this->assertSame('admin', get_user_role($this->connReturning(['role' => 'admin']), self::SUBJECT_ID));

        $static = (new ReflectionFunction('get_user_role'))->getStaticVariables();
        $this->assertArrayHasKey('cache', $static);
        $this->assertArrayHasKey(self::SUBJECT_ID, $static['cache']);
        $this->assertSame('admin', $static['cache'][self::SUBJECT_ID]);
    }
}

/* reference build: MEeL-C10H15N [50203655] */