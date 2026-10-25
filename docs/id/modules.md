# 🏗️ Modul & Arsitektur

Dokumentasi detail tentang arsitektur modul, class diagram, dan business logic layer MEeL-HUB.

---

## Daftar Isi

- [Arsitektur Aplikasi](#arsitektur-aplikasi)
- [Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)](#core-modules-modulescore-modulesmedia-modulesexceptions-modulestranscoder)
- [Media Pipeline](#media-pipeline)
- [Autentikasi Flow](#autentikasi-flow)
- [26. Modularisasi JS/CSS](#26-modularisasi-jscss)
- [27. Adaptive Aspect Ratio Player](#27-adaptive-aspect-ratio-player)
- [28. Chat API](#28-chat-api)
- [Arsitektur ProgressObserver](#arsitektur-progressobserver)
- [Konvensi Keamanan Filesystem (tanpa @)](#konvensi-keamanan-filesystem-tanpa-)

---

## Arsitektur Aplikasi

```
┌─────────────────────────────────────────────────────────────┐
│                     Browser (User)                          │
├─────────────────────────────────────────────────────────────┤
│              TailwindCSS · HTMX · Plyr.js                   │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP / AJAX
┌──────────────────────▼──────────────────────────────────────┐
│              Apache Web Server (mod_rewrite)                │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌────────────────┐  │
│  │ Video   │ │ Music    │ │ Books    │ │ Cloud Drive    │  │
│  │ Module  │ │ Module   │ │ Module   │ │ Module         │  │
│  └────┬────┘ └────┬─────┘ └────┬─────┘ └───────┬────────┘  │
│       │           │            │               │           │
│  ┌────▼───────────▼────────────▼───────────────▼────────┐  │
│  │              Core Modules (modules/)                  │  │
│  │  MediaLibrary · MediaViewer · MediaInteraction        │  │
│  │  Uploader · Transcoder · System · activity_logger     │  │
│  └──────────────────────┬────────────────────────────────┘  │
│                         │                                   │
│  ┌──────────────────────▼────────────────────────────────┐  │
│  │              Database (MySQL/MariaDB)                  │  │
│  └───────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Modules (`modules/core/`, `modules/media/`, `modules/exceptions/`, `modules/transcoder/`)

### 📁 Struktur Direktori Modul

```
modules/
├── core/                   # Core business logic
│   ├── System.php          # Queue management, storage monitoring
│   ├── Uploader.php        # Upload file lokal (video + music)
│   ├── Transcoder.php      # Facade download yt-dlp & transcoding → delegasi ke modules/transcoder/
│   ├── TranscoderBase.php  # Base class: konstanta bersama, manajemen proses/PID, resolusi path
│   ├── helpers.php         # Shim backward-compat → require helpers/main.php + auth/loader.php
│   ├── helpers/            # Utilitas per domain: main.php, storage.php, audio.php, url.php, metadata.php, text_upload.php, subtitle.php, upload.php, settings.php
│   ├── Router.php          # MeelRouter — tabel rute front controller (routeFor/url/dispatch)
│   ├── bootstrap.php       # Environment detection & error reporting
│   ├── base_url.php        # Perhitungan base URL terpusat (meel_base_url_path)
│   ├── activity_logger.php # Activity logging, IP banning, session kick
│   ├── GarbageCollector.php# Auto-cleanup temp files & guests
│   ├── ProgressObserver.php # Kontrak event progress (interface + adapter callable)
│   ├── BrowserProgressObserver.php # Presenter browser — event progress → overlay/JS
│   ├── CommentRenderer.php # Render komentar nested
│   ├── japanese.php        # Pemrosesan teks Jepang (MeCab)
│   └── SwPrecache.php      # Generator precache PWA (service worker)
├── auth/                   # Infrastruktur keamanan terpusat (dimuat via loader.php)
│   ├── RateLimiter.php     # File-based API rate limiter
│   ├── SsrfGuard.php       # Validasi URL SSRF-safe
│   ├── ValidatingProxy.php # Forward proxy SSRF-defense
│   ├── ValidatingProxyProtocol.php # Aturan protokol proxy (parsing, penerusan, relay)
│   └── helpers/            # authz.php, csrf.php, session.php, stream_auth.php, mfa.php, user.php
├── media/                  # Modul query media
│   ├── MediaLibrary.php    # Query DB, pagination, BookRepository, BookUploader
│   ├── MediaViewer.php     # View tracking, komentar, rekomendasi
│   ├── MediaInteraction.php# Like/dislike, hapus komentar
│   ├── SearchEngine.php    # FULLTEXT search dengan sanitizer + filtering
│   ├── ArchiveGuard.php    # Ekstraksi aman ZIP/CBZ — limit anti zip-bomb
│   ├── PlaylistRepository.php # Query playlist & route slug playlist
│   ├── MediaAdminRepository.php # Query metadata media untuk panel admin (edit video/music)
│   ├── ProfileRepository.php # Query data profil (count video, music)
│   ├── AdminFilteredRepository.php # Basis bersama untuk dua repository admin di bawah
│   ├── AdminActivityRepository.php # Query & filter activity log untuk admin viewer
│   └── AdminUploadQueueRepository.php # Query upload queue, stats & filter untuk admin viewer
├── exceptions/             # Class exception
│   ├── ProcessException.php
│   ├── DownloadException.php
│   └── TranscodeException.php
├── transcoder/             # Service hasil pemecahan Transcoder (extend TranscoderBase)
│   ├── EncodeService.php   # encodeMusic() — download → encode Opus → thumbnail → INSERT music
│   ├── DownloadService.php # processDownload() — unduh URL (yt-dlp) + finalisasi video HLS
│   ├── TranscodeService.php# transcodeVideo() + ownsTranscodeFile() — transcode audio/video
│   └── FfmpegUtils.php     # Trait: probeDuration(), generateSpriteAndVTT(), helper filesystem
└── autoload.php            # Class-map autoloader

# ── Di ROOT PROJECT (bukan di modules/) ────────────────────────────────
sw.js.php                   # Generator service worker — disajikan sebagai /sw.js via rewrite .htaccess
```

---

### 1. `modules/media/MediaLibrary.php`

**Class:** `MediaLibrary`, `BookRepository`, `BookUploader`

Fungsi utama query database untuk katalog media — dengan **pagination metadata dan cache getCounts()**:

```php
class MediaLibrary {
    protected function paginateResult($result, int $total, int $page, int $perPage): array;
    public function getCounts(): array;
    public static function clearCountsCache(): void;
    public function getVideosWithMeta(int $page = 1, int $perPage = 15): array;
    public function getVideos(int $limit, int $offset);
    public function countVideos(): int;
    private function searchMedia(array $cfg): ?\mysqli_result;
    private function countSearchMedia(array $cfg): int;
    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0);
    public function searchBooks(string $q, string $type = 'all', int $offset = 0, int $limit = 24);
    public function getUserPlaylists(int $user_id);
}
```

`searchVideo()` dan `searchMusic()` adalah thin wrapper yang memanggil `searchMedia()` / `countSearchMedia()` dengan config spesifik modul (tabel, kolom pencarian, session key).

**Resilience search:** `searchVideo()`, `searchMusic()`, dan `searchBooks()`
membungkus query FULLTEXT-nya dengan `try/catch (\mysqli_sql_exception)` —
query boolean-mode yang malformed jatuh ke hasil kosong, bukan error 500.

### 2. `modules/media/MediaViewer.php`

**Class:** `MediaViewer` — view tracking, komentar, rekomendasi.

### 3. `modules/media/MediaInteraction.php`

**Class:** `MediaInteraction` — like/dislike dan hapus komentar.

#### Tombol like/dislike (`partials/like_dislike.php`)

Kontainer ini dirender dari **satu** partial, yang di-include oleh
`music/watch.php`, `video/watch.php`, dan `controllers/api/like.php`.

Itu penting karena kontainer diganti seluruhnya: tombolnya memakai
`hx-swap="outerHTML"`, jadi apa pun yang dikirim server menjadi kontainer
yang baru. Markup-nya dulunya ditulis tiga kali, dan ketiganya sudah
melenceng — salinan di API masih memakai ikon `plus` dan teks "Playlist"
padahal halamannya sudah `list-plus` dan "Simpan"; warna aksennya juga
beda (`red-500` vs `red-600`, orange vs putih untuk dislike aktif). Yang
basi justru yang menang, karena yang menjawab swap adalah API. Jadi begitu
like diklik, bentuk tombolnya berubah di depan mata: kembali jadi plus polos
bertuliskan "Playlist".

Sekarang kedua sisi merender partial yang sama, jadi kontainer yang
dimasukkan setelah swap identik byte-per-byte dengan yang sudah tampil.

Partial menerima perbedaan-perbedaan itu sebagai data, bukan sebagai salinan
kedua:

| Variabel | Arti |
|---|---|
| `$like_dislike_media_id` | id media |
| `$like_dislike_media_type` | `music` atau `video` — menentukan warna aksen dan apakah tombol simpan muncul |
| `$like_dislike_state` | `''`, `like`, atau `dislike` |
| `$like_dislike_likes` / `$like_dislike_dislikes` | pencacah |
| `$like_dislike_csrf` | token CSRF untuk payload `hx-vals` |
| `$like_dislike_rate_limit` | detik tersisa saat rate limiter menolak; `0` berarti normal |

`LikeDislikePartialTest` menjaga agar salinannya tidak muncul lagi: testnya
gagal kalau markup kontainer ada di lebih dari satu berkas.

#### Aturan Balas Komentar

| Aturan | Nilai |
|---|---|
| Validasi induk | `resolveParentId()` memeriksa induknya ada **dan** punya kolom media yang sama. Kalau tidak, `parent_id` diset `NULL` sehingga komentarnya menjadi komentar root — deterministik dan tetap terlihat. |
| Kedalaman rantai | `MediaViewer::MAX_COMMENT_DEPTH` = 5. Melewati batas dipromosikan ke root, jadi satu orang tidak bisa membuat rantai tak terbatas hanya dengan memposting balasan ke balasan. |
| Urutan & paginasi | `getComments()` memakai urutan `created_at DESC, id DESC` dan menerima parameter `$offset`. Dengan urutan lama (`created_at ASC` + `LIMIT 200`), komentar baru bisa terdorong keluar jendela dan tidak pernah muncul lagi. Komentar yang sudah terlanjur ditulis tidak dihapus. |

### 4. `modules/core/Uploader.php`

**Class:** `Uploader` (menggunakan `FfmpegUtils` trait) — upload file lokal dengan:
- Validasi magic bytes (MP4/WebM/MKV)
- Active upload limit (max 3 simultan)
- Pre-flight disk space check (`require_disk_space()`)
- RAM disk staging (`/dev/shm`) untuk HLS
- Atomic DB transaction dengan rollback + file cleanup

**Delegasi ke helper bersama (`helpers/upload.php`)** — untuk menghindari duplikasi,
Uploader, `EncodeService`, `DownloadService`, dan admin editor memakai fungsi global yang sama:
- `meel_reserve_unique_filename()` — alokasi nama atomik `fopen(..., 'x')` (anti race condition)
- `meel_allocate_unique_dir()` — alokasi direktori unik tanpa menimpa
- `meel_sanitize_clean_name()` / `meel_sanitize_upload_filename()` — sanitasi nama
- `meel_ffmpeg_thumbnail_webp()` — satu-satunya jalur konversi gambar → WebP (`libwebp`)
- `meel_ffmpeg_encode_opus()` — satu-satunya jalur encode audio → Ogg/Opus
- `meel_insert_music_row()` — satu-satunya jalur INSERT baris tabel `music`
- `meel_magic_extension_ok()` — validasi magic bytes + ekstensi per jenis media

`processVideo()` tidak lagi menerima argumen `$upload_dir`. Argumen itu tidak
pernah dibaca, dan satu-satunya pemanggil mengirim `__DIR__ . "/"` ke sana.

Kedua jalur upload kini orkestrator di atas helper privat, bukan satu metode
panjang. Semua helper memakai satu konvensi: **kembalikan `null` untuk lanjut,
atau kembalikan array hasil akhir untuk berhenti.**

| Metode | Tanggung jawab |
|---|---|
| `assertVideoUploadAllowed()` / `assertMusicUploadAllowed()` | rate limit, batas upload bersamaan, cek ruang disk |
| `inspectVideoCodecs()` | magic bytes, codec video, codec audio |
| `buildVideoCleanName()` / `buildVideoThumbnail()` | nama bersih romaji, thumbnail dari user atau frame ffmpeg, sprite kecil |
| `prepareVideoWorkFolder()` | folder unik di bawah lock, `/dev/shm` bila cukup ruang |
| `runVideoHlsTranscode()` | pemanggilan ffmpeg untuk HLS |
| `stageVideoSubtitle()` | subtitle `.srt`/`.vtt`, hanya bila transcode berhasil |
| `moveVideoFilesToHdd()` | loop rename ke storage, di bawah move lock |
| `insertVideoRecord()` | transaksi; pemanggil membersihkan berkas kalau gagal |
| `validateMusicUpload()` / `stageMusicFile()` | ekstensi, magic bytes, ukuran, durasi |
| `buildMusicThumbnail()` | thumbnail dari user atau satu frame dari audio |
| `maybeTranscodeToOgg()` | encode ulang ke Ogg kecuali admin melewatinya |

### 5. `modules/core/Transcoder.php` — facade + layanan terpecah

**Class:** `Transcoder` — **facade** yang mempertahankan kontrak publik lama
(konstruktor, `processDownload`, `encodeMusic`, `transcodeVideo`) sambil
mendelegasikan implementasi ke service-service di `modules/transcoder/`
(yang semuanya `extends TranscoderBase`):

```
Caller existing
    ↓
Transcoder (facade, modules/core/Transcoder.php)
    ↓
├── EncodeService    (modules/transcoder/EncodeService.php)    encodeMusic()
├── DownloadService  (modules/transcoder/DownloadService.php)  processDownload() + finalisasi video
└── TranscodeService (modules/transcoder/TranscodeService.php) transcodeVideo() + ownsTranscodeFile()
    ↓
TranscoderBase (modules/core/TranscoderBase.php) — konstanta bersama, proses/PID, resolusi path
```

**`TranscoderBase`** menampung tanggung jawab bersama: konstanta konfigurasi
(`FFMPEG_THREADS=8`, `HLS_SEGMENT_DURATION=10`, `DOWNLOAD_TIMEOUT=900`,
`TRANSCODE_AUDIO_TIMEOUT=600`, `PID_DIR`, `FFMPEG_LIB_PATH`, `ENV_PREFIX` —
semuanya `protected` agar terlihat oleh service anak), konstruktor dengan
`ProgressObserver`, manajemen proses (`terminateAllProcesses()`, static
`killByPidFile()`, `cleanupStalePidFiles()`), serta resolusi path aman
(`getTranscodeFilePath()`, `resolveMusicInputPath()` — path server-side,
bukan dari input client).

Kontrak publik facade:
```php
class Transcoder {
    public function __construct(\mysqli $db_connection, int $session_user_id,
                                callable|ProgressObserver|null $progressListener = null);
    public function setProgressListener(callable|ProgressObserver|null $listener): void;
    public function terminateAllProcesses(): void;   // Hook shutdown yang graceful
    public function processDownload(string $url, string $type): string;
    public function encodeMusic($temp_file, $title, $artist, $album, $duration, $description);
    public function transcodeVideo(int $video_id, string $format): array;
}
```

Fitur (diwarisi bersama dari `TranscoderBase` + `FfmpegUtils`):
- RAM disk priority (`/dev/shm/meel/`) dengan fallback otomatis
- Per-platform format resolution (YouTube H.264+AAC, NicoNico, TikTok)
- Real-time progress via event `ProgressObserver` — overlay browser
  (`partials/ui.php` + JS `meel*`) dirender oleh `BrowserProgressObserver`, bukan class ini
- **Finalisasi transaksional** — finalisasi video memindahkan file HLS ke USB HDD
  *dan* memasukkan record database di dalam satu transaksi MySQL. Kegagalan apa pun
  di-rollback dan otomatis menghapus folder HLS/thumbnail yang sudah tersalin ke
  HDD (tidak ada file yatim di storage)
- **Terminasi proses berbasis PID** — proses anak (yt-dlp, ffmpeg) di-spawn via
  `proc_open()` dan dilacak dengan PID/process-group; timeout dihentikan dengan
  `posix_kill()` (SIGTERM → grace period → SIGKILL) menggantikan pencocokan string
  `pkill -f`. Caller mendaftarkan `terminateAllProcesses()` sebagai shutdown function
- **Manajemen PID file** — setiap proses yang di-spawn menulis PID ke `/tmp/meel_pids/{type}_{id}.pid`, memungkinkan panel admin membunuh proses lintas-request via `killByPidFile()`
- **Path library FFmpeg** — `proc_open()` env mengatur `LD_LIBRARY_PATH` secara eksplisit untuk mencegah FFmpeg hang ketika env parent process berbeda dari child
- **Timeout transcode** — audio transcode memiliki batas 600 detik (`TRANSCODE_AUDIO_TIMEOUT`); HLS remux memiliki batas 120 detik; stream read menggunakan `stream_set_timeout(30)` untuk mendeteksi pipe stall
- **Validasi cache** — file transcode divalidasi berdasarkan `filesize > 10KB` DAN `duration ≥ 50%` dari durasi sumber untuk menolak file corrupt/stub
- **Cek exit code FFmpeg** — `proc_close()` exit code diverifikasi; exit code non-zero di-log dengan 15 baris terakhir stderr dan status queue diatur ke `failed`. Baris queue juga ditandai `failed` kalau `proc_open()` gagal menjalankan ffmpeg sama sekali, atau ffmpeg keluar bersih tanpa menghasilkan file — sebelumnya kedua kasus jatuh ke bawah dan tercatat `completed`
- **Sanitasi nama file** — regex whitelist (`[^a-zA-Z0-9_\x{3000}-\x{9fff}...]`) mempertahankan karakter CJK sambil mencegah path traversal
- Cached directory size via `dir_size()`
- Thumbnail sprite + VTT

#### Alur internal `transcodeVideo()`

`transcodeVideo()` dulunya satu metode 341 baris. Sekarang jadi orkestrator 71
baris di atas beberapa helper privat, sehingga tiap langkah bisa dibaca dan
diuji sendiri:

| Langkah | Metode | Tanggung jawab |
|---|---|---|
| 1 | `prepareOutputDirectory()` | buat direktori shm, tolak mulai bila sisa RAM < 256 MB |
| 2 | `purgeStaleArtifacts()` | buang output lebih tua dari 2 jam dan baris queue lebih tua dari 15 menit |
| 3 | `loadTranscodeSource()` | ambil baris video, playlist HLS, segmen `.ts` terurut, durasi, total ukuran |
| 4 | `enforceSourceQuota()` | batas non-admin 200 MB / 10 menit |
| 5 | `claimTranscodeSlot()` | di bawah lock eksklusif: pakai cache yang valid, buang yang rusak, klaim marker, lalu `System::claimQueueSlot()` |
| 6 | `writeConcatList()` | susun manifest concat ffmpeg dengan kutip tunggal di-escape |
| 7 | `resolveAudioFormat()` | codec, bitrate, kelayakan thumbnail, metadata ID3 |
| 8 | `buildFfmpegArgv()` | rakit array argv yang diteruskan ke `proc_open()` |
| 9 | `runFfmpeg()` | jalankan proses, stream stderr, laporkan progres, tegakkan timeout |
| 10 | `reportProgress()` | ubah penanda `time=` dari ffmpeg menjadi persentase |

Semua helper privat memakai satu konvensi: **kembalikan `null` untuk lanjut, atau
kembalikan array hasil akhir untuk berhenti**. `claimTranscodeSlot()` punya
keadaan ketiga — `null` berarti "lanjutkan transcode", array berarti "cache hit
atau kegagalan, berhenti di sini". Lock di langkah 5 dilepas dengan
`try`/`finally`, bukan di tiga titik keluar yang terpisah.

Dua hal dibuang, bukan dipindahkan:

- **Builder perintah kedua yang tidak terpakai.** Kode lama merakit `$cmd`
  (string shell) sekaligus `$tc_cmd` (array argv). Hanya `$tc_cmd` yang pernah
  masuk ke `proc_open()`; `$cmd` dibangun lalu dibuang. Keduanya sudah lama tidak
  sinkron — `$cmd` meminta `-c:v copy` sedangkan builder yang benar memakai
  `-c:v mjpeg` — sehingga salinan yang tak terpakai itu jebakan bagi siapa pun
  yang tanpa sengaja memperbaiki yang salah.
- **`escapeshellarg()` pada string mati tersebut.** Setelah dihapus, berkas ini
  tidak lagi menyusun string shell sama sekali; `TranscodeService` hanya
  memanggil ffmpeg lewat array argv.

`tests/unit/TranscodeArgvTest.php` mengikat invariant argv. Berkas ini ada karena
pemecahan sempat memperkenalkan regresi: `use_thumb` hanya diinisialisasi
di cabang `ogg` pada switch format, sehingga `mp3`/`m4a`/`flac` membaca variabel
yang belum diinisialisasi dan diam-diam menghasilkan audio tanpa cover art. Test-nya
memassert perilaku yang teramati (stream mana yang dipetakan, flag mana yang
muncul), bukan menyalin ulang builder-nya.

#### Finalisasi video di `DownloadService`

`finalizeVideo()` dipecah menjadi beberapa langkah yang masing-masing punya
satu tanggung jawab. Dua di antaranya perlu dicatat karena bentuknya menentukan
betapa amannya langkah itu:

**`runParallelFfmpeg()`** menjalankan beberapa proses ffmpeg sekaligus lalu
mengawasi sampai semuanya selesai. Ini bagian yang paling rawan salah:
kebocoran pipe, anak proses yang tidak pernah di-reap (zombie plus PID file
yang tak pernah dihapus), dan jalur timeout yang meninggalkan ffmpeg berjalan
di belakang.

Dipisah dari `finalizeVideo()` pada T19, saat metodenya 268 baris dengan
sembilan fase. Yang menentukan bentuknya: **ini tetap metode, bukan kelas
baru** (`FfmpegProcess`). Semua hook yang dibutuhkan ber-`protected` di
`TranscoderBase`, dan daftar anak proses (`$childProcesses`) adalah
state per-instance yang dipindai `terminateAllProcesses()` saat shutdown.
Kelas terpisah tidak bisa menjangkau itu tanpa melebarkannya jadi `public`
atau memindahkan daftar anak ke collaborator bersama — dan dua-duanya jatuh
persis di mekanisme yang tugasnya **mencegah** proses ffmpeg yatim ketika
request mati di tengah transcode. Seam-nya dibiarkan: begitu ada harness uji
yang bisa mengujinya ujung ke ujung, kelasnya bisa diekstrak tanpa menurunkan
logikanya dari nol.

**Pemindahan ke HDD + pencatatan database** dibungkus dalam satu transaksi,
dan sifat pentingnya: kalau ada apa pun yang gagal di tengah, sistem TIDAK
boleh tertinggal setengah jadi — file sudah pindah tapi baris database belum
ada, atau sebaliknya. Karena itu `moveFile()` melempar, bukan mengembalikan
`false`, dan `catch` melakukan rollback database **ditambah** pembatalan efek
filesystem. Urutan pembatalannya penting dan tidak boleh dibalik:

1. rollback transaksi (baris database tidak jadi ada)
2. `rollbackFinalizeVideo()` (hapus folder target + thumbnail)
3. hapus folder kerja

`finalizeVideo()` sebagai pemanggil tidak perlu tahu detailnya — ia hanya
perlu tahu berhasil atau tidak; pesan errornya sudah di-emit di dalam.

### 6. `modules/core/System.php`

**Class:** `System` — queue management, storage monitoring, rate limiting.

Metode kunci — `forceStopQueue(int $id, string $task_type): bool`:
- **Kill PID inline** — membaca PID file dari `/tmp/meel_pids/` dan mengirim `SIGTERM` → `SIGKILL` secara langsung (tanpa dependency `Transcoder.php`, menghindari masalah output-before-headers di panel admin)
- Menghapus record queue dari `upload_queue` atau `transcode_queue`
- Mengembalikan `true` jika berhasil, `false` jika gagal

#### Verifikasi identitas PID (`killByPidFile`)

PID file bisa **basi**: kalau prosesnya sudah mati, OS akan mendaur ulang nomor
PID-nya untuk proses lain. Kill tanpa verifikasi berarti panel admin bisa
mematikan proses yang **tidak ada hubungannya**. Karena itu cmdline PID dicek
terlebih dahulu (`ffmpeg`, `ffprobe`, `yt-dlp`, `youtube-dl`, `setsid`, `sh`).

Dua kondisi gagal dibedakan secara sengaja:

| Kondisi | Sikap | Alasan |
|---|---|---|
| `/proc` tersedia, cmdline tidak cocok | **fail-closed** (tolak kill) | PID kemungkinan besar sudah didaur ulang — inilah yang dilindungi |
| `/proc` tidak tersedia (platform tanpa procfs, atau `hidepid`) | **fail-open** (kill tetap dilakukan) + `error_log` | Kita tidak bisa membuktikan apa pun; menolak kill akan membuat ffmpeg yatim selamanya |

Kalau PID adalah *process group leader*, kill diarahkan ke `-PID` sehingga
seluruh anak ffmpeg ikut mati. `kill -- -PID` memakai `--` agar PID negatif
tidak ditafsirkan sebagai opsi.

#### Record antrean tidak dihapus bila proses tak terbunuh

`forceStopQueue()` hanya menghapus record queue **bila tidak ada proses yang
menyelamatkannya perlu dibunuh**. Kalau PID file ada tapi kill ditolak,
record **dipertahankan** — kalau tidak, prosesnya akan jadi yatim permanen
tanpa jejak di DB dan tidak bisa lagi dihentikan dari UI.

Dua kondisi ini dibedakan lewat `TranscoderBase::hadPidFile()`, yang **wajib
dipanggil sebelum `killByPidFile()`** karena fungsi itu menghapus PID file di
akhir (sukses maupun gagal). Cases:

| Kondisi | Hasil |
|---|---|
| Tidak ada PID file (job selesai / belum mulai) | Record dihapus — queue tidak boleh macet |
| PID file ada, kill ditolak | Record dipertahankan, `error_log`, `return false` |

### 7. `modules/core/activity_logger.php`

Activity logging & IP Banning:
- `get_real_ip()` — Anti-Cloudflare masking
- `validate_and_format_ip()` — Normalize IP
- `get_access_method()` — Direct/Proxy/Cloudflare
- `get_connection_protocol()` — IPv4 vs IPv6
- `log_activity(...)` — INSERT INTO activity_log

Fitur: Guest auto-registration (ON DUPLICATE KEY UPDATE), session kick detection, stream.php throttling, device/page detection, IPv4-mapped IPv6.

### 8. `modules/core/helpers/` (utilitas global)

`helpers.php` kini shim yang me-require `helpers/main.php` + `modules/auth/loader.php`.
Fungsi-fungsi dibungkus `function_exists()` guard dan tersebar di subfolder per domain:
- `resolve_binary(array): string` — Binary path (MEEL_*_PATH override)
- `base_url(string): string` — Dynamic base URL (fallback via `meel_base_url_path()`, lihat `base_url.php`)
- `detectProtocol(): string` — HTTPS + Cloudflare
- `time_ago($timestamp)` — Waktu relatif (ID)
- `format_bytes($bytes)` — Ukuran file readable
- `music_thumbnail_url($thumbnail)` — Resolve thumbnail
- `video_thumbnail_sm_name(?string): string` — Turunkan nama varian 256px dari nama thumbnail
- `video_thumbnail_sm(?string): string` — Sama seperti di atas, tapi hanya mengembalikan nama bila file benar-benar ada
- `meel_is_default_thumbnail(string): bool` — True bila nama itu `default_thumb.jpg`/`default_thumb.webp` (case-insensitive, aman terhadap path)
- `get_user_usage($username)` — Usage drive
- `get_user_role(mysqli, int): string` — 3-level cache
- `invalidate_user_role_cache()`
- `get_csrf_token()`, `verify_csrf_token()`
- `check_disk_space(int, string): array`, `require_disk_space(...)`
- `dir_size(string, int): float` — Cached directory size
- `get_audio_mime_type(string): string`
- `get_audio_format_label(string): string`
- `get_audio_format_description(string): string`
- `log_drive_operation(...)`
- `meel_validate_text_upload(string $path, int $max_bytes = 2097152): bool` — Validasi berkas teks hasil unggah

#### Validasi berkas teks hasil unggah

Lirik (`.lrc`, `.txt`) dan subtitle (`.vtt`, `.srt`) menerima jenis berkas yang
sama: teks murni, maksimal 2 MB, tanpa byte NUL, dan tanpa tag PHP. Aturan itu
dulu ditulis dua kali — satu di `helpers/lyrics.php`, satu di
`helpers/subtitle.php` — dengan badan fungsi yang identik byte per byte. Dua
salinan aturan keamanan berarti salah satunya bisa melenceng sementara yang
lain sudah diperketat.

Kini aturannya ada di `meel_validate_text_upload()` di
`modules/core/helpers/text_upload.php`. `validate_lyrics_file()` dan
`validate_subtitle_file()` menjadi pembungkus tipis, jadi semua pemanggil
lama tetap bekerja tanpa perubahan. `helpers/main.php` memuat berkas baru itu
sebelum keduanya. `TextUploadValidationTest` mengunci bahwa pembungkus dan
aturan bersama selalu selaras.

#### Titik muat helper penyimpanan

`modules/core/helpers/storage.php` sengaja tetap ada meski isinya nyaris
kosong: `main.php` memuatnya dengan satu baris, dan hampir 50 berkas di luar
tests/ memanggil fungsi-fungsinya. Memecah titik muat itu jauh lebih berisiko
daripada manfaatnya.

Isinya sudah dipecah per domain, mengikuti konvensi folder ini:

| Berkas | Isi | Fungsi |
|---|---|---:|
| `storage_paths.php` | base path, thumbnail, PID, guard mount | 14 |
| `storage_disk.php` | kapasitas disk, cache file, audit log | 8 |
| `storage_xsendfile.php` | deteksi & header X-Sendfile | 11 |
| `storage_stream.php` | validator cache + serving media | 9 |

Urutan `require` tidak boleh diacak, karena mengikuti arah ketergantungan:
`paths` → `disk` → `xsendfile` → `stream`. PHP menunda definisi fungsi sampai
berkas selesai dibaca, jadi urutan hanya penting untuk efek samping saat
muat — satu-satunya efek samping itu blok `MEEL_HDD_CHECKED` di
`storage_paths.php`. Semua definisi tetap dibungkus `function_exists()`, jadi
berkas aman dimuat berkali-kali.

#### Helper ekspor data (CSV, JSON, SpreadsheetML)

`admin/activity_log.php` dulunya punya dua blok ekspor dan total enam
definisi pemetaan baris yang identik: preamble XML SpreadsheetML, closure
`$xls_cell`, header antrean unggah, serta pemetaan enam kolom baris antrean.
Konsekuensinya bukan cuma berkas panjang — kalau satu kolom diubah, ada lima
tempat lain yang harus ikut berubah dan tidak ada yang memberi tahu kalau
lupa. Ekspor CSV bisa diam-diam punya kolom berbeda dari ekspor JSON.

Yang dikumpulkan di `modules/core/helpers/export.php` adalah bentuk, bukan isi
data: daftar kolom milik tiap dataset tetap didefinisikan di halaman yang
memakainya, karena itu domain data, bukan utilitas.

Dua perbedaan semantik yang harus dijaga:

- `meel_export_values()` memakai `??` untuk kolom yang punya `default` — kunci
  yang tidak ada **dan** kunci bernilai `null` sama-sama menghasilkan
  default. Kolom tanpa `default` meneruskan null apa adanya.
- `meel_export_map()` untuk kolom bertipe `int?` membaca nilai mentah, bukan
  hasil `??`. Kalau `default` ikut diterapkan lebih dulu, null berubah jadi
  `''` lalu jadi `0`: JSON yang tadinya `"user_id": null` tiba-tiba
  melaporkan `"user_id": 0`. Null berarti "tidak diketahui", 0 berarti
  "user 0".

#### Penamaan thumbnail video

Aturan ini berlaku di `Uploader.php` dan dipakai ulang di mana pun nama
varian perlu diturunkan:

| Jenis | Pola nama | Sumber |
|---|---|---|
| Thumbnail utama | `{folder_name}_thumb.webp` | `Uploader.php` |
| Varian 256px (sm) | `{folder_name}_thumb_sm.webp` | `Uploader.php` |
| Default (fallback) | `default_thumb.webp` / `default_thumb.jpg` | tidak ikut dihapus |

Tiga hal yang sering bikin salah paham:

1. **`folder_name`, bukan `clean_name`.** `folder_name` berasal dari
   `meel_allocate_unique_dir()` sehingga dijamin unik; `clean_name` (hasil
   `getRomajiName()`) bisa sama antar video. Kalau thumbnail ikut memakai
   `clean_name`, dua video berjudul sama akan saling menimpa file thumbnail.
2. **Varian `_sm` sengaja TIDAK disimpan di DB.** Hanya `video.thumbnail` yang
   disimpan; keberadaan varian `_sm` dicek via `is_file()`. Karena itu scan
   "Cek Sinkronisasi Database" di admin memakai pengecualian yang sama agar
   varian tidak pernah dianggap sampah.
3. **`default_thumb.*` tidak pernah dihapus.** Semua jalur hapus (ganti
   thumbnail di `admin/edit-video.php`, hapus media di `fun-manage.php`, dan
   rollback di `Uploader.php`) meng-cek `meel_is_default_thumbnail()` dulu.
   Menghapus default thumbnail akan mematikan seluruh fallback gambar.

### 9. `modules/core/CommentRenderer.php`

`render_comments()` — render komentar nested dengan 2 tema (video/music); `comment_preview()` — preview komentar terbaru untuk header kolom komentar.

### 10. `modules/core/GarbageCollector.php`

`GarbageCollector` (static methods) — auto-cleanup:
- Temp files di RAM disk (`/dev/shm/meel/*`) dan project `temp/`
- Guest accounts (>2 jam) dengan throttle (1x/jam)
- Room catur multiplayer terbengkalai via `cleanChessRooms()` (throttle 1x/jam):
  - Lobby basi: `black_joined = 0` dan dibuat >24 jam yang lalu
  - Game ditinggalkan di tengah: sudah dimulai, **tanpa event terminal** (`resign`/`draw_accept`/`disconnect`/`game_over`) dan tanpa aktivitas >7 hari (beserta riwayat `moves`)
  - **Game yang sudah selesai TIDAK pernah dihapus** — riwayatnya dipertahankan
- Expired rate limit cache via `RateLimiter::cleanup()`
- Antrean hapus tertunda (`temp/pending_delete.json`) via `cleanPendingFileDeletions()` (throttle 1x/5 menit, TTL entri 30 menit)
- Timeboxed execution (max 3 detik)
- `removeFile()`/`removeDirectory()` helper dengan guard `is_writable()` proaktif — subtree milik user lain dilewati dengan error log, bukan warning PHP

#### TTL cache media (`cleanCacheDirectory()`)

Setiap prefix cache punya TTL sendiri; file yang **masih** dalam TTL dipejamkan
(tidak dihapus):

| Prefix | TTL | Sumber |
|---|:---:|---|
| `media_stats_` | 30 detik | `media.php` |
| `admin_orphans` | 120 detik | `admin_data.php` |
| `dirsize_` | 300 detik | `storage.php` |
| `server_stats_info` | 300 detik | `System.php` |
| `xsendfile_roots` | 86400 detik | `storage.php` |
| `.tmp.<epoch>.<hash>` | 300 detik | temp yatim |

File dengan **prefix tak dikenal** dihapus bila lebih tua dari 1 hari (86400 detik).

> **Kenapa fallback ini pakai flag `$prefixMatched`, bukan `isset($ttlMap)`?**
> Versi lama menulis `if (!$expired && !isset($ttlMap))`. `$ttlMap` adalah array
> lokal yang **selalu** di-set sebelum loop, jadi `isset($ttlMap)` selalu `true`
> dan fallback tersebut menjadi dead code — file prefix tak dikenal tidak pernah
> dihapus dan `temp/cache/` tumbuh tanpa batas. Sekarang flag-nya mencatat
> "apakah ada prefix yang cocok" secara eksplisit, sehingga fallback benar-benar
> berjalan.

**Bagaimana TTL-nya sebenarnya diukur.** Keluarga cache tidak sepakat soal key
waktu: `media_stats_` dan `server_stats_info` menulis `ts`, `dirsize_` dan
`xsendfile_roots` menulis `time`, `admin_orphans` menulis `checked_at`. Karena
itu key-nya dicari berurutan, dan file yang tidak punya satu pun key itu jatuh
ke waktu modifikasi supaya tidak ada cache yang menggantung selamanya.

> **Shortcut yang diam-diam merusak seluruh tabel.** Pemeriksaan kedaluwarsa
> dulu membaca `meel_read_cache_file($path, 0, [], '')`, dan helper itu
> langsung mengembalikan `null` begitu TTL-nya `0` — artinya "belum kedaluwarsa"
> dibaca sebagai "sudah kedaluwarsa". Akibatnya **semua** file ber-prefix yang
> dikenal dihapus di setiap sweep, termasuk yang baru saja ditulis. Cache tidak
> pernah berlaku: statistik, ukuran direktori, dan daftar root xsendfile
> dihitung ulang di setiap request. Panggilan itu dihapus, dan pencarian key
> yang ditulis sebagai fallback-nya — dan tidak pernah terjangkau — kini jadi
> satu-satunya jalur.

**Di mana cache berada.** Kolektor mencari direktori lewat
`meel_media_cache_dir()`, helper yang sama dengan yang dipakai aplikasi.
Dulu ia punya salinan sendiri yang hanya melihat konstanta
`MEEL_MEDIA_CACHE_DIR` dan mengabaikan environment variable dengan nama yang
sama — jadi deployment yang mengatur cache lewat environment menulis ke satu
direktori sementara sweeper membersihkan direktori lain.

#### Guard keamanan orphan cleaner (`controllers/admin/admin_actions.php`)

Daftar orphan berasal dari cache 10 menit, jadi **tidak dipercaya buta**.
Sebelum menghapus, isi daftar diverifikasi ulang ke DB dan dua hal dikecualikan:
- varian thumbnail `*_sm.*` (tidak pernah masuk DB, hanya dicek via `is_file()`)
- berkas yang ternyata masih terdaftar di DB

#### Auto-increment `users` sengaja TIDAK di-reset

Historically setelah guest dihapus, `ALTER TABLE users AUTO_INCREMENT = MAX(id)+1`
dipakai untuk "membersihkan" nomor. Ini **dihapus** karena `auth/auth.php`
memvalidasi `$_SESSION['user_id']` langsung ke tabel `users`: id yang didaur ulang
berarti sesi milik user A bisa diam-diam menjadi user B. Sekarang nomor grows
monoton, dan `GarbageCollector::resetGuestsAutoIncrement()` (namamethodinggal
historis) hanya memanggil `cleanGuests()`.

### 11. `modules/auth/RateLimiter.php`

File-based rate limiter dengan `flock()` safety. Role-based (admin = unlimited, member = 2x).

| Endpoint | Max/Window | Keterangan |
|---|:---:|---|
| `like` | 30/menit | HTMX 429 HTML response |
| `comment` | 10/menit | Flash message redirect |
| `upload` | 3/jam | — |
| `transcode` | 5/jam | — |
| `auto_metadata` | 5/jam | CPU-intensive (ffprobe + ffmpeg) |
| `register` | 5/jam | Bucket **per-IP**, hanya sukses yang dihitung, loopback bebas |
| `api` | 60/menit | Generic fallback |

**Fail-closed behavior:** Ketika direktori penyimpanan (`temp/ratelimit/`) tidak writable atau `flock()` gagal, rate limiter **menolak semua request** (fail-closed) daripada diam-diam membiarkannya lewat. Kegagalan dicatat via `error_log()`.

Endpoint `transcode` diperiksa **sebelum** spend koin dan pekerjaan berat dimulai (urutan di `transcode.php`: rate-limit → validasi → spend); admin lolos tanpa batas (diteruskan ke `RateLimiter` internal).

### 12. `modules/exceptions/`

Tiga class exception yang extends `\RuntimeException`:

| Class | Deskripsi | Method Ekstra |
|---|---|---|
| `ProcessException` | Gagal proses eksternal (FFmpeg, yt-dlp) | `getCommand()`, `getExitCode()`, `getOutput()` |
| `DownloadException` | Gagal download URL | `getUrl()`, `getStage()` (validation/metadata/download) |
| `TranscodeException` | Gagal transcoding FFmpeg | `getInput()`, `getOutput()`, `getFfmpegLog()` |

### 13. `modules/transcoder/FfmpegUtils.php` (Trait)

Digunakan oleh `Uploader`, `TranscoderBase`, dan service-service `modules/transcoder/`:
```php
trait FfmpegUtils {
    protected function resolveBinary(array $candidates): string;
    protected function probeDuration(string $file): int;
    protected function generateSpriteAndVTT(string $video, string $work_folder): void;

    // Helper filesystem — tanpa @ suppression (lihat Konvensi Keamanan Filesystem)
    protected function ensureDir(string $dir, int $perms = 0755): bool;
    protected function removeFile(string $path): void;
    protected function removeDir(string $dir): void;
    protected function moveFile(string $src, string $dst): bool;  // Aman lintas device (RAM → HDD)
}
```

> Catatan: `cleanupDir()` (alias `removeDir()`) telah dihapus — tidak ada pemanggilnya di seluruh project; gunakan `removeDir()` langsung.

Helper `moveFile()` membandingkan device ID `stat()` sebelum mencoba `rename()`: pemindahan dari RAM disk (`/dev/shm`) ke USB HDD adalah kasus lintas-device yang normal, sehingga kegagalan `EXDEV` yang diduga dilewati sama sekali dan fallback copy+unlink berjalan tanpa warning menyesatkan.

### 14. `modules/core/japanese.php`

Pemrosesan teks Jepang:
```php
function getRomajiName(string $text): string;           // Kana → Romaji untuk filename
function analyzeJapaneseText(string $text): array;       // MeCab analysis → [romaji, english]
function getMecabPath(): string;                         // MeCab binary resolver
```

### 15. `modules/core/bootstrap.php`

Bootstrap terpusat: auto-detect `MEEL_ENV`, konfigurasi error reporting per environment, set `MEEL_BASE_URL`, default timezone.

#### Jam aplikasi

Zona waktu diset **tanpa syarat** — `date_default_timezone_set()` jalan apa pun
yang kata `php.ini`. Dulu ada penjaga `if (!ini_get('date.timezone'))`, tetapi
default PHP untuk directive itu adalah "UTC", jadi `ini_get()` selalu
mengembalikan "UTC" dan penjaga itu tidak pernah jalan. Hasilnya, jam aplikasi
berbeda antar SAPI: CLI memakai UTC, Apache memakai Asia/Jakarta.

 itu penting karena MySQL mengonversi kolom `TIMESTAMP` memakai `time_zone`
**sesi** saat membacanya. Jadi nilai yang ditulis sebagai string jam PHP lalu
dibaca kembali sebagai string jam MySQL akan melewati geseran tujuh jam setiap
kali kedua sisi berbeda. Gejalanya: hitung mundur MEeLCoin yang kembali ke
lima jam tiap refresh, dan indikator "online" di panel admin yang bertentangan
dengan kenyataan.

Dua aturan menjaga jam tetap satu:

| Aturan | Di mana |
|---|---|
| PHP selalu jalan di zona waktu aplikasi (`MEEL_TIMEZONE`, default `Asia/Jakarta`) | `modules/core/bootstrap.php` |
| `time_zone` sesi MySQL disejajarkan dengan offset PHP di setiap koneksi | `auth/config.php` |

Dengan keduanya, timestamp yang ditulis PHP lalu dibaca MySQL adalah instants
yang sama, dan kode yang membandingkan kolom database dengan jam PHP
(`strtotime($row['last_activity'])`) jadi benar. `DateTimeSourceTest` menjaga
kedua aturan itu, dan menyimpan daftar eksplisit berkas yang memang sengaja
mencampur jam database dengan jam PHP.

### 15a. `modules/core/base_url.php`

Perhitungan **base URL terpusat** — satu-satunya sumber kebenaran untuk path base URL proyek (relatif terhadap `DOCUMENT_ROOT`):

```php
function meel_base_url_path(): string;   // Root proyek relatif DOCUMENT_ROOT (mis. "/MEeL")
```

Dipakai oleh `bootstrap.php` (fallback `MEEL_BASE_URL`), `auth/config.php`, `auth/config.example.php`, dan fallback `base_url()` di `helpers.php`. Dihitung dari lokasi file ini (`dirname(__DIR__, 2)`), bukan dari `dirname(SCRIPT_NAME)` — sehingga konsisten untuk semua halaman di subdirektori (admin/, video/, dll).

### 15b. Halaman Error (`err/`)

Error handling terpusat di satu halaman dinamis `err/index.php` — konten & tema menyesuaikan sumber error:

| File | Fungsi |
|---|---|
| `err/index.php` | Halaman error dinamis terpadu — dipanggil via `?code=...` |
| `err/offline.php` | Halaman offline PWA (fallback service worker) — wajib dipertahankan |

**Parameter `err/index.php`:**

| Param | Nilai | Efek |
|---|---|---|
| `code` | `denied` / `not_found` / `banned` / `revoked` / `maintance` | Jenis error + status HTTP (403 / 404 / 403 / 401 / 503). Default `not_found` |
| `reason` | teks | Baris alasan tambahan (dipakai redirect IP-ban) |

**Adaptasi sumber:** modul asal dideteksi dari `HTTP_REFERER` (video/music/books/drive/admin/profile) → warna aksen + label sumber berubah otomatis. Deteksi ini memakai `meel_same_origin_referer()`, jadi referer dari host lain diabaikan.

**Target tombol "Kembali" di halaman ini** selalu beranda (`index.php`) — halaman error butuh satu jalan keluar yang pasti, bukan halaman yang mungkin juga bermasalah.

> Parameter `?back=` pernah disebut di dokumen ini, tapi tidak pernah ada
> implementasinya. Sengaja tidak ditambahkan: parameter query yang menimpa
> tujuan redirect adalah celah open redirect kalau tidak divalidasi.

### 15c. Shortcut Keyboard

Shortcut keyboard diimplementasikan dalam JavaScript dan di-guard oleh `assets/js/shared/keyboard.js` (`meelKeyShortcutIgnored()`) — mencegah shortcut aktif saat mengetik di input, menahan modifier key (Ctrl/Alt/Meta), atau saat key di-repeat.

**Video Watch (`assets/js/video/watch/`):**

| Key | Aksi | Sumber |
|-----|------|--------|
| `0–9` | Loncat ke 0–90% durasi | Plyr default |
| `Space / K` | Play / Pause | Plyr default |
| `←` / `→` | Mundur / Maju (seek) | Plyr default |
| `↑` / `↓` | Volume naik / turun | Plyr default |
| `M` | Mute / Unmute | Plyr default |
| `F` | Layar penuh | Plyr default |
| `C` | Toggle caption | Plyr default |
| `L` | Toggle loop | `misc.js` |
| `A` | Toggle auto-next | `misc.js` |
| `S` | Toggle AI Upscale | `misc.js` → `toggleUpscale()` di `upscaler.js` |
| `B` | Toggle ambient glow | `misc.js` → `toggleGlow()` di `player-events.js` |
| `N` | Video berikutnya | `mini-player.js` |
| `I` | Toggle mini player | `mini-player.js` |

> **Dikunci saat modal resume terbuka.** Selama modal "Lanjutkan Sesi?" tampil,
> `meelResumeModalActive` bernilai `true` dan seluruh pintasan di atas
> dinonaktifkan (kecuali `Esc`), sehingga video tidak bisa dikendalikan di
> belakang layar. `←` / `→` dipakai untuk berpindah pilihan mengikuti posisi
> tombol — **← Lanjut**, **→ Ulang**; `Home` / `End` ke pilihan pertama/terakhir,
> lalu `Enter` atau `Spasi` menjalankan pilihan yang disorot.
> Dijaga oleh `window` listener fase `capture` di `assets/js/shared/resume-modal.js`
> yang beating Plyr (`window` bubble) dan `mini-player.js` (`window` capture).

**Ikon setelah swap htmx (`assets/js/shared/htmx-lucide.js`):**

Ikon lucide adalah elemen `<i data-lucide="...">` yang diganti Lucide
menjadi SVG, dan penggantian itu hanya terjadi saat `lucide.createIcons()`
dijalankan. Setiap fragmen yang dikirim server berisi markup
`<i data-lucide>` apa adanya, jadi ada yang harus merender ulang setelah
swap — itulah yang dilakukan berkas ini, pada `htmx:afterOnLoad`.

Listener-nya didaftarkan di `document`, **bukan** di `document.body`. Di
beberapa halaman (`music/watch.php`, `books/index.php`) berkas ini dimuat di
dalam `<head>`, dan saat itu `document.body` masih `null`; listener yang
didaftarkan di `document.body` tidak akan pernah terpasang, dan setiap swap
berikutnya akan meninggalkan ikon apa adanya tanpa galat apa pun. Gejalanya:
ikon muncul lagi setelah refresh, lalu menghilang lagi begitu like/dislike
diklik.

`partials/link.php` memasang stub pengantre untuk `window.lucide` sebelum
`lucide.js` termuat, jadi panggilan yang terlalu dini tidak hilang.

**Music Watch (`assets/js/music/watch/`):**

| Key | Aksi | Sumber |
|-----|------|--------|
| `0–9` | Loncat ke 0–90% durasi | Plyr default |
| `Space / K` | Play / Pause | Plyr default |
| `←` / `→` | Mundur / Maju (seek) | Plyr default |
| `↑` / `↓` | Volume naik / turun | Plyr default |
| `M` | Mute / Unmute | Plyr default |
| `L` | Toggle loop | `misc.js` |
| `E` | Toggle equalizer | `misc.js` |
| `V` | Toggle visualizer | `misc.js` |
| `I` | Kembali ke library / mini player | `misc.js` |

### 16. `modules/media/SearchEngine.php`

**Class:** `SearchEngine` — FULLTEXT search engine (video, music, books) dengan sanitizer query:

```php
class SearchEngine {
    public const VIDEO_LIMIT    = 20;
    public const MUSIC_LIMIT    = 20;
    public const MIN_SEARCH_QUERY = 3;   // Query pendek (< 3) tidak diproses
    public const MAX_SEARCH_QUERY = 255; // Batas panjang query

    public function __construct(mysqli $db_connection);
    public function parseParams(): array;                    // q (sanitized), offset, dll.
    public static function sanitizeQuery(string $q): string; // FULLTEXT-safe: buang operator murni, seimbangkan kutip, buang asterisk di awal token
    public function searchVideo(array $params): array;
    public function searchMusic(array $params): array;
    public static function clearCache(): void;
}
```

**Perilaku kunci:**
- `sanitizeQuery()` bersifat **public static** — dipakai semua entry point search
  sehingga sintaks FULLTEXT selalu valid (tidak ada `mysqli_sql_exception` pada input malformed).
- `parseParams()` membaca `$_GET['search']` + `$_GET['offset']`; offset ikut
  dalam **cache key**, sehingga pagination tidak pernah menyajikan halaman basi.
- `searchVideo()` / `searchMusic()` selalu menghitung **total** — termasuk untuk
  hasil kosong — sehingga `hasMore` dan metadata pagination dapat dipercaya
  (memperbaiki tampilan progres "1/1 → 2/1" yang salah).
- `MIN_SEARCH_QUERY = 3` — query lebih pendek diabaikan (efisiensi index)

### 17. `modules/autoload.php`

Class-map via `spl_autoload_register()`. Auto-load 19 class dari `modules/core/`, `modules/media/`, `modules/transcoder/`, `modules/auth/`, `drive/`, dll.

### 18. WatchController (`controllers/api/WatchController.php`)

```php
class VideoWatchController { public function getViewData(): array; }
class MusicWatchController { public function getViewData(): array; public function requireMedia(): void; }
```

### 19. Migration System (`database/migrate.php`)

Migration system bersifat **idempotent** — aman dijalankan berulang kali. Tabel `db_version` melacak migrasi mana yang sudah dijalankan (satu baris per versi, setiap versi hanya dieksekusi sekali).

> ⚠️ **Baca dulu kalau pernah melihat nomor migrasi yang berbeda.**
>
> Nomor **v1–v15 adalah riwayat lama** yang sudah di-*redunisasi* pada
> 17 Sep 2026 dan kini terkonsolidasi menjadi **v1** — v13 (MEeLCoin), v14
> (index `view_logs`), dan v15 (tabel `user_notifications`) semuanya tercakup
> di dalamnya. Nomor **v2 ke atas dihitung ulang dari nol** setelah reset, dan
> baris `db_version` dibersihkan menjadi `{1..5}`; loop hanya mengeksekusi
> versi `> MAX(db_version)`.
>
> Jadi dokumen atau kode yang menyebut "Migration v9" atau "v15" sebagai
> **versi aktif** memakai angka legacy — bukan bug.

**v1 — konsolidasi skema (hasil redunisasi legacy v1–v15)**

| Apa yang di-Sync | Detail |
|---|---|
| FULLTEXT indexes | Pencarian `video`, `music`, `books` |
| Performance indexes | `upload_date` pada video, music, books, drive_files |
| Foreign keys | `upload_queue`, `transcode_queue`, `drive_files` → `users.id` |
| Tipe title | `VARCHAR(255)` → `TEXT` (video, music, books) |
| Tabel activity_log | Audit trail untuk aksi user |
| UNIQUE KEY | `users.username` (dedup akun guest) |
| Kolom role | `varchar(20)` — mendukung admin, member, user, guest |
| Kolom MFA | `mfa_secret`, `mfa_backup_codes`, `mfa_enabled` |
| Index comments | Komposit `(video_id, created_at)` & `(music_id, created_at)` |
| Unique key interactions | Dipisah menjadi `(user_id, video_id)` & `(user_id, music_id)` |
| Sistem MEeLCoin | Kolom `meelcoin` + `meelcoin_last_refill`, tabel `site_settings`, `meelcoin_log` |
| Index view_logs | `(video_id)`, `(music_id)` — percepat `syncViewsFromLogs` |
| user_notifications | Sistem notifikasi untuk like, reply, MEeLCoin, chat admin |

**v2 — normalisasi state MEeLCoin**

| Apa yang di-Sync | Detail |
|---|---|
| Biaya upload MEeLCoin | `meelcoin_upload_cost` & `meelcoin_advanced_cost` dinormalkan minimum `1` (biaya `0` membuat `spend()` tidak memotong apa pun) |

**v3 — indeks hot query + FK komentar**

| Apa yang di-Sync | Detail |
|---|---|
| `activity_log` | `(created_at)`, `(user_id, created_at)`, `(action, created_at)` — feed admin (urut waktu), filter per-user & per-aksi |
| `upload_queue` | `(status, created_at)` (poll status antrean), `(user_id, media_type, id)` (daftar antrean per user/media) |
| `transcode_queue` | `(status)` (poll), `(created_at)` (histori) |
| `interactions` | `(video_id, type)`, `(music_id, type)` — count like/dislike per media |
| `users` | `(role, is_active, last_activity)` (listing admin + deteksi idle), `(last_activity)` |
| FK `comments.video_id → video.id` | `ON DELETE CASCADE`; baris orphan dibersihkan dulu sebelum FK dipasang |
| Buang indeks redundan | `site_settings.idx_setting_key`, `view_logs.idx_vl_user_video` (duplikat terhadap UNIQUE/PRIMARY) |

**v4 — indeks coalescing notifikasi**

| Apa yang di-Sync | Detail |
|---|---|
| `user_notifications` | `idx_un_user_type_created (user_id, type, created_at)` — menopang lookup coalescing `like` sekaligus covering untuk query pruning retensi. Tanpa ini `isCoalesced()` menyisir seluruh baris notifikasi pengguna tersebut per-press like |

**v5 — FK `interactions`**

| Apa yang di-Sync | Detail |
|---|---|
| Bersihkan baris yatim | `interactions` yang `user_id`/`video_id`/`music_id`-nya sudah tak ada dihapus lebih dulu |
| FK `interactions.user_id → users.id` | `ON DELETE CASCADE` |
| FK `interactions.video_id → video.id` | `ON DELETE CASCADE` |
| FK `interactions.music_id → music.id` | `ON DELETE CASCADE` |

**v6 — rekam biaya MEeLCoin di antrean (dasar refund pengembalian)**

| Apa yang di-Sync | Detail |
|---|---|
| `upload_queue` / `transcode_queue` | Kolom `coin_cost` (MEeLCoin yang benar-benar dipotong saat job dibuat) dan `coin_refunded` (0/1, mencegah refund ganda) |
| Indeks sweeper | `idx_uq_status_created`, `idx_tq_status_created` untuk mencari baris macet |

> **Mengapa biaya harus disimpan, bukan dihitung ulang?** Kalau sweeper menghitung
> ulang dari `site_settings`, dan admin mengubah harga setelah user upload,
> jumlah yang dikembalikan akan meleset — user mendapat kurang atau lebih.
> `coin_cost` merekam **angka saat transaksi terjadi**, jadi hasilnya selalu benar.

Refund-nya sendiri berjalan dalam satu transaksi: baris antrean dikunci dengan
`FOR UPDATE`, `MeelCoin::refund()` dijalankan, dan baru setelah itu
`coin_refunded` di-set ke 1. Langkah yang gagal membatalkan seluruh
transaksi — jadi user tidak mungkin menerima koinnya kembali tanpa penanda
terpasang, atau penanda terpasang tanpa koin yang kembali.
`refundQueueRow()` memegang transaksinya; kunci dan penanda dipisah jadi
helper sendiri supaya urutannya tetap terbaca.

Catatan migrasi:

- Pemasangan FK memakai **existence check** via `information_schema` (`meel_mig_add_fk`) — menolak FK ganda, baik yang sudah bernama sama maupun FK lain pada kolom yang sama dengan nama constraint berbeda.
- Entri FULLTEXT + `idx_*_upload_date` tetap dipertahankan di **v1 selain** sudah ada di `schema.sql` — idempoten (`has_index`) dan menopang DB lama yang belum pernah import schema terbaru; instalasi segar cukup import `schema.sql`.

> Fresh install pakai `database/schema.sql` (import langsung). Migration untuk **database yang sudah ada** agar sync ke skema terbaru.

> 💡 **Modul Rhythm (MEeL!Mania) TIDAK memakai migration system utama.** Tabel
> `rooms`, `moves`, `arcade_song`, & `arcade_score` dibuat lewat
> `arcade/schema.sql` + `arcade/migrate.php` (ekstensi terpisah —
> lihat [Arcade Collection](#21a-ekstensi-arcade-arcade)).

### 20. MFA System

Multi-Factor Authentication (TOTP) melindungi akun user:

| File | Fungsi |
|---|---|
| `auth/mfa_setup.php` | Setup MFA — generate secret, scan QR/barcode, verifikasi TOTP, backup codes |
| `auth/mfa_verify.php` | Verifikasi TOTP setelah login — rate limit 10 percobaan gagal, lock 5 menit |
| `admin/mfa_reset.php` | Admin reset MFA user yang kehilangan akses Authenticator |
| `controllers/system/mfa.php` | Backend controller — AJAX verify, regenerate backup codes, email backup |

**Flow:** `login.php` → cek `mfa_enabled` → redirect `mfa_verify.php` → valid TOTP → set session penuh

**Helper functions** (di `modules/auth/helpers/mfa.php`):
```php
function generate_mfa_secret(): string;      // Base32 random secret
function generate_totp(string $secret): string;// TOTP kode 6 digit
function verify_totp(string $secret, string $code): bool; // Verifikasi dengan window ±1
function generate_backup_codes(): array;      // 8 backup codes (6 digit, password_hash/bcrypt)
function verify_backup_code(string $stored, string $code): array; // Verify + consume code
```

### 21. Chess Multiplayer (`arcade/chess/`) — Ekstensi Arcade

> ⚠️ **Bagian dari ekstensi arcade** — tidak ada di MEeL-HUB core. Folder
> `arcade/` diinstal secara terpisah. Lihat [§21a](#21a-ekstensi-arcade-arcade).

Multiplayer catur real-time via LAN:

| File | Fungsi |
|---|---|
| `index.php` | Board catur dengan drag-and-drop, timer, chat, sound effects |
| `controller/create_room.php` | Buat ruang baru, return room code |
| `controller/join_room.php` | Gabung ruang dengan kode |
| `controller/get_move.php` | Ambil langkah lawan + flag `opponent_online` (polling) |
| `controller/save_move.php` | Simpan langkah dengan validasi legal move |
| `controller/check_room_status.php` | Cek status ruang (waiting/playing/ended) |
| `controller/game_action.php` | Resign / tawaran seri / terima / tolak / `disconnect_win` / `game_over` (checkmate & stalemate) |
| `controller/chess_helpers.php` | Helper bersama: `chess_opponent_online()` (deteksi offline) |

**Alur multiplayer (color picker):**

```
Klik "Multiplayer LAN" → konfirmasi SweetAlert
  → overlay "Pilih Warna" (papan disembunyikan & terkunci)
      ├── Putih = createRoom() → state "Menunggu Lawan" + room code
      │        → lawan join → overlay tertutup → polling mulai
      └── Hitam = joinRoom() (prompt kode) → sync papan → overlay tertutup
```

**Deteksi disconnect lawan:**
- `get_move.php` mengembalikan `opponent_online` berdasarkan `users.last_activity` (diperbarui di setiap request oleh `activity_logger`)
- Ambang offline: `CHESS_OPPONENT_OFFLINE_SECONDS` (default 90 detik) — di atas throttle timer tab background browser
- Aksi `disconnect_win` di `game_action.php`: klaim kemenangan, **server memverifikasi ulang** lawan benar-benar offline sebelum mencatat event terminal `disconnect`
- Aksi `game_over` di `game_action.php`: client mencatat checkmate/stalemate (hanya bisa dideteksi di sisi client) agar GC mempertahankan game yang sudah selesai

**Security guards (semua controller):**
- Wajib login — respons JSON `401` + `login_required: true` (JS `arcade/chess/assets/js/api.js` redirect ke login)
- Semua aksi POST wajib `csrf_token` valid (403 jika tidak)
- Token CSRF tidak pernah disimpan ke `moves.move_data`
- `admin/catur.php?auto_cleanup=1` juga wajib `csrf_token` (dikirim JS via `window.MEEL_ADMIN_CSRF`)

### 21a. Ekstensi Arcade (`arcade/`)

Ekstensi arcade adalah **modul terpisah** dari MEeL-HUB core. Folder `arcade/`
bisa diinstal atau dihapus tanpa memengaruhi fungsi HUB. Ekstensi ini
mengelola database sendiri (`arcade/schema.sql` + `arcade/migrate.php`).

Selain catur multiplayer, ekstensi arcade menyediakan **9 game** — 7 game
statis (HTML/JS murni, tanpa backend) + Chess (PHP multiplayer) + Rhythm
(PHP + DB sendiri):

| Game | Folder | Tipe | Deskripsi |
|---|---|---|---|
| Miku & Teto Run | `arcade/dino/` | Statis | Endless runner ala Chrome Dino |
| Snake | `arcade/snake/` | Statis | Snake klasik |
| 2048 | `arcade/2048/` | Statis | Puzzle gabung tile |
| Tetris | `arcade/tetris/` | Statis | Tetromino legendaris |
| Breakout | `arcade/breakout/` | Statis | Bola pantul & bata |
| Simon Says | `arcade/simon-says/` | Statis | Game memori |
| Ludo | `arcade/ludo/` | Statis | Board game 2–4 pemain / vs Bot |
| Chess | `arcade/chess/` | PHP + DB | Multiplayer LAN real-time (lihat §21) |
| **MEeL!Mania** | `arcade/rhythm/` | PHP + DB | Rhythm game 4-lane ala osu!mania |

**Modul Rhythm (`arcade/rhythm/`):**

| File | Fungsi |
|---|---|
| `index.php` | Lobby — daftar lagu (builtin + custom), filter/sort/search |
| `game.php` | Gameplay 4-lane — A/S/K/L atau sentuh, 4 tingkat kecepatan |
| `editor/` | Beatmap editor — buat/simpan beatmap di browser (localStorage) |
| `manage/` | Manajemen lagu custom (list, edit, hapus) |
| `api/songs.php` | GET — daftar lagu (builtin + custom, sort/filter/search, limit 100) |
| `api/beatmap.php` | GET — ambil beatmap per lagu (builtin via slug, custom via ID numerik; increment `play_count`) |
| `api/upload.php` | POST — upload lagu custom (auth + CSRF; non-admin 10/jam; MP3/OGG/OPUS/FLAC/WAV ≤ 20MB & ≤ 5 menit; beatmap 10–5000 notes; FLAC otomatis di-transcode ke Opus; cover → WebP) |
| `api/delete.php` | POST — hapus lagu custom (owner/admin saja) |
| `migration.sql` | **Tabel DB ekstensi** — `rooms`, `moves`, `arcade_song` & `arcade_score` |

> ⚠️ **Instalasi:** jalankan migrasi arcade sekali:
> `php arcade/migrate.php` — bukan bagian dari `database/schema.sql`
> (23 tabel) maupun `database/migrate.php`. Atau gunakan
> `install.sh` yang menawarkan instalasi arcade secara interaktif.

### 22. `modules/core/Router.php` — front controller

`router.php` menerjemahkan path permintaan lalu menyerahkannya ke
`MeelRouter::dispatch()`. Tabel rutenya dua konstanta: `ROUTES` untuk halaman
inti dan `OPTIONAL_ROUTES` untuk modul yang bisa dimatikan (`arcade`).

`dispatch()` dulu memuat seluruh cabangnya di satu fungsi 50 baris, dengan
`exit` di hampir setiap cabang. Sekarang ia terbaca sebagai rangkaian
keputusan pendek, masing-masing di tempatnya:

| Langkah | Di mana | Apa yang diputuskan |
|---|---|---|
| Alias profil | `profileAliasRedirect()` | `/profile?u=<nama>` dan `/profile/<nama>/<tab>` sama-sama menunjuk `/profile/<nama>` dengan tab sebagai query. Murni — mengembalikan URL tujuan atau `null` |
| Pencarian rute | `routeFor()` | tabel eksak dulu, lalu rute opsional, lalu pola slug (`music/<slug>`, `profile/<nama>`, `admin/chat/<nama>`) |
| Penjaga opsional | `guardOptionalRoute()` | modul halaman yang nonaktif dialihkan ke hub, modul API yang nonaktif menjawab JSON 404 |
| Menjalankan handler | `runHandler()` | menyetel `SCRIPT_NAME`, pindah ke direktori handler supaya path relatifnya bekerja, lalu me-`include` |

`profileAliasRedirect()` yang murni justru inti dari ini: aturan pengalihan
bisa diuji tanpa mengirim permintaan. `RouterDispatchTest` menguji itu, dan
sekaligus memastikan setiap rute di kedua tabel menunjuk berkas yang benar-benar
ada — rute yang menunjuk berkas hilang menggagalkan seluruh halaman dengan 500
yang tidak menjelaskan apa pun.

Satu rute seperti itu ada. `/arcade/rhythm/editor` menunjuk
`arcade/rhythm/editor/index.php`, direktori yang sudah tidak ada sejak editor
dipindah ke `arcade/rhythm/manage/`. Meminta URL itu menghasilkan
"500 — Gagal mengubah working directory." Sekarang rute tersebut menunjuk
`arcade/rhythm/manage/edit.php`, berkas yang sama sudah dituju dua URL editor
lainnya.

#### Tabel Rute Utama

Sejak migrasi routing, semua URL publik menggunakan URL bersih (tanpa ekstensi `.php`). Request masuk ke front controller `router.php` yang me-resolve path ke file handler melalui tabel rute di `modules/core/Router.php`.

**Cara kerja:**

```
Request: /MEeL/music/beranda?format=ogg
  → .htaccess: semua request non-file/non-dir di-rewrite ke router.php (kecuali media upload/)
  → modules/core/Router.php: routeFor('music/beranda') → handler 'music/index.php'
  → require music/index.php dengan query string asli
```

**Aturan `.htaccess` (root):**
- Semua URL bersih (contoh `video/watch`, `music/beranda`, `admin/stats`) → `router.php`
- File `.php` lama (contoh `video/watch.php`, `music/index.php`, `upload_advanced.php`,
  `admin/cookies.php`, `admin/analys`, `admin/content.php`, `admin/content`) → **301** ke URL bersih (query terjaga, bookmark lama tetap jalan)
- Varian trailing-slash (contoh `/video/upload/`) → **301** ke non-slash
- `DirectorySlash Off` di direktori media (`video/upload`, `music/upload`, `books/upload`)
  agar `/video/upload` dilayani router (bukan redirect mod_dir)

**Skema `beranda`:** tiap modul punya halaman utama `beranda`
(`video/beranda`, `music/beranda`, `books/beranda`, `drive/beranda`, `admin/beranda`,
`arcade/beranda`). Halaman `index.php` lama tetap jalan sebagai alias.

**Tabel rute utama (`modules/core/Router.php`):**

| URL | Handler (file) |
|---|---|
| `/`, `/beranda` | `index.php` (hub) |
| `/introduction`, `/update`, `/upload`, `/transcode` | `introduction.php`, `update.php`, `upload_advanced.php`, `transcode.php` |
| `/err`, `/err/offline` | `err/index.php` (dinamis `?code=`), `err/offline.php` (PWA) |
| `/video/beranda`, `/video/watch`, `/video/search`, `/video/load-more`, `/video/upload`, `/video/stream` | `video/*.php` |
| `/music/beranda`, `/music/watch`, `/music/search`, `/music/load-more`, `/music/upload`, `/music/playlist`, `/music/playlist-action`, `/music/stream`, `/music/file` | `music/*.php` |
| `/music/<nama-playlist>` | `music/view_playlist.php` (route slug playlist — lihat di bawah) |
| `/books/beranda`, `/books/read`, `/books/read-pdf`, `/books/search`, `/books/upload`, `/books/file` | `books/*.php` |
| `/drive/beranda`, `/drive/upload`, `/drive/delete`, `/drive/download`, `/drive/stream` | `drive/*.php` |
| `/profile/<username>` (`?tab=all\|video\|music`), `/profile/channel-more`, `/profile/edit`, `/profile/manage`, `/profile/edit-video`, `/profile/edit-music`, `/profile/notification` | `profile/index.php` (profil + grid channel publik, tab via query), `profile/channel_more.php` (fragment HTMX), `profile/edit-video.php` (pemilik, non-admin), `profile/edit-music.php` (pemilik, non-admin), `profile/notification.php`, `controllers/profile/*.php` |
| `/admin/beranda`, `/admin/edit-video`, `/admin/edit-music`, `/admin/stats`, `/admin/user-management`, `/admin/meelcoin`, `/admin/activity-log`, `/admin/catur`, `/admin/mfa-reset`, `/admin/chat` | `admin/*.php` (edit-video/edit-music khusus admin), `controllers/admin/*.php` |
| `/auth/login`, `/auth/register`, `/auth/logout`, `/auth/mfa-setup`, `/auth/mfa-verify` | `auth/*.php` |
| `/arcade/beranda`, `/arcade/chess`, `/arcade/rhythm`, `/arcade/rhythm/game`, `/arcade/rhythm/editor`, `/arcade/rhythm/manage`, `/arcade/rhythm/edit` | `arcade/*.php` *(ekstensi — memerlukan folder arcade terpasang)*. Ketiga URL editor (`/arcade/rhythm/editor`, `/arcade/rhythm/edit`, `/arcade/rhythm/manage/edit`) menuju berkas yang sama, `arcade/rhythm/manage/edit.php` |
| `/arcade/rhythm/api/songs`, `/arcade/rhythm/api/beatmap`, `/arcade/rhythm/api/upload`, `/arcade/rhythm/api/delete` | `arcade/rhythm/api/*.php` (MEeL!Mania) *(ekstensi)* |
| `/api/like`, `/api/comment`, `/api/delete-comment`, `/api/auto-metadata`, `/api/pdf`, `/api/download-transcode`, `/api/post-encode`, `/api/theme`, `/api/ajax-refresh`, `/api/server-stats`, `/api/server-stats-sse`, `/api/notification`, `/api/chat`, `/api/meelcoin` | `controllers/api/*.php` |
| `/system/mfa` | `controllers/system/mfa.php` |

> **Route slug playlist:** playlist punya URL berbasis nama — `/music/<nama-playlist>`
> (contoh `/music/leo-need`). Slug dibuat dari nama playlist (`playlistSlug()`),
> dijamin unik per user (`getUserPlaylistRoutes()`); nama non-ASCII jatuh ke `playlist`,
> nama bentrok dengan rute modul atau slug lain diberi akhiran `-<id>`. Route eksak
> (`beranda`, `watch`, dll.) selalu menang; selain itu dianggap slug playlist
> (`resolvePlaylistSlug()`). URL lama `music/playlist?id=X` tetap didukung.


### 23. `modules/auth/` dan konfigurasi bersama

`auth/config.php` di-include setiap halaman: ia memuat kredensial dari
`auth/settings.php`, membuka koneksi database, memulai sesi, dan menyetel
header keamanan.

Jalur gagalnya dulu melapor lewat `fwrite(STDERR, ...)`. `STDERR` hanya ada di
SAPI CLI dan CGI — di Apache konstanta itu tidak ada, jadi koneksi database
yang gagal berubah menjadi "Undefined constant STDERR", yaitu fatal yang
menutupi pesan yang justru ingin disampaikan. Berkas itu sekarang memakai
`error_log()`, yang jalan di semua SAPI dan tetap menulis ke stderr di CLI.

`WebSafeErrorOutputTest` menjaga supaya ini tidak kembali: sebuah berkas
hanya boleh memakai `STDERR`, `STDOUT`, atau `STDIN` kalau ia hidup di
direktori perkakas CLI, menjaga dirinya sendiri dengan pemeriksaan `PHP_SAPI`
yang menghentikan eksekusi, atau tidak di-include berkas mana pun.

### Editor Media Admin (musik + video)

`admin/edit-music.php` dan `admin/edit-video.php` tadinya salinan satu sama
lain: sekitar 50% logika PHP mereka identik, termasuk blok unggah thumbnail
beserta daftar MIME, batas ukuran, dan pemanggilan ffmpeg. Graph
`codebase-memory` menunjukkan kedua berkas berubah bersama 20 kali (coupling
0,87) — ciri khas salinan yang seharusnya disatukan.

Bagian yang sama sekarang tinggal di satu tempat:

| Berkas | Tanggung jawab |
|---|---|
| `admin/includes/media_editor.php` | `meel_editor_media_config()` — label, nama berkas default, dan wording error per jenis media; `meel_editor_context_redirect()` — pengalihan admin/profil; `meel_editor_upload_thumbnail()` — seluruh jalur unggah |
| `admin/partials/edit_media_uploader_card.php` | kartu "diunggah oleh" di sidebar |
| `admin/partials/edit_media_stats.php` | deretan views/likes/dislikes |
| `admin/edit-music.php` | bagian khusus musik: artis, album, seksi lirik, penulisan database |
| `admin/edit-video.php` | bagian khusus video: unggah subtitle, penulisan database |

`admin/edit-music.php` mengecil dari 427 ke 357 baris dan `admin/edit-video.php`
dari 468 ke 398. Panggilan shell ke ffmpeg berpindah dari dua berkas ke satu,
jadi ada tepat satu tempat ter-audit tempat ffmpeg dipanggil.

Satu perbedaan sengaja dipertahankan. Kalau unggah thumbnail gagal, halaman
musik tetap menyimpan judul dan deskripsi, sedangkan halaman video tidak
menyimpan apa pun. Asimetri itu muncul dari dua salinan yang lama melenceng
berbeda-bedanya; ini bukan efek penyatuan, dan mengubahnya adalah keputusan
produk, bukan refactor. `AdminEditorSharedTest` menguncinya supaya tidak
berubah tanpa sengaja.

### Repository admin — basis bersama

`AdminActivityRepository` dan `AdminUploadQueueRepository` menopangi dua tab
di admin activity log viewer. Query mereka dulunya salinan satu sama lain:
filter waktu, pencarian, hitung, halaman, ekspor, dan pembersihan identik.
Yang benar-benar berbeda cuma nama tabel, alias-nya, dan dua kolom yang
disaring — dan salinan yang tertinggal satu perubahan query tetap melayani
hasil basi tanpa itu terlihat.

Query bersama sekarang ada di `modules/media/AdminFilteredRepository.php`.
Masing-masing subclass hanya menyediakan bagian yang memang berbeda:

| Subclass | Tabel | Alias | Filter utama | Kolom pencarian |
|---|---|---|---|---|
| `AdminActivityRepository` | `activity_log` | `al` | `action` | `u.username`, `al.ip_address` |
| `AdminUploadQueueRepository` | `upload_queue` | `uq` | `status` (dibatasi `processing`, `completed`, `failed`) | `u.username`, `uq.url` |

Pembatasan status itu cocok dengan empat opsi yang ditawarkan UI admin;
`pending` sengaja tidak termasuk. Status di luar daftar itu berarti "tanpa
filter status", bukan "tanpa baris" — kalau tidak, memfilter nilai tak
dikenal membuat tab terlihat kosong tanpa alasan yang jelas.

Yang tetap di masing-masing subclass: `getDistinctActions()`, `getWeeklyStats()`
dan `clearAll()` untuk activity log; `getStats()` untuk antrean.

`AdminFilteredRepositoryTest` dan
`AdminFilteredRepositoryIntegrationTest` mengunci ini: query bersama tidak
boleh disalin balik ke subclass, tiap repository harus tetap menunjuk tabelnya
sendiri, dan perilaku filter keduanya harus tetap berbeda.

### Admin Activity Log Viewer

`admin/activity_log.php` — viewer audit trail dengan 3 tab. Berkas induknya
memegang pemrosesan request (324 baris); markup tiap tab hidup di partial
sendiri di `admin/partials/`:

| Berkas | Isi |
|---|---|
| `admin/activity_log.php` | handler POST, export, pemilih tab — 402 baris |
| `admin/partials/activity_log_log.php` | tab activity |
| `admin/partials/activity_log_views.php` | tab statistik view |
| `admin/partials/activity_log_uploads.php` | tab antrean unggah |

Partial itu berada di direktori yang bisa diakses lewat web, jadi masing-masing
mengembalikan 403 alih-alih berjalan tanpa koneksi database.
`admin/partials/live-monitor-manage.php` adalah kasus sebaliknya: diambil lewat
AJAX, jadi ia memuat dirinya sendiri dan memeriksa peran admin.

**Tab Activity** (tema biru-600):
- Filter berdasarkan tipe aksi, username/IP, rentang waktu
- Pagination (50/halaman)
- Stats cards (aktivitas 7 hari, user unik, total entri)
- Badge aksi berwarna (login=biru, upload=hijau, ban=merah)
- Cleanup manual log (>7, 14, 30, 90, 365 hari) dengan CSRF

**Tab Admin Actions** (tema ungu-600):
- Filter berdasarkan username admin, tipe aksi, rentang waktu
- Stats cards (aksi admin 7 hari, admin unik, total entri)
- Badge berwarna (coin=kuning, reset=merah, login=biru, lainnya=abu-abu)
- Maintenance: hapus yang lebih lama dari 7–365 hari

**Tab Upload Queue** (tema hijau-600):
- Filter berdasarkan status (`processing`/`completed`/`failed` — sesuai enum `upload_queue.status`), uploader, rentang waktu
- Stats cards (total upload, selesai, gagal, aktif)
- Badge status berwarna
- Export CSV/JSON/XLS dengan preview modal
- Maintenance: hapus yang selesai/gagal lebih lama dari 7–365 hari

> **Catatan:** User admin dikecualikan dari dropdown MEeLCoin manual adjustment
> (`WHERE role NOT IN ('guest', 'admin')`) — saldo admin dikelola melalui auto-refill
> dan biaya upload saja.

### 22. PWA Service Worker (`sw.js.php` + `modules/core/SwPrecache.php`)

Service worker **dibangkitkan dinamis oleh PHP** — panduan lengkap di
[`pwa.md`](pwa.md).

| Komponen | Peran |
|---|---|
| `modules/core/SwPrecache.php` | `baseAssets()` + `moduleAssets()` (semua `assets/css/*/manifest.php`) → `all()`; `version()` = hash konten → update SW otomatis |
| `sw.js.php` | Skrip SW lengkap, `Content-Type: application/javascript`, output deterministik |
| `.htaccess` | `RewriteRule ^sw\.js$ sw.js.php [L]` — URL `/sw.js` dipertahankan |

Menambah folder modul baru (`assets/css/<folder>/manifest.php`) otomatis menambahkan CSS-nya ke precache — **tanpa perubahan SW manual**.

### 23. Theme System (`assets/css/shared/theme-tokens.css` + `light-theme.css` + `assets/js/shared/theme.js`)

Sistem light/dark mode dengan CSS variables dan JavaScript toggle.

| Komponen | Peran |
|---|---|
| `assets/css/shared/theme-tokens.css` | CSS variables untuk dark mode (default) — `--meel-bg`, `--meel-surface`, `--meel-text`, dll. |
| `assets/css/shared/light-theme.css` | Override Tailwind utilities saat `html[data-theme="light"]` — 500+ baris overrides |
| `assets/js/shared/theme.js` | `MEELTheme` — toggle manager, localStorage + DB sync, smooth transition |
| `controllers/api/theme.php` | REST API (GET/POST) untuk theme preference |
| `database/schema.sql` | Kolom `custom_theme` di tabel `users` |

**Arsitektur:**
```
theme-tokens.css (variables)
       ↓
light-theme.css (overrides saat data-theme="light")
       ↓
theme.js (toggle + persist)
       ↓
localStorage (source of truth, anti-flash, hanya guest)
+ DB custom_theme (sync untuk logged-in user)
```

**Behavior guest:** Theme preference hanya disimpan di `localStorage` (tanpa tulis DB). `MEELTheme.init({ isLoggedIn: false })` → `toggle()` hanya simpan ke localStorage tanpa panggil API.

**Behavior logged-in:** Theme disimpan ke `localStorage` DAN `users.custom_theme` (DB). Saat halaman dimuat, localStorage diterapkan dulu (anti-flash), lalu disinkronkan dengan DB jika berbeda.

### 24. Profile Module (`profile/index.php` + `controllers/profile/`)

Halaman profil pengguna dengan visibilitas berbasis role, theme toggle, dan grid channel publik.

| Komponen | Peran |
|---|---|
| `profile/index.php` | Halaman profil — menampilkan avatar, bio, statistik, tombol aksi, grid konten channel |
| `profile/channel_more.php` | Fragment HTMX untuk infinite scroll load-more pada profile channel (sentinel auto-load) |
| `profile/content_card.php` | Komponen kartu konten channel, dipakai bersama oleh `index.php` dan `channel_more.php` |
| `controllers/profile/profile_edit.php` | Handler edit profil |
| `controllers/profile/manage.php` | Manajemen konten (video/music) |
| `modules/media/ProfileRepository.php` | Query data profil (count video, music, feed paginated) |

**Variabel kunci:**
- `$is_logged_in` — apakah pengunjung punya session aktif
- `$is_guest_profile` — apakah profil yang dilihat adalah profil "Guest" sintetis
- `$is_owner` — apakah pengunjung melihat profil diri sendiri
- `$active_tab` — tab filter konten (`all`, `video`, `music`)

**Aturan visibilitas:**

| Elemen | Owner | Visitor (login) | Guest |
|---|:---:|:---:|:---:|
| Edit Profile, Kelola Konten, MFA | ✅ | ❌ | ❌ |
| Theme Toggle | ✅ | ❌ | ✅ (profil sendiri saja) |
| Upload Stats | ✅ | ✅ | ❌ |
| Channel Tabs (All/Video/Music) | ✅ | ✅ | ❌ |
| Content Grid + Load More | ✅ | ✅ | ❌ |
| Bio | dari DB | dari DB | "Akun Guest" |
| Badge | Staff/Member | Staff/Member | Guest |

**Profile sebagai Channel:** Halaman profil sekaligus channel publik. Untuk user yang login, menampilkan grid konten dengan batch awal 12 item. Infinite scroll via HTMX memuat lebih banyak melalui `profile/channel-more` — sentinel `#channel-more-area` memakai `hx-trigger=revealed`, jadi batch berikutnya dimuat sendiri saat masuk viewport (retry via `assets/js/shared/sentinel-retry.js`, end box saat konten habis). Kartu dirender partial `profile/content_card.php`. Profil guest hanya menampilkan card profil — tab dan grid konten tersembunyi.

**Canonical redirects:**
- `profile/?u=X` → 301 → `profile/X`
- `profile/<user>/<all|video|music>` → 301 → `profile/<user>?tab=<type>`

**Akses profil guest:** Guest bisa melihat profil pengguna lain (termasuk profil Guest sintetis mereka sendiri). Profil Guest dibangun di-memory (tanpa query DB) dengan `id=0`, `role='guest'`.

**Inisialisasi session:** Menggunakan `meel_boot_session()` (bukan `session_start()` mentah) untuk memastikan nama cookie session cocok dengan seluruh aplikasi (`meel`).

### 25. Notification Module (`modules/core/Notification.php` + `controllers/api/notification.php`)

Sistem notifikasi berbasis database dengan user scoping dan actor tracking.

**Database:** tabel `user_notifications` (bagian dari migrasi **v1** — dulunya legacy v15, sekarang dikonsolidasikan)

```php
class Notification {
    public static function create(mysqli $conn, int $userId, string $type, string $title, string $message, ?int $relatedId = null, ?string $relatedSlug = null, ?int $actorUserId = null): void;
    public static function getUnreadCount(mysqli $conn, int $userId): int;
    public static function getList(mysqli $conn, int $userId, int $limit = 20): array;
    public static function markRead(mysqli $conn, int $notifId, int $userId): void;
    public static function markAllRead(mysqli $conn, int $userId): void;
    public static function deleteOne(mysqli $conn, int $notifId, int $userId): bool;
    public static function deleteAllByUser(mysqli $conn, int $userId): bool;
    public static function deleteByChat(mysqli $conn, int $userId, string $message, int $actorUserId): bool;
}
```

**Tipe notifikasi:** `like`, `reply`, `admin_chat`

**Generasi link:** Kolom `related_slug` menyimpan tipe media (`video`/`music`) atau key kompon (`type:id` untuk reply). Halaman notifikasi membangun link secara dinamis menggunakan prefix `meel_base_url_path()`.

**API:** `controllers/api/notification.php` — POST only untuk aksi yang mengubah state (mark_read, delete, delete_all) dengan verifikasi CSRF. Aksi read-only (unread_count, list) menerima GET.

---

**Adding Light Mode ke Halaman Baru:**
1. Gunakan CSS variables (`var(--meel-bg)`, `var(--meel-surface)`) alih-alih hardcoded colors
2. Jika pakai Tailwind hardcoded (`bg-[#0d1017]`), tambah override di `light-theme.css`
3. Logo/icon harus di-exclude dari color changes

---

### 26. MEeLCoin System (`modules/core/MeelCoin.php`)

Mata uang internal untuk membatasi upload per-user. Semua perubahan saldo memakai **satu statement `UPDATE` dengan guard saldo** — tanpa pola read-modify-write — sehingga potongan tidak bisa tertimpa request lain yang berjalan bersamaan.

**Database:** kolom `users.meelcoin` & `users.meelcoin_last_refill`, tabel `site_settings` (kunci `meelcoin_*`), tabel `meelcoin_log` (audit kredit/debit).

```php
class MeelCoin {
    public static function spend(mysqli $conn, int $userId, int $amount, string $reason): array; // [ok, error]
    public static function refund(mysqli $conn, int $userId, int $amount, string $reason): bool;
    public static function refill(mysqli $conn, int $userId, string $role): bool;
    public static function getRefillCountdown(mysqli $conn, int $userId, string $role): int;     // 0 = siap refill
}
```

| Aturan | Implementasi |
|---|---|
| Potong saldo | `SET meelcoin = meelcoin - ? WHERE id = ? AND meelcoin >= ?` — 0 baris terpengaruh = saldo kurang |
| Refund | `SET meelcoin = meelcoin + ?` (ditambah dari nilai DB terkini) |
| Refill | Siklus per-user: grant dibatasi `LEAST(max, meelcoin + refill)` di DB. Jendela refill dimulai saat saldo **turun di bawah maksimum** (yaitu saat spend), jadi refill tidak bisa menumpuk dan tidak menutup potongan upload berikutnya |
| Countdown | Mengikuti `meelcoin_last_refill` user tersebut, diukur dengan `TIMESTAMPDIFF(..., NOW())` — jam database di kedua sisi, jadi nilainya tidak melenceng terhadap waktu server. `0` = siap refill |
| Biaya | Wajib ≥ 1 (ditegakkan panel admin). Biaya ≤ 0 → alur coin dilewati di runtime |
| Admin | Dikecualikan dari pemotongan (`$is_admin`) dan dari dropdown penyesuaian manual |
| Refund `upload_advanced.php` | Hanya untuk sentinel gagal eksplisit (`''`, `DISCONNECTED`, `Download gagal*`, `File audio tidak ditemukan*`); hasil tak dikenal tidak direfund + `error_log` |
| Refund antrean gagal | Dijalankan **di momen kegagalan**: `modules/core/helpers/upload.php` (`upload_failed_refund` — download/upload) dan `transcode.php` (`transcode_refund` — transcode). Idempoten via `reason` unik |

**Status antrean & PID (`modules/transcoder/DownloadService.php`):**

- Status ditulis jujur oleh `releaseQueue()` **setelah** `finalizeVideo()`/`finalizeMusic()` sukses (`DONE:…` / `ENCODE_MUSIC:…` → `completed`; selain itu → `failed`). Enum `upload_queue.status` hanya `processing/completed/failed` — tidak ada nilai `orphaned`.
- Class `QueueReconciler` **dihapus**: nol panggilan produksi, `checkDownloadedFile()` mengabaikan argumennya (menandai antrean gagal sebagai `completed` berdasarkan file temp siapa pun), dan ia menulis/mencari status `orphaned` yang di luar enum. Tak ada sweeper global untuk antrean `processing` basi — bila diperlukan, buat job CLI (rencana T16/T17), bukan penebak file temp.
- PID file memakai taskType `transcode_dl_{upload_queue_id}` (bukan `transcode`): `transcode` dipakai `TranscodeService` dengan id dari `transcode_queue` — tabel berbeda → rentang id berbeda → tabrakan kunci bila nama sama. `queue_id` asli (bukan `0`) membuat antrean paralel tidak saling menimpa file pid.

**Sinkronisasi UI:** `meelRefreshCoinBalance()` (`assets/js/engine/result.js`) menyegarkan elemen `#coin-balance` setelah overlay `meelDone`/`meelError` — halaman tidak di-render ulang oleh overlay.

---

## Media Pipeline

### Video Pipeline

```
Upload → FFmpeg Transcode → HLS (.m3u8 + .ts)
                                ↓
                          Sprite Generator
                                ↓
                         VTT Thumbnails
                                ↓
                         Move to HDD
                                ↓
                          DB Insert
```

#### Kartu video (`video/cards.php`)

Satu berkas, dua fungsi, dan keduanya **tidak bisa ditukar**:

| Fungsi | Dipakai di |
|---|---|
| `meel_render_video_card()` | kartu grid di beranda/library video |
| `meel_render_video_rec_card()` | kartu sidebar rekomendasi di `video/watch.php` |

Pola ini sengaja meniru `music/cards.php`. Markup sidebar sebelumnya disalin
mentah di dua tempat (`video/watch.php` dan `video/search_video.php`), dan dua
berkas terpisah dengan `include` hanya memindahkan kontrak implisit "isi
variabel ini dulu sebelum include" ke tempat lain — variabelnya tetap bocor ke
scope pemanggil. Fungsi membuat kontraknya eksplisit lewat argumen.

Dua sumber data memberi nama kolom berbeda untuk username yang sama:
`MediaViewer::getRecommendations()` menyelect `u.username AS uploader`,
sedangkan `MediaLibrary::searchVideo()` menyelect
`u.username AS uploader_name`. Dua-duanya `users.username`, jadi di sini
keduanya dibaca.

### Audio Pipeline

```
Upload/Download → FFmpeg Encode → Opus (.ogg)
                                      ↓
                            Thumbnail Extraction
                                (ID3 → JPG)
                                      ↓
                               DB Insert
```

### Download URL Pipeline

```
URL Input → yt-dlp Metadata → Download → Type Check
                                            ↓
                              ┌──────────────┴──────────────┐
                              ↓                             ↓
                          Video                         Music
                              ↓                             ↓
                     FFmpeg HLS                    FFmpeg Opus
                     (codec copy)                   (libopus)
                              ↓                             ↓
                      Sprite + VTT                  Cover Art
                              ↓                             ↓
                         DB Insert                    DB Insert
```

---

## Autentikasi Flow

```
Request → auth.php
  ↓
Session exists? → Tidak → Redirect ke login.php
  ↓ Ya
Validasi last_session_id
  ↓
Berbeda? → Ya → Session Destroy → Redirect ke /err/?code=revoked
  ↓ Tidak
Update last_activity
  ↓
Lanjutkan ke halaman yang diminta
```

### Login Flow

```
POST login
  ↓
Verify CSRF token
  ↓
Validasi username & password
  ↓
Gagal 5x? → Lock 5 menit
  ↓ Berhasil
Cek MFA (mfa_enabled)
  ↓
Aktif? → Simpan mfa_temp_uid → Redirect ke mfa_verify.php
  ↓ Tidak
Set session variables (user_id, username, role)
  ↓
Update last_session_id
  ↓
Redirect ke index.php
```

### MFA Verification Flow

```
POST mfa_verify.php
  ↓
Rate limit: max 10 gagal, lock 5 menit
  ↓
Verifikasi TOTP 6 digit
  ↓
Gagal? → Increment fail count
  ↓ Valid
Set session lengkap (user_id, username, role)
  ↓
Set mfa_verified = true
  ↓
Hapus mfa_temp_uid dari session
  ↓
Redirect ke index.php
```

---

## 26. Modularisasi JS/CSS

MEeL menggunakan pendekatan modular untuk JavaScript dan CSS — setiap modul memiliki file-file terpisah yang dimuat secara dinamis.

### Struktur Direktori Assets

```
assets/
├── css/
│   ├── video/           # Video module CSS (13 file)
│   │   ├── autonext.css   # Overlay auto-next
│   │   ├── base.css     # Base styles
│   │   ├── cards.css    # Video card styles
│   │   ├── fullscreen.css # Fullscreen player
│   │   ├── glow.css     # Ambient glow effect
│   │   ├── layout.css   # Player layout
│   │   ├── mini-player.css # Floating mini player
│   │   ├── navbar.css   # Video navbar
│   │   ├── player.css   # Plyr overrides
│   │   ├── resume-modal.css # Modal resume playback
│   │   ├── seek.css     # Seek indicator
│   │   ├── toast.css    # Toast notifications
│   │   ├── utility.css  # Utility classes
│   │   └── watch/       # Watch page specific
│   ├── profile/         # Profile module CSS (10 file)
│   │   ├── base.css     # Base profile styles
│   │   ├── cards.css    # Media cards
│   │   ├── coin.css     # MEeLCoin display
│   │   ├── edit.css     # Edit profile
│   │   ├── manage.css   # Profile management
│   │   ├── notification.css # Notification settings
│   │   ├── stat.css     # Statistics
│   │   ├── mfa-switch.css # MFA toggle
│   │   ├── type-badge.css # User role badges
│   │   └── empty-state.css # Empty state displays
│   ├── admin/           # Admin module CSS (6 file)
│   │   ├── activity_log.css # Activity log viewer
│   │   ├── chat.css     # Admin chat styles
│   │   ├── catur.css    # Chess admin
│   │   ├── index.css    # Admin dashboard
│   │   ├── mfa_reset.css # Halaman reset MFA
│   │   └── stats.css    # Halaman statistik
│   ├── music/           # Music module CSS (10 file)
│   │   ├── base.css     # Base music styles
│   │   ├── cards.css    # Music card styles
│   │   ├── layout.css   # Music layout
│   │   ├── mini-player.css # Music mini player
│   │   ├── player.css   # Music player
│   │   ├── playlist.css # Playlist styles
│   │   ├── playlist-modal.css # Modal playlist
│   │   ├── resume-modal.css # Modal resume playback
│   │   ├── utility.css  # Utility classes
│   │   └── visualizer.css # Audio visualizer
│   ├── books/           # Books module CSS (6 file)
│   │   ├── base.css     # Base books styles
│   │   ├── cards.css    # Book card styles
│   │   ├── manga.css    # Manga reader
│   │   ├── pdf.css      # PDF reader
│   │   ├── reader.css   # Book reader base
│   │   └── utility.css  # Utility classes
│   └── shared/          # Shared CSS (7 file)
│       ├── comment.css  # Comment section
│       ├── design-tokens.css # Design tokens
│       ├── light-theme.css  # Light mode overrides
│       ├── nav.css      # Navigation bar
│       ├── notification.css # Notification styles
│       ├── theme-tokens.css # Theme CSS variables
│       └── upload-form.css  # Upload form styles
├── js/
│   ├── video/watch/     # Video watch page JS (12 files)
│   │   ├── state.js     # Global state variables
│   │   ├── lifecycle.js # Page lifecycle management
│   │   ├── player-init.js # Player initialization
│   │   ├── player-events.js # Player event handlers + aspect ratio
│   │   ├── recovery.js  # Error recovery & stuck detector
│   │   ├── mini-player.js # Floating mini player
│   │   ├── gestures.js  # Touch gestures
│   │   ├── search.js    # Search functionality
│   │   ├── seek-indicator.js # Seek visual feedback
│   │   ├── vtt-sprites.js # VTT sprite thumbnails
│   │   └── misc.js      # Miscellaneous utilities
│   ├── shared/          # Shared JS (21 files)
│   │   ├── nav.js       # Navigation behavior
│   │   ├── theme.js     # Theme toggle
│   │   ├── keyboard.js  # Keyboard shortcuts
│   │   ├── comment.js   # Comment section
│   │   ├── notification.js # Notification system
│   │   ├── plyr-config.js # Plyr configuration
│   │   ├── format-time.js # Time formatting
│   │   ├── resume-modal.js # Resume playback modal
│   │   ├── recovery-manager.js # Recovery factory: stuck detector, waiting timeout, reconnect overlay
│   │   ├── media-session.js # Media Session API: OS media controls artwork/metadata
│   │   ├── index-hub.js # Homepage hub
│   │   └── ...          # Other shared utilities
│   ├── profile/         # Profile JS (5 files)
│   │   ├── manage.js    # Profile management
│   │   ├── avatar-crop.js # Avatar cropping
│   │   ├── coin-countdown.js # MEeLCoin countdown
│   │   └── theme-init.js # Theme initialization
│   ├── admin/           # Admin JS (3 files)
│   │   ├── activity_log.js # Activity log viewer
│   │   └── chat/        # Admin chat
│   ├── music/           # Music JS
│   │   └── watch/       # Music watch page JS
│   │       ├── player-core.js # Music player core (visualizer, EQ, Media Session)
│   │       ├── description-toggle.js # Toggle "Selengkapnya" untuk deskripsi music
│   │       └── ...
│   └── books/           # Books JS
│       └── read/reader.js # Book reader
```

### Pemetaan CSS per Module

Setiap modul CSS memiliki `manifest.php` yang mendaftarkan file-file CSS-nya. `SwPrecache` membaca manifest ini untuk menghasilkan daftar precache service worker secara otomatis.

```php
// assets/css/video/manifest.php
return ['base.css', 'cards.css', 'fullscreen.css', 'glow.css', 'layout.css',
        'mini-player.css', 'navbar.css', 'player.css', 'seek.css', 'toast.css'];
```

#### Bundle CSS per modul

`scripts/build-css-bundle.php` menggabungkan file-file yang terdaftar di
`manifest.php` menjadi satu `assets/css/<module>/bundle.css`.

- **Manifest tetap daftar utama.** Satu kebenaran; file asli tidak dihapus,
  jadi halaman yang belum memakai bundle tetap jalan.
- **Urutan file dijaga persis** seperti di manifest, karena urutan CSS
  menentukan kaskade — `light-theme.css` harus tetap paling akhir.
- **Bundle tidak di-commit.** `assets/css/*/bundle.css` ada di `.gitignore`,
  jadi clone baru otomatis jatuh ke daftar file individual.

```bash
php scripts/build-css-bundle.php --dry-run   # lihat hasilnya tanpa menulis
php scripts/build-css-bundle.php
```

### Loader Dinamis

JavaScript dimuat secara dinamis oleh `main.js` di setiap halaman modul. File-file JS di-load berurutan sesuai dependency:

```javascript
// Contoh: video/watch/main.js memuat script secara berurutan
const scripts = [
    'state.js', 'recovery.js', 'player-init.js', 'player-events.js',
    'lifecycle.js', 'mini-player.js', 'gestures.js', 'vtt-sprites.js',
    'seek-indicator.js', 'misc.js'
];
```

Halaman watch music juga menggunakan view-router (`shared/view-router.js`) untuk navigasi SPA. Script yang terdaftar di `DIRECT_SCRIPTS` (mis. `recovery-manager.js`, `description-toggle.js`) dimuat via `loadScriptOnce()` saat transisi SPA — memastikan global seperti `meelCreateReconnectOverlay` tersedia saat `player-core.js` dieksekusi.

---

## 27. Adaptive Aspect Ratio Player

Player video MEeL mendukung adaptive aspect ratio untuk berbagai format video (4:3, 16:9, 21:9, portrait).

### Cara Kerja

1. **Placeholder:** Server merender wrapper dengan `style="aspect-ratio: 16/9;"` sebagai default
2. **Runtime:** Saat video metadata loaded, JavaScript `applyMeelVideoAspect()` mengubah aspect-ratio sesuai dimensi aktual
3. **Constraint:** Video non-16:9 mendapat `max-width` proporsional agar height setara 16:9

### Logika `applyMeelVideoAspect(wrapper, videoW, videoH)`

| Kondisi | Behavior |
|---|---|
| Portrait (videoW < videoH) | `max-height: 80vh`, width auto, center horizontal |
| Landscape < 16:9 (4:3, 5:4, 1:1) | `max-width: calc(100% × 9 × videoW / (16 × videoH))`, center |
| 16:9 atau lebih lebar (21:9) | Full width, height natural |

### Contoh Perhitungan untuk 4:3

```
max-width = calc(100% × 9 × 4 / (16 × 3))
         = calc(100% × 36/48)
         = 75% dari parent width
```

Pada parent 1000px:
- **4:3:** 750px × 562.5px (centered)
- **16:9:** 1000px × 562.5px (full width)

Keduanya memiliki height yang sama — video 4:3 lebih kecil dan centered, seperti YouTube.

### CSS Support

```css
/* player.css — mencegah stretching */
.plyr__video-wrapper video {
    object-fit: contain;
}
```

### File Terkait Video Player

| File | Peran |
|---|---|
| `assets/js/video/watch/player-events.js` | Fungsi `applyMeelVideoAspect()` |
| `assets/css/video/player.css` | `object-fit: contain` untuk video |
| `assets/css/video/watch/main.css` | Mobile max-height constraint |

---

## 28. Chat API

Sistem chat real-time antar user dengan HTMX polling.

### Endpoint

| Endpoint | Method | Deskripsi |
|---|---|---|
| `/api/chat` | GET | Ambil pesan chat (parameter: `after_id`, `limit`) |
| `/api/chat` | POST | Kirim pesan chat (parameter: `message`, `csrf_token`) |

### Database Schema

```sql
CREATE TABLE chat_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
```

### Fitur

- Pesan diurutkan berdasarkan `created_at` ASC
- HTMX polling setiap 3 detik untuk pesan baru
- Delete pesan sendiri (dengan validasi ownership)
- Notification type `admin_chat` untuk pesan ke admin
- Rate limiting: 30 pesan per menit per user

### File Terkait Chat

| File | Peran |
|---|---|
| `controllers/api/chat.php` | Chat API endpoint |
| `admin/chat.php` | Admin chat interface |
| `assets/js/shared/notification.js` | Polling notifikasi termasuk chat |
| `assets/css/admin/chat.css` | Admin chat styles |

---

## Arsitektur ProgressObserver

`Transcoder` adalah class **business-layer murni** — tidak pernah meng-echo HTML/JS. Progress dilaporkan sebagai event terstruktur ke `ProgressObserver`, sehingga engine yang sama berjalan bersih di browser, script CLI, cron, maupun endpoint API tanpa mencemari output buffer.

### File

| File | Peran |
|---|---|
| `modules/core/ProgressObserver.php` | Interface `ProgressObserver` + adapter `CallableProgressObserver` |
| `modules/core/BrowserProgressObserver.php` | Presenter browser: memetakan event ke overlay MEeL (`partials/ui.php`) + panggilan JS `meel*` |

### Penggunaan

```php
// CLI / cron / API — tanpa output sama sekali
$tc = new Transcoder($conn, $uid);

// Overlay streaming browser (upload_advanced.php, transcode.php)
require_once 'modules/core/BrowserProgressObserver.php';
$tc = new Transcoder($conn, $uid, new BrowserProgressObserver());

// Observer kustom atau callable mentah
$tc = new Transcoder($conn, $uid, function (string $stage, array $data): void {
    fwrite(STDERR, "[{$stage}] " . json_encode($data) . PHP_EOL);
});
```

### Kontrak event — `ProgressObserver::onProgress(string $stage, array $data)`

| Stage | Payload | Arti |
|---|---|---|
| `download_start` | `['url' => string]` | Unduh dimulai (titik injeksi overlay) |
| `transcode_start` | `[]` | Transcode dimulai (titik injeksi overlay) |
| `phase` | `['phase' => string]` | Pergantian fase overlay (`transcode`, `sprite`, ...) |
| `download_progress` | `['pct' => int]` + opsional `eta`, `speed`, `size`, `frag` | Progress yt-dlp |
| `transcode_progress` | `['pct' => int, 'label' => ?string]` | Progress FFmpeg |
| `sprite_progress` | `['pct' => int, 'label' => ?string]` | Progress sprite/VTT |
| `done` | `['title' => string, 'url' => string]` | Video selesai difinalisasi |
| `done_transcode` | `['title' => string, 'download_link' => string]` | Transcode audio selesai |
| `error` | `['message' => string]` | Error fatal yang ditampilkan ke user |

Tidak ada stage `redirect`. Observer versi lama punya case `redirect` yang
kosong untuk navigasi browser, tapi tidak pernah ada yang memancarkannya —
navigasi musik lewat string berawalan `REDIRECT:` yang ditangani di
`upload_advanced.php`, bukan lewat event progres. Case itu sudah dihapus.

Tiap stage ditangani method private sendiri, yang dijangkau lewat peta
`BrowserProgressObserver::STAGE_HANDLERS`. `onProgress()` hanya mencari
handler-nya, jadi stage yang dipancarkan tapi tak terpetakan akan hilang
diam-diam; `BrowserProgressObserverTest` membaca sumber transcoder dan
memastikan setiap stage yang dipancarkan punya handler.

**Jaminan:**
- Exception observer ditangkap dan di-log di dalam `emit()` — tidak pernah merambat ke pipeline media (tidak ada proses yatim atau file setengah pindah)
- Tanpa observer terpasang, `emit()` adalah no-op — nol polusi output buffer
- Download musik mengembalikan string berawalan `REDIRECT:` sehingga *caller* yang memutuskan kelanjutan (tidak ada `exit` di business layer)

---

## Konvensi Keamanan Filesystem (tanpa @)

Setelah audit engine pemrosesan media, codebase **tidak pernah memakai operator `@` (error suppression) pada operasi filesystem** (`unlink`, `rmdir`, `mkdir`, `copy`, `rename`, `fopen`, `scandir`, `file_put_contents`, ...). `@` yang serampangan menyembunyikan kegagalan permission/IO yang nyata — mis. USB HDD yang ter-mount read-only, atau folder temp milik proses lain — dan membuat debugging mustahil.

Setiap akses filesystem mengikuti tiga aturan:

1. **Cek keberadaan & permission secara proaktif** — guard dengan `is_file()`, `is_dir()`, `is_readable()`, `is_writable()` sebelum menyentuh filesystem. Ingat: `unlink`/`rmdir` butuh **parent directory yang writable**, bukan hanya file yang ada.
2. **Cek nilai balik** — perlakukan `false`/`null` sebagai kegagalan dan log via `error_log()` (atau logger khusus) termasuk path yang terlibat.
3. **Pakai helper bersama, bukan `@` inline** — gunakan helper terpusat di bawah daripada menebar suppression ad-hoc.

### Helper filesystem bersama

| Helper | Lokasi | Tujuan |
|---|---|---|
| `ensureDir()` | trait `FfmpegUtils` | Pembuatan ala `mkdir -p` dengan logging |
| `removeFile()` | trait `FfmpegUtils` | unlink ber-guard (keberadaan + parent writable) |
| `removeDir()` | trait `FfmpegUtils` | Pembersihan dir datar (glob → removeFile → rmdir) |
| `moveFile()` | trait `FfmpegUtils` | Pindah lintas-device dengan cek device `stat()` |
| `GarbageCollector::removeFile()` | `GarbageCollector.php` | unlink statis ber-guard |
| `GarbageCollector::removeDirectory()` | `GarbageCollector.php` | Pembersihan rekursif ber-guard (melewati subtree non-writable, `rmdir` hanya saat kosong) |
| `meel_write_cache_file()` | `helpers/storage.php` | Tulis cache **atomic** (file sementara + `rename()`), bukan `LOCK_EX` |
| `meel_read_cache_file()` | `helpers/storage.php` | Baca cache JSON + cek TTL + kunci wajib (satu-satunya implementasi di repo) |
| `meel_sweep_stale_cache_tmp()` | `helpers/storage.php` | Buang sisa `.tmp.*` dari proses yang mati saat menulis cache |

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
</div>
