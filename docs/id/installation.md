# 🚀 Panduan Instalasi MEeL

Langkah-langkah setup MEeL di server lokal Anda.

---

## Daftar Isi

- [⚡ Instalasi Otomatis (install.sh)](#-instalasi-otomatis-installsh)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi Langkah demi Langkah](#instalasi-langkah-demi-langkah)
- [Verifikasi Instalasi](#verifikasi-instalasi)
- [Troubleshooting Instalasi](#troubleshooting-instalasi)

---

## ⚡ Instalasi Otomatis (install.sh)

Installer otomatis yang menjalankan sebagian besar langkah panduan ini dalam satu perintah (diuji di Ubuntu/Debian):

```bash
./install.sh                 # mode interaktif (tanya konfigurasi)
./install.sh --yes           # non-interaktif, pakai semua default
./install.sh --hdd=/path     # set MEEL_HDD_BASE langsung
./install.sh --skip-apt      # lewati instalasi paket sistem (sudah ada)
./install.sh --xsendfile     # aktifkan MEEL_USE_XSENDFILE (wajib mod_xsendfile Apache)
```

Urutan langkah yang dijalankan (idempotent untuk sebagian besar langkah):

1. **Cek dependency** — PHP 8.1+ dengan ekstensi `mysqli`, `pdo_mysql`,
   `fileinfo`, `mbstring`, `intl`, `gd`, `zip`, `xml`, `curl`, plus
   MariaDB/MySQL; diinstall via apt bila perlu (`--skip-apt` untuk melewati).
2. **Setup database** — schema tidak punya CREATE DATABASE/USE; installer buat DB jika belum ada dan import schema.sql dengan nama DB eksplisit. Schema asli tidak diubah. Jika schema mengandung USE/CREATE DATABASE, installer peringatkan. Jika DB sudah ada berisi tabel, import dilewati (reimport hanya atas konfirmasi eksplisit; mode `--yes` memaksa reimport).
3. **Buat akun admin** — Anda **wajib** membuat username dan password sendiri
   (tidak ada kredensial default). Script akan meminta input dan menyimpannya
   ke database dengan bcrypt hash.
4. **Konfigurasi aplikasi** — buat `auth/settings.php` & `auth/config.php`
   dari template, patch kredensial DB + `MEEL_HDD_BASE`, dan (opsional)
   aktifkan `MEEL_USE_XSENDFILE` via `--xsendfile`.
5. **Direktori storage** — buat struktur lengkap di `MEEL_HDD_BASE`
   (termasuk `music/upload/{file,thumbnail}` dan
   `books/upload/{manga,pdf,thumbnail}`), arahkan symlink deploy
   `{video,music,books}/upload` serta `data_drive/public` ke storage terpusat,
   dan **salin `.htaccess` hardening ke target** (symlink tidak pernah
   di-commit).
6. **Apache** — aktifkan `mod_rewrite` (best-effort).
7. **Migration** — `php database/migrate.php`.
8. **Verifikasi akhir** — `php tests/check_deploy.php`; **exit code `1` jika
   ada FAIL** dan banner akhir dibedakan antara "deployment sehat" vs "server
   belum siap dipakai".

> 💡 Langkah 8 memvalidasi `MEEL_HDD_BASE` asli di `auth/settings.php` (tanpa override `--hdd`) — konfigurasi yang diuji adalah konfigurasi yang digunakan aplikasi.

---

## Persyaratan Sistem

### Minimum Requirements

| Komponen | Versi | Keterangan |
|---|---|---|
| **PHP** | **8.1+** | — |
| **MySQL** | 5.7+ / MariaDB 10.2+ | Encoding `utf8mb4` |
| **Apache** | 2.4+ | Wajib `mod_rewrite` |
| **FFmpeg** | 6.0+ | Untuk HLS & transcoding |
| **FFprobe** | (bundled with FFmpeg) | Untuk probing media |
| **yt-dlp** | Versi terbaru | Untuk download URL |
| **RAM** | 2 GB (4 GB+) | 4 GB+ untuk transcoding |
| **Storage** | 10 GB+ | Tergantung ukuran media |

### Translator mecab

```bash
sudo apt install mecab mecab-ipadic-utf8 libmecab-dev
```

### PHP Extensions yang Diperlukan

```bash
# Pada Ubuntu/Debian
sudo apt install php8.1-mysqli php8.1-pdo-mysql php8.1-gd php8.1-fileinfo \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-xml
```

Atau aktifkan di `php.ini`:
```ini
extension=mysqli
extension=pdo_mysql
extension=gd
extension=fileinfo
extension=json
extension=mbstring
extension=intl
extension=zip
```

> ⚠️ **Ekstensi `intl`** diperlukan untuk fitur transliterasi nama file (karakter Jepang/Kana → Romaji).
> ⚠️ **Ekstensi `zip`** diperlukan untuk upload manga (ZIP/CBZ).
> ⚠️ **Ekstensi `mecab`** diperlukan untuk translate yang lebih baik

> 💡 `install.sh` menginstal dan memverifikasi semua ekstensi di atas otomatis (termasuk `pdo_mysql` dan `fileinfo`) — lihat [Instalasi Otomatis](#instalasi-otomatis-installsh).

### OS yang Direkomendasikan

**Linux (Ubuntu Server / Debian)** sangat direkomendasikan. Windows memiliki keterbatasan:
- Manajemen sinyal proses FFmpeg berbeda
- Sistem permission file berbeda
- Case-sensitive path pada file PHP

---

## Instalasi Langkah demi Langkah

### 1. Install Web Server (XAMPP/LAMPP atau Manual)

**Opsi A — LAMPP (Linux):**
```bash
# Download XAMPP for Linux
wget https://www.apachefriends.org/xampp-files/8.2.12/xampp-linux-x64-8.2.12-0-installer.run
chmod +x xampp-linux-*.run
sudo ./xampp-linux-*.run
```

**Opsi B — Manual (Ubuntu/Debian):**
```bash
sudo apt update
sudo apt install apache2 mysql-server php8.1 php8.1-mysqli php8.1-gd \
                 php8.1-mbstring php8.1-intl php8.1-zip php8.1-fileinfo
```

### 2. Kloning Repositori

```bash
cd /opt/lampp/htdocs  # Untuk XAMPP/LAMPP
# atau
cd /var/www/html      # Untuk Apache manual

git clone https://github.com/mifada2543/MEeL-HUB.git MEeL
cd MEeL
```

### 3. Setup Database

> **📁 File skema database sudah tersedia di [`database/schema.sql`](../../database/schema.sql).**
> Setelah impor, jalankan migrasi untuk menyelesaikan setup.

> ⚠️ `schema.sql` **tidak** memuat `CREATE DATABASE` / `USE`. Nama database wajib disebut di baris perintah — inilah yang mencegah impor mendarat diam-diam di database yang salah. Untuk nama DB selain `MEeL`, sebutkan nama itu langsung di perintah; `install.sh` memakai cara yang sama.

#### Opsi A — Via MySQL CLI (cepat):

```bash
mysql -u root -p MEeL < database/schema.sql
```

#### Opsi B — Via MySQL prompt:

```bash
mysql -u root -p
```
```sql
CREATE DATABASE IF NOT EXISTS `MEeL` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `MEeL`;
SOURCE /path/ke/MEeL/database/schema.sql;
```

#### Opsi C — Via phpMyAdmin / GUI lainnya:

1. Buka phpMyAdmin → **pilih atau buat database target terlebih dahulu**
2. Pergi ke tab **Import**
3. Pilih file `database/schema.sql`
4. Klik **Go**

### 4. Konfigurasi Aplikasi

```bash
cd /opt/lampp/htdocs/MEeL/auth
cp settings.example.php settings.php
cp config.example.php config.php
```

> 💡 `install.sh` menerapkan `chmod 640` + `chgrp` ke grup web pada `auth/settings.php` **hanya bila `$CAN_ELEVATE` terisi** (privilege elevation terdeteksi). Pada instalasi baru tanpa elevation, file tetap default (biasanya 644) — konfigurasi DB **bukan** "never world-readable" secara default. Untuk production, jalankan eksplisit:
> ```bash
> sudo chgrp "$WEB_GROUP" auth/settings.php auth/config.php
> sudo chmod 640 auth/settings.php auth/config.php
> ```

Edit `auth/settings.php`:
```php
$server   = "localhost";
$username = "root";       // User database Anda
$password = "";           // Password database Anda
$db       = "MEeL";       // Nama database
```

> `auth/config.php` adalah entry point yang me-require `auth/settings.php`.

Setelah `auth/config.php` sudah diisi, jalankan migrasi database:
```bash
php database/migrate.php
```

> 💡 Migrasi akan menambahkan FULLTEXT index, foreign key, dan pengoptimalan lainnya secara otomatis.

### 5. Buat Direktori Runtime

```bash
cd /opt/lampp/htdocs/MEeL
mkdir -p data_drive/public data_drive/private_admins temp profile/upload music/upload/file music/upload/thumbnail books/upload/manga books/upload/pdf books/upload/thumbnail

# Deteksi user web server (Apache: www-data di Debian/Ubuntu, apache di RHEL/CentOS, _www di macOS)
WEB_USER="${WEB_USER:-$(ps aux | grep -E '(apache|httpd)' | grep -v grep | head -1 | awk '{print $1}')}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$(id -gn "$WEB_USER" 2>/dev/null || echo "$WEB_USER")}"

# Baseline: dirs 750, files 640 (tanpa akses world, file tidak executable)
sudo find data_drive temp profile/upload music/upload books/upload -type d -exec chmod 750 {} \;
sudo find data_drive temp profile/upload music/upload books/upload -type f -exec chmod 640 {} \;

# Direktori writable: 770 (group write) — hanya tempat app menulis saat runtime
sudo chmod 770 data_drive/public data_drive/private_admins temp profile/upload music/upload/file music/upload/thumbnail books/upload/manga books/upload/pdf books/upload/thumbnail

# Ownership: hanya direktori runtime, bukan seluruh repo
sudo chown -R "$WEB_USER:$WEB_GROUP" data_drive temp profile/upload music/upload books/upload
```

> 💡 Override dengan `WEB_USER=apache WEB_GROUP=apache` jika auto-deteksi salah pilih user. **Jangan** `chown` seluruh repo atau `MEEL_HDD_BASE` — hanya direktori runtime yang tercantum di atas.
> ⚠️ `books/upload`, `music/upload`, `video/upload` adalah folder nyata yang ter-track di repo (placeholder `.gitkeep` + `.htaccess` hardening) — **bukan symlink**. Isinya (file upload) di-ignore; file media disimpan di bawah `MEEL_HDD_BASE` dan disajikan lewat endpoint PHP (lihat [6. Media Storage](#6-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)).
> ℹ️ `data_drive/public` dan `data_drive/private_admins` adalah folder nyata yang ter-track di repo (bukan symlink) — keduanya storage bawaan (fallback) modul Drive dan dibuat/dipakai otomatis. Untuk storage Drive di HDD eksternal, set `MEEL_HDD_DRIVE` di `auth/settings.php` (lihat di bawah). **Jangan pernah commit symlink di dalam `data_drive/`** — `.gitignore` memblokirnya.

### 6. Media Storage (MEEL_HDD_BASE) — Endpoint PHP + Rewrite (tanpa symlink)

Semua path media terpusat di `auth/settings.php` — ubah satu baris dan seluruh sistem mengikuti:

```php
// auth/settings.php
// WAJIB DIGANTI sebelum produksi — nilai default hanya placeholder!
define('MEEL_HDD_BASE', '/media/CHANGE_ME/MEeL/media');

// Path turunan (otomatis):
//   MEEL_HDD_VIDEO_UPLOAD = MEEL_HDD_BASE . '/video/upload/'
//   MEEL_HDD_VIDEO_DIR    = MEEL_HDD_BASE . '/video/upload/video/'
//   MEEL_HDD_THUMB_DIR    = MEEL_HDD_BASE . '/video/upload/thumbnail/'
//   MEEL_HDD_MUSIC_UPLOAD = MEEL_HDD_BASE . '/music/upload/'
//   MEEL_HDD_BOOKS_UPLOAD = MEEL_HDD_BASE . '/books/upload/'
//   MEEL_HDD_DRIVE        = MEEL_HDD_BASE . '/drive/'
```

#### Cara file media disajikan (tanpa perlu symlink)

Repositori men-track `books/upload`, `music/upload`, dan `video/upload` sebagai folder nyata (placeholder `.gitkeep` + `.htaccess` hardening) — storage fallback bawaan. **Tidak ada symlink yang di-commit atau diperlukan.** File media disimpan di bawah `MEEL_HDD_BASE` (via konstanta turunan `MEEL_HDD_*_UPLOAD`) dan disajikan lewat **endpoint PHP** yang dipetakan oleh **internal rewrite** di `.htaccess` root:

```apache
<IfModule mod_rewrite.c>
    RewriteRule ^video/upload/(.+)$ video/stream.php?f=$1 [END,QSA,B]
    RewriteRule ^music/upload/(.+)$ music/file.php?f=$1 [END,QSA,B]
    RewriteRule ^books/upload/(.+)$ books/file.php?f=$1 [END,QSA,B]
</IfModule>
```

URL di browser tetap `.../upload/...` (segmen HLS relatif seperti `.ts` tetap resolve dengan benar), tetapi Apache secara internal meneruskan request ke endpoint, yang me-resolve file asli via `meel_media_base_path()`. Rule-rule ini dibungkus `<IfModule mod_rewrite.c>` dan ditempatkan **sebelum** routing front controller (`router.php`) — pengecualian eksplisit agar request streaming/range langsung mengenai endpoint tanpa lewat router. Flag `END` menghentikan seluruh proses rewrite (bukan hanya ronde saat ini, beda dengan `L`), dan flag `B` meng-escape backreference sehingga nama file yang mengandung spasi atau karakter khusus tetap lolos rewrite tanpa merusak query string:

- `MEEL_HDD_*_UPLOAD` terdefinisi → file dibaca dari path HDD;
- tidak terdefinisi → file dibaca dari folder fallback repo
  `<root>/{module}/upload`.

Endpoint menerapkan proteksi path traversal, whitelist ekstensi, dukungan Range (206 — diperlukan untuk HLS `.ts` dan video besar), serta referer gate untuk HLS video (anti-hotlink). Seluruh module (video, music, books, drive) juga mendukung akselerasi X-Sendfile opsional dengan fallback otomatis — lihat [mod_xsendfile](#-aktifkan-mod_xsendfile-opsional--lanjutan--untuk-akselerasi-streaming). Pemutaran audio memakai `music/stream?id=...` (otorisasi session + referer gate ketat).

`.gitignore` secara eksplisit memblokir commit symlink dengan nama folder ini (sebelumnya pernah ter-commit symlink absolut `/media/<user>/...` yang membocorkan username OS dan broken di mesin lain).

#### Storage Cloud Drive — `MEEL_HDD_DRIVE`

**Modul Drive memakai pola konstanta yang sama**: base path-nya dibaca langsung dari `MEEL_HDD_DRIVE` (turunan `MEEL_HDD_BASE` di `auth/settings.php`), persis seperti Video/Music/Books yang membaca konstanta `MEEL_HDD_*_UPLOAD` — tanpa symlink di mana pun:

```php
// auth/settings.php
define('MEEL_HDD_DRIVE', MEEL_HDD_BASE . '/drive/'); // base = public/ + private_admins/
```

- **Mode HDD (`MEEL_HDD_DRIVE` terdefinisi):** Drive membaca/menulis file langsung di bawah `<MEEL_HDD_DRIVE>/public/<type>` dan `<MEEL_HDD_DRIVE>/private_admins/<username>/<type>`; kedua sub-tree dibuat otomatis. File private hanya dilayani lewat endpoint ber-otorisasi `drive/stream.php` / `drive/download.php` (yang membaca `MEEL_HDD_DRIVE` langsung), jadi **tidak perlu** symlink untuk `private_admins`. Pratinjau publik memakai path web `data_drive/public/...` — agar pratinjau di browser tetap jalan di mode HDD, arahkan symlink **saat deploy** (jangan pernah di-commit) — **`install.sh` membuatnya otomatis**:
  ```bash
  rm -f data_drive/public && ln -s "$BASE/drive/public" data_drive/public
  ```
  `tests/check_deploy.php` menilai symlink deploy ini **PASS** (target di dalam `MEEL_HDD_DRIVE`) dan hanya memberi WARN bila target menunjuk ke luar storage Drive (mis. symlink ter-commit `/media/<user>/...`).
- **Mode fallback (`MEEL_HDD_DRIVE` tidak terdefinisi):** Drive memakai folder ter-track `data_drive/public` & `data_drive/private_admins` (folder nyata, dibuat otomatis oleh `DriveStorage::ensureDirectoryExists()`). Ini perilaku bawaan pada clone baru
- **Jangan pernah commit symlink di dalam `data_drive/`**: `.gitignore` memblokir `data_drive/public` / `data_drive/private_admins` sebagai symlink. `tests/check_deploy.php` hanya memperingatkan symlink yang menunjuk ke LUAR `MEEL_HDD_DRIVE` (mis. `/media/<user>/...`); symlink deploy ke dalam `MEEL_HDD_DRIVE` dinilai PASS. Symlink absolut yang ter-commit membocorkan username OS lewat repo publik dan membuat modul Drive crash di mesin lain (`RuntimeException: Folder penyimpanan gagal dibuat`)
- Subtree private tetap di-deny oleh `data_drive/.htaccess` yang ter-track (`RewriteRule ^private_admins/ - [F,L]`) plus `data_drive/private_admins/.htaccess` (`Require all denied` — ter-track di folder fallback dan dibuat ulang saat deploy pada target storage eksternal)

#### Mount / Buat Storage

```bash
df -h   # cek mount point yang tersedia

# Contoh /etc/fstab untuk mount permanen (opsional):
# /dev/sdb1  /media/<user>/MEeL/media  ext4  defaults,nofail  0 2
sudo mount -a
```

#### Buat Struktur Direktori Storage

```bash
BASE=/media/<user>/MEeL/media   # ganti dengan path ANDA
mkdir -p "$BASE"/video/upload/video "$BASE"/video/upload/thumbnail
mkdir -p "$BASE"/music/upload/file   "$BASE"/music/upload/thumbnail
mkdir -p "$BASE"/books/upload/manga  "$BASE"/books/upload/pdf "$BASE"/books/upload/thumbnail
mkdir -p "$BASE"/drive/public "$BASE"/drive/private_admins
touch "$BASE"/.meel_mount
```

`.meel_mount` adalah **penanda volume ter-mount**: dibuat `install.sh`, diblokir `.gitignore`, dan dibaca `meel_storage_ready()` untuk membedakan volume yang benar-benar ter-mount dengan folder kosong sisa di mountpoint. Tanpa penanda, folder storage yang kosong dianggap *belum ter-mount* dan semua halaman Drive/media jatuh ke layar aman `err/?code=storage` (HTTP 503) alih-alih crash.

#### Tidak Ada Symlink yang Perlu Dibuat

Folder upload books/music/video adalah folder nyata di repo — tidak ada yang perlu di-symlink untuk instalasi manual. **`install.sh` otomatis membuat symlink deploy** `<root>/{video,music,books}/upload → $MEEL_HDD_BASE/{m}/upload` saat `MEEL_HDD_BASE` berbeda dari folder repo, dan **menyalin `.htaccess` hardening ke target** agar `check_deploy` tetap PASS (symlink tidak pernah ter-commit — `.gitignore` memblokirnya).

Untuk instalasi manual, symlink bersifat opsional — jika ingin Apache menyajikan file langsung (melewati PHP) demi performa, Anda boleh mengarahkan folder ke storage HDD **saat deploy**:

```bash
cd /opt/lampp/htdocs/MEeL
for d in books music video; do
    rm -rf "$d/upload"
    ln -s "$BASE/$d/upload" "$d/upload"
done
ls -la books/upload music/upload video/upload   # harus menunjuk ke BASE Anda
```

> ⚠️ Jika membuat symlink manual, pastikan `.htaccess` hardening (pola `data_drive/.htaccess`: `php_flag engine off` + `ForceType` + `Options -Indexes`) ada di **target** storage — `install.sh` melakukannya otomatis. `tests/check_deploy.php` menilai symlink yang menunjuk ke `MEEL_HDD_BASE` sebagai PASS, yang menunjuk ke tempat lain sebagai FAIL.

#### Perizinan

```bash
# Deteksi user web server (sama seperti langkah 5)
WEB_USER="${WEB_USER:-$(ps aux | grep -E '(apache|httpd)' | grep -v grep | head -1 | awk '{print $1}')}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-$(id -gn "$WEB_USER" 2>/dev/null || echo "$WEB_USER")}"

# Hanya chown storage tree yang dibuat, bukan seluruh mount point
sudo chown -R "$WEB_USER:$WEB_GROUP" "$BASE"

# Baseline: dirs 750, files 640
sudo find "$BASE" -type d -exec chmod 750 {} \;
sudo find "$BASE" -type f -exec chmod 640 {} \;

# Subdir writable: 770
sudo chmod 770 "$BASE"/video/upload/video "$BASE"/video/upload/thumbnail \
    "$BASE"/music/upload/file "$BASE"/music/upload/thumbnail \
    "$BASE"/books/upload/manga "$BASE"/books/upload/pdf "$BASE"/books/upload/thumbnail \
    "$BASE"/drive/public "$BASE"/drive/private_admins
```

> ⚠️ **Jangan** jalankan `chmod -R 775` atau `chown -R` pada seluruh mount point — membuat file executable dan bisa memengaruhi file tak terkait di mount bersama. Hanya subdirektori storage MEeL yang butuh permission disesuaikan.

#### .htaccess Keamanan di Folder Upload (Wajib)

Setiap direktori upload wajib berisi `.htaccess` yang mematikan eksekusi PHP (`php_flag engine off`), guard MIME `ForceType`, dan `Options -Indexes` — pola yang sama dengan `data_drive/.htaccess`. `tests/security_test.php` memverifikasi ini:

```bash
php tests/security_test.php
```

> Security test sekarang melaporkan **0 FAIL di semua environment** — aturan deny `.htaccess` folder upload ter-track di repo dan diverifikasi secara statis. Ia bisa mengeluarkan **5 warning non-kritis** (review query mentah MediaViewer, cek MIME profile_edit, dan deteksi parameter session) — itu item review, bukan masalah deployment. Untuk verifikasi level storage gunakan `tests/check_deploy.php` (lihat [6. Media Storage](#6-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)).

#### Verifikasi Storage

```bash
df -h "$BASE"
test -d "$BASE/video/upload/video" && echo "storage OK"
php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_BASE') ? MEEL_HDD_BASE : 'NOT SET';"
```

#### Cek Deployment Otomatis (Satu Perintah)

Project menyertakan health-check CLI yang memverifikasi area kritis deployment dalam satu kali jalan — `MEEL_HDD_BASE`, folder upload (folder nyata atau symlink saat deploy), **subdirektori non-auto-create** (`music/upload/file`, `music/upload/thumbnail`, `books/upload/pdf`, `books/upload/thumbnail` — modul music/books **tidak** membuatnya otomatis, jadi jika salah satu hilang: **FAIL** → deployment belum siap, exit code `1`), hardening `.htaccess` folder upload, guard portabilitas `data_drive/` (symlink deploy ke `MEEL_HDD_DRIVE` = PASS; menunjuk ke luar = WARN), dan aturan `mod_rewrite` PWA:

```bash
php tests/check_deploy.php                           # cek lokal + probe HTTP otomatis
php tests/check_deploy.php --url=http://localhost/MEeL   # probe HTTP eksplisit
php tests/check_deploy.php --hdd=/tmp/meel-storage/media  # override MEEL_HDD_BASE (testing/CI)
php tests/check_deploy.php --no-color                # tanpa warna ANSI (untuk CI/log)
```

Setiap item dilaporkan sebagai `PASS` / `WARN` / `FAIL` beserta ringkasan; exit code `0` saat sehat dan `1` saat ada minimal satu FAIL (ramah CI). Jika storage belum ter-mount, script melaporkan FAIL pada area storage / folder upload / `.htaccess` upload — mount storage, set `MEEL_HDD_BASE`, pastikan folder upload punya `.htaccess`, lalu jalankan ulang sampai muncul `✅ Deployment sehat.`

> 💡 `install.sh` menjalankan health-check ini sebagai langkah verifikasi terakhir **tanpa `--hdd`** (konfigurasi asli `auth/settings.php` yang diuji) dan **keluar dengan code `1`** jika ada minimal satu FAIL — banner akhir dibedakan antara "deployment sehat" dan "server belum siap dipakai".

### 7. Konfigurasi Apache

#### Aktifkan mod_rewrite:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

#### Konfigurasi AllowOverride untuk MEeL (hanya development — production harus pakai VirtualHost dengan `AllowOverride None`):

Edit `/etc/apache2/apache2.conf` atau config site-specific di `/etc/apache2/sites-available/`:
```apache
# Scoped ke MEeL saja — bukan seluruh /var/www/html
<Directory /opt/lampp/htdocs/MEeL>
    Options -Indexes +FollowSymLinks
    # Set minimal untuk .htaccess jalan (dev only):
    #   Options — untuk Options -Indexes di .htaccess
    #   FileInfo — untuk mod_rewrite, AddType, dll.
    #   AuthConfig — untuk directive Require
    #   Limit — untuk Order/Allow/Deny (legacy)
    AllowOverride Options FileInfo AuthConfig Limit
    Require all granted
</Directory>
```

> ⚠️ **Hanya development.** `<Directory /var/www/html>` luas dengan `AllowOverride All` dan `Options Indexes` tidak aman — mengaktifkan directory listing dan mengizinkan `.htaccess` di mana saja. Deployment production harus pakai VirtualHost dedicated dengan `AllowOverride None` dan taruh semua rewrite/access rule langsung di config VirtualHost.

#### 📱 mod_rewrite & PWA (wajib)

**PWA bergantung pada mod_rewrite + pemrosesan .htaccess**: service worker dibangkitkan oleh `sw.js.php` dan disajikan sebagai `/sw.js` via rewrite `.htaccess` root. Verifikasi bahwa ini bekerja:

```bash
curl -sI http://localhost/MEeL/sw.js | grep -i content-type
# Content-Type: application/javascript; charset=utf-8   ← benar
```

Jika `.htaccess` tidak diproses (`AllowOverride` dinonaktifkan), `/sw.js` mengembalikan 404 dan PWA menurun secara diam-diam — situs tetap berfungsi, tetapi **mode offline dan "Add to Home Screen" berhenti bekerja**.

#### ⚡ Aktifkan mod_xsendfile (Opsional / Lanjutan — untuk akselerasi streaming)

mod_xsendfile mempercepat streaming file besar (FLAC 33MB+, MKV 4K) dengan membiarkan Apache mengirim file langsung dari disk tanpa melalui PHP. Ini **opsional** — aplikasi otomatis fallback ke streaming PHP jika modul tidak ada atau path tidak di-whitelist.

**Instalasi berbeda tergantung flavor Apache:**

---

**A. Apache Standar (Ubuntu/Debian — terinstall via `apt`)**

Gunakan paket distro — tidak perlu kompilasi manual:

```bash
sudo apt update
sudo apt install libapache2-mod-xsendfile
sudo a2enmod xsendfile
sudo systemctl restart apache2
```

**B. XAMPP / LAMPP (Apache dari Apache Friends)**

Tidak ada paket distro — kompilasi manual terhadap `apxs` milik XAMPP:

```bash
git clone --depth 1 https://github.com/nmaier/mod_xsendfile.git /tmp/mod_xsendfile
cd /tmp/mod_xsendfile

# Kompilasi dengan apxs XAMPP
/opt/lampp/bin/apxs -c mod_xsendfile.c

# Jika apxs gagal dengan error libtool, kompilasi manual:
# gcc -c -I/opt/lampp/include -I/opt/lampp/include/apr-1 -fPIC -DPIC mod_xsendfile.c -o mod_xsendfile.o
# gcc -shared -o mod_xsendfile.so mod_xsendfile.o -L/opt/lampp/lib -lapr-1

sudo cp mod_xsendfile.so /opt/lampp/modules/
sudo chmod 755 /opt/lampp/modules/mod_xsendfile.so
```

**Konfigurasi (kedua flavor)**

Tambahkan ke config Apache (mis. `/etc/apache2/conf-available/meel-xsendfile.conf` untuk Apache standar, atau `httpd.conf` untuk XAMPP):

```apache
LoadModule xsendfile_module modules/mod_xsendfile.so

# Kernel sendfile: opsional, mempercepat pengiriman file statis oleh Apache
EnableSendfile on

<IfModule xsendfile_module>
    XSendFile on
    # Whitelist HANYA direktori media/storage aktual yang butuh akselerasi:
    # 1. Storage media terpusat (MEEL_HDD_BASE dari auth/settings.php)
    XSendFilePath "/media/<user>/MEeL/media"
    # 2. Upload profile (jika MEEL_HDD_BASE tidak dipakai untuk profile)
    XSendFilePath "/opt/lampp/htdocs/MEeL/profile/upload"
    # 3. Output transcode sementara (segmen HLS, dll.)
    XSendFilePath "/dev/shm/meel"
    # 4. Drive public storage (jika pakai MEEL_HDD_DRIVE eksternal)
    # XSendFilePath "/media/<user>/MEeL/media/drive/public"
</IfModule>
```

> ⚠️ **JANGAN** gunakan `XSendFilePath "/opt/lampp/htdocs/MEeL"` — ini me-whitelist seluruh webroot. Hanya whitelist subdirektori spesifik tempat file media benar-benar berada.
>
> ✅ Jika `XSendFilePath` tidak mencakup path storage, **streaming tidak rusak**: aplikasi membaca `XSendFilePath` dari konfigurasi Apache dan hanya mengirim header `X-Sendfile` untuk path yang di-whitelist — sisanya otomatis fallback ke streaming PHP (hanya kehilangan akselerasi).

**Restart Apache:**

```bash
# Apache Standar:
sudo systemctl restart apache2

# XAMPP/LAMPP:
sudo /opt/lampp/lampp restart
```

**Verifikasi modul ter-load:**

```bash
# Apache Standar:
apache2ctl -M | grep xsendfile
# Output: xsendfile_module (shared)

# XAMPP/LAMPP:
/opt/lampp/bin/httpd -M | grep xsendfile
# Output: xsendfile_module (shared)
```

**Aktifkan di aplikasi** — edit `auth/settings.php`:

```php
define('MEEL_USE_XSENDFILE', true);
```

**Uji akselerasi bekerja:**

1. Putar file FLAC besar atau MKV 4K
2. Cek log akses Apache: header `X-Sendfile` harus muncul di response (pakai `curl -I` atau devtools browser)
3. Penggunaan CPU saat playback harus signifikan lebih rendah dibanding streaming PHP

---

### 8. Setup FFmpeg

```bash
# Ubuntu/Debian
sudo apt install ffmpeg

# Verifikasi
ffmpeg -version
ffprobe -version

# Cek lokasi binary
which ffmpeg    # Output: /usr/bin/ffmpeg
which ffprobe   # Output: /usr/bin/ffprobe
```

### 9. Setup yt-dlp

```bash
# Install via pip
sudo apt install python3-pip
pip3 install yt-dlp

# Atau download langsung
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp

# Verifikasi
yt-dlp --version
```

### 10. Migration System

Setelah semua setup selesai, jalankan migrasi database untuk mengoptimalkan skema:
```bash
# Dari root proyek
/opt/lampp/bin/php database/migrate.php
```

Migration bersifat **idempotent** — aman dijalankan berulang kali. Mensync database ke skema terbaru (FULLTEXT, FK, UNIQUE KEY, MFA, MEeLCoin, index comments, interactions, user_notifications):

> 💡 **Ekstensi arcade (MEeL!Mania, Chess, dll.) punya migration DB sendiri** — tabel `rooms`, `moves`, `arcade_song` & `arcade_score` dibuat lewat `arcade/migrate.php`, **bukan** bagian dari `database/migrate.php`. Install opsional:

> 💡 **Ekstensi Arcade (MEeL!Mania, Chess, dll.) punya migrasi DB sendiri** — tabel `rooms`, `moves`, `arcade_song` & `arcade_score` dibuat lewat `arcade/migrate.php`, **bukan** bagian dari `database/migrate.php`. Install opsional:
> ```bash
> php arcade/migrate.php
> ```
> Lihat [Panduan Arcade](arcade-optional.md) untuk detail.

### 11. Setup cookies.txt (untuk yt-dlp)

Untuk download dari YouTube dan platform lain, ekspor cookie browser:
1. Install ekstensi [Get cookies.txt LOCALLY](https://chrome.google.com/webstore/detail/get-cookiestxt-locally/cclelndahbckbenkjhflpdbgdldlbecc)
2. Login ke YouTube di browser
3. Ekspor cookies ke format Netscape
4. Simpan sebagai `cookies.txt` di `temp/` (folder terproteksi `.htaccess`; file lama di root otomatis dimigrasikan):

```bash
cp /path/to/cookies.txt /opt/lampp/htdocs/MEeL/temp/cookies.txt
```

#### ⚠️ Peringatan Keamanan

- **JANGAN PERNAH commit `cookies.txt`** ke version control
- **JANGAN PERNAH bagikan** cookies di chat, issue, log, atau screenshot
- Set permission ketat:
  ```bash
  chmod 600 /opt/lampp/htdocs/MEeL/temp/cookies.txt
  chown www-data:www-data /opt/lampp/htdocs/MEeL/temp/cookies.txt
  ```
- Gunakan **akun YouTube khusus**, bukan akun utama Anda
- **Rotasi cookies segera** jika pernah terekspos
- Verifikasi proteksi: cek `.gitignore` mengandung `temp/cookies.txt` dan `temp/.htaccess` memblokir akses web

> Lihat [Konfigurasi](configuration.md#cookies-management) untuk detail lebih lanjut tentang penanganan dan rotasi cookie.

---

### 12. Verifikasi Instalasi

1. Nyalakan Apache & MySQL:
   ```bash
   # LAMPP
   sudo /opt/lampp/lampp start
   
   # Manual
   sudo systemctl start apache2 mysql
   ```

2. Buka browser: `http://localhost/MEeL/`

3. Login dengan kredensial yang Anda buat saat instalasi (`install.sh` meminta membuat akun admin)

4. Cek halaman Admin: `http://localhost/MEeL/admin/`

### Pemeriksaan Keamanan Instalasi

| Aturan | Kenapa |
|---|---|
| Installer tidak pernah menimpa kredensial database | Patcher memverifikasi hasilnya dengan `re.search`, berhenti dengan `die` bila gagal, lalu mengecek `php -r 'require auth/settings.php'`. Kegagalan diam-diam di sini berarti instalasi melapor sukses padahal kredensial operator dibuang dan yang dipakai adalah nilai default. |
| Migrasi gagal berhenti, bukan melanjutkan | `database/migrate.php` mengumpulkan kegagalan bersama nomor langkahnya, menulis ke STDERR, **tidak** mencatat versi, dan keluar dengan kode 1. Kalau versi tetap naik padahal langkahnya gagal, skema bisa setengah termigrasi permanen — `SELECT MAX(version)` melewati migrasi itu selamanya. |
| `UseCanonicalName On` di VirtualHost | `install.sh` menulisnya ke VirtualHost yang di-generate. Tanpa itu `SERVER_NAME` disalin dari header `Host:` kiriman klien, dan `MEEL_ENV` bisa dipengaruhi penyerang luar. |

---

## Troubleshooting Instalasi

### ❌ "Koneksi ke database gagal"

- Pastikan MySQL/MariaDB berjalan: `sudo systemctl status mysql`
- Verifikasi kredensial di `auth/settings.php`
- Coba: `mysql -u root -p -e "SHOW DATABASES;"`

### ❌ "Penyimpanan Offline" / Redirect ke maintenance

- Periksa path HDD di `auth/settings.php`:
  ```php
  define('MEEL_HDD_BASE', '/media/[user]/MEeL/media');
  ```
- Sesuaikan dengan mount point Anda: `df -h` untuk cek mount point
- Pastikan storage ter-mount dan endpoint media me-resolve-nya:
  ```bash
  php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; echo meel_media_base_path('video');"
  # Output harus path storage Anda, mis. /media/<user>/MEeL/media/video/upload
  ```
- Folder upload (`books/upload`, `music/upload`, `video/upload`) adalah folder nyata ter-track di repo (tanpa perlu symlink). Media disajikan lewat endpoint PHP (`video/stream.php`, `music/file.php`, `books/file.php`) yang dipetakan rewrite `.htaccess` — lihat [6. Media Storage](#6-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)
- Atau nonaktifkan sementara untuk development

### ❌ Deployment Check melaporkan FAIL pada folder upload

- **Gejala:** `php tests/check_deploy.php` → `FAIL` pada folder upload / `.htaccess` folder upload (storage tidak ter-mount atau `MEEL_HDD_BASE` salah)
- **Penyebab:** storage HDD (`MEEL_HDD_BASE`) tidak ter-mount / salah di `auth/settings.php`. Folder upload adalah folder nyata di repo — tidak ada symlink yang perlu diperbaiki. Symlink saat deploy yang menunjuk ke target salah juga memicu peringatan
- **Perbaikan:** mount storage, set `MEEL_HDD_BASE` dengan benar, dan pastikan setiap folder upload punya `.htaccess` (lihat [6. Media Storage](#6-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)). Jalankan ulang `php tests/check_deploy.php` sampai muncul `✅ Deployment sehat.`

### ❌ "403 Forbidden" pada halaman

- Periksa `.htaccess` di direktori terkait
- Pastikan `AllowOverride All` di konfigurasi Apache

### ❌ "500 Internal Server Error"

- Cek error log: `sudo tail -f /var/log/apache2/error.log`
- Aktifkan error reporting di PHP: `ini_set('display_errors', 1);`

### ❌ FFmpeg/yt-dlp tidak ditemukan

- Pastikan binary terinstall: `which ffmpeg && which yt-dlp`
- Transcoder dan Uploader sudah auto-detect via `resolveBinary()`

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
