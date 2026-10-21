<?php
use PHPUnit\Framework\TestCase;

/** @coversNothing */
class BindParamTypeTest extends TestCase
{
    private const TARGET = '/arcade/rhythm/api/upload.php';
    private const SCHEMA = '/arcade/schema.sql';
    private const TABLE = 'arcade_song';

    private function source(): string
    {
        return (string) file_get_contents(MEEL_ROOT . self::TARGET);
    }

    private function columnTypes(): array
    {
        $sql = (string) file_get_contents(MEEL_ROOT . self::SCHEMA);
        $start = stripos($sql, 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '`');
        $this->assertNotFalse($start, 'tabel ' . self::TABLE . ' tidak ditemukan di schema');

        $end = strpos($sql, ');', $start);
        $body = substr($sql, $start, ($end === false ? strlen($sql) : $end) - $start);

        $types = [];
        if (preg_match_all('/^\s*`(\w+)`\s+([a-z]+)/mi', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $col) {
                $types[strtolower($col[1])] = strtolower($col[2]);
            }
        }
        $this->assertNotEmpty($types, 'kolom arcade_song tidak ter-parse dari schema');
        return $types;
    }

    private function expectedChar(string $sqlType): string
    {
        return match (true) {
            str_contains($sqlType, 'int') => 'i',
            str_contains($sqlType, 'float'),
            str_contains($sqlType, 'double'),
            str_contains($sqlType, 'decimal') => 'd',
            default => 's',
        };
    }

    private function boundTypes(string $window): array
    {
        $this->assertSame(
            1,
            preg_match('/bind_param\(\s*"([is]+)"/i', $window, $m),
            'bind_param tidak ter-parse'
        );
        return str_split(strtolower($m[1]));
    }

    private function assertBinding(string $label, array $pairs): void
    {
        $types = $this->columnTypes();

        $this->assertNotEmpty($pairs, "{$label}: tidak ada kolom ter-parse");

        foreach ($pairs as $i => [$column, $char]) {
            $column = strtolower(trim((string) $column));
            if (!isset($types[$column])) {
                $this->fail("{$label}: kolom {$column} tidak ada di schema");
            }
            $this->assertSame(
                $this->expectedChar($types[$column]),
                $char,
                sprintf(
                    '%s: kolom %s bertipe %s di schema -> harus "%s", bukan "%s" (posisi %d)',
                    $label,
                    $column,
                    $types[$column],
                    $this->expectedChar($types[$column]),
                    (string) $char,
                    $i
                )
            );
        }
    }

    public function testInsertBindTypesMatchSchemaColumns(): void
    {
        $src = $this->source();
        $from = stripos($src, 'INSERT INTO arcade_song');
        $this->assertNotFalse($from, 'INSERT INTO arcade_song tidak ditemukan');

        $window = substr($src, $from, 900);
        $this->assertSame(
            1,
            preg_match('/\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/is', $window, $m),
            'INSERT tidak ter-parse'
        );

        $columns = array_map('trim', explode(',', $m[1]));
        $values = array_map('trim', explode(',', $m[2]));
        $bound = $this->boundTypes(substr($window, (int) strpos($window, 'VALUES')));

        $pairs = [];
        $bindIndex = 0;
        foreach ($values as $colIndex => $v) {
            if ($v === '?') {
                $pairs[] = [$columns[$colIndex] ?? '__missing__', $bound[$bindIndex] ?? null];
                $bindIndex++;
            }
        }

        $this->assertCount(count($pairs), $bound, 'INSERT: jumlah karakter type harus sama dengan jumlah placeholder');
        $this->assertBinding('INSERT', $pairs);
    }

    public function testUpdateBindTypesMatchSchemaColumns(): void
    {
        $src = $this->source();
        $from = stripos($src, 'UPDATE arcade_song SET');
        $this->assertNotFalse($from, 'UPDATE arcade_song tidak ditemukan');

        $window = substr($src, $from, 900);
        $this->assertSame(
            1,
            preg_match(
                '/SET\s+(.+?)\s*updated_at\s*=\s*CURRENT_TIMESTAMP\s+WHERE\s+(\w+)\s*=\s*\?/is',
                $window,
                $m
            ),
            'UPDATE tidak ter-parse'
        );

        preg_match_all('/(\w+)\s*=\s*\?/i', $m[1], $c);
        $columns = $c[1];
        $columns[] = $m[2];

        $bound = $this->boundTypes($window);

        $pairs = [];
        foreach ($columns as $i => $column) {
            $pairs[] = [$column, $bound[$i] ?? null];
        }

        $this->assertCount(count($pairs), $bound, 'UPDATE: jumlah karakter type harus sama dengan jumlah placeholder');
        $this->assertBinding('UPDATE', $pairs);
    }
}
