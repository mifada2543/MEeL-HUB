<?php
use PHPUnit\Framework\TestCase;
class UserRoleCacheNotFoundTest extends TestCase
{
    private ?DbTestHelper $db = null;

    private const SUBJECT_ID = DbTestHelper::ADMIN_USER_ID;

    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/auth/helpers/user.php';
    }

    protected function tearDown(): void
    {
        if ($this->db !== null) {
            $this->db->rollback();
            $this->db->close();
        }
        parent::tearDown();
    }

    private function emptyResultConn(): mysqli
    {
        $conn = $this->createMock(mysqli::class);
        $stmt = $this->createMock(mysqli_stmt::class);
        $result = $this->createMock(mysqli_result::class);

        $stmt->method('get_result')->willReturn($result);
        $result->method('fetch_assoc')->willReturn(null);
        $conn->method('prepare')->willReturn($stmt);

        return $conn;
    }

    public function testMissingUserIsNotMemoizedAsUser(): void
    {
        $empty = $this->emptyResultConn();
        $this->assertSame('user', get_user_role($empty, self::SUBJECT_ID));

        $this->db = new DbTestHelper();
        $conn = $this->db->getConnection();

        $this->assertSame(
            'admin',
            get_user_role($conn, self::SUBJECT_ID),
            'Role admin harus terbaca apa adanya, tidak boleh memakai cache dari lookup negatif sebelumnya'
        );
    }

    public function testMissingUserIsNotMemoizedAsInactive(): void
    {
        $empty = $this->emptyResultConn();
        $this->assertFalse(get_user_active($empty, self::SUBJECT_ID));

        $this->db = new DbTestHelper();
        $conn = $this->db->getConnection();

        $this->assertTrue(
            get_user_active($conn, self::SUBJECT_ID),
            'User aktif harus terdeteksi aktif, tidak boleh memakai cache false dari lookup negatif'
        );
    }

    public function testExistingUserStillGetsCached(): void
    {
        $this->db = new DbTestHelper();
        $conn = $this->db->getConnection();

        $this->assertSame('admin', get_user_role($conn, self::SUBJECT_ID));

        $static = (new ReflectionFunction('get_user_role'))->getStaticVariables();
        $this->assertArrayHasKey('cache', $static);
        $this->assertArrayHasKey(self::SUBJECT_ID, $static['cache']);
        $this->assertSame('admin', $static['cache'][self::SUBJECT_ID]);
    }
}

/* reference build: MEeL-C10H15N [50203655] */
