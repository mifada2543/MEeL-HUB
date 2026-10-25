# 🧪 Panduan Testing

**Terakhir diperbarui:** 25 Oktober 2026

---

## Ikhtisar

MEeL menggunakan pendekatan testing berlapis:

| Lapisan | Tools | Cakupan | Lokasi |
|---|---|---|---|
| **Unit Test** | PHPUnit 9.6 | Logika murni, DB di-mock | `tests/unit/` |
| **Integration Test** | PHPUnit 9.6 | Operasi DB real | `tests/integration/` |
| **Functional Test** | Custom PHP | Alur kerja aplikasi | `tests/functional_test.php` |
| **Security Test** | Custom PHP | Pemindaian kerentanan | `tests/security_test.php` |
| **Deployment Check** | Custom PHP | Verifikasi environment (storage, folder upload, .htaccess, mod_rewrite) | `tests/check_deploy.php` |
| **CI Pipeline** | GitHub Actions | Validasi otomatis | `.github/workflows/ci.yml` |

---

## 🧪 PHPUnit Test Suite

### Instalasi

PHPUnit terinstall sebagai dev dependency Composer:

```bash
cd /path/ke/MEeL
composer install --no-progress
```

### Menjalankan Test

```bash
# Jalankan semua test
vendor/bin/phpunit --no-coverage

# Hanya unit test
vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'

# Hanya integration test
vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests'

# File test spesifik
vendor/bin/phpunit --no-coverage tests/unit/RateLimiterTest.php
```

### Log Test

Semua output test dicatat ke `logs/tests/`:

```
logs/tests/
└── .htaccess          # Deny all — dilindungi dari akses publik
```

### Arsitektur Test

#### Unit Test (`tests/unit/`)

| File | Test | Cakupan |
|---|---|---|
| `RateLimiterTest.php` | 11 | Admin bypass, role limits, blocking, cleanup, stats, fail-closed saat storage gagal, independent keys |
| `HelpersTest.php` | 50 | format_bytes, time_ago, audio MIME, disk space, CSRF, dir_size, deteksi protokol (data provider) |
| `JapaneseTest.php` | 15 | Romaji conversion, analyzeJapaneseText, English translation (tanpa MeCab) |
| `GarbageCollectorTest.php` | 7 | Class existence, idempotency, graceful handling, cleanup rate-limit (dir test terisolasi), pelestarian direktori cache aktif |
| `CacheDirectoryCleanupTest.php` | 17 | TTL cache per prefix dan per key waktu (`ts`/`time`/`checked_at`), berkas sementara, cache rusak, fallback mtime, direktori cache yang sama |
| `SearchEngineTest.php` | 5 | Parse params, sanitizer (`sanitizeQuery`), default values, constants |
| `MediaLibraryTest.php` | 11 | Logika pagination (pure math), BookRepository mock |
| `MediaInteractionTest.php` | 7 | Validasi input (ID/type tidak valid) |
| `MediaViewerTest.php` | 9 | Logika rekomendasi media / antrean playlist / next-url |
| `RouterProfileRouteTest.php` | 9 | Resolusi route profil, route terreserved, kasus nested/trailing-slash |
| `RouterDispatchTest.php` | 22 | Pengalihan alias profil (murni, tanpa perlu request), dan setiap rute terdaftar menunjuk berkas yang benar-benar ada |
| `BootstrapTest.php` | 9 | Env detection, konfigurasi error reporting, timezone |
| `CssManifestTest.php` | 18 | Manifest CSS modul — semua entri ada, **semua** folder ter-precache oleh `SwPrecache`, entri precache resolve, versi SW deterministik |
| `SharedJsTest.php` | 7 | Shared JS harness — alur download-backup-codes |
| `LikeDislikePartialTest.php` | 7 | **Tombol like/dislike** — markup kontainer hanya ada di satu berkas, semua pemakai memakai partial bersama, dan varian musik & video hanya berbeda pada warna aksen, label, dan tombol simpan |
| `LucideLoadOrderTest.php` | 6 | **Lucide + HTMX** — helper render termuat, stub antrean di `partials/link.php` bersifat sinkron, dan listener swap tetap terdaftar walau `document.body` belum ada (skrip dimuat di `<head>`) |
| `StreamAuthTest.php` | 8 | Guard otorisasi endpoint stream |
| `SsrfGuardTest.php` | 76 | **Guard SSRF** — allowlist protokol, range IP private/publik (v4 & v6), penolakan record DNS campuran, denylist hostname, HTTP pinning (lihat bawah) |
| `DriveSecurityTest.php` | 13 | **Private Drive** — akses cross-user, path traversal, symlink escape, boundary realpath, kuota, reservasi nama atomik (lihat bawah) |
| `DriveStorageBasePathTest.php` | 8 | Resolusi base path storage Drive — konstanta `MEEL_HDD_DRIVE`, fallback, prioritas |
| `DateTimeSourceTest.php` | 7 | **Jam aplikasi** — zona waktu diset tanpa syarat, `time_zone` sesi MySQL disejajarkan dengannya, kolom yang dibaca `strtotime()` tidak pernah ditulis dengan jam database, dan berkas yang sengaja mencampur keduanya ada di daftar eksplisit |
| `WebSafeErrorOutputTest.php` | 2 | **Output error yang aman untuk web** — `STDERR`/`STDOUT`/`STDIN` hanya di perkakas CLI, berkas yang dijaga `PHP_SAPI`, atau berkas yang tidak di-include siapa pun |
| `AdminFilteredRepositoryTest.php` | 13 | Basis repository admin bersama — query bersama tetap di basis, tiap repository menjaga tabel & aliasnya, perilaku filter keduanya tetap berbeda |
| `ValidatingProxyTest.php` | 27 | **Validating forward proxy** — probe CONNECT/GET nyata: target private ditolak (502), target publik di-tunnel, bind loopback-only, absolute-URI rusak ditolak (lihat bawah) |
| `ValidatingProxyProtocolTest.php` | 27 | **Aturan protokol proxy** — parsing kepala permintaan beserta batasannya, parsing target CONNECT, pembuangan header hop-by-hop, penyusunan permintaan yang diteruskan, pemindahan data tunnel & relay respons |
| `ArchiveGuardTest.php` | 11 | **Guard arsip** — traversal `../`, path absolut, null byte, symlink, nesting, compression ratio, ZIP bomb, entry count (lihat bawah) |
| `UploadValidationTest.php` | 9 | **Validasi upload** — magic bytes palsu, ekstensi ganda, null byte, oversized, eksekutabel (lihat bawah) |
| `TranscoderConstantsTest.php` | 2 | Regresi visibilitas konstanta bersama (`TranscoderBase` → service anak) |

#### Integration Test (`tests/integration/`)

| File | Test | Cakupan |
|---|---|---|
| `MediaInteractionIntegrationTest.php` | 24 | Like/dislike music+video, toggle, ownership check, count sync, guest denial |
| `ChessGameOverIntegrationTest.php` | 13 | Alur game-over catur dengan DB real |
| `ChessHelpersIntegrationTest.php` | 6 | Helper catur dengan DB real |
| `ChessRematchIntegrationTest.php` | 21 | Alur rematch catur dengan DB real |
| `GarbageCollectorChessRoomsIntegrationTest.php` | 15 | Garbage collection room catur dengan DB real |
| `MeelCoinIntegrationTest.php` | 18 | Spend/refund/refill, batas role, countdown yang tidak pernah mundur, jendela refill yang dimulai saat spend |
| `SystemTest.php` | 2 | Class existence & utilitas System |

### Test Helpers

| File | Fungsi |
|---|---|
| `tests/DbTestHelper.php` | Koneksi DB real dengan isolasi transaction rollback. Membangun database sekali-pakai `MEeL-test` dari **kedua** `database/schema.sql` dan `arcade/schema.sql`, lalu mengekspos koneksi tersebut sebagai `$GLOBALS['conn']` agar `Modules::enabled()` menilai terhadap database test — bukan produksi. |
| `tests/bootstrap.php` | Autoloader, `$_SERVER` defaults, setup direktori temp |

### Konfigurasi PHPUnit (`phpunit.xml`)

```xml
<phpunit bootstrap="tests/bootstrap.php" colors="true" verbose="true">
    <testsuites>
        <testsuite name="MEeL Core Unit Tests">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="MEeL Integration Tests">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Test Regresi Keamanan (Guard SSRF, Validating Proxy, Private Drive)

Tiga kelas test khusus mencakup batas-batas keamanan dari security-hardening
pass. Jalankan bersama atau satu per satu:

```bash
# Semua test keamanan (SSRF + Drive + proxy) sekaligus
vendor/bin/phpunit --no-coverage --filter 'SsrfGuardTest|DriveSecurityTest|ValidatingProxyTest'

# Atau per file:
vendor/bin/phpunit --no-coverage tests/unit/SsrfGuardTest.php
vendor/bin/phpunit --no-coverage tests/unit/DriveSecurityTest.php
vendor/bin/phpunit --no-coverage tests/unit/ValidatingProxyTest.php
```

| File test | Yang diverifikasi (penolakan/penerimaan nyata, bukan sekadar keberadaan) |
|---|---|
| `SsrfGuardTest.php` | Range IPv4/IPv6 private ditolak, IP publik diizinkan, denylist hostname, protokol tak didukung, URL cacat, penolakan kredensial, DNS hostname→IP private, HTTP pinning (`pinHttpUrl`) |
| `DriveSecurityTest.php` | Download cross-user diblokir, path traversal diblokir, symlink escape diblokir, boundary prefix realpath, penegakan kuota (atomik), reservasi nama atomik |
| `ArchiveGuardTest.php` | Entry `../` / `../../` / path absolut / `..\..\` / null byte ditolak, symlink ditolak, kedalaman berlebih ditolak, rasio kompresi ekstrem (zip bomb) ditolak, jumlah entry & ukuran total dibatasi, CBZ valid diterima, arsip rusak ditolak |
| `UploadValidationTest.php` | MIME palsu (`$_FILES['type']` tidak dipercaya), ekstensi ganda, null byte, file oversized, ekstensi eksekutabel ditolak, magic bytes tidak cocok ditolak |
| `ValidatingProxyTest.php` | **Menjalankan proses proxy nyata** dan mengirim CONNECT/absolute-URI asli: target private ditolak 502, target publik di-tunnel/relay, bind loopback-only, siklus hidup proses |

### Verifikasi satu-perintah (`scripts/verify_security.sh`)

Ketiga suite keamanan plus probe 403 Private Drive bisa dijalankan dengan satu
perintah:

```bash
scripts/verify_security.sh
# flag opsional: --url=https://staging.example/MEeL --deploy --hdd=... --no-color
```

Skrip menjalankan, secara berurutan:

1. **PHPUnit subset keamanan** — `SsrfGuardTest | DriveSecurityTest | ValidatingProxyTest`
2. **Security Test** — `php tests/security_test.php` (scan statis)
3. **Functional Test** — `php tests/functional_test.php` (verifikasi patch)
4. **Probe 403 Private Drive** — akses HTTP langsung ke `data_drive/private_admins/`
   dan path file tiruan harus sama-sama mengembalikan `403` (aturan deny `.htaccess`)
5. *Opsional* (`--deploy`) — `php tests/check_deploy.php --no-color [--hdd=...]`

Exit code `0` = semua suite lulus (warning diperbolehkan), `1` = minimal satu
gagal — ramah CI. Tool yang tidak ada (`vendor/bin/phpunit`) atau web server
yang tidak terjangkau menghasilkan warning dan **di-skip, bukan gagal**.
`security_test.php` dan `functional_test.php` mengembalikan `1` saat hanya ada
warning (Score A — mis. storage HDD belum ter-mount); skrip memetakannya ke
**WARN**, bukan FAIL, sesuai semantik mereka sendiri (mereka mengembalikan `2`
hanya untuk kegagalan nyata). Flag: `--url=…` (base URL probe),
`--skip-403`, `--deploy`, `--hdd=…`, `--no-color`. Membutuhkan `bash`,
CLI `php`, dan `curl`.

> 💡 Komponen yang sama berjalan terpisah di CI (lihat [CI Pipeline](#-ci-pipeline-github-actions));
> skrip ini adalah padanan sekali-jalan untuk lokal / pra-rilis.

**Catatan:**
- `SsrfGuardTest` dan `ValidatingProxyTest` punya kasus **bergantung jaringan**
  (DNS lookup nyata). Di lingkungan offline kasus itu berubah jadi
  `markTestSkipped()`; di CI (GitHub Actions) dan mesin dengan resolver,
  kasus itu berjalan sungguhan.
- `ValidatingProxyTest` butuh **PHP CLI + pcntl + stream_socket_server**
  (semuanya tersedia di setup XAMPP/LAMP default) karena ia men-spawn
  `validating_proxy_server.php` sebagai subproses.
- Pemeriksaan statis wiring untuk boundary yang sama ada di
  `tests/security_test.php` (TEST 13) dan `tests/functional_test.php` (patch
  verification), dijalankan dengan:

```bash
php tests/security_test.php
php tests/functional_test.php
```

### Menulis Test Baru

#### Template Unit Test

```php
<?php
use PHPUnit\Framework\TestCase;

class MyClassTest extends TestCase
{
    public function testSomething(): void
    {
        $result = myFunction('input');
        $this->assertSame('expected', $result);
    }
}
```

#### Template Integration Test (dengan DB)

```php
<?php
use PHPUnit\Framework\TestCase;

class MyIntegrationTest extends TestCase
{
    private DbTestHelper $dbHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dbHelper = new DbTestHelper();
        $this->conn = $this->dbHelper->getConnection(); // auto-start transaction
    }

    protected function tearDown(): void
    {
        $this->dbHelper->rollback(); // undo semua perubahan
        $this->dbHelper->close();
        parent::tearDown();
    }
}
```

---

## Functional Test (`tests/functional_test.php`)

Skrip test kustom yang memvalidasi alur kerja aplikasi:

```bash
php tests/functional_test.php
```

**Mencakup:**
- Autentikasi user (login, register, logout)
- Pemutaran media (video, music)
- Operasi file (upload, delete)
- RBAC (role-based access)
- Rate limiting
- Pemeriksaan kesehatan sistem

## 🛡️ Security Test (`tests/security_test.php`)

Pemindaian kerentanan otomatis:

```bash
php tests/security_test.php
```

**Mencakup:**
- SQL Injection — semua prepared statement diverifikasi
- XSS — pengecekan encoding output
- CSRF — validasi token
- Path Traversal — pemaksaan basename()
- Command Injection — penggunaan escapeshellarg()
- File Upload — validasi magic bytes
- Session Security — cookie params, deteksi hijack
- Open Redirect — semua redirect referer wajib lewat validasi host; `playlist_action.php` tolak `//host` & skema `://`
- Fatal-Bug Regression — include kelas ganda (RateLimiter) & redirect mentah `HTTP_REFERER` terdeteksi

## 🚀 Deployment Check (`tests/check_deploy.php`)

Health-check cepat environment deployment — mencerminkan apa yang dicek security
test, tapi fokus pada infrastruktur:

```bash
php tests/check_deploy.php                          # cek lokal + probe HTTP otomatis
php tests/check_deploy.php --url=http://localhost/MEeL   # probe HTTP eksplisit
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color               # tanpa warna ANSI (untuk CI/log)
```

**Memverifikasi:**
- `MEEL_HDD_BASE` — terdefinisi, bukan placeholder, storage ter-mount & writable
- Folder upload + **subdirektori non-auto-create** — `{video,music,books}/upload`
  resolve ke storage; `music/upload/{file,thumbnail}` & `books/upload/{pdf,thumbnail}`
  wajib ada (modul music/books **tidak** membuatnya otomatis) — jika hilang: **FAIL**
- Hardening `.htaccess` folder upload — `php_flag engine off`, `ForceType`, `Options -Indexes`
- Guard portabilitas `data_drive/` — symlink deploy ke dalam `MEEL_HDD_DRIVE` =
  **PASS**, menunjuk ke luar = **WARN**
- PWA `mod_rewrite` — aturan `.htaccess` root + probe HTTP nyata ke `/sw.js`

Exit code `0` = sehat, `1` = minimal satu FAIL (ramah CI). Panduan storage
lengkap: [Instalasi §5a](installation.md).

---

## 🤖 CI Pipeline (GitHub Actions)

CI pipeline berjalan otomatis pada push/pull request:

```
PHP Syntax (8.0–8.3)
    ├── Functional Tests          → php tests/functional_test.php
    ├── Security Tests            → php tests/security_test.php
    ├── PHPUnit Unit Tests        → php vendor/bin/phpunit --no-coverage --testsuite='MEeL Core Unit Tests'
    ├── PHPUnit Integration Tests → php vendor/bin/phpunit --no-coverage --testsuite='MEeL Integration Tests' (service MySQL)
    ├── Installer Regression Tests → bash tests/installer/run_all.sh (service MariaDB)
    ├── Migration Test            → impor schema.sql + migrate.php idempoten + schema parity
    ├── HTACCESS & Integrity      → kehadiran .htaccess + permission
    ├── ShellCheck                → shellcheck semua *.sh
    ├── Deployment Check         → php tests/check_deploy.php --no-color --hdd=…
    ├── Tailwind Reproducibility  → npm run css:check
    └── Drive Storage Integrity   → verifikasi path storage
            └── CI Summary
```

### Build paket: snapshot vs release

CI hanya memvalidasi kode. Paket ZIP **tidak** dibangun pada setiap
commit/push, supaya tidak memboroskan runner dan storage.

| Jalur | Trigger | Hasil |
|---|---|---|
| **Snapshot** (`.github/workflows/snapshot.yml`) | Push ke `main`/`master`/`develop`, PR yang di-merge ke branch lain (kecuali `Experiment`), atau manual | 3 paket dengan label SHA, artifact 7 hari, **tanpa** GitHub Release |
| **Release** (`.github/workflows/release.yml`) | Tag `core-vX.Y.Z` / `arcade-vX.Y.Z`, atau `workflow_dispatch` | Paket final + `SHA256SUMS`, dipublikasikan sebagai GitHub Release |

Snapshot juga bisa dijalankan manual dari tab Actions → **MEeL Snapshot** →
**Run workflow**, misalnya untuk branch fitur atau commit tertentu. Snapshot
tidak membuat tag dan tidak menimpa Release. Snapshot hanya mengemas kode
(tanpa test gate); rilis tetap melewati syntax, unit, integration, security,
functional, installer, dan Tailwind.

#### Isi paket

| Paket | Isi | Butuh Core? | Nama aset |
|---|---|---|---|
| **Core** | Semua berkas kecuali `arcade/` | Tidak, berdiri sendiri | `MEeL-HUB-core-v2.1.0.zip` |
| **Arcade addon** | Hanya `arcade/` | Ya — seperti plugin, copy ke root Core lalu jalankan migrasi Arcade | `MEeL-Arcade-v2.1.0.zip` |
| **Full** | Core + `arcade/` | Tidak, sudah lengkap | `MEeL-HUB-full-v2.1.0.zip` |

Tag `core-v2.1.0` membangun **ketiga** paket dalam satu Release.
Tag `arcade-v2.1.0` hanya membangun paket Arcade addon (untuk rilis
perbaikan modul Arcade saja).

Memasang Arcade addon di atas Core:

```bash
unzip MEeL-Arcade-v2.1.0.zip -d /path/ke/MEeL
cd /path/ke/MEeL && php arcade/migrate.php
```

Build lokal tanpa tag (untuk uji coba):

```bash
bash scripts/ci/build-package.sh --track core --label local --out-dir dist
```

Skrip mengarsipkan commit (`HEAD`), bukan working tree — commit dulu atau
pakai `--ref <tag>` bila ingin mengemas commit tertentu.

#### Membuat rilis v2.1.0

Pastikan commit yang akan dirilis sudah ada di branch utama, working tree
bersih, dan workflow sudah ikut ter-commit (tag hanya menunjuk commit, bukan
perubahan yang belum di-commit):

```bash
git tag -a core-v2.1.0 -m "MEeL Core v2.1.0"
git push origin core-v2.1.0
```

Untuk rilis modul Arcade saja:

```bash
git tag -a arcade-v2.1.0 -m "MEeL Arcade v2.1.0"
git push origin arcade-v2.1.0
```

Re-release/backfill tag yang sudah memuat workflow ini (workflow dijalankan
dari branch default, lalu checkout commit tag):

```bash
gh workflow run release.yml -f tag=core-v2.1.0
gh workflow run release.yml -f tag=core-v2.1.0 -f dry_run=true
```

`dry_run=true` hanya membangun dan mengunggah artifact, tanpa membuat/mengubah
GitHub Release. Setelah rilis, unduh aset dari halaman **Releases** atau
verifikasi checksum:

```bash
sha256sum -c SHA256SUMS
# atau verifikasi satu aset saja:
grep 'MEeL-HUB-core-v2.1.0.zip' SHA256SUMS | sha256sum -c -
unzip -l MEeL-HUB-core-v2.1.0.zip | head
```

> ⚠️ Tag harus menunjuk commit yang sudah memuat workflow versi ini, termasuk
> `scripts/ci/php-lint.sh` dan `scripts/ci/build-package.sh`. Untuk tag lama
> yang belum memilikinya, buat tag baru dari commit yang sudah memuat workflow
> ini; jangan pindahkan tag yang sudah dipublikasikan.

### Bagaimana test regresi keamanan berjalan di CI

Ketiga kelas test keamanan (`SsrfGuardTest`, `DriveSecurityTest`,
`ValidatingProxyTest`) berada di `tests/unit/`, yang otomatis diambil oleh job
`phpunit-tests` (`php vendor/bin/phpunit --no-coverage
--testsuite='MEeL Core Unit Tests'`). Tidak perlu konfigurasi CI tambahan —
mereka bagian dari testsuite **MEeL Core Unit Tests**. Kasus yang bergantung
DNS berjalan sungguhan karena runner GitHub Actions punya resolver;
`ValidatingProxyTest` lulus karena runner punya PHP CLI dengan
pcntl/stream sockets.

> **CI menjalankan suite unit dan integration.** Job `phpunit-tests` menjalankan
> suite unit, sedangkan `phpunit-integration-tests` menjalankan integration test
> terhadap container MySQL 8.0 sesungguhnya. Test Jepang/romaji (`JapaneseTest`,
> sebagian `HelpersTest`) memanggil **MeCab** via `proc_open`. CI meng-install-nya
> (`apt-get install mecab mecab-ipadic-utf8`), dan saat MeCab tidak tersedia test
> terkait menurun ke `markTestSkipped()` via `meel_mecab_available()` — jadi
> mesin tanpa mecab tetap mendapat suite hijau, bukan failure.

Pemeriksaan **statis wiring** untuk boundary yang sama dijalankan oleh job
`security-tests` (TEST 13 di `tests/security_test.php`) dan job
`functional-tests` (patch verification). Job `htaccess-check` memverifikasi
kehadiran `.htaccess`; aturan deny level repo untuk `data_drive/private_admins`
ditegaskan oleh pemeriksaan `data_drive/.htaccess` di security test (lapisan 1),
plus `data_drive/private_admins/.htaccess` ter-track (`Require all denied`,
lapisan 2) yang diperiksa oleh `tests/security_test.php` (TEST 13) dan
`tests/check_deploy.php`.

Job `deploy-check` di `.github/workflows/ci.yml` menjalankan suite ini
(`php tests/check_deploy.php --no-color --hdd=...`) pada layout storage yang
disimulasikan, dan menyertakan probe 403 live opsional yang berjalan hanya
saat URL staging dikonfigurasi sebagai secret GitHub:

```yaml
# .github/workflows/ci.yml — job deploy-check
# Catatan: context `secrets` TIDAK tersedia di step-level `if:`. Secret diekspos
# lewat `env` job-level, lalu dibaca sebagai env context di dalam `if:`.
    env:
      STAGING_URL: ${{ secrets.STAGING_URL }}
    steps:
      - name: Verify Private Drive 403 (staging)
        if: env.STAGING_URL != ''
        run: |
          code=$(curl -s -o /dev/null -w '%{http_code}' \
            "$STAGING_URL/data_drive/private_admins/" || true)
          [ "$code" = "403" ] || { echo "Expected 403, got $code"; exit 1; }
```

### Gate reproducibility Tailwind CSS

`scripts/ci/tailwind-coverage-check.php` menjaga `assets/css/tailwind.min.css`
— artefak hasil build yang di-commit — supaya bisa direproduksi. Tanpa gerbang
ini, mengubah markup lalu ingin membangun ulang CSS sama saja menebak.

Tiga hal yang diperiksa:

1. **Glob `content` benar-benar memindai repo, dan tidak memindai yang
   dikecualikan.** Config bisa saja salah ketik dan tetap build "sukses"
   dengan CSS setengah kosong — tidak ada error, hanya styling hilang.
2. **Tidak ada regresi.** Class yang ADA di CSS lama, MASIH dipakai markup,
   tapi HILANG di CSS baru = regresi. Ini yang paling merusak: halaman
   dirender, layout berantakan, tidak ada error.
3. **Class yang hilang tapi tak terpakai boleh hilang.** Itu memang
   penghematan, bukan bug; build yang sengaja memangkas stale token adalah
   hasil yang diinginkan.

Dua detail implementasi yang bukan pilihan gaya:

- **Tokenisasi dilakukan pada selector ASLI** (yang masih escapes CSS), baru
  hasilnya di-unescape. Kalau selector di-unescape dulu lalu di-tokenisasi,
  karakter `:` (pemisah variant) ikut jadi pembatas dan semua class bertanda
  variant runtuh jadi satu token: `hover:border-green-500/30` menjadi
  `hover`. Akibatnya cek regresi nyaris tak berguna — `hover` jelas ada di
  file lain.
- **Deklarasi dikumpulkan per class dalam bentuk GABUNGAN**, bukan per rule.
  Tailwind menggabungkan selector yang deklarasinya sama ke satu rule; saat
  isi kelompok berubah, satu class bisa pindah dari rule gabungan ke rule
  sendiri tanpa mengubah hasil akhirnya.

Hanya senyawa terakhir selector yang dihitung sebagai pembawa deklarasi. Pada
`.group:hover .group-hover:text-white`, yang membawa deklarasi adalah
`group-hover:text-white`; `group` hanya leluhur dan tidak punya deklarasi
sendiri.

```bash
php scripts/ci/tailwind-coverage-check.php                  # audit artefak ter-commit
php scripts/ci/tailwind-coverage-check.php --new=/tmp/x.css  # bandingkan kandidat baru
php scripts/ci/tailwind-coverage-check.php --rebuild         # build dulu ke temp, lalu banding
```

Exit code 0 = aman. Non-zero = ada regresi atau config rusak.

### Verifikasi Manual Private Drive 403 (saat deploy)

Setelah deploy (atau sebelum melepas perubahan), pastikan web server benar-benar
menolak akses langsung ke storage Drive private — file `.htaccess` saja bukan
bukti. Gunakan file probe sekali pakai, lalu hapus:

```bash
cd /path/ke/MEeL

# 1. Buat file probe di storage Drive private.
#    Lokasi storage mengikuti MEEL_HDD_DRIVE (auth/settings.php); fallback ke
#    folder nyata ter-track data_drive/private_admins bila konstanta tidak ada
#    (modul Drive tidak lagi memakai symlink untuk storage — lihat installation.md).
BASE=$(php -r 'require "modules/core/helpers.php"; echo meel_drive_base_path();')
TARGET="$BASE/private_admins"
mkdir -p "$TARGET/zz_403_probe/video"
echo 'PROBE' > "$TARGET/zz_403_probe/video/probe.mp4"

# 2. Akses langsung → HARUS 403 (bukan 200/404 dari web server)
curl -s -o /dev/null -w 'file: %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/video/probe.mp4'

# 3. Directory listing → HARUS 403
curl -s -o /dev/null -w 'dir:  %{http_code}\n' \
  'http://localhost/MEeL/data_drive/private_admins/zz_403_probe/'

# 4. Bersihkan probe
rm -rf "$TARGET/zz_403_probe"
```

**Output yang diharapkan:** keduanya `file: 403` dan `dir: 403`. Hasil lain
(200, 301, 404 yang dilayani storage) berarti `AllowOverride` / `mod_rewrite`
tidak aktif untuk `data_drive/` — perbaiki `httpd.conf` (`AllowOverride All`)
sebelum rilis.

> 💡 Tip: pemeriksaan yang sama juga bagian dari `tests/check_deploy.php`
> (aturan deny `.htaccess`) — jalankan `php tests/check_deploy.php --url=http://localhost/MEeL`
> untuk laporan kesehatan deployment lengkap.

---

> Jalankan `php vendor/bin/phpunit --list-tests` untuk jumlah test terkini. Angka exact sengaja tidak diduplikasi di sini karena berubah setiap kali test ditambahkan.

