# Referensi Fungsi MEeL

Daftar lengkap fungsi `meel_*()` yang tersedia di codebase MEeL-HUB.
Fungsi-fungsi ini dibuat untuk menghindari duplikasi kode dan menyediakan API terpusat yang konsisten.

---

## Daftar Isi

- [Autentikasi & Sesi](#autentikasi--sesi)
- [URL & Base Path](#url--base-path)
- [Storage & Media Path](#storage--media-path)
- [Upload & File Handling](#upload--file-handling)
- [FFmpeg & Encoding](#ffmpeg--encoding)
- [Database & Migration](#database--migration)
- [Admin Utilities](#admin-utilities)
- [Drive & Cloud Storage](#drive--cloud-storage)
- [Error Handling](#error-handling)
- [Testing & Mecab](#testing--mecab)

---

## Autentikasi & Sesi

### `meel_boot_session()`

Inisialisasi session PHP dengan konfigurasi keamanan yang tepat (httponly, samesite Lax, secure cookie jika HTTPS).

```php
function meel_boot_session(): void
```

**File:** `modules/auth/helpers/session.php:3`
**Dipanggil oleh:** Semua entry point yang memerlukan session (index, controller, API).
**Catatan:** Timeout session 12 jam (43200 detik). Hanya `session_start()` jika session belum aktif.

---

## URL & Base Path

### `meel_base_url_path()`

Mengembalikan base URL path relatif dari project root (mis. `/MEeL` atau `/` jika di document root).

```php
function meel_base_url_path(): string
```

**File:** `modules/core/base_url.php:6`
**Return:** String path relatif, tanpa trailing slash.
**Contoh:** `"/MEeL"` atau `""` jika project ada di document root.

---

## Storage & Media Path

### `meel_media_base_path(string $module)`

Mengembalikan path absolut folder upload media berdasarkan modul (`video`, `music`, `books`).

```php
function meel_media_base_path(string $module): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$module` | `string` | Nama modul: `'video'`, `'music'`, atau `'books'` |

**File:** `modules/core/helpers/storage.php:4`
**Return:** Path absolut ke folder upload modul.
**Logika:**
- Cek konstanta `MEEL_HDD_*_UPLOAD` (mis. `MEEL_HDD_VIDEO_UPLOAD`)
- Jika terdefinisi dan non-kosong → kembalikan path HDD
- Jika tidak → fallback ke `<root>/<module>/upload`

**Contoh:**
```php
$video_path = meel_media_base_path('video');
// → '/media/user/MEeL/media/video/upload' atau '/opt/lampp/htdocs/MEeL/video/upload'
```

---

### `meel_drive_base_path(?string $hddDriveOverride = null)`

Mengembalikan path absolut folder storage Drive (public + private_admins).

```php
function meel_drive_base_path(?string $hddDriveOverride = null): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$hddDriveOverride` | `?string` | Override path HDD (opsional, default: gunakan `MEEL_HDD_DRIVE`) |

**File:** `modules/core/helpers/storage.php:73`
**Return:** Path absolut ke folder Drive.
**Logika:**
- Jika `$hddDriveOverride` atau `MEEL_HDD_DRIVE` terdefinisi → kembalikan path HDD
- Jika tidak → fallback ke `<root>/data_drive`

---

### `meel_serve_media_file(string $module, string $relPath, array $opts = [])`

Menyajikan file media dengan Range support (HTTP 206), referer gate untuk HLS, dan validasi keamanan.

```php
function meel_serve_media_file(string $module, string $relPath, array $opts = []): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$module` | `string` | Nama modul: `'video'`, `'music'`, `'books'` |
| `$relPath` | `string` | Path relatif file dalam folder upload |
| `$opts` | `array` | Opsi tambahan: `'hls_gate' => true` untuk referer gate |

**File:** `modules/core/helpers/storage.php:246`
**Return:** void (langsung mengirim response dan `exit`).
**Fitur:**
- Validasi path traversal (`..`, null byte)
- Whitelist ekstensi (m3u8, ts, vtt, mp4, webm, mkv, jpg, png, webp, gif, pdf, mp3, ogg, m4a, flac, wav, opus)
- MIME type mapping otomatis
- Range request support (206 Partial Content) untuk HLS `.ts` dan video besar
- Referer gate untuk video HLS (anti-hotlink)
- Pembersihan output buffer (`ob_end_clean` + `ob_implicit_flush`) sebelum streaming — mencegah korupsi data biner

---

### `meel_write_cache_file(string $path, string $content)`

Menulis file cache secara atomic dengan `LOCK_EX` (mencegah race condition).

```php
function meel_write_cache_file(string $path, string $content): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$path` | `string` | Path lengkap file cache |
| `$content` | `string` | Isi konten yang akan ditulis |

**File:** `modules/core/helpers/storage.php:196`
**Return:** void.
**Catatan:** Jika direktori tidak writable, log error ke error_log.

---

## Upload & File Handling

### `meel_read_magic_bytes(string $path, int $length = 16)`

Membaca bytes pertama dari file untuk identifikasi tipe file (magic bytes).

```php
function meel_read_magic_bytes(string $path, int $length = 16): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$path` | `string` | Path file yang akan dibaca |
| `$length` | `int` | Jumlah bytes yang dibaca (default: 16) |

**File:** `modules/core/helpers/upload.php:12`
**Return:** String berisi bytes (binary), atau `''` jika gagal dibaca.

---

### `meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio')`

Memvalidasi magic bytes file terhadap ekstensi dan jenis media yang diizinkan.

```php
function meel_magic_extension_ok(string $path, string $ext, string $mediaKind = 'audio'): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$path` | `string` | Path file yang akan divalidasi |
| `$ext` | `string` | Ekstensi file (tanpa titik) |
| `$mediaKind` | `string` | Jenis media: `'video'`, `'audio'`, `'image'`, `'pdf'`, `'archive'` |

**File:** `modules/core/helpers/upload.php:31`
**Return:** `''` (kosong) jika valid; pesan error jika tidak valid.
**Supported media kinds:**
- `video` — Matroska/WebM, MP4/MOV
- `audio` — Ogg/Opus, FLAC, WAV, MP3, MP4/M4A
- `image` — JPEG, PNG, WebP, GIF
- `pdf` — PDF
- `archive` — ZIP

---

### `meel_sanitize_upload_filename(string $original, string $fallback = 'file')`

Sanitasi nama file upload menjadi nama file fisik yang aman.

```php
function meel_sanitize_upload_filename(string $original, string $fallback = 'file'): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$original` | `string` | Nama file asli dari user |
| `$fallback` | `string` | Nama fallback jika hasil kosong (default: `'file'`) |

**File:** `modules/core/helpers/upload.php:203`
**Return:** Nama file bersih (hanya `[a-zA-Z0-9._-]`), tanpa path separator, tanpa null byte, tanpa traversal.
**Catatan:** Nama asli user tetap bisa disimpan sebagai metadata terpisah.

---

### `meel_sanitize_clean_name(string $raw, int $max_len = 120)`

Sanitasi nama dasar (tanpa ekstensi) menjadi karakter aman untuk nama file media.

```php
function meel_sanitize_clean_name(string $raw, int $max_len = 120): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$raw` | `string` | Nama mentah yang akan dibersihkan |
| `$max_len` | `int` | Panjang maksimum hasil (default: 120) |

**File:** `modules/core/helpers/upload.php:265`
**Return:** Nama bersih, atau `''` jika hasil kosong.
**Catatan:** Mengganti karakter non-aman dengan `_`, memotong ke `$max_len`.

---

### `meel_reserve_unique_filename(string $dir, string $clean_name, string $ext, int $max_attempts = 1000, string $suffix_sep = '-')`

Alokasi nama file unik secara atomik menggunakan `fopen(..., 'x')` (O_EXCL).

```php
function meel_reserve_unique_filename(string $dir, string $clean_name, string $ext, int $max_attempts = 1000, string $suffix_sep = '-'): ?string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$dir` | `string` | Folder tujuan |
| `$clean_name` | `string` | Nama bersih (tanpa ekstensi) |
| `$ext` | `string` | Ekstensi file (tanpa titik) |
| `$max_attempts` | `int` | Maksimal percobaan (default: 1000) |
| `$suffix_sep` | `string` | Pemisah suffix (default: `'-'`) |

**File:** `modules/core/helpers/upload.php:290`
**Return:** Nama file unik yang berhasil di-reserve, atau `null` jika semua gagal.
**Fitur:**
- Placeholder kosong dibuat lebih dulu → pemanggil menimpa dengan `move_uploaded_file` / `ffmpeg -y`
- Anti race condition: dua request bersamaan tidak mungkin memilih nama yang sama
- Contoh hasil: `lagu-ku.opus`, `lagu-ku-1.opus`, `lagu-ku-2.opus`, ...

---

### `meel_allocate_unique_dir(string $parent, string $base)`

Alokasi nama folder unik (suffix `-1`, `-2`, ...) di dalam parent.

```php
function meel_allocate_unique_dir(string $parent, string $base): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$parent` | `string` | Folder induk |
| `$base` | `string` | Nama dasar folder |

**File:** `modules/core/helpers/upload.php:359`
**Return:** Nama folder unik tanpa trailing slash.
**Contoh:** `meel_allocate_unique_dir('/tmp', 'encode')` → `'encode'` atau `'encode-1'` atau `'encode-2'`, ...

---

### `meel_upload_allowed_table(string $table)`

Memvalidasi nama tabel yang diizinkan untuk operasi upload.

```php
function meel_upload_allowed_table(string $table): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$table` | `string` | Nama tabel yang akan divalidasi |

**File:** `modules/core/helpers/upload.php:215`
**Return:** `string` nama tabel jika valid (`'music'` atau `'video'`), atau `''` jika tidak valid.

---

### `meel_insert_music_row(\mysqli $conn, ...)`

Insert baris musik baru ke database (satu-satunya jalur INSERT untuk music).

```php
function meel_insert_music_row(
    \mysqli $conn,
    int $user_id,
    string $title,
    string $artist,
    string $album,
    string $description,
    string $search_metadata,
    string $filename,
    string $thumbnail,
    ?int $duration = null
): array
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$user_id` | `int` | ID user pemilik |
| `$title` | `string` | Judul musik |
| `$artist` | `string` | Nama artis |
| `$album` | `string` | Nama album |
| `$description` | `string` | Deskripsi |
| `$search_metadata` | `string` | Metadata untuk pencarian |
| `$filename` | `string` | Nama file audio |
| `$thumbnail` | `string` | Nama file thumbnail |
| `$duration` | `?int` | Durasi dalam detik (opsional) |

**File:** `modules/core/helpers/upload.php:430`
**Return:** `[bool $ok, string $error]` — `$ok = true` jika berhasil, `$error` berisi pesan mysqli jika gagal.
**Catatan:** Kolom `duration` hanya disertakan jika != null (perilaku lama dijaga).

---

## FFmpeg & Encoding

### `meel_validate_video_codec(string $file_path, string $ffprobe_bin = '/usr/bin/ffprobe', string $env_prefix = '')`

Validasi codec video via ffprobe sebelum upload — hanya H.264 (AVC) dan H.265 (HEVC) yang diizinkan (wajib untuk stream-copy HLS MPEG-TS).

```php
function meel_validate_video_codec(
    string $file_path,
    string $ffprobe_bin = '/usr/bin/ffprobe',
    string $env_prefix = ''
): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$file_path` | `string` | Path file video yang akan divalidasi |
| `$ffprobe_bin` | `string` | Path biner ffprobe (default: `'/usr/bin/ffprobe'`) |
| `$env_prefix` | `string` | Prefix env (mis. `'export LD_LIBRARY_PATH=''; '`) |

**File:** `modules/core/helpers/upload.php:90`
**Return:** `''` jika valid; pesan error jika tidak valid.
**Catatan:** Codec lain (VP9, AV1, dll.) ditolak karena tidak dapat di-copy ke HLS MPEG-TS.

**Dipakai oleh:** `modules/core/Uploader.php` (pipeline upload video)

---

### `meel_validate_audio_codec(string $file_path, string $ffprobe_bin = '/usr/bin/ffprobe', string $env_prefix = '')`

Validasi kompatibilitas codec audio dengan HLS MPEG-TS lama — memutuskan antara stream-copy dan transcode ke AAC, atau menolak file.

```php
function meel_validate_audio_codec(
    string $file_path,
    string $ffprobe_bin = '/usr/bin/ffprobe',
    string $env_prefix = ''
): array
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$file_path` | `string` | Path file video yang akan divalidasi |
| `$ffprobe_bin` | `string` | Path biner ffprobe (default: `'/usr/bin/ffprobe'`) |
| `$env_prefix` | `string` | Prefix env |

**File:** `modules/core/helpers/upload.php:145`
**Return:** `['error' => string, 'has_audio' => bool, 'needs_audio_transcode' => bool]`
- `error` — pesan penolakan; `''` jika file lolos
- `has_audio` — `true` jika video memiliki audio stream
- `needs_audio_transcode` — `true` jika audio harus di-transcode ke AAC
**Aturan:**
- Kompatibel (stream-copy): AAC, MP3, AC3, E-AC3
- Tidak kompatibel (Opus, Vorbis, DTS, FLAC, ...) dengan durasi > 5 menit → ditolak (transcode audio berdurasi panjang terlalu memberatkan server)

**Dipakai oleh:** `modules/core/Uploader.php` (pipeline upload video)

---

### `meel_ffmpeg_thumbnail_webp(string $ffmpeg_bin, string $src, string $dst, int $max_width, string $extra = '', string $env_prefix = '', int $threads = 0)`

Konversi gambar/frame menjadi WebP via ffmpeg (satu-satunya jalur konversi thumbnail).

```php
function meel_ffmpeg_thumbnail_webp(
    string $ffmpeg_bin,
    string $src,
    string $dst,
    int $max_width,
    string $extra = '',
    string $env_prefix = '',
    int $threads = 0
): bool
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$ffmpeg_bin` | `string` | Path biner ffmpeg |
| `$src` | `string` | File sumber (gambar, audio, video) |
| `$dst` | `string` | Path output `.webp` |
| `$max_width` | `int` | Lebar maksimal (min scale, iw) |
| `$extra` | `string` | Argumen tambahan (mis. `'-ss 00:00:05'` atau `'-an -vframes 1'`) |
| `$env_prefix` | `string` | Prefix env (mis. `'export LD_LIBRARY_PATH=''; '`) |
| `$threads` | `int` | Nilai `-threads` ffmpeg (0 = default) |

**File:** `modules/core/helpers/upload.php:327`
**Return:** `true` jika file output terbentuk dan berisi data.
**Contoh:**
```php
$ok = meel_ffmpeg_thumbnail_webp(
    '/usr/bin/ffmpeg',
    '/path/to/video.mp4',
    '/path/to/thumb.webp',
    320,
    '-ss 00:00:05 -an -vframes 1'
);
```

---

### `meel_ffmpeg_encode_opus(string $ffmpeg_bin, string $input, string $output, string $env_prefix = '', int $threads = 0, array $metadata = [])`

Encoding audio → Opus/Ogg via ffmpeg (satu-satunya jalur encoding Opus).

```php
function meel_ffmpeg_encode_opus(
    string $ffmpeg_bin,
    string $input,
    string $output,
    string $env_prefix = '',
    int $threads = 0,
    array $metadata = []
): array
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$ffmpeg_bin` | `string` | Path biner ffmpeg |
| `$input` | `string` | File sumber audio |
| `$output` | `string` | Path output `.ogg` |
| `$env_prefix` | `string` | Prefix env |
| `$threads` | `int` | Nilai `-threads` ffmpeg (0 = default) |
| `$metadata` | `array` | Tag metadata `['title' => ..., 'artist' => ...]` |

**File:** `modules/core/helpers/upload.php:394`
**Return:** `[int $exit_code, string $log]` — log berisi gabungan stdout+stderr untuk pesan error.
**Contoh:**
```php
[$code, $log] = meel_ffmpeg_encode_opus(
    '/usr/bin/ffmpeg',
    '/path/to/input.mp3',
    '/path/to/output.ogg',
    '',
    2,
    ['title' => 'Judul Lagu', 'artist' => 'Nama Artis']
);
```

---

### `meel_handle_upload(string $media_type, callable $process_fn, string $log_action): array`

Handler upload terpusat — cek CSRF, spend/refund MeelCoin, callback proses, dan logging aktivitas untuk upload video & music.

```php
function meel_handle_upload(string $media_type, callable $process_fn, string $log_action): array
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$media_type` | `string` | `'video'` atau `'music'` |
| `$process_fn` | `callable` | `fn($_POST, $_FILES) → ['status'=>'success'|'error', 'id'=>int?, 'msg'=>string]` |
| `$log_action` | `string` | Nama aksi untuk `log_activity()` |

**File:** `modules/core/helpers/upload.php:486`
**Return:** `['status' => 'success'|'', 'alert_message' => string, 'extra' => array]`
- `extra['coin_balance']` — saldo MeelCoin terbaru (saat MeelCoin aktif)
- `extra['hour_count']` — jumlah upload per jam (saat MeelCoin nonaktif)
- `extra['total_uploads']` — total jumlah upload

**Dipakai oleh:** `video/upload.php`, `music/upload.php`

---

### `meel_asset_version(string $file): string`

Mengembalikan query string `?v=<filemtime>` untuk cache-busting. Menggunakan static cache untuk menghindari syscalls `filemtime()` berulang.

```php
function meel_asset_version(string $file): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$file` | `string` | Path file relatif dari project root (mis. `'assets/js/shared/media-session.js'`) |

**File:** `modules/core/helpers/url.php:108`
**Return:** String `?v=<unix_timestamp>`.

---

### `meel_asset_dir_version(string $dir): string`

Mengembalikan `?v=<max_mtime>` untuk file `.js` terbaru dalam sebuah direktori. Digunakan untuk cache-busting bundle.

```php
function meel_asset_dir_version(string $dir): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$dir` | `string` | Path direktori relatif dari project root (mis. `'assets/js/music/watch'`) |

**File:** `modules/core/helpers/url.php:120`
**Return:** String `?v=<unix_timestamp>`. Menggunakan glob + static cache.

---

## Database & Migration

### `meel_mig_has_column(\mysqli $conn, string $table, string $col)`

Memeriksa apakah kolom tertentu ada di tabel (untuk migrasi database).

```php
function meel_mig_has_column(\mysqli $conn, string $table, string $col): bool
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$col` | `string` | Nama kolom |

**File:** `database/migrate.php:14`
**Return:** `true` jika kolom ada.
**Catatan:** Identifiers berasal dari daftar migrasi internal (hardcoded, bukan input user).

---

### `meel_mig_has_index(\mysqli $conn, string $table, string $index)`

Memeriksa apakah index tertentu ada di tabel (untuk migrasi database).

```php
function meel_mig_has_index(\mysqli $conn, string $table, string $index): bool
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$index` | `string` | Nama index |

**File:** `database/migrate.php:22`
**Return:** `true` jika index ada.

---

### `meel_mig_add_index(\mysqli $conn, string $table, string $index, string $cols)`

`ADD INDEX` idempoten — otomatis dilewati jika index sudah ada.

```php
function meel_mig_add_index(\mysqli $conn, string $table, string $index, string $cols): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$index` | `string` | Nama index |
| `$cols` | `string` | Daftar kolom (mis. `'user_id, created_at'`) |

**File:** `database/migrate.php:30`
**Return:** void.
**Catatan:** Error duplicate/already-exists diabaikan; kegagalan lain dicetak sebagai warning CLI (`[MEeL] ⚠ Warning`).

---

### `meel_mig_add_fulltext(\mysqli $conn, string $table, string $index, string $cols)`

`ADD FULLTEXT INDEX` idempoten — untuk index pencarian FULLTEXT.

```php
function meel_mig_add_fulltext(\mysqli $conn, string $table, string $index, string $cols): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$index` | `string` | Nama index |
| `$cols` | `string` | Daftar kolom (mis. `'title, description'`) |

**File:** `database/migrate.php:44`
**Return:** void.
**Catatan:** Perilaku idempoten sama dengan `meel_mig_add_index`.

---

### `meel_mig_add_unique(\mysqli $conn, string $table, string $index, string $cols)`

`ADD UNIQUE INDEX` idempoten — untuk constraint unik.

```php
function meel_mig_add_unique(\mysqli $conn, string $table, string $index, string $cols): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$index` | `string` | Nama index |
| `$cols` | `string` | Daftar kolom (mis. `'username'`) |

**File:** `database/migrate.php:58`
**Return:** void.
**Catatan:** Perilaku idempoten sama dengan `meel_mig_add_index`.

---

### `meel_mig_add_fk(\mysqli $conn, string $table, string $constraint, string $fk_def)`

Menambahkan foreign key constraint — error duplicate diabaikan.

```php
function meel_mig_add_fk(\mysqli $conn, string $table, string $constraint, string $fk_def): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$conn` | `\mysqli` | Koneksi database |
| `$table` | `string` | Nama tabel |
| `$constraint` | `string` | Nama constraint |
| `$fk_def` | `string` | Definisi FK (mis. `'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'`) |

**File:** `database/migrate.php:72`
**Return:** void.
**Catatan:** Tidak ada pre-check keberadaan (beda dengan helper `add_*` lain) — duplicate difilter oleh pemeriksaan error yang sama.

---

### `meel_arc_mig_has_column(\mysqli $conn, string $table, string $col)`

Versi Arcade dari `meel_mig_has_column` — memeriksa kolom untuk migrasi Arcade.

```php
function meel_arc_mig_has_column(\mysqli $conn, string $table, string $col): bool
```

**File:** `arcade/migrate.php:14`

---

### `meel_arc_mig_has_index(\mysqli $conn, string $table, string $index)`

Versi Arcade dari `meel_mig_has_index` — memeriksa index untuk migrasi Arcade.

```php
function meel_arc_mig_has_index(\mysqli $conn, string $table, string $index): bool
```

**File:** `arcade/migrate.php:22`

---

## Admin Utilities

### `meel_admin_remove_dir(string $dir, int &$counter, int &$failed)`

Hapus direktori secara rekursif (untuk orphan cleaner admin).

```php
function meel_admin_remove_dir(string $dir, int &$counter, int &$failed): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$dir` | `string` | Path direktori yang akan dihapus |
| `$counter` | `int&` | Referensi counter file yang berhasil dihapus |
| `$failed` | `int&` | Referensi counter file yang gagal dihapus |

**File:** `controllers/admin/admin_actions.php:129`
**Return:** void.
**Catatan:** Menghapus isi terlebih dahulu, lalu direktori kosong. Log error ke error_log jika gagal.

---

### `meel_admin_clean_empty_parents(string $path, array $stop_dirs)`

Hapus direktori induk kosong secara rekursif (sampai ke salah satu `$stop_dirs`).

```php
function meel_admin_clean_empty_parents(string $path, array $stop_dirs): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$path` | `string` | Path file/folder yang baru dihapus |
| `$stop_dirs` | `array` | Array path di mana berhenti menghapus |

**File:** `controllers/admin/admin_actions.php:156`
**Return:** void.
**Contoh:**
```php
meel_admin_clean_empty_parents(
    '/path/to/deleted/file.mp3',
    ['/opt/lampp/htdocs/MEeL/video/upload/video']
);
```

---

### `meel_remove_media_dir(string $dir, int &$counter, array &$failed)`

Hapus direktori media secara rekursif (untuk admin stats/delete).

```php
function meel_remove_media_dir(string $dir, int &$counter, array &$failed): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$dir` | `string` | Path direktori media |
| `$counter` | `int&` | Referensi counter file yang berhasil dihapus |
| `$failed` | `array&` | Referensi array nama file yang gagal dihapus |

**File:** `admin/stats.php:36`
**Return:** void.
**Catatan:** Mirip `meel_admin_remove_dir` tetapi menggunakan array `$failed` (bukan int) untuk tracking file yang gagal.

---

## Drive & Cloud Storage

### `log_drive_operation(int $userId, string $username, string $operation, string $filename, string $type, string $scope, string $status = 'success')`

Menulis audit log operasi Drive (upload, download, delete, dll.) dalam format JSON.

```php
function log_drive_operation(
    int $userId,
    string $username,
    string $operation,
    string $filename,
    string $type,
    string $scope,
    string $status = 'success'
): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$userId` | `int` | ID user |
| `$username` | `string` | Username |
| `$operation` | `string` | Jenis operasi (upload, download, delete, dll.) |
| `$filename` | `string` | Nama file |
| `$type` | `string` | Tipe file |
| `$scope` | `string` | Scope operasi (public, private) |
| `$status` | `string` | Status operasi (default: `'success'`) |

**File:** `modules/core/helpers/storage.php:208`
**Return:** void.
**Output:** JSON per baris di `logs/drive_audit.log` dengan timestamp, IP, dan user agent.

---

### `invalidate_dir_size_cache(string $username)`

Invalidate cache ukuran folder Drive untuk user tertentu.

```php
function invalidate_dir_size_cache(string $username): void
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$username` | `string` | Username yang cache-nya akan diinvalidate |

**File:** `modules/core/helpers/storage.php:180`
**Return:** void.
**Catatan:** Menghapus file cache `temp/dirsize_<md5>.cache`.

---

## Error Handling

### `meel_err_alpha(string $hex, int $alpha)`

Konversi hex warna dengan alpha channel (untuk halaman error).

```php
function meel_err_alpha(string $hex, int $alpha): string
```

| Parameter | Tipe | Deskripsi |
|-----------|------|-----------|
| `$hex` | `string` | Hex color tanpa alpha (mis. `'#3b82f6'`) |
| `$alpha` | `int` | Nilai alpha 0-255 |

**File:** `err/index.php:10`
**Return:** Hex color dengan alpha appended (mis. `'#3b82f6'` + `alpha=204` → `'#3b82f6cc'`).

---

## Testing & Mecab

### `meel_mecab_available()`

Memeriksa apakah mecab terinstall dan berfungsi di sistem.

```php
function meel_mecab_available(): bool
```

**File:** `tests/bootstrap.php:24`
**Return:** `true` jika mecab tersedia dan bisa menjalankan perintah dasar.
**Cache:** Hasil di-cache statis (hanya diperiksa sekali per request).

---

## Catatan untuk Developer

1. **Guard `function_exists`**: Semua fungsi `meel_*` dibungkus dengan `if (!function_exists('...'))` untuk mencegah error duplikasi deklarasi jika file di-require lebih dari sekali.

2. **Penamaan Konsisten**:
   - `meel_media_*` — untuk path media (video/music/books)
   - `meel_drive_*` — untuk path Drive (cloud storage)
   - `meel_mig_*` — untuk helper migrasi database
   - `meel_arc_mig_*` — untuk helper migrasi Arcade
   - `meel_admin_*` — untuk utilities admin
   - `meel_ffmpeg_*` — untuk operasi FFmpeg
   - `meel_*_ok` — untuk validasi (mengembalikan bool atau pesan error)

3. **Error Handling**:
   - Fungsi upload mengembalikan `[bool, string]` (ok, error message)
   - Fungsi FFmpeg mengembalikan `[int, string]` (exit code, log)
   - Fungsi path mengembalikan string (path absolut)
   - Fungsi validasi mengembalikan `''` jika valid, pesan error jika tidak

4. **Path Handling**:
   - Selalu gunakan `meel_media_base_path()` untuk path media, jangan hardcode
   - Gunakan `rtrim($path, '/\\')` untuk normalisasi path
   - Gunakan `realpath()` untuk resolve symlink dan deteksi path traversal
