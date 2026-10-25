# 🔧 Troubleshooting & FAQ

Panduan pemecahan masalah umum di MEeL-HUB.

---

## Daftar Isi

- [Masalah Database](#masalah-database)
- [Masalah Storage & HDD](#masalah-storage--hdd)
- [Masalah HLS Streaming](#masalah-hls-streaming)
- [Masalah Transcoding](#masalah-transcoding)
- [Masalah yt-dlp](#masalah-yt-dlp)
- [Masalah Sesi & Login](#masalah-sesi--login)
- [Masalah Upload](#masalah-upload)
- [Masalah Performa](#masalah-performa)
- [Masalah Ikon](#masalah-ikon)
- [Error Codes](#error-codes)
- [FAQ](#faq)
- [Advanced Upload & Antrean](#advanced-upload--antrean)

---

## Masalah Database

### ❌ "Koneksi ke database gagal"

**Penyebab:**
- MySQL/MariaDB tidak berjalan
- Kredensial di `auth/settings.php` salah
- Database `MEeL` belum dibuat

**Solusi:**
```bash
# Cek status MySQL
sudo systemctl status mysql
# atau untuk LAMPP
sudo /opt/lampp/lampp status

# Jika tidak berjalan
sudo systemctl start mysql

# Verifikasi kredensial
mysql -u root -p -e "SHOW DATABASES;"

# Buat database jika belum ada
mysql -u root -p -e "CREATE DATABASE MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
```

**Khusus MariaDB: `root` memakai plugin `unix_socket`**

Pada instalasi MariaDB default, `root` ter-autentikasi lewat plugin
`unix_socket` — koneksi hanya sah dari proses yang berjalan sebagai user OS
`root` (via socket). Akibatnya `mysql -u root` sukses di CLI, tapi koneksi dari
proses web server (`www-data`) gagal **"Access denied"** meskipun password
kosong. Solusi yang disarankan: buat user database khusus untuk aplikasi:

```sql
CREATE USER 'meel'@'localhost' IDENTIFIED BY 'password_kuat';
GRANT ALL PRIVILEGES ON MEeL.* TO 'meel'@'localhost';
FLUSH PRIVILEGES;
```

Lalu pakai kredensial itu di `auth/settings.php` (`$username`/`$password`).
Alternatif (kurang disarankan): ubah `root` ke `mysql_native_password`:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED VIA mysql_native_password USING PASSWORD('password_baru');
```

### ❌ "Tabel tidak ditemukan"

**Penyebab:** SQL belum di-import

**Solusi:**
```bash
mysql -u root -p MEeL < database/schema.sql
```
File `database/schema.sql` berisi seluruh skema (23 tabel + admin default) — import langsung.

### ❌ "Column 'description' cannot be null" (atau error kolom lain)

**Penyebab:** Versi skema database tidak sesuai dengan kode

**Solusi:** Jalankan ALTER TABLE untuk menambahkan kolom yang hilang:
```sql
ALTER TABLE video ADD COLUMN description text DEFAULT NULL;
ALTER TABLE music ADD COLUMN description text DEFAULT NULL;
ALTER TABLE video ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE music ADD COLUMN duration int(11) DEFAULT 0;
ALTER TABLE video ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE music ADD COLUMN search_metadata text DEFAULT NULL;
ALTER TABLE users ADD COLUMN last_session_id varchar(128) DEFAULT NULL;
ALTER TABLE users ADD COLUMN access_via varchar(100) DEFAULT NULL;
ALTER TABLE comments ADD COLUMN comment text NOT NULL;
```

---

## Masalah Storage & HDD

### ❌ Halaman maintenance muncul (`err/?code=maintance`)

**Penyebab:** `MEEL_HDD_BASE` di `auth/settings.php` tidak cocok dengan mount point.

**Solusi:**

1. Cek mount point HDD:
   ```bash
   df -h
   lsblk
   ```

2. Sesuaikan path di `auth/settings.php` — **hanya satu baris**:
   ```php
   define('MEEL_HDD_BASE', '/path/yang/benar');
   ```
   Semua modul akan otomatis mengikuti.

3. Untuk development/testing, nonaktifkan HDD check sementara:
   ```php
   // modules/core/helpers.php - comment out the check
   // if (!is_dir($hdd_check_path)) { ... }
   ```

4. Perbaiki permission jika perlu:
   ```bash
   sudo chown -R www-data:www-data /media/[user]/MEeL
   sudo chmod -R 775 /media/[user]/MEeL
   ```

### ❌ "File tidak ditemukan di HDD" setelah upload

**Penyebab:** Permission filesystem atau `MEEL_HDD_BASE` salah.

**Solusi:**
```bash
# Cek apakah folder tujuan bisa diakses
ls -la /media/[user]/MEeL/media/video/upload/

# Perbaiki permission
sudo setfacl -R -m u:daemon:rx /media/[user]/MEeL/media

# Atau berikan akses penuh
sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
```

### Debug Mode untuk Admin

Halaman maintenance kini terpadu di `err/?code=maintance` (HTTP 503) dan hanya menampilkan status perawatan.
Diagnosa path storage dilakukan lewat perintah filesystem (contoh `ls -la` dan `df -h` di atas).

### ⚠️ Dialihkan ke `err/?code=storage` — "Storage Belum Siap" (HTTP 503)

**Gejala:**
- Membuka Drive / halaman upload dialihkan ke `err/?code=storage` (HTTP 503)
- Streaming video/musik/books membalas **503 kosong** (tanpa body)
- Endpoint Drive `upload`/`delete` membalas JSON
  `{"status":"error","code":"storage_unmounted"}`
- Log berisi `[MEeL] Peringatan: MEEL_HDD_BASE tidak dapat diakses: /media/<user>/MEeL/media`

**Penyebab:** volume `MEEL_HDD_BASE` (di `auth/settings.php`) belum ter-mount —
HDD belum dicolok, `mount` belum dijalankan, atau path salah. Dulu kasus ini
berujung fatal `mkdir(): Permission denied ... RuntimeException: Folder
penyimpanan gagal dibuat`; sekarang semua jalur dicek lebih dulu oleh
`meel_storage_ready()` lalu diarahkan ke halaman error yang aman, sehingga
tidak ada folder sampah yang terbentuk di filesystem root.

**Diagnosis:**
```bash
df -h | grep MEeL
ls -la "$(php -r "require 'auth/settings.php'; echo MEEL_HDD_BASE;")"
php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; var_dump(meel_storage_ready());"
```

**Solusi:**
```bash
sudo mount /dev/sdX? /media/<user>/MEeL          # sesuaikan dengan fstab
touch "$(php -r "require 'auth/settings.php'; echo MEEL_HDD_BASE;")/.meel_mount"
```

**Penanda `.meel_mount`** dibuat `install.sh` dan masuk `.gitignore`
(**jangan pernah di-commit**). Ia memberi tahu `meel_storage_ready()` bahwa
folder itu adalah volume ter-mount, bukan sisa folder kosong di mountpoint.
Tanpa penanda: folder **kosong** dianggap belum ter-mount (arah aman: blokir),
folder berisi (volume lama) tetap dilayani dengan log peringatan. Hilangnya
penanda juga diperingatkan `tests/check_deploy.php`.

---

### ❌ Modul Drive crash: "Folder penyimpanan gagal dibuat" (RuntimeException)

**Gejala:**
- Membuka `drive/index.php`, upload, download, atau streaming melempar
  `RuntimeException: Folder penyimpanan gagal dibuat` dari `drive/DriveService.php`
  (`ensureDirectoryExists()`)
- Terjadi pada clone baru atau mesin yang symlink lama repo-nya broken

**Penyebab & Solusi:**

1. **Symlink lama ter-commit ke path absolut yang tidak ada** (checkout lama):
   repo dulu men-track `data_drive/public` & `data_drive/private_admins` sebagai
   **symlink** menunjuk ke `/media/<devuser>/MEeL/media/drive/...` — broken di
   mesin lain. Update repo (symlink sudah dihapus, folder kini ter-track sebagai
   folder nyata), atau perbaiki manual:
   ```bash
   rm -f data_drive/public data_drive/private_admins   # hapus symlink lama
   mkdir -p data_drive/public data_drive/private_admins
   ```

2. **`MEEL_HDD_DRIVE` menunjuk ke path yang tidak ada / tidak writable:** saat
   terdefinisi, modul Drive membaca storage langsung dari `MEEL_HDD_DRIVE`
   (turunan `MEEL_HDD_BASE` di `auth/settings.php`) — **tanpa symlink**. Cek di
   mana aplikasi benar-benar me-resolve storage:
   ```bash
   php -r "require 'auth/settings.php'; echo defined('MEEL_HDD_DRIVE') ? MEEL_HDD_DRIVE : 'NOT SET';"
   php -r "require 'auth/settings.php'; require 'modules/core/helpers.php'; echo meel_drive_base_path();"
   ```
   Jika path hasil resolve tidak ada atau tidak writable, perbaiki `MEEL_HDD_BASE`
   di `auth/settings.php` (atau biarkan tidak di-set agar memakai fallback
   `data_drive/`).

3. **Folder fallback tidak writable** (saat `MEEL_HDD_DRIVE` tidak terdefinisi):
   ```bash
   sudo chown -R www-data:www-data data_drive
   sudo chmod -R 775 data_drive
   ```
   `data_drive/public` & `data_drive/private_admins` dibuat otomatis oleh
   `DriveStorage::ensureDirectoryExists()`.

> ⚠️ **Jangan pernah commit symlink di dalam `data_drive/`** — `.gitignore`
> memblokirnya. `tests/check_deploy.php` hanya memperingatkan symlink yang
> menunjuk ke LUAR `MEEL_HDD_DRIVE` (mis. `/media/<user>/...`); symlink deploy
> ke dalam `MEEL_HDD_DRIVE` dinilai PASS. Lihat
> [Installation §5a](installation.md#5a-media-storage-meel_hdd_base--endpoint-php--rewrite-tanpa-symlink)
> untuk layout storage Drive (`MEEL_HDD_DRIVE` vs fallback `data_drive/`).

---

## Masalah HLS Streaming

### ❌ Overlay "Sambungan Terputus" muncul sendiri saat meng-loop video

**Gejala:** setelah video diulang 2–4 kali (±10 menit), muncul overlay
"Sambungan Terputus", lalu video *jumped* / posisi playback berubah sendiri.
Console menunjukkan:

```
HTML5 video error: PipelineStatus::PIPELINE_ERROR_DECODE: video decode error! code: 3
Mengecek ketersediaan file media di: upload/video/.../....m3u8
Media terdeteksi online! Memulai pemulihan via HTMX...
```

**Penyebab:** atribut native `loop` menyuruh browser melakukan seek
`duration → 0` tanpa memberi tahu hls.js. `HLS_CONFIG.backBufferLength` hanya
**10 detik**, sehingga data di posisi 0 sudah dievict dari `SourceBuffer`.
Chromium lalu menjalankan seek di luar buffer dan melempar `PIPELINE_ERROR_DECODE`
— padahal file medianya sehat (HEAD `.m3u8` membalas 200).

Versi lama langsung menaikkan kode error 3 ke *full recovery*: probe HEAD →
`htmx` swap → seluruh player dibangun ulang, padahal tidak ada yang rusak.

**Perbaikan (sudah masuk di MEeL core):**

| Lapis | Lokasi | Yang dilakukan |
|-------|--------|-----------------|
| Self-heal | `recovery.js` | Kode 2 & 3 diberi *grace period* 8 detik — `hls.recoverMediaError()` lalu dipantau apakah `currentTime` bergerak. Bergerak → selesai, tidak ada recovery. |
| Loop wrap | `player-events.js` | Handler `ended` memberi tahu hls.js posisi tujuan (`hls.startLoad()`) agar ia memuat ulang segmen, bukan buta terhadap seek. |
| Back buffer | `player-init.js` | Video pendek (≤2 menit) disimpan utuh di back buffer, sehingga loop tidak perlu flush sama sekali. |

Log `[MEeL] Error media self-healed, playback lanjut normal.` menandakan
grace period berhasil. Kalau Anda melihat
`Error media tidak pulih dalam 8 detik, lanjut ke recovery`, berarti media
memang bermasalah dan recovery berjalan seperti seharusnya.

**Kalau masih terjadi:** naikkan `MEDIA_ERROR_GRACE_MS` di
`assets/js/video/watch/state.js` (default `8e3`), atau turunkan ambang
back buffer manual.

---


### ❌ Status Loop Playback hilang tiap refresh

**Gejala:** tombol Loop aktif, tapi setelah refresh — atau setelah auto-recovery,
atau setelah ganti kualitas — statusnya kembali normal, padahal video itu
memang sedang diputar beruntun. Di sisi lain, loop yang tetap "nyala" ikut
terbawa ke video berikutnya saat berpindah.

**Penyebab:** status loop hanya hidup di memori player, jadi hilang setiap kali
player dibangun ulang. Dan yang lebih halus, tidak ada yang membersihkannya
saat pindah ke video lain.

**Perbaikan (sudah masuk di MEeL core):**

| Lokasi | Yang dilakukan |
|---|---|
| `assets/js/video/watch/state.js` | Membaca `MEEL_KEYS.VIDEO_LOOP` (`meel_video_loop`) saat boot, dan mengekspor `persistVideoLoop()`, `restoreVideoLoop()`, `clearVideoLoop()` |
| `setupMeelPlayerEvents()` | Memanggil `restoreVideoLoop()`, sehingga loop dipulihkan di **semua** jalur pembuatan player: load awal, auto-recovery, rebuild karena ganti kualitas, dan pergantian video via mini player |
| Pindah video & Auto Next | `clearVideoLoop()` dipanggil saat `skipToNextVideo`, klik kartu mini player, guard klik `.rekomendasi-item`, dan saat Auto Next diaktifkan — sehingga loop tidak ikut terbawa ke video berikutnya |

Auto Next dan Loop saling meniadakan dua arah: menyalakan Auto Next
membersihkan key loop, menyalakan Loop mematikan Auto Next, dan state stale
ketika keduanya aktif dibersihkan saat boot.

## Masalah Transcoding

### ❌ Proses Transcoding Gagal / Stuck

**Penyebab 1: Queue Stuck**
1. Buka Admin Panel → **Active Background Tasks**
2. Klik **Clean Stuck Queues** atau **Force Stop** per task

**Penyebab 2: FFmpeg Error**
```bash
# Cek FFmpeg installation
ffmpeg -version
which ffmpeg

# resolveBinary() (trait modules/transcoder/FfmpegUtils.php) sudah auto-detect
# Jika ingin custom path, set MEEL_FFMPEG_PATH / MEEL_FFPROBE_PATH di auth/settings.php
```

**Penyebab 3: Resource Limit**
```bash
# Monitor CPU/RAM
htop

# Cek proses berjalan
ps aux | grep ffmpeg

# Kill paksa jika perlu
killall -9 ffmpeg
```

### ❌ "FFmpeg gagal menghasilkan file output"

**Solusi:**
1. Periksa disk space:
   ```bash
   df -h
   ```

2. Periksa permission temp folder:
   ```bash
   ls -la /opt/lampp/htdocs/MEeL/temp/
   sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
   ```

3. Coba transcode manual untuk debugging:
   ```bash
   ffmpeg -i input.mp4 -codec copy -hls_time 10 output.m3u8
   ```

### ❌ Transcoding Lambat

**Optimasi:**
```php
// Sesuaikan FFMPEG_THREADS dengan CPU Anda
// Di modules/core/TranscoderBase.php (diwarisi semua service transcoder)
protected const FFMPEG_THREADS = 8; // Ganti dengan jumlah core CPU Anda (nproc)
```

Cek CPU:
```bash
nproc  # Jumlah core
lscpu  # Detail CPU
```

---

## Masalah yt-dlp

### ❌ "Gagal ambil metadata" atau "Gagal parsing metadata"

**Penyebab 1: yt-dlp tidak terinstall**
```bash
which yt-dlp
# Jika tidak ditemukan:
sudo wget https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -O /usr/local/bin/yt-dlp
sudo chmod +x /usr/local/bin/yt-dlp
```

**Penyebab 2: Node.js tidak terinstall**
```bash
which node
# Jika tidak ditemukan:
sudo apt install nodejs
```

**Penyebab 3: Cookies tidak valid/expired**
- Export ulang cookies dari browser
- Pastikan Anda login ke YouTube/platform target
- Simpan sebagai `cookies.txt` di `temp/` (file lama di root otomatis dimigrasikan)

**Penyebab 4: Update yt-dlp**
```bash
yt-dlp -U
# atau
pip3 install -U yt-dlp
```

### ❌ "Download gagal" / yt-dlp error

Cek log error:
```bash
cat /tmp/ytdlp_error.log
```

**Solusi umum:**
1. Update yt-dlp ke versi terbaru
2. Update cookies.txt
3. Coba URL di terminal langsung:
   ```bash
   yt-dlp --cookies cookies.txt "https://youtube.com/watch?v=..."
   ```
4. Jika video di-private/region-locked, tidak bisa di-download

### ❌ Debug Metadata

Saat metadata gagal di-parsing, Transcoder akan menampilkan debug overlay:
```
╔═══════════════════════════════════════════════════╗
║ ⚠️ DEBUG: GAGAL PARSING METADATA                 ║
║                                                   ║
║ URL: https://youtube.com/watch?v=...              ║
║ Return Code: 1 (0 = sukses)                       ║
║ Output: ...                                       ║
║                                                   ║
║ Cek Path System:                                  ║
║ - Node:    /usr/bin/node                          ║
║ - yt-dlp:  /usr/local/bin/yt-dlp                  ║
║ - ffmpeg:  /usr/bin/ffmpeg                        ║
╚═══════════════════════════════════════════════════╝
```

---

## Masalah Sesi & Login

### ❌ Sesi Tiba-tiba Berakhir

**Penyebab:**
- Session timeout (12 jam)
- Session di-kick oleh admin
- Login dari perangkat lain

**Solusi:**
1. Login ulang
2. Jika sering terjadi, cek waktu server:
   ```bash
   timedatectl status
   ```
3. Pastikan folder session PHP writable:
   ```bash
   sudo chmod 777 /var/lib/php/sessions
   # atau
   sudo chmod 777 /tmp
   ```

### ❌ Terus menerus dialihkan ke `/err/?code=revoked` (Session Revoked)

**Penyebab:** Session ID di browser Anda tidak cocok dengan yang terdaftar di database (misalnya karena ter-kick oleh Admin, masuk dari perangkat lain, atau cookie sesi terhapus/tidak persisten).

**Solusi:**
1. Klik tombol **Kembali** pada layar error, lalu lakukan login ulang (atau buka `auth/login.php`).
2. Bersihkan cache dan cookie browser Anda khusus untuk domain platform ini.
3. Jika menggunakan Cloudflare Tunnel, pastikan pengaturan kuki sesi diatur agar tetap persisten.
4. Coba akses menggunakan Mode Incognito / Private Window di browser Anda.

### ❌ Dialihkan ke `/err/?code=banned` (Access Blocked)

**Penyebab:** Alamat IP Anda terdaftar pada tabel pemblokiran firewall internal (`ip_ban`).

**Solusi:**
1. Hubungi Admin MEeL-HUB untuk menghapus IP Anda dari daftar cek di Admin Panel -> **Firewall & Banned IPs**.
2. Jika Anda adalah Admin, Anda dapat mem-bypass blokir secara default (karena role admin dikecualikan dari pengecekan ban di `activity_logger.php`), atau masuk menggunakan koneksi lokal/IP lain terlebih dahulu untuk menghapus IP tersebut melalui dashboard.

### ❌ Login Gagal Terus (Locked)

**Penyebab:** 5x gagal login dalam waktu singkat

**Solusi:**
- Tunggu 5 menit (countdown otomatis)
- Atau hapus session lock di database:
  ```sql
  -- Hapus login_fail_count dari session (tidak bisa langsung dari DB)
  -- Alternatif: restart Apache
  sudo systemctl restart apache2
  ```

---

## Masalah Upload

### ❌ Upload Gagal "File terlalu besar"

**Solusi:** Perbesar `upload_max_filesize` di `php.ini`:
```ini
upload_max_filesize = 500M
post_max_size = 500M
max_execution_time = 300
max_input_time = 300
```

Restart Apache setelah mengubah:
```bash
sudo systemctl restart apache2
```

### ❌ "Batas upload tercapai!"

Non-admin memiliki limit:
- **2 upload per jam** untuk video & music
- **10 upload per jam** untuk drive

Tunggu hingga kuota ter-reset, atau minta admin untuk meng-upload-kan.

### ❌ Upload Advanced URL Error

**Ceklist:**
1. URL harus valid (https://)
2. URL tidak lebih dari 500 karakter
3. Server tidak sedang sibuk (max 2 proses simultan)
4. yt-dlp terinstall dan cookies valid
5. Cukup storage space

---

## Masalah Performa

### ❌ Halaman Lambat

**Optimasi:**
1. **Aktifkan OPcache** di `php.ini`:
   ```ini
   opcache.enable=1
   opcache.memory_consumption=128
   opcache.max_accelerated_files=4000
   ```

2. **Monitor database**:
   ```sql
   SHOW PROCESSLIST;
   EXPLAIN SELECT * FROM video ORDER BY upload_date DESC;
   ```
   Tambahkan index jika perlu:
   ```sql
   ALTER TABLE video ADD INDEX idx_upload_date (upload_date);
   ALTER TABLE music ADD INDEX idx_upload_date (upload_date);
   ```

3. **Cek koneksi internet** untuk asset eksternal (jika ada loading CDN seperti Google Fonts di file testing)

### ❌ CPU 100% saat Transcoding

**Penyebab:** Transcoding HLS atau sprite generation

**Solusi:**
1. Turunkan `FFMPEG_THREADS`:
   ```php
   private const FFMPEG_THREADS = 4; // Dari 8
   ```
2. Batasi proses simultan (already implemented: max 2)
3. Atur transcoding di jam sepi menggunakan cron job

### ❌ HDD/SSD Penuh

**Cleanup rutin:**
1. Admin Panel → **Database Sync Check** → Bersihkan file orphan
2. Hapus file temporary:
   ```bash
   rm -rf /opt/lampp/htdocs/MEeL/temp/*
   ```
3. Bersihkan guest users: Admin Panel → **Clean Inactive Guests**
4. Hapus queue stuck: Admin Panel → **Clean Stuck Queues**
5. Hapus file drive yang tidak perlu

> **Catatan "Cek Sinkronisasi Database":** baris *"{n} varian thumbnail 256px (X MB) dipertahankan — bukan sampah"* itu normal. Tiap video punya dua berkas thumbnail: yang asli (terdaftar di DB) dan varian turunan 256px `<stem>_sm.webp` yang sengaja tidak didaftarkan di DB karena dipakai untuk kartu grid. Jangan dihapus manual lewat "Bersihkan SSD Thinkpad" — baris itu hanya informasi.

---

## Masalah Ikon

### ❌ Ikon lucide tidak tampil

**Solusi:**
```html
<script src="/MEeL/assets/js/compatibilitas/lucide.js"></script>
<script>lucide.createIcons();</script>
```

### ❌ Ikon hilang setelah swap htmx

**Gejala:** ikon ada saat halaman dimuat, tapi begitu ada bagian yang
diganti — misalnya saat like/dislike diklik — ikon di area itu menghilang.
Setelah halaman di-refresh ikon kembali muncul, dan swap berikutnya
menghapusnya lagi. Tidak ada apa-apa di console.

**Penyebab:** ikon adalah elemen `<i data-lucide="...">` yang diganti Lucide
menjadi SVG. penggantian itu hanya terjadi saat `lucide.createIcons()`
dijalankan. Server selalu mengirim markup `<i data-lucide>` apa adanya
dalam setiap fragmen yang di-swap, jadi ada yang harus merender ulang
setelahnya.

`assets/js/shared/htmx-lucide.js` yang melakukannya pada
`htmx:afterOnLoad`. Listener-nya didaftarkan di `document`, **bukan** di
`document.body` — itu disengaja. Di beberapa halaman file ini dimuat di
dalam `<head>`, dan saat itu `document.body` masih `null`; listener yang
didaftarkan di `document.body` tidak akan pernah terpasang, dan setiap swap
berikutnya akan meninggalkan ikon apa adanya tanpa galat apa pun.

**Cek:**
- `assets/js/shared/htmx-lucide.js` termuat di halaman tersebut
- halaman tidak memuat salinan sendiri berkas itu
- di console: `typeof lucide` adalah `"object"` dan `lucide.createIcons` ada

## Error Codes

### HTTP Status Codes

| Kode | Arti | Penyebab Umum |
|---|---|---|
| **401** | Unauthorized | User belum login |
| **403** | Forbidden | IP banned, user inactive, role insufficient |
| **404** | Not Found | Media tidak ada, file hilang |
| **500** | Internal Server Error | Database error, PHP error |
| **503** | Service Unavailable | HDD offline, server busy |

### System Error Messages

| Pesan | Arti | Solusi |
|---|---|---|
| "Penyimpanan Offline" | HDD external tidak ter-mount | Cek `df -h` dan path di `helpers.php` |
| "Server sedang sibuk" | Queue penuh (max 2) | Tunggu atau bersihkan queue stuck |
| "Batas upload tercapai!" | Rate limit aktif | Tunggu 1 jam atau minta admin |
| "File terlalu besar!" | Melebihi quota | Upload file lebih kecil |
| "Durasi terlalu panjang!" | Melebihi batas durasi | Gunakan video lebih pendek |
| "Security Error" | File mencurigakan | Upload file dengan format benar |

---

## FAQ

### Q: Apakah MEeL bisa diakses dari internet?

**A:** Bisa, tapi disarankan menggunakan Cloudflare Tunnel atau VPN. Jangan expose langsung tanpa HTTPS.

### Q: Bagaimana cara backup data?

**A:** Backup database + folder media:
```bash
mysqldump -u root -p MEeL > backup_meel.sql
tar -czf media_backup.tar.gz /media/[user]/MEeL/
```

### Q: Kenapa video tidak muncul thumbnail?

**A:** Thumbnail digenerate otomatis dari frame ke-5 video. Pastikan FFmpeg terinstall.

### Q: Format musik apa yang didukung?

**A:** MP3, OGG/Opus, M4A/AAC, FLAC, WAV. Semua akan di-transcode ke Opus/OGG.

### Q: Bagaimana cara menambahkan admin baru?

**A:** Register user biasa, lalu ubah role di database:
```sql
UPDATE users SET role = 'admin', is_active = 1 WHERE id = [user_id];
```

### Q: Apakah bisa streaming video 4K?

**A:** Secara teknis bisa, tapi kendala di bandwidth dan storage. Disarankan maksimal 1080p.

### Q: Kenapa session guest tidak valid?

**A:** Guest otomatis dibuat saat pertama kali akses. Jika ada masalah, hapus guest tidak aktif via Admin Panel.

---

## Advanced Upload & Antrean

Dokumentasi penanganan masalah khusus untuk fitur **Advanced Upload (yt-dlp)** dan antrean latar belakang (Background Tasks) di MEeL-HUB.

### Gejala & Masalah Umum

Fitur Advanced Upload menggunakan pustaka CLI `yt-dlp` yang dikombinasikan dengan pembungkus PHP asinkron untuk mengunduh media dari URL eksternal (seperti YouTube, NicoNico, TikTok) lalu mengonversinya secara otomatis. Masalah biasanya ditandai dengan status antrean yang terus menerus `"processing"` atau muncul notifikasi **"Server sedang sibuk"** meskipun tidak ada proses aktif.

---

### Masalah 1: Antrean (Queue) Stuck / Server Sibuk

Sistem membatasi maksimal **2 proses unduhan/transcoding simultan** demi menjaga stabilitas kinerja CPU server. Jika proses terputus secara tidak wajar (misalnya koneksi putus atau server mati mendadak), status antrean di database tetap menggantung sebagai `"processing"`.

#### Solusi A — Melalui Admin Panel (Direkomendasikan)

1. Masuk sebagai akun **Admin**.
2. Buka **Dashboard Admin** (`admin/index.php`).
3. Scroll ke bagian **Active Background Tasks**.
4. Klik tombol **Clean Stuck Queues** (akan membersihkan tugas yang menggantung lebih dari 30 menit).
5. Atau klik ikon silang (**Force Stop** `x`) pada tugas spesifik yang mengalami macet.

#### Solusi B — Melalui Database (Manual SQL)

Jalankan perintah SQL berikut melalui phpMyAdmin atau MySQL Client untuk mereset antrean:
```sql
-- Menghapus antrean yang menggantung
DELETE FROM `upload_queue` 
WHERE `status` = 'processing';
```

#### Solusi C — Menghentikan Proses di OS (Linux)

Jika proses FFmpeg atau yt-dlp masih berjalan di latar belakang dan memakan CPU:
```bash
# Cek apakah ada proses aktif
ps aux | grep -E "yt-dlp|ffmpeg"

# Hentikan paksa seluruh proses pengunduhan/transcoding
sudo killall -9 yt-dlp
sudo killall -9 ffmpeg
```

---

### Masalah 2: Gagal Ambil / Parsing Metadata

Saat memasukkan URL, sistem memanggil `yt-dlp --print-json` untuk mengekstrak informasi media sebelum mengunduhnya. Jika proses ini gagal, debug overlay akan muncul di layar.

#### Langkah Pemeriksaan:

1. **Verifikasi Binary Path**:
   Pastikan executable `yt-dlp`, `ffmpeg`, dan `node` dapat ditemukan di server:
   ```bash
   which yt-dlp   # Harapan: /usr/local/bin/yt-dlp
   which ffmpeg   # Harapan: /usr/bin/ffmpeg
   which node     # Harapan: /usr/bin/node
   ```
2. **Sesuaikan Path di PHP**:
   Verifikasi pencarian path binary pada metode `resolveBinary()` di trait `modules/transcoder/FfmpegUtils.php` (dipakai bersama `Uploader` & service transcoder).

   > ⚠️ Konstanta path `HDD_BASE`/`HDD_VIDEO_DIR`/`HDD_THUMB_DIR` telah **dipindahkan** ke `auth/settings.php` sebagai `MEEL_HDD_*`. Konstanta transcoder (`FFMPEG_THREADS`, `DOWNLOAD_TIMEOUT`, dll.) kini di `modules/core/TranscoderBase.php`.
3. **Uji URL Langsung di Terminal**:
   Jalankan perintah berikut untuk melihat pesan error asli dari `yt-dlp`:
   ```bash
   yt-dlp --no-warnings --print-json "https://www.youtube.com/watch?v=VIDEO_ID"
   ```

---

### Masalah 3: Masalah Cookies (Sign-in / Bot Detection)

Platform video seperti YouTube sering kali memblokir akses otomatis dari server (Bot/Cloud Provider) dengan tantangan Captcha atau batasan login.

> [!IMPORTANT]
> Sistem membutuhkan kuki browser terkini yang disimpan dalam berkas `cookies.txt` di folder `temp/` proyek MEeL agar bisa mengunduh dengan sukses (file lama di root otomatis dimigrasikan).

#### Cara Memperbarui Cookies:

1. Buka browser Anda dan pasang ekstensi **Get cookies.txt LOCALLY** (Chrome/Firefox).
2. Buka dan masuk (Sign-in) ke akun YouTube Anda.
3. Klik ikon ekstensi dan pilih **Export / Download** cookies untuk domain youtube.com.
4. Simpan berkas hasil download tersebut dengan nama `cookies.txt` lalu pindahkan ke folder `temp/` proyek MEeL (chmod `0600` diterapkan otomatis oleh aplikasi):
   ```bash
   cp /path/ke/download/cookies.txt /opt/lampp/htdocs/MEeL/temp/cookies.txt
   sudo chown www-data:www-data /opt/lampp/htdocs/MEeL/temp/cookies.txt
   ```

---

### Masalah 4: Kegagalan Transcoding Pasca-Download

Terkadang file berhasil diunduh secara penuh, namun gagal saat dikonversi ke HLS (untuk video) atau Opus (untuk musik).

#### Cara Mengatasi:

1. **Periksa Sisa Ruang Penyimpanan**:
   Transcoding membutuhkan sisa ruang minimal 2x dari ukuran video asli.
   ```bash
   df -h
   ```
2. **Periksa Izin Tulis Folder Staging (temp)**:
   Pastikan folder `temp/` dapat ditulis oleh web server:
   ```bash
   sudo chmod -R 777 /opt/lampp/htdocs/MEeL/temp/
   ```
3. **Kurangi Beban CPU (CPU Throttling)**:
   Jika server Anda mengalami crash atau restart akibat suhu tinggi saat transcoding, kurangi jumlah thread CPU yang dialokasikan untuk FFmpeg di berkas `modules/core/TranscoderBase.php` (diwarisi semua service transcoder):
   ```php
   // Ganti nilai dari 8 menjadi 4 atau 2 sesuai core CPU Anda
   protected const FFMPEG_THREADS = 4;
   ```

   Cek jumlah core CPU: `nproc`