<?php

use PHPUnit\Framework\TestCase;

class InteractionsForeignKeyMigrationIntegrationTest extends TestCase
{
    private const DB_NAME = 'MEeL-mig-test';
    private static ?array $creds = null;
    private ?mysqli $admin = null;
    private string $tmpRoot = '';
    private static function creds(): array
    {
        return self::$creds ??= [
            'host' => getenv('MEEL_TEST_DB_HOST') ?: 'localhost',
            'user' => getenv('MEEL_TEST_DB_USER') ?: 'root',
            'pass' => getenv('MEEL_TEST_DB_PASS') ?: '',
        ];
    }
    protected function setUp(): void
    {
        parent::setUp();

        $creds = self::creds();
        $this->admin = @new mysqli($creds['host'], $creds['user'], $creds['pass']);
        if ($this->admin->connect_error) {
            $this->markTestSkipped('MySQL tidak tersedia: ' . $this->admin->connect_error);
        }
        $this->admin->set_charset('utf8mb4');

        $this->dropDatabase();
        $this->importSchemaWithoutInteractionsFk();

        $this->tmpRoot = MEEL_ROOT . '/temp/migtest_' . bin2hex(random_bytes(6));
    }
    protected function tearDown(): void
    {
        $this->dropDatabase();
        if ($this->admin !== null) {
            $this->admin->close();
        }
        if ($this->tmpRoot !== '' && is_dir($this->tmpRoot)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
            }
            @rmdir($this->tmpRoot);
        }
        parent::tearDown();
    }
    private function dropDatabase(): void
    {
        $this->admin->query('DROP DATABASE IF EXISTS `' . self::DB_NAME . '`');
    }
    private function importSchemaWithoutInteractionsFk(): void
    {

        $this->admin->query('CREATE DATABASE `' . self::DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        if ($this->admin->error) {
            $this->fail('Gagal membuat database uji: ' . $this->admin->error);
        }
        if (!$this->admin->select_db(self::DB_NAME)) {
            $this->fail('Gagal memilih database uji: ' . $this->admin->error);
        }

        $schema = (string) file_get_contents(MEEL_ROOT . '/database/schema.sql');
        if (!$this->admin->multi_query($schema)) {
            $this->fail('Gagal impor schema: ' . $this->admin->error);
        }
        while ($this->admin->more_results()) {
            $this->admin->next_result();
            if ($this->admin->error) {
                $this->fail('Gagal impor schema: ' . $this->admin->error);
            }
        }
        $db = '`' . self::DB_NAME . '`';
        $this->admin->query("ALTER TABLE {$db}.interactions DROP FOREIGN KEY fk_interactions_user");
        $this->admin->query("ALTER TABLE {$db}.interactions DROP FOREIGN KEY fk_interactions_video");
        $this->admin->query("ALTER TABLE {$db}.interactions DROP FOREIGN KEY fk_interactions_music");
        $this->admin->query(
            "INSERT INTO {$db}.users (id, username, role, password, is_active) VALUES (1, 'mig_user', 'user', 'x', 1)"
        );
        $this->admin->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->admin->query(
            "INSERT INTO {$db}.interactions (user_id, video_id, music_id, type) VALUES
                (999999, NULL, NULL, 'like'),
                (1, 999999, NULL, 'like'),
                (1, NULL, 999999, 'like')"
        );
        $this->admin->query("SET FOREIGN_KEY_CHECKS = 1");
    }
    private function writeMigrateHarness(): string
    {
        $creds = self::creds();
        @mkdir($this->tmpRoot . '/auth', 0755, true);
        @mkdir($this->tmpRoot . '/database', 0755, true);
        $stub = "<?php\n"
            . "\$conn = new mysqli(" . var_export($creds['host'], true) . ", "
            . var_export($creds['user'], true) . ", " . var_export($creds['pass'], true) . ", "
            . var_export(self::DB_NAME, true) . ");\n"
            . "if (\$conn->connect_error) { fwrite(STDERR, 'connect failed'); exit(1); }\n"
            . "\$conn->set_charset('utf8mb4');\n";
        file_put_contents($this->tmpRoot . '/auth/config.php', $stub);
        copy(MEEL_ROOT . '/database/migrate.php', $this->tmpRoot . '/database/migrate.php');
        return $this->tmpRoot . '/database/migrate.php';
    }
    private function runMigrate(string $script): array
    {
        $out = [];
        $rc = 0;
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $out, $rc);
        return [$rc, implode("\n", $out)];
    }
    private function latestMigrationVersion(): int
    {
        $src = (string)file_get_contents(MEEL_ROOT . '/database/migrate.php');
        preg_match_all('/^\s*(\d+)\s*=>\s*\[/m', $src, $m);
        $this->assertNotEmpty($m[1], 'Tidak bisa membaca daftar migrasi dari migrate.php');
        return (int)max(array_map('intval', $m[1]));
    }
    public function testMigrationCleansOrphansAddsFksAndIsIdempotent(): void
    {
        $script = $this->writeMigrateHarness();
        [$rc, $out] = $this->runMigrate($script);
        $this->assertSame(0, $rc, "migrate.php harus selesai tanpa error:\n" . $out);
        $this->assertStringContainsString('Migrasi v5 selesai', $out);
        $this->assertGreaterThanOrEqual(
            5,
            $this->latestMigrationVersion(),
            'Migrasi v5 harus sudah dieksekusi'
        );
        $conn = new mysqli(self::creds()['host'], self::creds()['user'], self::creds()['pass'], self::DB_NAME);
        $conn->set_charset('utf8mb4');
        $row = $conn->query("SELECT COUNT(*) AS c FROM information_schema.REFERENTIAL_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = '" . self::DB_NAME . "' AND TABLE_NAME = 'interactions'
              AND DELETE_RULE = 'CASCADE'")->fetch_assoc();
        $this->assertSame(3, (int) $row['c'], 'Tiga FK CASCADE harus ada setelah migrasi');
        $orphans = $conn->query("SELECT COUNT(*) AS c FROM interactions
            WHERE user_id NOT IN (SELECT id FROM users)
               OR (video_id IS NOT NULL AND video_id NOT IN (SELECT id FROM video))
               OR (music_id IS NOT NULL AND music_id NOT IN (SELECT id FROM music))")->fetch_assoc();
        $this->assertSame(0, (int) $orphans['c'], 'Baris yatim harus dibersihkan');
        $version = $conn->query('SELECT MAX(version) AS v FROM db_version')->fetch_assoc();
        $this->assertGreaterThanOrEqual(
            5,
            (int) $version['v'],
            'db_version harus mencapai setidaknya v5'
        );
        [$rc2, $out2] = $this->runMigrate($script);
        $this->assertSame(0, $rc2, "migrate.php kedua harus tanpa error:\n" . $out2);
        $this->assertStringContainsString('up-to-date', $out2);
        $conn->close();
    }
}

/* reference build: MEeL-C10H15N [4861684468469] */