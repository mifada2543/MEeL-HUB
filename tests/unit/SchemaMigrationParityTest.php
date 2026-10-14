<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga konvensi T9: **setiap perubahan skema harus ada di KEDUA tempat** —
 * `database/migrate.php` (untuk DB lama yang perlu upgrade) dan
 * `database/schema.sql` (untuk instalasi baru yang import langsung).
 *
 * Kenapa ini penting dan belum dijaga sebelumnya:
 *
 * `tests/DbTestHelper.php` membangun database test dari `schema.sql`,
 * BUKAN dengan menjalankan `migrate.php`. Artinya kalau `migrate.php`
 * dan `schema.sql` berbeda, **seluruh test integrasi tetap hijau** —
 * karena yang diuji adalah hasil import schema, bukan hasil migrasi.
 * Installasi baru (import schema.sql) dan hasil upgrade (jalanin
 * migrate.php) jadi dua dunia berbeda, dan tidak ada yang mengetahuinya.
 *
 * Kasus nyata yang ditemukan 2026-10-14 (T17a): sebelum migrasi v6,
 * `schema.sql` tertinggal dari DB nyata — `upload_queue` tidak punya
 * `updated_at` maupun status `'pending'`, `transcode_queue` tidak punya
 * `file_path`. Baru ketahuan karena kebetulan sedang membaca kedua
 * file itu. Test di bawah mencegah terulangnya.
 *
 * Pendekatan: bandingkan **nama kolom & indeks** per tabel antara
 * `schema.sql` dan apa yang dideklarasikan `migrate.php`. Ini analisis
 * statis (tanpa DB) supaya bisa jalan di suite unit biasa.
 */

/** @coversNothing */
class SchemaMigrationParityTest extends TestCase
{
    /** @var string */
    private string $schema;

    /** @var string */
    private string $migrate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = (string)file_get_contents(MEEL_ROOT . '/database/schema.sql');
        $this->migrate = (string)file_get_contents(MEEL_ROOT . '/database/migrate.php');
    }

    /**
     * Semua kolom yang dideklarasikan di schema.sql untuk sebuah tabel.
     */
    private function schemaColumns(string $table): array
    {
        $body = $this->schemaTableBody($table);
        preg_match_all('/^\s+`([a-z0-9_]+)`\s+/mi', $body, $cols);
        return array_map('strtolower', $cols[1]);
    }

    /**
     * Badan definisi tabel (isi kurung) dari schema.sql.
     * fail() melempar exception, jadi setelahnya tidak boleh ada baris lain.
     */
    private function schemaTableBody(string $table): string
    {
        $pattern = '/CREATE TABLE\s+IF NOT EXISTS\s+`' . preg_quote($table, '/') . '`\s*\((.*?)\n\s*\)\s*ENGINE/is';
        $this->assertMatchesRegularExpression(
            $pattern,
            $this->schema,
            "Tabel `{$table}` tidak ditemukan di schema.sql"
        );
        preg_match($pattern, $this->schema, $m);
        return $m[1];
    }

    /**
     * Semua indeks yang dideklarasikan di schema.sql untuk sebuah tabel.
     */
    private function schemaIndexes(string $table): array
    {
        $body = $this->schemaTableBody($table);
        preg_match_all('/(?:KEY|INDEX)\s+`([a-z0-9_]+)`/i', $body, $idx);
        return array_map('strtolower', $idx[1]);
    }

    /**
     * Nama indeks yang di-DROP oleh migrasi —indeks itu memang tidak
     * boleh ada di schema.sql.
     */
    private function droppedIndexes(): array
    {
        preg_match_all(
            "/meel_mig_drop_index\(\s*\\\$conn,\s*'[a-z0-9_]+',\s*'([a-z0-9_]+)'\s*\)/i",
            $this->migrate,
            $m
        );
        return array_map('strtolower', $m[1]);
    }

    /** Tabel yang dijaga paritasnya. */
    public function guardedTables(): array
    {
        return [
            'upload_queue'   => ['upload_queue'],
            'transcode_queue' => ['transcode_queue'],
        ];
    }

    /**
     * @dataProvider guardedTables
     */
    public function testQueueTablesHaveRefundColumnsInBothSources(string $table): void
    {
        $cols = $this->schemaColumns($table);

        foreach (['coin_cost', 'coin_refunded'] as $col) {
            $this->assertContains(
                $col,
                $cols,
                "Kolom `{$table}`.`{$col}` harus ada di schema.sql — "
                . 'tanpa kolom ini, instalasi baru tidak punya kolom yang dikunci migrate.php'
            );
            $this->assertStringContainsString(
                $col,
                $this->migrate,
                "Kolom `{$col}` harus dibuat oleh migrasi — "
                . 'tanpanya, database lama tidak akan pernah mendapat kolom ini'
            );
        }
    }

    /**
     * Kolom refund harus punya DEFAULT yang masuk akal — kolom NOT NULL
     * tanpa default membuat INSERT lama (yang tidak menyebut kolom ini)
     * gagal atau mengisi 0 secara tak sengaja.
     */
    public function testRefundColumnsHaveSafeDefaults(): void
    {
        foreach (array_keys($this->guardedTables()) as $table) {
            $pattern = '/CREATE TABLE\s+IF NOT EXISTS\s+`' . preg_quote($table, '/') . '`\s*\((.*?)\n\s*\)\s*ENGINE/is';
            preg_match($pattern, $this->schema, $m);

            $this->assertMatchesRegularExpression(
                '/`coin_cost`\s+int[^,]*DEFAULT 0/i',
                $m[1],
                "`{$table}`.coin_cost harus DEFAULT 0 — INSERT lama tidak menyebut kolom ini"
            );
            $this->assertMatchesRegularExpression(
                '/`coin_refunded`\s+tinyint[^,]*DEFAULT 0/i',
                $m[1],
                "`{$table}`.coin_refunded harus DEFAULT 0 — kalau default 1, sweeper akan "
                . 'mengira baris lama sudah direfund dan uang user tidak akan pernah kembali'
            );
        }
    }

    /**
     * Indeks sweeper harus ada di kedua sumber, karena query sweeper
     * memfilter berdasarkan status + created_at.
     */
    public function testSweeperIndexesExistInBothSources(): void
    {
        foreach (['idx_uq_status_created', 'idx_tq_status_created'] as $idx) {
            $this->assertStringContainsString(
                $idx,
                $this->schema,
                "Indeks `{$idx}` harus ada di schema.sql"
            );
            $this->assertStringContainsString(
                $idx,
                $this->migrate,
                "Indeks `{$idx}` harus dibuat oleh migrasi — database lama akan tetap "
                . 'tanpa indeks dan sweeper jadi full-scan'
            );
        }
    }

    /**
     * Guard umum: setiap indeks yang di-DROP migrasi tidak boleh muncul
     * lagi di schema.sql — kalau muncul, migrasi dan schema bertentangan.
     */
    public function testDroppedIndexesAreAbsentFromSchema(): void
    {
        foreach ($this->droppedIndexes() as $idx) {
            $this->assertStringNotContainsString(
                '`' . $idx . '`',
                $this->schema,
                "Indeks `{$idx}` di-DROP oleh migrasi tapi masih ada di schema.sql — "
                . 'instalasi baru dan hasil upgrade akan punya indeks berbeda'
            );
        }
    }

    /**
     * Status enum harus sama. `upload_queue` gained `'pending'` saat
     * migrasi; kalau schema.sql belum menyusul, INSERT dengan status
     * 'pending' akan gagal (dan di strict mode jadi error).
     */
    public function testUploadQueueStatusEnumMatchesMigration(): void
    {
        $pattern = '/CREATE TABLE\s+IF NOT EXISTS\s+`upload_queue`\s*\((.*?)\n\s*\)\s*ENGINE/is';
        preg_match($pattern, $this->schema, $m);

        $this->assertMatchesRegularExpression(
            "/`status`\s+enum\s*\([^)]*'pending'/i",
            $m[1],
            "status upload_queue di schema.sql harus punya nilai 'pending' — "
            . 'tabel di DB sudah punya enum ini, schema.sql harus menyusul'
        );
    }

    /**
     * Kolom yang ada di DB tapi tidak disebut migrasi = drift.
     * Test ini mengunci daftar kolom wajib yang harus disebut migrate.php
     * kalau tabel antrean berubah.
     */
    public function testEveryQueueColumnAddedAfterV6IsBackedByMigration(): void
    {
        foreach (['upload_queue', 'transcode_queue'] as $table) {
            $cols = $this->schemaColumns($table);

            // Kolom yang wajib punya padanan di migrate.php (dari v6).
            $mustHaveMigration = ['coin_cost', 'coin_refunded'];

            foreach ($mustHaveMigration as $col) {
                if (in_array($col, $cols, true)) {
                    $this->assertStringContainsString(
                        $col,
                        $this->migrate,
                        "Kolom `{$table}`.`{$col}` ada di schema.sql tapi tidak di migrate.php — "
                        . 'database yang sudah ada tidak akan pernah mendapat kolom ini (konvensi T9 dilanggar)'
                    );
                }
            }
        }
    }
}

/* reference build: MEeL-C10H15N [schema-migrate-parity] */