<?php
/**
 * Normalisasi kamus JMdict (SQLite) ke skema v2.
 *
 * Skema lama menyimpan gloss yang sama berulang kali untuk setiap bentuk
 * kata (kanji/kana), sehingga 496k baris hanya berisi 196k gloss unik.
 * Skema v2 memisahkan entri gloss dari kunci pencarian dan memakai
 * WITHOUT ROWID untuk tabel keys.
 *
 * Pemakaian:
 *   php scripts/build-jmdict-sqlite.php
 *   php scripts/build-jmdict-sqlite.php --source=assets/dict/jmdict.sqlite3 \
 *       --out=/tmp/jmdict-v2.sqlite3 --force
 *
 * Hasil tidak menimpa sumber. Setelah diverifikasi, ganti berkas lama:
 *   mv /tmp/jmdict-v2.sqlite3 assets/dict/jmdict.sqlite3
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan dari terminal: php scripts/build-jmdict-sqlite.php\n");
}

const MEEL_JMDICT_SCHEMA_VERSION = 2;

function jmdict_fail(string $message): never
{
    fwrite(STDERR, '[jmdict] ERROR: ' . $message . "\n");
    exit(1);
}

function jmdict_say(string $message): void
{
    echo '[jmdict] ' . $message . "\n";
}

function jmdict_arg(array $argv, string $name): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

function jmdict_has_flag(array $argv, string $name): bool
{
    return in_array('--' . $name, $argv, true);
}

function jmdict_table_columns(PDO $db, string $table): array
{
    $cols = [];
    foreach ($db->query('PRAGMA table_info(' . $table . ')') as $row) {
        $cols[] = (string) $row['name'];
    }
    return $cols;
}

function jmdict_lookup(PDO $db, string $sql, string $word): ?string
{
    $stmt = $db->prepare($sql);
    $stmt->execute([':w' => $word]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

$root = dirname(__DIR__);
$source = jmdict_arg($argv, 'source') ?? $root . '/assets/dict/jmdict.sqlite3';
$out = jmdict_arg($argv, 'out') ?? $root . '/assets/dict/jmdict-v2.sqlite3';
$force = jmdict_has_flag($argv, 'force');

if (!is_file($source)) {
    jmdict_fail('sumber tidak ditemukan: ' . $source);
}
if (!is_dir(dirname($out))) {
    jmdict_fail('direktori output tidak ditemukan: ' . dirname($out));
}
if (realpath($source) === realpath($out)) {
    jmdict_fail('sumber dan output tidak boleh sama');
}
if (is_file($out) && !$force) {
    jmdict_fail('output sudah ada, pakai --force untuk menimpa: ' . $out);
}
if (!extension_loaded('pdo_sqlite')) {
    jmdict_fail('ekstensi pdo_sqlite tidak tersedia');
}

try {
    $src = new PDO('sqlite:' . $source);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $src->exec('PRAGMA query_only = ON');

    $columns = jmdict_table_columns($src, 'entries');
    if ($columns === []) {
        jmdict_fail('tabel entries tidak ditemukan di sumber');
    }
    if ($columns === ['id', 'glosses']) {
        $hasKeys = (int) $src->query(
            "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = 'keys'"
        )->fetchColumn();
        if ($hasKeys === 1) {
            jmdict_say('sumber sudah memakai skema v2; tidak ada yang dilakukan');
            exit(0);
        }
    }
    if ($columns !== ['reading', 'glosses']) {
        jmdict_fail('skema sumber bukan v1 (kolom: ' . implode(', ', $columns) . ')');
    }

    $sourceRows = (int) $src->query('SELECT count(*) FROM entries')->fetchColumn();
    $distinctGlosses = (int) $src->query('SELECT count(DISTINCT glosses) FROM entries')->fetchColumn();
    if ($sourceRows === 0 || $distinctGlosses === 0) {
        jmdict_fail('sumber kosong');
    }

    if (is_file($out)) {
        @unlink($out);
    }

    $db = new PDO('sqlite:' . $out);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA journal_mode = OFF');
    $db->exec('PRAGMA synchronous = OFF');
    $db->exec('PRAGMA page_size = 4096');
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('ATTACH DATABASE ' . $db->quote($source) . ' AS src');

    $db->exec(
        'CREATE TABLE entries (
            id INTEGER PRIMARY KEY,
            glosses TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE keys (
            reading TEXT NOT NULL,
            pos INTEGER NOT NULL,
            entry_id INTEGER NOT NULL,
            PRIMARY KEY (reading, pos)
        ) WITHOUT ROWID'
    );

    jmdict_say('menyalin ' . number_format($distinctGlosses) . ' gloss unik...');
    $db->exec('INSERT INTO entries(glosses) SELECT DISTINCT glosses FROM src.entries ORDER BY glosses');

    jmdict_say('membangun indeks sementara...');
    $db->exec('CREATE INDEX idx_gloss_tmp ON entries(glosses)');

    jmdict_say('memetakan ' . number_format($sourceRows) . ' kunci...');
    $db->exec(
        'INSERT INTO keys(reading, pos, entry_id)
         SELECT s.reading, s.rowid, e.id
         FROM src.entries s
         JOIN entries e ON e.glosses = s.glosses'
    );

    $db->exec('DROP INDEX idx_gloss_tmp');
    $db->exec('DETACH DATABASE src');
    $db->exec('PRAGMA user_version = ' . MEEL_JMDICT_SCHEMA_VERSION);
    $db->exec('VACUUM');

    $integrity = $db->query('PRAGMA integrity_check')->fetchColumn();
    if ($integrity !== 'ok') {
        jmdict_fail('integrity_check gagal: ' . (string) $integrity);
    }

    $entries = (int) $db->query('SELECT count(*) FROM entries')->fetchColumn();
    $keys = (int) $db->query('SELECT count(*) FROM keys')->fetchColumn();
    if ($entries !== $distinctGlosses) {
        jmdict_fail("jumlah entries tidak cocok: {$entries} != {$distinctGlosses}");
    }
    if ($keys !== $sourceRows) {
        jmdict_fail("jumlah keys tidak cocok: {$keys} != {$sourceRows}");
    }

    $oldSql = 'SELECT glosses FROM entries WHERE reading = :w ORDER BY rowid LIMIT 1';
    $newSql = 'SELECT e.glosses FROM keys k JOIN entries e ON e.id = k.entry_id
               WHERE k.reading = :w ORDER BY k.pos LIMIT 1';

    $samples = ['猫', '東京', '食べる', 'こう', 'かん', 'し', '初音ミク', 'ナイトメア'];
    foreach ($samples as $word) {
        $before = jmdict_lookup($src, $oldSql, $word);
        $after = jmdict_lookup($db, $newSql, $word);
        if ($before !== $after) {
            jmdict_fail('lookup berubah untuk "' . $word . '": ' . var_export($before, true) . ' -> ' . var_export($after, true));
        }
    }

    $random = $src->query('SELECT DISTINCT reading FROM entries ORDER BY RANDOM() LIMIT 500')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($random as $word) {
        $before = jmdict_lookup($src, $oldSql, (string) $word);
        $after = jmdict_lookup($db, $newSql, (string) $word);
        if ($before !== $after) {
            jmdict_fail('lookup acak berubah untuk "' . $word . '"');
        }
    }

    $db = null;
    $src = null;

    $outSize = (int) filesize($out);
    jmdict_say(sprintf(
        'selesai: %s (%s baris, %s kunci, %.2f MB, schema v%d)',
        $out,
        number_format($entries),
        number_format($keys),
        $outSize / 1048576,
        MEEL_JMDICT_SCHEMA_VERSION
    ));
} catch (Throwable $e) {
    @unlink($out);
    jmdict_fail($e->getMessage());
}
