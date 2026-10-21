<?php
use PHPUnit\Framework\TestCase;

require_once MEEL_ROOT . '/modules/media/AdminActivityRepository.php';
require_once MEEL_ROOT . '/modules/media/AdminUploadQueueRepository.php';

/**
 * Penyatuan dua repository panel admin.
 *
 * `AdminActivityRepository` dan `AdminUploadQueueRepository` dulunya
 * menyalin seluruh query satu sama lain; query bersama kini ada di
 * `modules/media/AdminFilteredRepository.php`.
 *
 * Test ini menahan:
 *  1. Subclass tidak boleh menyalin query bersama balik.
 *  2. Tiap repository harus tetap menunjuk tabel dan alias yang benar.
 *  3. Perilaku filter tiap jenis repository harus tetap berbeda.
 *
 * @coversNothing
 */
class AdminFilteredRepositoryTest extends TestCase
{
    private const SHARED_METHODS = [
        'buildFilter',
        'countFiltered',
        'fetchPage',
        'fetchAll',
        'clearOlderThan',
    ];

    /**
     * @return array<string, string>
     */
    private function subclasses(): array
    {
        return [
            'AdminActivityRepository' => MEEL_ROOT . '/modules/media/AdminActivityRepository.php',
            'AdminUploadQueueRepository' => MEEL_ROOT . '/modules/media/AdminUploadQueueRepository.php',
        ];
    }

    /**
     * @return object
     */
    private function makeWithoutConnection(string $class)
    {
        $reflection = new ReflectionClass($class);
        return $reflection->newInstanceWithoutConstructor();
    }

    /**
     * @return mixed
     */
    private function readProtected(object $obj, string $property)
    {
        $prop = new ReflectionProperty(AdminFilteredRepository::class, $property);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }

    /**
     * @return string
     */
    private function callProtected(object $obj, string $method): string
    {
        $m = new ReflectionMethod($obj, $method);
        $m->setAccessible(true);
        return (string) $m->invoke($obj);
    }

    public function testTheSharedBaseClassExists(): void
    {
        $this->assertFileExists(
            MEEL_ROOT . '/modules/media/AdminFilteredRepository.php',
            'Basis repository yang disatukan hilang'
        );
        $this->assertTrue(
            class_exists(AdminFilteredRepository::class),
            'AdminFilteredRepository tidak terdefinisi'
        );
    }

    public function testBothRepositoriesExtendTheSharedBase(): void
    {
        foreach (array_keys($this->subclasses()) as $class) {
            $this->assertTrue(
                is_subclass_of($class, AdminFilteredRepository::class),
                "{$class} harus mewarisi AdminFilteredRepository"
            );
        }
    }

    public function testTheSharedMethodsLiveInTheBaseAndNotInTheSubclasses(): void
    {
        $base = new ReflectionClass(AdminFilteredRepository::class);

        foreach (self::SHARED_METHODS as $method) {
            $this->assertTrue(
                $base->hasMethod($method),
                "{$method} seharusnya ada di basis"
            );
            $this->assertSame(
                AdminFilteredRepository::class,
                $base->getMethod($method)->getDeclaringClass()->getName(),
                "{$method} harus dideklarasikan di basis"
            );
        }

        foreach ($this->subclasses() as $class => $file) {
            $src = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'LEFT JOIN users u',
                $src,
                "{$class} menyalin query bersama secara inline"
            );
            $this->assertStringNotContainsString(
                'AUTO_INCREMENT = ',
                $src,
                "{$class} menyalin logika AUTO_INCREMENT secara inline"
            );
            foreach (self::SHARED_METHODS as $method) {
                $this->assertFalse(
                    (new ReflectionClass($class))->getMethod($method)->getDeclaringClass()->getName() === $class,
                    "{$class} mendeklarasikan ulang {$method}"
                );
            }
        }
    }

    public function testEachRepositoryPointsAtItsOwnTableAndAlias(): void
    {
        $expected = [
            'AdminActivityRepository' => ['activity_log', 'al'],
            'AdminUploadQueueRepository' => ['upload_queue', 'uq'],
        ];

        foreach ($expected as $class => [$table, $alias]) {
            $repo = $this->makeWithoutConnection($class);
            $this->assertSame(
                $table,
                $this->callProtected($repo, 'tableName'),
                "{$class} salah menunjuk tabel"
            );
            $this->assertSame(
                $alias,
                $this->callProtected($repo, 'tableAlias'),
                "{$class} salah memakai alias"
            );
        }
    }

    public function testActivityFilterTargetsActionAndIpAddress(): void
    {
        $repo = $this->makeWithoutConnection(AdminActivityRepository::class);
        $repo->buildFilter('login', '203.0', 7);

        $this->assertSame(
            "1=1 AND al.action = ? AND (u.username LIKE ? OR al.ip_address LIKE ?) AND al.created_at >= NOW() - INTERVAL ? DAY",
            $this->readProtected($repo, 'where_sql')
        );
        $this->assertSame(['login', '%203.0%', '%203.0%', 7], $this->readProtected($repo, 'params'));
        $this->assertSame('sssi', $this->readProtected($repo, 'types'));
    }

    public function testActivityFilterWithoutPrimaryOrSearchKeepsOnlyTheDateWindow(): void
    {
        $repo = $this->makeWithoutConnection(AdminActivityRepository::class);
        $repo->buildFilter('', '', 30);

        $this->assertSame(
            '1=1 AND al.created_at >= NOW() - INTERVAL ? DAY',
            $this->readProtected($repo, 'where_sql')
        );
        $this->assertSame([30], $this->readProtected($repo, 'params'));
        $this->assertSame('i', $this->readProtected($repo, 'types'));
    }

    public function testUploadFilterTargetsStatusAndUrl(): void
    {
        $repo = $this->makeWithoutConnection(AdminUploadQueueRepository::class);
        $repo->buildFilter('processing', 'http', 14);

        $this->assertSame(
            "1=1 AND uq.status = ? AND (u.username LIKE ? OR uq.url LIKE ?) AND uq.created_at >= NOW() - INTERVAL ? DAY",
            $this->readProtected($repo, 'where_sql')
        );
        $this->assertSame(['processing', '%http%', '%http%', 14], $this->readProtected($repo, 'params'));
        $this->assertSame('sssi', $this->readProtected($repo, 'types'));
    }

    /**
     * Status antrean yang tidak dikenal harus diabaikan, bukan ikut
     * difilter — kalau diteruskan, hasilnya baris kosong tanpa alasan
     * yang terlihat.
     *
     * @dataProvider unknownStatusProvider
     */
    public function testUploadFilterIgnoresAnUnknownStatus(string $status): void
    {
        $repo = $this->makeWithoutConnection(AdminUploadQueueRepository::class);
        $repo->buildFilter($status, '', 30);

        $this->assertSame(
            '1=1 AND uq.created_at >= NOW() - INTERVAL ? DAY',
            $this->readProtected($repo, 'where_sql'),
            "Status '{$status}' seharusnya tidak menambah kondisi"
        );
        $this->assertSame([30], $this->readProtected($repo, 'params'));
    }

    public static function unknownStatusProvider(): array
    {
        return [
            'kosong' => [''],
            'tidak dikenal' => ['bogus'],
            'huruf besar' => ['PROCESSING'],
            'dengan spasi' => ['processing '],
        ];
    }

    public function testBuildFilterResetsAnyPreviousFilter(): void
    {
        $repo = $this->makeWithoutConnection(AdminUploadQueueRepository::class);

        $repo->buildFilter('processing', 'abc', 7);
        $repo->buildFilter('', '', 30);

        $this->assertSame(
            '1=1 AND uq.created_at >= NOW() - INTERVAL ? DAY',
            $this->readProtected($repo, 'where_sql'),
            'Filter sebelumnya tidak boleh bocor ke filter berikutnya'
        );
        $this->assertSame([30], $this->readProtected($repo, 'params'));
        $this->assertSame('i', $this->readProtected($repo, 'types'));
    }

    /**
     * Method khas tiap repository harus tetap ada dan hanya ada di sana.
     */
    public function testTypeSpecificMethodsStayInTheirOwnRepository(): void
    {
        $activity = new ReflectionClass(AdminActivityRepository::class);
        $upload = new ReflectionClass(AdminUploadQueueRepository::class);

        foreach (['getDistinctActions', 'getWeeklyStats', 'clearAll'] as $method) {
            $this->assertTrue($activity->hasMethod($method), "{$method} hilang dari repository aktivitas");
            $this->assertFalse($upload->hasMethod($method), "{$method} tidak boleh ada di repository antrean");
        }

        $this->assertTrue($upload->hasMethod('getStats'), 'getStats hilang dari repository antrean');
        $this->assertFalse($activity->hasMethod('getStats'), 'getStats tidak boleh ada di repository aktivitas');
    }
}

/* reference build: MEeL-C6H20N7O [admin-filtered-repository] */
