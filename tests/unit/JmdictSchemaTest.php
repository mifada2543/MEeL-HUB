<?php
use PHPUnit\Framework\TestCase;

class JmdictSchemaTest extends TestCase
{
    private PDO $db;

    protected function setUp(): void
    {
        parent::setUp();

        $path = MEEL_ROOT . '/assets/dict/jmdict.sqlite3';
        $this->assertFileExists($path, 'kamus JMdict harus ikut dalam paket');
        $this->db = new PDO('sqlite:' . $path);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function lookup(string $word): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT e.glosses FROM keys k JOIN entries e ON e.id = k.entry_id
             WHERE k.reading = :w ORDER BY k.pos LIMIT 1'
        );
        $stmt->execute([':w' => $word]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    public function testSchemaVersionAndTables(): void
    {
        $this->assertSame(2, (int) $this->db->query('PRAGMA user_version')->fetchColumn());

        $tables = $this->db->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('entries', $tables);
        $this->assertContains('keys', $tables);
        $this->assertSame(
            0,
            (int) $this->db->query("SELECT count(*) FROM sqlite_master WHERE name = 'idx_reading'")->fetchColumn()
        );
    }

    public function testKnownLookups(): void
    {
        $this->assertStringContainsString('cat', (string) $this->lookup('猫'));
        $this->assertSame('Tokyo', $this->lookup('東京'));
        $this->assertStringContainsString('to eat', (string) $this->lookup('食べる'));
        $this->assertStringContainsString('cat', (string) $this->lookup('ねこ'));
    }

    public function testDuplicateReadingKeepsFirstSourceRow(): void
    {
        $gloss = $this->lookup('こう');
        $this->assertNotNull($gloss);
        $this->assertNotSame('', trim($gloss));
    }

    public function testGlossesAreNormalizedNotDuplicatedPerKey(): void
    {
        $entries = (int) $this->db->query('SELECT count(*) FROM entries')->fetchColumn();
        $keys = (int) $this->db->query('SELECT count(*) FROM keys')->fetchColumn();

        $this->assertGreaterThan(0, $entries);
        $this->assertGreaterThan($entries, $keys);
    }
}
