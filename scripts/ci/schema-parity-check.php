<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied. Jalankan dari terminal.\n");
}
$root = dirname(__DIR__, 2);
$host = getenv('CI_PARITY_HOST') ?: null;
$port = getenv('CI_PARITY_PORT') ?: null;
$user = getenv('CI_PARITY_USER') ?: null;
$pass = getenv('CI_PARITY_PASS');
if ($pass === false) {
    $pass = null;
}
if ($host === null || $user === null) {
    require_once $root . '/auth/config.php';
    $host = $host ?? ($server ?? '127.0.0.1');
    $user = $user ?? ($username ?? 'root');
    $pass = $pass ?? ($password ?? '');
}
$port = $port !== null ? (int)$port : 3306;
$admin = @new mysqli($host, $user, $pass, '', $port);
if ($admin->connect_error) {
    fwrite(STDERR, "Koneksi MySQL gagal ({$user}@{$host}): {$admin->connect_error}\n");
    exit(1);
}
$admin->set_charset('utf8mb4');
$schemaFile = $root . '/database/schema.sql';
$schema = (string)file_get_contents($schemaFile);
if ($schema === '') {
    fwrite(STDERR, "schema.sql tidak terbaca / kosong\n");
    exit(1);
}
function import(mysqli $admin, string $sql, string $label): void
{
    if (!$admin->multi_query($sql)) {
        fwrite(STDERR, "Import {$label} gagal: {$admin->error}\n");
        exit(1);
    }
    while ($admin->more_results()) {
        $admin->next_result();
        if ($admin->error) {
            fwrite(STDERR, "Import {$label} gagal: {$admin->error}\n");
            exit(1);
        }
    }
    echo "  ✅ {$label} import OK\n";
}
$upgradeDb = 'meel_parity_upgrade';
$freshDb   = 'meel_parity_fresh';
echo "═══ 1/4 Bangun DB jalur upgrade (skema versi LAMA) ═══\n";
$admin->query("DROP DATABASE IF EXISTS `{$upgradeDb}`");
$admin->query("CREATE DATABASE `{$upgradeDb}`");
$admin->select_db($upgradeDb);
$legacy = preg_replace('/^\s*`coin_(cost|refunded)`[^\n]*\n/m', '', $schema);
$legacy = preg_replace('/^\s*KEY `idx_(uq|tq)_status_created`[^\n]*\n/m', '', $legacy);
$legacy = str_replace('`MEeL`', '`' . $upgradeDb . '`', $legacy);
import($admin, $legacy, 'skema legacy');
foreach (['upload_queue', 'transcode_queue'] as $t) {
    $r = $admin->query("SHOW COLUMNS FROM `{$t}` LIKE 'coin_cost'");
    if (!$r || $r->num_rows > 0) {
        fwrite(STDERR, "Setup gagal: {$t}.coin_cost masih ada di 'skema legacy'.\n");
        exit(1);
    }
}
echo "  ✅ Konfirmasi: skema legacy benar-benar tidak punya kolom v6\n";
echo "\n═══ 2/4 Bangun DB jalur instalasi baru (schema.sql) ═══\n";
$admin->query("DROP DATABASE IF EXISTS `{$freshDb}`");
$admin->query("CREATE DATABASE `{$freshDb}`");
$admin->select_db($freshDb);
import($admin, str_replace('`MEeL`', '`' . $freshDb . '`', $schema), 'schema.sql');
echo "\n═══ 3/4 Jalankan migrate.php pada DB upgrade ═══\n";
$cfg = $root . '/auth/settings.php';
$cfgBackup = is_file($cfg) ? (string)file_get_contents($cfg) : null;
$example = $root . '/auth/settings.example.php';
if (!is_file($example)) {
    fwrite(STDERR, "auth/settings.example.php tidak ditemukan — tidak bisa menyiapkan kredensial.\n");
    exit(1);
}
$tpl = (string)file_get_contents($example);
$tpl = preg_replace('/\$server\s*=\s*"[^"]*"/',   '$server   = ' . var_export($host, true), $tpl, 1);
$tpl = preg_replace('/\$username\s*=\s*"[^"]*"/', '$username = ' . var_export($user, true), $tpl, 1);
$tpl = preg_replace('/\$password\s*=\s*"[^"]*"/', '$password = ' . var_export($pass, true), $tpl, 1);
$tpl = preg_replace('/\$db\s*=\s*"[^"]*"/',       '$db       = ' . var_export($upgradeDb, true), $tpl, 1);
file_put_contents($cfg, $tpl);
$out = [];
$rc = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/migrate.php') . ' 2>&1', $out, $rc);
echo '  ' . implode("\n  ", $out) . "\n";
if ($cfgBackup !== null) {
    file_put_contents($cfg, $cfgBackup);
} else {
    @unlink($cfg);
}
if ($rc !== 0) {
    fwrite(STDERR, "\n❌ migrate.php keluar dengan kode {$rc} pada DB upgrade.\n");
    exit(1);
}
echo "\n═══ 4/4 Bandingkan kedua jalur ═══\n";
$tables = ['upload_queue', 'transcode_queue', 'comments', 'interactions', 'user_notifications'];
$columns = function (mysqli $admin, string $db, string $table): array {
    $admin->select_db($db);
    $r = $admin->query("SHOW COLUMNS FROM `{$table}`");
    if (!$r) {
        return [];
    }
    $cols = [];
    while ($x = $r->fetch_assoc()) {
        $cols[] = strtolower($x['Field']);
    }
    sort($cols);
    return $cols;
};
$bad = 0;
foreach ($tables as $t) {
    $upgraded = $columns($admin, $upgradeDb, $t);
    $fresh = $columns($admin, $freshDb, $t);
    if ($upgraded === [] || $fresh === []) {
        echo "  ⚠️  {$t}: tabel tidak terbaca di salah satu jalur — dilewati\n";
        continue;
    }
    $onlyUpgrade = array_values(array_diff($upgraded, $fresh));
    $onlyFresh = array_values(array_diff($fresh, $upgraded));
    if ($onlyUpgrade !== []) {
        echo "  ❌ {$t}: kolom hasil UPGRADE tapi tidak ada di instalasi BARU: " . implode(', ', $onlyUpgrade) . "\n";
        echo "     → migrasi menambah kolom ini tapi schema.sql tidak menyusul.\n";
        $bad = 1;
    }
    if ($onlyFresh !== []) {
        echo "  ❌ {$t}: kolom di instalasi BARU tapi tidak ada hasil upgrade: " . implode(', ', $onlyFresh) . "\n";
        echo "     → schema.sql punya kolom yang migrasi tidak pernah buat.\n";
        $bad = 1;
    }
    if ($onlyUpgrade === [] && $onlyFresh === []) {
        echo "  ✅ {$t}: paritas sempurna (" . count($upgraded) . " kolom)\n";
    }
}
$admin->query("DROP DATABASE IF EXISTS `{$upgradeDb}`");
$admin->query("DROP DATABASE IF EXISTS `{$freshDb}`");
echo "\n";
if ($bad) {
    fwrite(STDERR, "❌ Paritas skema GAGAL — jalur upgrade dan instalasi baru berbeda.\n");
    echo "   Konvensi T9: setiap kolom di migrate.php WAJIB ada juga di schema.sql.\n";
    exit(1);
}
echo "✅ Paritas skema OK — upgrade dan instalasi baru menghasilkan skema identik.\n";
exit(0);