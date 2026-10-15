<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Access denied. Jalankan dari terminal.\n");
}
$root = dirname(__DIR__, 2);

function meel_env_or_null(string $name): ?string
{
    $v = getenv($name);
    return ($v === false || $v === '') ? null : $v;
}

// ── Kredensial ──────────────────────────────────────────────────────────────
//
// Urutan: CI_PARITY_* > MEEL_DB_* > nilai literal di auth/settings.php >
// bawaan. Tidak ada pola regex yang disuntikkan ke dalam berkas mana pun --
// hanya pembacaan nilai yang sudah tertulis di sana.
//
// JANGAN pernah `require auth/config.php` hanya demi membaca tiga variabel.
// Berkas itu bukan sekadar pembaca konfigurasi: ia BENAR-BENAR membuka
// koneksi, sehingga skrip ini ikut konek ke database yang bukan urusannya,
// dan ketika gagalnya, pesan yang muncul milik auth/config.php
// ("[MEeL SYSTEM ERROR] ...") -- bukan pesan skrip ini. Lebih buruk, itu terjadi
// SEBELUM blok koneksi di bawah, jadi semua penjaga di sini tidak pernah
// dijalankan.
//
// Kegagalan seperti itu nyata terjadi di CI: langkah paritas tidak meng-export
// apa pun, `export` dari langkah sebelumnya tidak bertahan karena tiap
// `run:` adalah shell baru, jadi host jatuh ke bawaan "localhost" (= socket
// unix). Di runner tidak ada socket itu karena MySQL berjalan di container
// terpisah lewat TCP 127.0.0.1 -- hasilnya "No such file or directory" tanpa
// penjelasan.
$host = meel_env_or_null('CI_PARITY_HOST') ?? meel_env_or_null('MEEL_DB_HOST');
$user = meel_env_or_null('CI_PARITY_USER') ?? meel_env_or_null('MEEL_DB_USER');
$pass = meel_env_or_null('CI_PARITY_PASS') ?? meel_env_or_null('MEEL_DB_PASS');
$port = meel_env_or_null('CI_PARITY_PORT') ?? meel_env_or_null('MEEL_DB_PORT');

// auth/settings.php bisa tidak ada (CI belum menyalinnya) -- karena itu
// keduanya diperiksa dan berkas HANYA dibaca, tidak pernah di-include.
if ($host === null || $user === null) {
    foreach ([$root . '/auth/settings.php', $root . '/auth/settings.example.php'] as $settingsFile) {
        if (!is_file($settingsFile)) {
            continue;
        }
        $src = (string) file_get_contents($settingsFile);
        if ($host === null && preg_match('/\$server\s*=\s*"([^"]*)"/', $src, $m)) {
            $host = $m[1];
        }
        if ($user === null && preg_match('/\$username\s*=\s*"([^"]*)"/', $src, $m)) {
            $user = $m[1];
        }
        if ($pass === null && preg_match('/\$password\s*=\s*"([^"]*)"/', $src, $m)) {
            $pass = $m[1];
        }
    }
}

// Bawaan 127.0.0.1, BUKAN "localhost". "localhost" membuat mysqli memakai
// socket unix, dan tidak setiap mesin punya socket itu -- persis kasus runner CI.
if ($host === null || $host === '') {
    $host = '127.0.0.1';
}
if ($user === null || $user === '') {
    $user = 'root';
}
if ($pass === null) {
    $pass = '';
}
$port = $port !== null ? (int)$port : 3306;

fwrite(STDOUT, "═══ 0/4 Kredensial ═══\n");
fwrite(STDOUT, sprintf("  %s@%s:%d (database: %s)\n", $user, $host, $port, $pass === '' ? '<tanpa password>' : '<ada password>'));
if ($host === 'localhost') {
    fwrite(STDERR, "❌ Host 'localhost' berarti socket unix — hampir selalu salah di CI.\n");
    fwrite(STDERR, "   Set CI_PARITY_HOST=127.0.0.1 (atau MEEL_DB_HOST).\n");
    exit(1);
}
// `@` hanya menahan warning, BUKAN exception. Pada default PHP 8.1+
// mysqli melempar mysqli_sql_exception, jadi `@new mysqli(...)` gagal dengan
// fatal error 255 + stack trace -- pesan "Koneksi MySQL gagal (...)" di bawah
// tidak pernah tampil. try/catch supaya kegagalannya terbaca.
try {
    $admin = new mysqli($host, $user, $pass, '', $port);
    if ($admin->connect_error) {
        fwrite(STDERR, "Koneksi MySQL gagal ({$user}@{$host}): {$admin->connect_error}\n");
        exit(1);
    }
} catch (\mysqli_sql_exception $e) {
    fwrite(STDERR, "Koneksi MySQL gagal ({$user}@{$host}): " . $e->getMessage() . "\n");
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

// Pengaman PRA-DESTRUKTIF, dan harus di sini -- sebelum DROP pertama.
//
// Script ini menjalankan `DROP DATABASE IF EXISTS` pada nama database yang
// disebut di bawah. Kalau nama itu sampai berubah menjadi database milik orang
// lain (MEeL, database kerja pengembang, atau database produksi), database itu
// hilang sebelum satu baris pun sempat memverifikasi apa pun.
//
// Karena itu nama database diperiksa DI SINI, sebelum destruksi. Verifikasi
// yang diletakkan sesudah langkah 1 tidak bernilai apa-apa: langkah 1 sudah
// menghapus database-nya duluan. Verifikasi harus mendahului destruksi.
//
// Yang boleh dibDROP hanya meel_parity_*. Ini skrip uji yang membangun
// database sementara sendiri; tidak ada alasan sampainya ia menyentuh
// database di luar namespace tersebut.
foreach ([$upgradeDb, $freshDb] as $kandidat) {
    if (!str_starts_with($kandidat, 'meel_parity_')) {
        fwrite(STDERR, "❌ Nama database skrip ini tidak boleh dibDROP: \"{$kandidat}\".\n");
        fwrite(STDERR, "   Hanya meel_parity_* yang diizinkan -- ini skrip uji, bukan skrip migrasi.\n");
        exit(1);
    }
}
// Pembersihan di JALUR KELUAR APAPUN -- bukan hanya di akhir skrip.
//
// Dua DROP di ujung skrip hanya tercapai kalau semuanya sukses. Di antara
// pemeriksaan gagal dan ujung itu ada belasan `exit(1)`: impor gagal, target
// migrasi keliru, paritas tidak cocok. Semua itu meninggalkan
// meel_parity_upgrade dan meel_parity_fresh di server -- dan di runner CI
// yang baru selesai, jadi tidak ada yang membersihkannya.
//
// Sisa database uji tidak boleh menjadi milik server yang dipakai orang lain.
// Jadi pembersihan didaftarkan sebagai shutdown function: diekssekusi juga pada
// `exit(1)` dan pada fatal error.
//
// Koneksinya dibuat ulang, bukan memakai $admin, karena $admin bisa sudah
// tertutup atau berada di tengah kondisi tidak bisa dipakai.
register_shutdown_function(static function () use ($upgradeDb, $freshDb, $host, $user, $pass, $port): void {
    try {
        $c = @new mysqli($host, $user, $pass, '', $port);
    } catch (\Throwable) {
        return;
    }
    if ($c->connect_error) {
        return;
    }
    foreach ([$upgradeDb, $freshDb] as $db) {
        // Pengaman yang sama diulang di sini: shutdown function berjalan tanpa
        //_guard_ di atas, jadi ia tidak boleh bisa menjatuhkan nama lain.
        if (str_starts_with($db, 'meel_parity_')) {
            $c->query("DROP DATABASE IF EXISTS `{$db}`");
        }
    }
    $c->close();
});

echo "═══ 1/4 Bangun DB jalur upgrade (skema versi LAMA) ═══\n";
$admin->query("DROP DATABASE IF EXISTS `{$upgradeDb}`");
$admin->query("CREATE DATABASE `{$upgradeDb}`");
$admin->select_db($upgradeDb);
$legacy = preg_replace('/^\s*`coin_(cost|refunded)`[^\n]*\n/m', '', $schema);
$legacy = preg_replace('/^\s*KEY `idx_(uq|tq)_status_created`[^\n]*\n/m', '', $legacy);
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
import($admin, $schema, 'schema.sql');
echo "\n═══ 3/4 Jalankan migrate.php pada DB upgrade ═══\n";
$cfg = $root . '/auth/settings.php';
$cfgBackup = is_file($cfg) ? (string)file_get_contents($cfg) : null;
$example = $root . '/auth/settings.example.php';
if (!is_file($example)) {
    fwrite(STDERR, "auth/settings.example.php tidak ditemukan — tidak bisa menyiapkan kredensial.\n");
    exit(1);
}

// settings.php hanya perlu ADA — auth/config.php menolak berjalan tanpanya.
// Isinya boleh apa adanya karena SELURUH kredensial datang dari variabel
// lingkungan di bawah.
//
// Versi sebelumnya menyuntikkan nilai ke dalam source settings.example.php lewat
// regex seperti /\$server\s*=\s*"[^"]*"/. Regex itu bisa berhenti cocok
// diam-diam setiap kali format example berubah, tanpa error apa pun, dan
// migrate.php lalu berjalan ke database yang salah -- persis kelas kegagalan
// yang sempat membuat empat regex itu tidak cocok. Env var tidak punya kelas
// kegagalan itu: tidak ada pola yang bisa gagal.
file_put_contents($cfg, file_get_contents($example));

// Pengaman: sebelum menjalankan migrate.php, pastikan variabel lingkungan itu
// BENAR-BENAR sampai dan dipakai auth/config.php.
//
// Tanpa ini, kegagalan diam-diam paling berbahaya yang mungkin terjadi adalah
// migrate.php berjalan ke database bawaan settings.example.php, yaitu `MEeL` —
// yaitu database kerja milik pengembang, atau database produksi. Mempermigrate
// DB orang tanpa disadari adalah hal yang tidak boleh bisa terjadi, jadi di sini
// diperiksa lebih dulu dan dihentikan bila targetnya keliru.
$env = 'MEEL_DB_HOST=' . escapeshellarg((string)$host)
     . ' MEEL_DB_NAME=' . escapeshellarg($upgradeDb)
     . ' MEEL_DB_USER=' . escapeshellarg((string)$user)
     . ' MEEL_DB_PASS=' . escapeshellarg((string)$pass);

$probe = [];
$probeRc = 0;
exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' -r '
    . escapeshellarg('require ' . var_export($root . '/auth/config.php', true) . '; echo $conn->query("SELECT DATABASE() AS d")->fetch_assoc()["d"];')
    . ' 2>/dev/null', $probe, $probeRc);
$terhubung = trim(implode('', $probe));
if ($terhubung !== $upgradeDb) {
    fwrite(STDERR, "\n❌ migrate.php akan berjalan ke database \"{$terhubung}\", bukan \"{$upgradeDb}\".\n");
    fwrite(STDERR, "   Dihentikan agar database lain tidak ikut termigrasi tanpa disadari.\n");
    if ($cfgBackup !== null) {
        file_put_contents($cfg, $cfgBackup);
    } else {
        @unlink($cfg);
    }
    exit(1);
}
echo "  ✅ Target migrate.php terverifikasi: {$terhubung}\n";

$out = [];
$rc = 0;
exec($env . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/database/migrate.php') . ' 2>&1', $out, $rc);
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