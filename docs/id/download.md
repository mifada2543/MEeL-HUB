# MEeL Panduan Troubleshooting Download/Upload

> **Audiens:** siapa pun yang harus mendiagnosis unduhan yang tidak jalan — pengembang, operator, dan AI agent.
> **Proyek:** MEeL — media hub PHP (XAMPP, yt-dlp, FFmpeg).
> **Keluhan khas:** "`upload_advanced.php` tidak mau download video".

---

## 1. 🧠 Pahami Alur Download

```
upload_advanced.php (form POST)
  → Transcoder::processDownload($url, $type)
    → fetchMetadata()          [yt-dlp --skip-download --print-json]
    → emit('download_start')   [BrowserProgressObserver → inject overlay partials/ui.php]
    → proc_open("exec setsid timeout 900 sh -c '<export …; yt-dlp …>'")
                               [PID/PGID dilacak; pembungkus sh -c itu WAJIB — exec
                                mengganti shell seketika, jadi tanpa itu awalan
                                export dan perintah yt-dlp tidak akan pernah jalan]
    → finalizeVideo()          [transcode HLS + sprite + pindahkan ke HDD — transaksional, auto-rollback]
      → emit('done')           [BrowserProgressObserver → meelDone() JS]
```

**Class kunci:** `modules/core/Transcoder.php` (facade → `modules/transcoder/`: `DownloadService`, `EncodeService`, `TranscodeService` — logika bisnis murni), `modules/core/BrowserProgressObserver.php` (lapisan presentasi), `modules/core/Uploader.php`.
**Dependensi kunci:** yt-dlp, FFmpeg, FFprobe, node, `temp/cookies.txt`.

---

## 2. 🔴 Bug Paling Sering: `base_path` yang Salah

### Gejala

- Overlay download tidak pernah muncul
- Tidak ada progres, halaman seolah tidak melakukan apa-apa
- Atau error di console: `meelError is not defined`

### Akar masalah

Berkas di `modules/core/` memakai `dirname(__DIR__)`, yang resolve ke `.../modules/` — bukan root proyek.

### Perbaikan

Pakai `dirname(__DIR__, 2)` untuk berkas di dalam `modules/core/`.

```php
// SALAH (modules/transcoder/DownloadService.php)
$this->base_path = dirname(__DIR__);    // → .../MEeL/modules/

// BENAR
$this->base_path = dirname(__DIR__, 2); // → .../MEeL/
```

### Apa yang terdampak saat `base_path` salah

| Path yang dipakai | Lokasi benar | Lokasi salah |
|---|---|---|
| `cookies.txt` | `.../MEeL/temp/cookies.txt` | `.../MEeL/modules/cookies.txt` |
| `partials/ui.php` | `.../MEeL/partials/ui.php` | `.../MEeL/modules/partials/ui.php` |
| `music/upload/file/` | `.../MEeL/music/upload/file/` | `.../MEeL/modules/music/upload/file/` |

### 🔍 Cara mengecek

```bash
php -r "echo dirname(__DIR__);" 2>/dev/null   # Uji dari modules/core/
```

Berkas di `modules/core/` butuh `dirname(__DIR__, 2)`. Berkas di `auth/`, `drive/`, `partials/`, dan `tests/` cukup `dirname(__DIR__)`.

---

## 3. 🔧 Langkah Diagnosis Berurutan

### Langkah 1 — Dasar lingkungan

```bash
which yt-dlp          # Harus terpasang
which ffmpeg          # Harus terpasang
which ffprobe         # Harus terpasang
which node            # Harus terpasang (runtime JS yt-dlp)
ls -la temp/cookies.txt   # Harus ada di temp/ (salinan di root dipindahkan otomatis)
php -v                # Harus 7.0+ (untuk dirname($path, 2))
```

### Langkah 2 — Fungsi PHP

```bash
php -r "echo function_exists('proc_open') ? 'YES' : 'NO';"
php -r "echo function_exists('posix_kill') ? 'YES' : 'NO';"   # ekstensi posix
php -r "echo ini_get('disable_functions') ?: 'none';"
```

`proc_open` dan `proc_close` **tidak boleh** dinonaktifkan (dipanggil dengan argumen array dan env var, tanpa shell). `posix_kill()` dipakai untuk terminasi yang presisi (SIGTERM → jeda → SIGKILL); tanpa ekstensi `posix` dipakai fallback `kill -- -PGID` lewat `shell_exec`. `popen` sudah tidak lagi dipakai transcoder.

### Langkah 3 — Path penyimpanan

```bash
ls -la /media/[user]/MEeL/media/            # mount HDD (ganti [user] dengan username Anda)
ls -la /dev/shm/meel/temp/                  # staging RAM disk
df -h /dev/shm                              # Butuh > 512 MB kosong
```

### Langkah 4 — Path berkas yang dipakai PHP

Untuk setiap `dirname(__DIR__)` di codebase, periksa hasil resolusinya:

```bash
grep -rn "dirname(__DIR__)" modules/core/
```

Bandingkan kedalaman berkas dari root proyek. Berkas di `modules/core/X.php` me-resolve `dirname(__DIR__)` satu level ke atas ke `modules/` — salah kalau yang diharapkan adalah root proyek.

### Langkah 5 — Uji di browser

```json
{
  "agent_type": "browser-use",
  "prompt": "1. Login at /auth/login.php with credentials
   2. Navigate to /upload_advanced.php
   3. Submit a short YouTube URL
   4. Check if the overlay appears and progresses through the phases",
  "params": { "url": "http://localhost/auth/login.php" }
}
```

---

## 4. ⚠️ Masalah Umum Lainnya

### Overlay tidak muncul / `meelPhase is not defined`

Overlay sudah tidak lagi di-include oleh transcoder. `Transcoder` hanya melaporkan event; `BrowserProgressObserver` menyuntik `partials/ui.php` di tengah stream saat event **`download_start`** (atau `transcode_start`) pertama tiba — dikunci flag `$overlayInjected` sehingga mustahil ter-include dua kali.

Kalau overlay tidak pernah muncul:

- Pastikan halaman memasang observer: `new Transcoder($conn, $uid, new BrowserProgressObserver())` (lihat `upload_advanced.php` / `transcode.php`). Tanpa observer, `emit()` adalah no-op.
- Pastikan `download_start` benar-benar dipancarkan (fetchMetadata berhasil).
- Template utama (`upload_advanced.php`) tetap meng-include `partials/ui.php` untuk halaman non-POST / busy / rate_limit — itu normal, bukan include ganda.

`BrowserProgressObserver::injectOverlay()` harus meng-include `partials/ui.php` lewat **`dirname(__DIR__, 2) . '/partials/ui.php'`**. Observer ini berada di `modules/core/` (dua level di bawah root proyek); memakai `__DIR__ . '/../partials/...'` resolve ke `modules/partials/` yang tidak ada (`file_exists = false` → include dilewati diam-diam → respons streamed hanya berisi padding + `<script>meelPhase(...)` tanpa markup `#meel-overlay`, jadi tidak ada yang tampil). Gejalanya: respons POST diawali deretan spasi, bukan tag CSS `<link`.

### Error handler berjalan sebelum overlay termuat

Error mengalir sebagai event **`error`** → observer memanggil `meelError()`. Kalau overlay belum disuntik (event `download_start` belum menyala), fungsi JS `meelError` belum ada. Itulah sebabnya pemanggil (`upload_advanced.php`) tetap menyimpan try-catch-nya sendiri dengan fallback HTML/JS biasa, dan error handler global (`set_error_handler` + `register_shutdown_function`) memanggil `meelError()` secara langsung.

Fallback di sisi pemanggil:

```php
echo "<script>
  if (typeof meelError === 'function') {
    meelError(" . json_encode($msg) . ");
  } else {
    document.write('<div style=\"...\">' + " . json_encode($msg) . " + '</div>');
  }
</script>";
```

### Format string yt-dlp terlalu ketat

Format YouTube `bestvideo[height<=1080][vcodec^=avc1]` mensyaratkan H.264. Kalau yang tersedia hanya VP9/AV1, unduhan gagal. Longgarkan menjadi `bestvideo[height<=1080]+bestaudio/best[height<=1080]`, atau pakai `--format-sort` untuk fallback.

---

## 5. 🧪 Prosedur Pengujian

Setelah mengubah sesuatu:

1. **Pemeriksaan sintaks**

   ```bash
   php -l modules/core/Transcoder.php
   php -l modules/core/TranscoderBase.php
   php -l modules/transcoder/EncodeService.php
   php -l modules/transcoder/DownloadService.php
   php -l modules/transcoder/TranscodeService.php
   php -l modules/core/Uploader.php
   ```

2. **Uji di browser** dengan URL YouTube asli

3. **Periksa** (fase digerakkan `BrowserProgressObserver` dari event engine):
   - Overlay langsung muncul → `meelPhase('download')` (event `download_start`)
   - Progres diperbarui → `meelDlPct(...)` (event `download_progress`)
   - Transcode dimulai → `meelPhase('transcode')` (event `phase`)
   - Selesai → `meelPhase('done')` (event `done` → `meelDone(...)`)
   - Error → `meelError(...)` (event `error`)
   - Tidak ada error di console

   Debug cepat: kalau `meelPhase`/`meelDlPct` tidak pernah dipanggil, periksa apakah observer terpasang (lihat "Overlay tidak muncul") dan apakah event-nya benar-benar dipancarkan.

4. **Periksa log error** kalau unduhan gagal:

   ```bash
   cat /tmp/ytdlp_error.log
   ```

---

## 6. 📁 Referensi Berkas

| Berkas | Fungsi |
|---|---|
| `upload_advanced.php` | Form advanced upload + handler POST |
| `modules/core/Transcoder.php` + `modules/transcoder/` | Facade; download/HLS/finalize di `DownloadService`, encode musik di `EncodeService`, transcode di `TranscodeService` |
| `modules/core/Uploader.php` | Proses unggah file langsung |
| `modules/core/helpers/` | `resolve_binary()`, `require_disk_space()` (helpers.php adalah shim) |
| `modules/core/System.php` | Server sibuk, rate limit, manajemen antrean |
| `modules/core/japanese.php` | Sanitasi nama berkas `getRomajiName()` |
| `modules/transcoder/FfmpegUtils.php` | `probeDuration()`, `generateSpriteAndVTT()`, `moveFile()` |
| `partials/ui.php` | Overlay MEeL Engine (HTML/CSS/JS) |
| `controllers/api/post_encode.php` | Post-encode musik (Opus/OGG) |
| `auth/config.php` | Koneksi DB, konstanta path, header keamanan |

---

<div align="center">
  <sub><a href="index.md">← Kembali ke index dokumentasi</a></sub>
</div>
