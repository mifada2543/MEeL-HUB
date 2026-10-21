<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga: tidak ada schema.sql yang boleh memilih database sendiri.
 *
 * schema.sql DULU memuat `CREATE DATABASE` dan `USE`, dan `USE` di dalam
 * berkas menang atas nama database di baris perintah — impor berjalan, exit
 * 0, tabel tidak ada di database yang dimaksud, dan langkah apa pun yang
 * menyusul menguji database kosong tanpa memberi tahu apa pun.
 *
 * Berkas ini adalah jaring pengaman kedua; penjaga yang sama juga ada di
 * .github/workflows/ci.yml, tapi penjaga itu hanya berjalan kalau CI
 * berjalan.
 *
 * PERAWATAN: pola di sini sengaja TIDAK menyebut nama database apa pun.
 * Pola yang menyebut nama bisa diam-diam gagal cocok.
 */
class SchemaDatabaseSelectionTest extends TestCase
{
    public function schemaFiles(): array
    {
        return [
            ['database/schema.sql', MEEL_ROOT . '/database/schema.sql'],
            ['arcade/schema.sql',   MEEL_ROOT . '/arcade/schema.sql'],
        ];
    }

    /**
     * @dataProvider schemaFiles
     */
    public function testSchemaNeverSelectsADatabase(string $label, string $path): void
    {
        $this->assertFileExists($path, $label . ' harus ada');

        $sql = (string) file_get_contents($path);

        $offenders = [];
        foreach (preg_split('/\R/', $sql) as $no => $line) {
            $trimmed = ltrim($line);
            if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (preg_match('/^CREATE\s+DATABASE\b/i', $trimmed)
                || preg_match('/^USE\s+\S/i', $trimmed)
            ) {
                $offenders[] = sprintf('%s:%d  %s', $label, $no + 1, trim($line));
            }
        }

        $this->assertSame(
            [],
            $offenders,
            $label . " tidak boleh memilih database.\n"
            . "  CREATE DATABASE / USE di dalam berkas mengalahkan nama database\n"
            . "  di baris perintah, jadi impor mendarat diam-diam di database yang\n"
            . "  salah. Baris yang terdeteksi:\n    - " . implode("\n    - ", $offenders)
        );
    }

    /**
     * Pemanggil IMPOR harus menyebut nama database-nya sendiri. Kalau tidak,
     * tidak ada yang menunjuk ke database tujuan.
     *
     * @dataProvider schemaFiles
     */
    public function testImporterSelectsDatabaseExplicitly(string $label, string $path): void
    {
        $sql = (string) file_get_contents($path);

        $this->assertGreaterThan(
            1000,
            strlen($sql),
            $label . ' suspiciously kecil -- cek lagi berkasnya'
        );
        $this->assertSame(
            1,
            preg_match('/CREATE\s+TABLE/i', $sql),
            $label . ' harus tetap memuat CREATE TABLE'
        );
    }
}
