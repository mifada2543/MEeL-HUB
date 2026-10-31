# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

> **Gaya entri:** hanya *apa yang berubah* untuk pengguna. Analisis akar
> masalah, aturan yang berlaku, dan detail implementasi ada di
> [docs/](docs/) — bukan di berkas ini.

## [Released]

### Added

- **Pintasan keyboard `B` — Ambient Glow:** `assets/js/video/watch/misc.js` memetakan `B` ke `window.toggleGlow()`, jadi efek ambient glow bisa dinyalakan tanpa membuka menu Settings. Tidak bentrok dengan shortcut bawaan Plyr maupun shortcut MEeL lain, dan tetap terblokir selama modal "Lanjutkan Sesi?" tampil. Tercatat juga di `introduction.php` dan tabel shortcut di `docs/{id,en}/modules.md`.
- **Integrasi FFmpeg-lite bawaan:** `tools/ffmpeg-lite/ffmpeg` dan `ffprobe` kini menjadi default untuk instalasi baru. Path kustom tetap dipertahankan pada instalasi yang sudah ada melalui `lib/install/config.sh`. Resolusi binary menggunakan urutan: `MEEL_FFMPEG_PATH` → `tools/ffmpeg-lite/ffmpeg` → sistem path. Dokumentasi pembangunan FFmpeg-lite tersedia di `tools/ffmpeg-lite/README.md`.

### Changed

- **Resolusi path tidak lagi bergantung pada working directory:** 282 `require`/`include` yang sebelumnya memakai path relatif (di 66 berkas) kini memakai `__DIR__`, begitu pula 47 pemanggilan `filemtime()` di 12 berkas. Sebelumnya halaman yang di-*include* dari tempat lain bisa memuat `config.php` yang salah.
- **Editor admin musik & video berbagi satu berkas:** `admin/includes/media_editor.php` memegang konfigurasi per jenis media, pemeriksaan konteks admin/profil, dan blok unggah thumbnail. Dua halaman editor tidak lagi salinan satu sama lain.
- **Validasi berkas teks (lirik & subtitle) berbagi satu aturan:** `meel_validate_text_upload()` di `modules/core/helpers/text_upload.php`; `validate_lyrics_file()` dan `validate_subtitle_file()` menjadi pembungkus tipis.
- **Query activity log & antrean unggah admin berbagi satu repository:** `modules/media/AdminFilteredRepository.php`; tiap subclass hanya menunjuk tabel, alias, dan filternya sendiri.
- **Stage progres dipetakan ke handler:** `BrowserProgressObserver::STAGE_HANDLERS` menggantikan satu `switch` panjang, jadi stage yang dipancarkan tapi tak terpetakan tidak hilang diam-diam.
- **Aturan protokol proxy validasi dipisah dari berkas server:** `modules/auth/ValidatingProxyProtocol.php` — parsing, penerusan, dan relay bisa diuji tanpa menjalankan server.
- **Dokumentasi dikonsolidasikan:** 28 berkas markdown jadi 24, 22.372 baris jadi 17.588, nav viewer 13 dokumen jadi 12. `info.md` masuk ke `modules.md`, `upload-issues.md` masuk ke `troubleshooting.md`, dan `problem-solved.md` dihapus. Rincian lengkap di `docs/`.
- **URL thumbnail dan gambar profil menggunakan cache-busting versi:** Fungsi helper baru `video_thumbnail_url()`, `music_thumbnail_url()`, `book_thumbnail_url()`, dan `meel_profile_image_url()` menambahkan parameter `?v=<mtime>` untuk memastikan pembaruan instan tanpa mengubah Cache-Control 7 hari. Halaman admin dan profil kini menggunakan helper ini untuk semua gambar media dan profil.
- **Cache HTTP thumbnail dan gambar profil diperpanjang:** Thumbnail video/musik dan gambar profil kini menggunakan `Cache-Control: public, max-age=604800, stale-while-revalidate=86400` (7 hari), sementara thumbnail buku tetap `private` dengan batas waktu yang sama. Service worker (`sw.js.php`) kini memiliki cache khusus thumbnail (`meel-thumbs-<versi>`) dengan TTL 7 hari dan refresh otomatis untuk entri lama.

### Fixed

- **Overlay "Sambungan Terputus" muncul sendiri saat video di-loop:** video yang diulang 2–4 kali memicu player dibangun ulang padahal medianya sehat. `recovery.js` kini memberi *grace period* 8 detik sebelum eskalasi, dan hls.js diberi tahu posisi tujuan saat loop. Rincian di `docs/{id,en}/troubleshooting.md`.
- **Status Loop Playback ter-reset setiap refresh:** status loop kini disimpan di `sessionStorage` dan dipulihkan di semua jalur pembuatan player — load awal, auto-recovery, ganti kualitas, dan pergantian video. Loop juga dibersihkan saat berpindah video, dan Auto Next saling meniadakan dengan Loop.
- **Countdown isi ulang MEeLCoin melompat balik ke 5 jam:** jam PHP dan jam MySQL berbeda zona waktu, jadi kolom `DATETIME` yang ditulis `NOW()` terlihat di masa depan. `bootstrap.php` kini menyetel zona waktu tanpa syarat dan `auth/config.php` menyamakan `time_zone` sesi MySQL dengan offset PHP. Aturan lengkapnya ada di `docs/{id,en}/development.md` § Kontrak Waktu.
- **Sweeper cache menghapus setiap cache yang diketahuinya:** pemeriksaan kedaluwarsa membaca TTL `0` sebagai "sudah kedaluwarsa", jadi tiap sweep menghapus cache yang baru ditulis. Kunci waktu tiap keluarga cache kini dicari berurutan.
- **Slot antrean bisa diklaim dua kali:** pemeriksaan "server sibuk" dan INSERT-nya terpisah. `System::claimQueueSlot()` kini membungkusnya dalam `GET_LOCK()` MySQL.
- **GC menghapus artefak job yang masih berjalan:** `GarbageCollector::STALE_SECONDS` 300 → 1200, di atas kedua batas timeout transcode, supaya marker `.processing` milik job hidup tidak terhapus.
- **Rute `/arcade/rhythm/editor` menunjuk berkas yang tidak ada:** sekarang menuju `arcade/rhythm/manage/edit.php`, sama dengan dua URL editor lainnya.
- **`auth/config.php` dimuat dua kali:** memakai `require_once`, jadi header keamanan dan bookkeeping sesi tidak terulang.
- **Ikon Lucide hilang setelah swap htmx:** `assets/js/shared/htmx-lucide.js` mendaftarkan listener di `document`, bukan `document.body` — di halaman yang memuatnya di `<head>`, `document.body` masih `null` sehingga listener tak pernah terpasang.
- **Galat konfigurasi hilang di halaman:** koneksi database gagal dilaporkan lewat `error_log()` supaya pesan aslinya tidak tertutup fatal error.
- **Balasan komentar bisa menempel ke media lain:** `parent_id` sekarang divalidasi — induknya harus ada dan punya kolom media yang sama; kalau tidak, `parent_id` diset `NULL` sehingga komentar menjadi root.
- **Komentar baru tersembunyi di balik jendela 200 baris:** urutan dibalik menjadi `created_at DESC, id DESC` dan ditambahkan parameter `$offset` untuk paginasi.
- **Rantai balasan tanpa batas:** `MediaViewer::MAX_COMMENT_DEPTH` = 5; melewati batas dipromosikan ke root.
- **Counter `views` naik tiap kali halaman dibuka:** `UNIQUE (user_id, video_id, music_id)` tidak menahan apa pun karena MySQL menganggap NULL berbeda. Dipecah jadi dua unique terpisah per jenis media, seperti tabel `interactions`.
- **Rate limit drive tidak pernah aktif:** tabel `drive_files` tidak pernah diisi, jadi batas 10 file/jam tidak berlaku. Setiap unggahan kini dicatat.
- **Antrean hapus tertunda bisa menghapus path di luar root media:** `cleanupPendingDeletions()` kini mewajibkan `realpath()` berada di salah satu root media atau drive.
- **Pratinjau ekspor admin memuat seluruh tabel:** `fetchAll()` menerima batas, dan pratinjau memakai `countFiltered()` + `fetchPage()`.
- **Thumbnail tidak terlihat di admin stats:** Gambar thumbnail video/musik di halaman admin stats kini menggunakan helper `video_thumbnail_url()`/`music_thumbnail_url()` yang sudah menerapkan cache-busting, sehingga thumbnail yang diperbarui langsung tampil.
- **Label bahasa Inggris dan Indonesia tidak konsisten:** Label "Login", "Register", "views", "Reading:", "Watching:", "Browsing Video Library", dll. diseragamkan ke bahasa Indonesia yang sesuai dengan UI aplikasi.
- **Tampilan halaman login/register tidak konsisten:** Judul halaman dan deskripsi meta kini menggunakan label "Masuk" dan "Daftar" yang konsisten dengan UI.
- **Placeholder thumbnail video/musik di halaman admin hilang:** Helper URL thumbnail kini memastikan path yang valid, dan fallback placeholder digunakan bila thumbnail tidak tersedia.
- **Kesalahan bind_param pada upload rhythm game:** Kolom string di `arcade/rhythm/api/upload.php` sebelumnya di-bind dengan tipe integer, menyebabkan nilai tersimpan sebagai `'0'`. Sekarang tipe bind sudah dikoreksi dan diuji terhadap skema.
- **Label "Synced" di halaman admin views tidak terjemahkan:** Label "Synced" dan "No Data" di halaman analitik tayangan kini ditampilkan sebagai "Tersinkron" dan "Tidak Ada Data".
- **Label "views" di halaman admin stats tidak terjemahkan:** Label "views" di statistik admin kini ditampilkan sebagai "tayangan".
- **Label "Diunggah oleh" di kartu uploader admin tidak konsisten:** Label "Diunggah oleh" kini konsisten di semua halaman admin.
- **Label "Unknown" di aktivitas logger tidak terjemahkan:** Label "Unknown" untuk jenis perangkat kini ditampilkan sebagai "Tidak diketahui".
- **Label "Reading:", "Watching:", "Listening:" di aktivitas logger tidak konsisten:** Label aktivitas kini konsisten: "Membaca:", "Menonton:", "Mendengar:".
- **Label "Viewing Profile:" di aktivitas logger tidak konsisten:** Label kini ditampilkan sebagai "Melihat Profile:".
- **Label "Browsing HUB" dan turunannya tidak konsisten:** Label "Browsing ..." kini ditampilkan dalam bahasa Indonesia yang sesuai: "Menjelajahi perpustakaan Video/Musik/Buku/Arcade/Drive", "Membaca Profil", "Menjelajahi HUB".
- **Label "Cover Art" di halaman upload musik tidak konsisten:** Label "Cover Art" kini ditampilkan sebagai "Sampul".
- **Label "Save to MEeL Music" di halaman upload musik tidak konsisten:** Label "Save to MEeL Music" kini ditampilkan sebagai "Simpan ke MEeL Musik".
- **Label "Song Title..." di halaman upload musik tidak konsisten:** Placeholder "Song Title..." kini ditampilkan sebagai "Judul Lagu...".
- **Label "views" di halaman video/musik cards tidak konsisten:** Label "views" di kartu video dan musik kini ditampilkan sebagai "tayangan".
- **Label "views" di halaman profile manage tidak konsisten:** Label "views" di halaman kelola profil kini ditampilkan sebagai "tayangan".
- **Label "PDF Viewer" di halaman baca buku tidak konsisten:** Label iframe PDF kini ditampilkan sebagai "Penampil PDF".
- **Label "Login", "Register" di halaman auth tidak konsisten:** Judul halaman dan deskripsi meta kini konsisten menggunakan "Masuk" dan "Daftar".
- **Label "MEeL | Login", "MEeL | Register" di halaman auth tidak konsisten:** Judul dan og:title kini konsisten menggunakan "MEeL | Masuk" dan "MEeL | Daftar".
- **Label "Foto Saat Ini" dan "Pratinjau Foto" di halaman edit profil tidak konsisten:** Label dalam modal pratinjau foto profil kini konsisten.
- **Variabel `$my_pl_rows` tidak didefinisikan di music/view_playlist.php:** Variabel `$my_pl_rows` kini dideklarasikan sebelum digunakan dalam dropdown playlist.
- **CSS admin tidak terkompresi dan tidak konsisten:** File CSS admin (`assets/css/admin/shared/*.css`, `assets/css/admin/edit/*.css`) dikompresi, dikonsolidasikan, dan disederhanakan untuk meningkatkan performa dan konsistensi.
- **Kode CSS admin mengandung banyak spasi kosong dan komentar:** CSS admin kini dibersihkan dari komentar dan spasi tidak perlu, serta menggunakan sintaks ringkas.
- **Kode PHP admin mengandung banyak spasi kosong dan komentar:** File PHP admin dibersihkan dari komentar dan spasi tidak perlu untuk meningkatkan keterbacaan dan konsistensi.
- **Kode JavaScript admin tidak konsisten:** Beberapa file JavaScript admin dibersihkan dan disederhanakan.
- **Kode PHP di database/backfill_english_metadata.php tidak perlu:** File backfill kini dibersihkan dari spasi dan komentar yang tidak perlu.
- **Dokumentasi HTML/MD menggunakan bahasa Inggris di beberapa tempat:** Halaman dokumentasi HTML dan file index.md kini menggunakan bahasa Indonesia yang konsisten untuk navigasi dan teks statis.

### Security

- **`MEEL_ENV` tidak lagi bisa dipengaruhi header kiriman klien:** hanya dibaca dari `REMOTE_ADDR`; `install.sh` menulis `UseCanonicalName On` di VirtualHost hasil generate. Sebelumnya `Host: localhost` dari penyerang luar cukup menyalakan `display_errors` dan `unsafe-eval` untuk seluruh situs.
- **Otorisasi admin selalu membaca database:** `is_admin()`/`require_admin()` tidak lagi memakai `$_SESSION['role']` sebagai sumber otoritatif, sehingga pencabutan admin berlaku seketika.
- **Menonaktifkan MFA mewajibkan password + kode TOTP:** sesi yang dibajak tidak lagi bisa menurunkan akun ke 1FA dan menghapus kode cadangan. Seed TOTP tidak pernah dirender ulang untuk sesi login.
- **Kode TOTP hanya bisa dipakai sekali:** time-step yang cocok disimpan di `users.mfa_last_step`, dan pembaruan-nya memakai kondisi yang menolak pemakaian ulang — termasuk dua permintaan bersamaan dengan kode sama.
- **Token CSRF tidak lagi ada di URL:** penghapusan media memakai POST dengan token di body form; token tidak lagi masuk ke riwayat browser, access log, atau header `Referer`.
- **Login tidak membocorkan apakah akun ada:** semua kegagalan memakai pesan yang sama, dan `password_verify` boneka dijalankan ketika user tidak ditemukan supaya waktu respons tidak memisahkan kedua cabang.
- **Escaping dilakukan saat render, bukan saat simpan:** konten yang disimpan tetap data mentah. Halaman yang bisa diakses pengunjung anonim tidak lagi menjadi stored XSS begitu penulis kedua mengedit datanya.
- **Otorisasi streaming gagal-terbuka:** `meel_stream_path_allowed()` memakai allowlist prefiks publik yang eksplisit, jadi path yang tidak dikenali ditolak, bukan lolos.
- **Limiter MFA & login berlaku lintas sesi:** bucket `mfa` dan `login` (10 permintaan / 300 detik) dihitung di sisi server — membuang cookie tidak lagi meresetnya.
- **Migrasi gagal berhenti, bukan melanjutkan:** kegagalan dikumpulkan bersama nomor langkahnya, versi **tidak** dicatat, dan proses keluar dengan kode 1 — skema tidak bisa setengah termigrasi permanen.
- **Open redirect ditutup dengan satu helper:** `meel_same_origin_referer()` membandingkan host referer dengan `MEEL_CANONICAL_HOST` yang dikonfigurasi, hanya menerima http/https, dan menolak URL protocol-relative.
- **Unduhan dibatasi ukuran dan durasinya:** `--max-filesize` dan `--match-filter` pada yt-dlp, ditambah pemeriksaan metadata sebelum unduhan dimulai.
- **Webroot ditutup:** `vendor/`, `node_modules/`, `tools/`, `assets/dict/`, dan `storage/` tidak lagi bisa diunduh tanpa autentikasi.
- **Seluruh `.htaccess` kompatibel dengan Apache 2.4:** direktif `Deny from all` dibungkus `IfModule` — tanpa itu Apache 2.4 membuang seluruh `.htaccess` dan membiarkan `controllers/` terbuka.
- **`global $x;` yang tidak pernah dipakai dihapus:** `log_drive_operation()` sempat bergantung pada global `$conn` yang tidak pernah dibaca.
- **Checker keamanan memindai seluruh repo:** berkas yang dipindai naik dari 9 menjadi 21, dan pola open redirect kini dilacak lewat taint, bukan hanya pencarian `HTTP_REFERER` di dalam `header()`.
- **CI gagal pada peringatan apa pun:** keluar kode selain nol kini merah, jadi setiap peringatan tidak lagi ditunda tanpa batas.
- **Bypass statis thumbnail video/musik:** Thumbnail video dan musik kini dapat disajikan langsung oleh Apache bila symlink storage aktif (bypass statis), dengan fallback ke handler PHP. Konfigurasi Apache di `.htaccess` dan direktori upload menerapkan `Cache-Control: public` untuk thumbnail publik, sementara thumbnail buku tetap dilayani melalui PHP dengan `Cache-Control: private`.
- **Pemaksaan MIME type untuk thumbnail webp/avif:** Root `.htaccess` dan template `.htaccess` di direktori upload kini memaksa `Content-Type: image/webp` dan `image/avif` untuk thumbnail, mencegah response "inherit" dari konfigurasi legacy.
- **Penghapusan Pragma/Expires dari response media:** `meel_serve_media_file()` menghapus header `Pragma` dan `Expires` yang dikirim oleh `session_start()` untuk memastikan Cache-Control media tidak bentrok dengan session-based caching.

## [2.2.0] - 2026-10-31

### Added
-**FFmpeg-lite bawaan:** Bundel `tools/ffmpeg-lite/ffmpeg` dan `ffprobe` disertakan sebagai default untuk instalasi baru. Proses instalasi otomatis mendeteksi dan menggunakan binary bawaan bila tersedia, dengan fallback ke path sistem bila tidak. Dokumentasi pembangunan FFmpeg-lite tersedia di `tools/ffmpeg-lite/README.md` dan `tools/ffmpeg-lite/BUILD-INFO.txt`.
- **Cache-busting URL untuk thumbnail dan gambar profil:** Helper baru `video_thumbnail_url()`, `music_thumbnail_url()`, `book_thumbnail_url()`, dan `meel_profile_image_url()` menambahkan parameter `?v=<mtime>` untuk cache-busting instan. URL ini digunakan di seluruh aplikasi untuk memastikan pembaruan thumbnail langsung tampil tanpa mengubah Cache-Control 7 hari.
- **Cache service worker khusus untuk thumbnail:** Service worker (`sw.js.php`) kini memiliki cache khusus `meel-thumbs-<versi>` dengan TTL 7 hari dan mekanisme refresh otomatis untuk entri lama, memastikan thumbnail selalu segar.

### Changed
- **Path resolution menggunakan `__DIR__`:** 282 `require`/`include` dan 47 pemanggilan `filemtime()` kini menggunakan `__DIR__` untuk menghindari loading `config.php` yang salah saat halaman di-include dari lokasi berbeda.
- **Editor admin musik/video berbagi satu berkas:** `admin/includes/media_editor.php` kini digunakan bersama oleh kedua jenis media, menghilangkan duplikasi kode dan menyederhanakan pemeliharaan.
- **Validasi upload teks (lirik/subtitle) terpusat:** `meel_validate_text_upload()` di `modules/core/helpers/text_upload.php` menjadi titik validasi tunggal, dengan `validate_lyrics_file()` dan `validate_subtitle_file()` sebagai pembungkus ringan.
- **Repository aktivitas dan antrean admin terpusat:** `modules/media/AdminFilteredRepository.php` menjadi superclass untuk semua repository admin, dengan subclass hanya menentukan tabel, alias, dan filter spesifik.
- **Pemetaan stage progres ke handler:** `BrowserProgressObserver::STAGE_HANDLERS` menggantikan `switch` panjang, memastikan stage yang dipancarkan tidak hilang diam-diam.
- **Pemisahan aturan proxy validasi:** Logika parsing, penerusan, dan relay protokol proxy dipindahkan ke `modules/auth/ValidatingProxyProtocol.php`, memungkinkan pengujian unit tanpa menjalankan server.
- **Konsolidasi dokumentasi:** 28 file markdown menjadi 24, 22.372 baris menjadi 17.588. File `info.md` digabung ke `modules.md`, `upload-issues.md` ke `troubleshooting.md`, dan `problem-solved.md` dihapus. Nav viewer berkurang dari 13 dokumen menjadi 12.
- **Cache HTTP thumbnail dan gambar profil diperpanjang:** Thumbnail video/musik dan gambar profil kini menggunakan `Cache-Control: public, max-age=604800, stale-while-revalidate=86400` (7 hari). Thumbnail buku tetap `private` dengan batas waktu yang sama untuk menjaga privasi.
- **Bypass statis untuk thumbnail publik:** Apache kini dapat menyajikan thumbnail video/musik langsung bila symlink storage aktif (bypass statis), dengan fallback ke handler PHP. Konfigurasi di `.htaccess`, `video/upload/.htaccess`, `music/upload/.htaccess`, dll. menerapkan aturan ini.
- **Pemaksaan MIME type untuk thumbnail:** Root `.htaccess` dan template `.htaccess` di direktori upload kini memaksa `Content-Type` yang benar untuk webp/avif/jpg/png/gif/avif, mencegah response "inherit" dari konfigurasi legacy.
- **Penghapusan header Pragma/Expires dari response media:** `meel_serve_media_file()` membersihkan header `Pragma` dan `Expires` yang dikirim oleh `session_start()` untuk memastikan Cache-Control media tidak bentrok dengan session-based caching.
- **Thumbnail tidak memulai session di stream.php:** Request thumbnail kini melewati `meel_boot_session()` di `video/stream.php`, menghindari pengiriman `Set-Cookie` dan header session-based caching yang menghalangi cache browser/CDN.

### Fixed
- **Overlay "Sambungan Terputus" muncul sendiri saat video di-loop:** Loop video memicu recovery berulang. `recovery.js` kini memberi *grace period* 8 detik sebelum eskalasi, dan hls.js diberi tahu posisi tujuan saat loop. Lihat `docs/{id,en}/troubleshooting.md` untuk detail.
- **Status Loop Playback ter-reset setiap refresh:** Status loop kini disimpan di `sessionStorage` dan dipulihkan di semua jalur pembuatan player (load awal, auto-recovery, ganti kualitas, pergantian video). Loop dibersihkan saat berpindah video, dan Auto Next saling meniadakan dengan Loop.
- **Countdown isi ulang MEeLCoin melompat balik ke 5 jam:** Perbedaan zona waktu PHP dan MySQL menyebabkan kolom `DATETIME` terlihat di masa depan. `bootstrap.php` menetapkan zona waktu tanpa syarat, dan `auth/config.php` menyamakan `time_zone` sesi MySQL dengan offset PHP. Lihat `docs/{id,en}/development.md` § Kontrak Waktu.
- **Sweeper cache menghapus cache yang baru ditulis:** Pemeriksaan kedaluwarsa salah membaca TTL `0` sebagai "kedaluwarsa", menyebabkan semua cache terhapus. Kunci waktu kini dicari berurutan per keluarga cache.
- **Slot antrean bisa diklaim dua kali:** Pemeriksaan "server sibuk" dan INSERT terpisah. `System::claimQueueSlot()` kini menggunakan `GET_LOCK()` MySQL untuk atomisitas.
- **GC menghapus artefak job yang masih berjalan:** `GarbageCollector::STALE_SECONDS` dinaikkan dari 300 menjadi 1200 (di atas timeout transcode), mencegah penghapusan marker `.processing` milik job hidup.
- **Rute `/arcade/rhythm/editor` menunjuk berkas yang tidak ada:** Sekarang mengarah ke `arcade/rhythm/manage/edit.php`, konsisten dengan editor lainnya.
- **`auth/config.php` dimuat dua kali:** Menggunakan `require_once` untuk mencegah duplikasi header keamanan dan bookkeeping sesi.
- **Ikon Lucide hilang setelah swap htmx:** Listener didaftarkan di `document` bukan `document.body`, mencegah masalah saat halaman memuat script di `<head>`.
- **Galat konfigurasi tidak terlihat di halaman:** Koneksi database gagal dilaporkan lewat `error_log()` agar pesan asli tidak tertutup fatal error.
- **Balasan komentar bisa menempel ke media lain:** `parent_id` kini divalidasi — induk harus ada dan memiliki kolom media yang sama; bila tidak, `parent_id` diset `NULL` sehingga komentar menjadi root.
- **Komentar baru tersembunyi di balik 200 baris:** Urutan dibalik menjadi `created_at DESC, id DESC`, dan parameter `$offset` ditambahkan untuk paginasi.
- **Rantai balasan tanpa batas:** `MediaViewer::MAX_COMMENT_DEPTH` = 5; melewati batas dipromosikan ke root.
- **Counter `views` naik tiap kali halaman dibuka:** `UNIQUE (user_id, video_id, music_id)` tidak berfungsi karena MySQL menganggap NULL berbeda. Sekarang dipecah menjadi dua unique terpisah per jenis media, seperti tabel `interactions`.
- **Rate limit drive tidak pernah aktif:** Tabel `drive_files` tidak pernah diisi, menyebabkan batas 10 file/jam tidak berlaku. Setiap unggahan kini dicatat.
- **Antrean hapus tertunda bisa menghapus path di luar root media:** `cleanupPendingDeletions()` kini memeriksa `realpath()` berada di salah satu root media atau drive.
- **Pratinjau ekspor admin memuat seluruh tabel:** `fetchAll()` menerima batas, dan pratinjau menggunakan `countFiltered()` + `fetchPage()`.
- **Thumbnail tidak terlihat di admin stats:** Gambar thumbnail video/musik di halaman admin stats kini menggunakan helper `video_thumbnail_url()`/`music_thumbnail_url()` yang menerapkan cache-busting, sehingga thumbnail yang diperbarui langsung tampil.
- **Label bahasa Inggris dan Indonesia tidak konsisten:** Label seperti "Login", "Register", "views", "Reading:", "Watching:", "Browsing Video Library", dll. diseragamkan ke bahasa Indonesia yang sesuai dengan UI aplikasi.
- **Tampilan halaman login/register tidak konsisten:** Judul halaman dan deskripsi meta kini menggunakan label "Masuk" dan "Daftar" yang konsisten.
- **Placeholder thumbnail video/musik di halaman admin hilang:** Helper URL thumbnail memastikan path valid, dan fallback placeholder digunakan bila thumbnail tidak tersedia.
- **Kesalahan bind_param pada upload rhythm game:** Kolom string di `arcade/rhythm/api/upload.php` sebelumnya di-bind dengan tipe integer, menyebabkan nilai tersimpan sebagai `'0'`. Sekarang tipe bind dikoreksi dan diuji terhadap skema.
- **Label "Synced" di halaman admin views tidak terjemahkan:** Label "Synced" dan "No Data" kini ditampilkan sebagai "Tersinkron" dan "Tidak Ada Data".
- **Label "views" di halaman admin stats tidak terjemahkan:** Label "views" kini ditampilkan sebagai "tayangan".
- **Label "Diunggah oleh" di kartu uploader admin tidak konsisten:** Label kini konsisten di semua halaman admin.
- **Label "Unknown" di aktivitas logger tidak terjemahkan:** Label "Unknown" untuk jenis perangkat kini ditampilkan sebagai "Tidak diketahui".
- **Label "Reading:", "Watching:", "Listening:" di aktivitas logger tidak konsisten:** Label aktivitas kini konsisten: "Membaca:", "Menonton:", "Mendengar:".
- **Label "Viewing Profile:" di aktivitas logger tidak konsisten:** Label kini ditampilkan sebagai "Melihat Profile:".
- **Label "Browsing HUB" dan turunannya tidak konsisten:** Label "Browsing ..." kini ditampilkan dalam bahasa Indonesia: "Menjelajahi perpustakaan Video/Musik/Buku/Arcade/Drive", "Membaca Profil", "Menjelajahi HUB".
- **Label "Cover Art" di halaman upload musik tidak konsisten:** Label "Cover Art" kini ditampilkan sebagai "Sampul".
- **Label "Save to MEeL Music" di halaman upload musik tidak konsisten:** Label "Save to MEeL Music" kini ditampilkan sebagai "Simpan ke MEeL Musik".
- **Label "Song Title..." di halaman upload musik tidak konsisten:** Placeholder "Song Title..." kini ditampilkan sebagai "Judul Lagu...".
- **Label "views" di halaman video/musik cards tidak konsisten:** Label "views" kini ditampilkan sebagai "tayangan".
- **Label "views" di halaman profile manage tidak konsisten:** Label "views" kini ditampilkan sebagai "tayangan".
- **Label "PDF Viewer" di halaman baca buku tidak konsisten:** Label iframe PDF kini ditampilkan sebagai "Penampil PDF".
- **CSS admin tidak terkompresi dan tidak konsisten:** File CSS admin (`assets/css/admin/shared/*.css`, `assets/css/admin/edit/*.css`) dikompresi, dikonsolidasikan, dan disederhanakan untuk meningkatkan performa dan konsistensi.
- **Kode CSS admin mengandung banyak spasi kosong dan komentar:** CSS admin kini dibersihkan dari komentar dan spasi tidak perlu, serta menggunakan sintaks ringkas.
- **Kode PHP admin mengandung banyak spasi kosong dan komentar:** File PHP admin dibersihkan dari komentar dan spasi tidak perlu untuk meningkatkan keterbacaan dan konsistensi.
- **Kode JavaScript admin tidak konsisten:** Beberapa file JavaScript admin dibersihkan dan disederhanakan.
- **Kode PHP di database/backfill_english_metadata.php tidak perlu:** File backfill kini dibersihkan dari spasi dan komentar yang tidak perlu.
- **Dokumentasi HTML/MD menggunakan bahasa Inggris di beberapa tempat:** Halaman dokumentasi HTML dan file index.md kini menggunakan bahasa Indonesia yang konsisten untuk navigasi dan teks statis.
- **Variabel `$my_pl_rows` tidak didefinisikan di music/view_playlist.php:** Variabel kini dideklarasikan sebelum digunakan dalam dropdown playlist.

### Security
- **`MEEL_ENV` tidak lagi bisa dipengaruhi header kiriman klien:** Hanya dibaca dari `REMOTE_ADDR`; `install.sh` menulis `UseCanonicalName On` di VirtualHost hasil generate. Sebelumnya `Host: localhost` dari penyerang luar cukup menyalakan `display_errors` dan `unsafe-eval` untuk seluruh situs.
- **Otorisasi admin selalu membaca database:** `is_admin()`/`require_admin()` tidak lagi memakai `$_SESSION['role']` sebagai sumber otoritatif, sehingga pencabutan admin berlaku seketika.
- **Menonaktifkan MFA mewajibkan password + kode TOTP:** Sesi yang dibajak tidak lagi bisa menurunkan akun ke 1FA dan menghapus kode cadangan. Seed TOTP tidak pernah dirender ulang untuk sesi login.
- **Kode TOTP hanya bisa dipakai sekali:** Time-step yang cocok disimpan di `users.mfa_last_step`, dan pembaruannya memakai kondisi yang menolak pemakaian ulang — termasuk dua permintaan bersamaan dengan kode sama.
- **Token CSRF tidak lagi ada di URL:** Penghapusan media memakai POST dengan token di body form; token tidak lagi masuk ke riwayat browser, access log, atau header `Referer`.
- **Login tidak membocorkan apakah akun ada:** Semua kegagalan memakai pesan yang sama, dan `password_verify` boneka dijalankan ketika user tidak ditemukan supaya waktu respons tidak memisahkan kedua cabang.
- **Escaping dilakukan saat render, bukan saat simpan:** Konten yang disimpan tetap data mentah. Halaman yang bisa diakses pengunjung anonim tidak lagi menjadi stored XSS begitu penulis kedua mengedit datanya.
- **Otorisasi streaming gagal-terbuka:** `meel_stream_path_allowed()` memakai allowlist prefiks publik yang eksplisit, jadi path yang tidak dikenali ditolak, bukan lolos.
- **Limiter MFA & login berlaku lintas sesi:** Bucket `mfa` dan `login` (10 permintaan / 300 detik) dihitung di sisi server — membuang cookie tidak lagi meresetnya.
- **Migrasi gagal berhenti, bukan melanjutkan:** Kegagalan dikumpulkan bersama nomor langkahnya, versi **tidak** dicatat, dan proses keluar dengan kode 1 — skema tidak bisa setengah termigrasi permanen.
- **Open redirect ditutup dengan satu helper:** `meel_same_origin_referer()` membandingkan host referer dengan `MEEL_CANONICAL_HOST` yang dikonfigurasi, hanya menerima http/https, dan menolak URL protocol-relative.
- **Unduhan dibatasi ukuran dan durasinya:** `--max-filesize` dan `--match-filter` pada yt-dlp, ditambah pemeriksaan metadata sebelum unduhan dimulai.
- **Webroot ditutup:** `vendor/`, `node_modules/`, `tools/`, `assets/dict/`, dan `storage/` tidak lagi bisa diunduh tanpa autentikasi.
- **Seluruh `.htaccess` kompatibel dengan Apache 2.4:** Direktif `Deny from all` dibungkus `IfModule` — tanpa itu Apache 2.4 membuang seluruh `.htaccess` dan membiarkan `controllers/` terbuka.
- **`global $x;` yang tidak pernah dipakai dihapus:** `log_drive_operation()` sempat bergantung pada global `$conn` yang tidak pernah dibaca.
- **Checker keamanan memindai seluruh repo:** Berkas yang dipindai naik dari 9 menjadi 21, dan pola open redirect kini dilacak lewat taint, bukan hanya pencarian `HTTP_REFERER` di dalam `header()`.
- **CI gagal pada peringatan apa pun:** Keluar kode selain nol kini merah, jadi setiap peringatan tidak lagi ditunda tanpa batas.
- **Bypass statis thumbnail publik:** Thumbnail video/musik kini dapat disajikan langsung oleh Apache bila symlink storage aktif, dengan fallback ke handler PHP. Konfigurasi Apache menerapkan `Cache-Control: public` untuk thumbnail publik, sementara thumbnail buku tetap dilayani melalui PHP dengan `Cache-Control: private`.
- **Pemaksaan MIME type untuk thumbnail webp/avif:** Root `.htaccess` dan template `.htaccess` di direktori upload kini memaksa `Content-Type: image/webp` dan `image/avif` untuk thumbnail, mencegah response "inherit" dari konfigurasi legacy.
- **Penghapusan Pragma/Expires dari response media:** `meel_serve_media_file()` menghapus header `Pragma` dan `Expires` yang dikirim oleh `session_start()` untuk memastikan Cache-Control media tidak bentrok dengan session-based caching.


## [2.1.4] - 2026-10-27

### Added

- **Dukungan WebGPU untuk AI Upscale:** Model upscale lokal (MEeLScale, FSRCNN) kini dapat menggunakan WebGPU bila tersedia, meningkatkan performa dibanding CPU. Dokumentasi dan konfigurasi tersedia di `docs/{id,en}/configuration.md` § AI Upscale.

### Changed

- **Ekstrak Arcade sebagai ekstensi terpisah:** Modul arcade (9 game) dipindahkan ke ekstensi terpisah dengan database dan migrasi sendiri. Dokumentasi dan rilis kini terpisah antara Core dan Arcade. Lihat `CHANGELOG-arcade.md` untuk perubahan spesifik Arcade.

### Fixed

- **Upscale AI tidak berfungsi di beberapa browser:** Model upscale kini dimuat secara lazy-load dari `assets/models/`, dan pengecekan WebGPU dilakukan sebelum inisialisasi.

### Security

- **Peningkatan validasi unduhan:** yt-dlp kini menggunakan `--max-filesize` dan `--match-filter` untuk membatasi ukuran dan durasi unduhan, mencegah abuse bandwidth.

<!--
