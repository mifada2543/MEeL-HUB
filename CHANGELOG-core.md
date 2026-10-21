# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

> **Gaya entri:** hanya *apa yang berubah* untuk pengguna. Analisis akar
> masalah, aturan yang berlaku, dan detail implementasi ada di
> [docs/](docs/) — bukan di berkas ini.

## [Unreleased]

### Added

- **Pintasan keyboard `B` — Ambient Glow:** `assets/js/video/watch/misc.js` memetakan `B` ke `window.toggleGlow()`, jadi efek ambient glow bisa dinyalakan tanpa membuka menu Settings. Tidak bentrok dengan shortcut bawaan Plyr maupun shortcut MEeL lain, dan tetap terblokir selama modal "Lanjutkan Sesi?" tampil. Tercatat juga di `introduction.php` dan tabel shortcut di `docs/{id,en}/modules.md`.

### Changed

- **Resolusi path tidak lagi bergantung pada working directory:** 282 `require`/`include` yang sebelumnya memakai path relatif (di 66 berkas) kini memakai `__DIR__`, begitu pula 47 pemanggilan `filemtime()` di 12 berkas. Sebelumnya halaman yang di-*include* dari tempat lain bisa memuat `config.php` yang salah.
- **Editor admin musik & video berbagi satu berkas:** `admin/includes/media_editor.php` memegang konfigurasi per jenis media, pemeriksaan konteks admin/profil, dan blok unggah thumbnail. Dua halaman editor tidak lagi salinan satu sama lain.
- **Validasi berkas teks (lirik & subtitle) berbagi satu aturan:** `meel_validate_text_upload()` di `modules/core/helpers/text_upload.php`; `validate_lyrics_file()` dan `validate_subtitle_file()` menjadi pembungkus tipis.
- **Query activity log & antrean unggah admin berbagi satu repository:** `modules/media/AdminFilteredRepository.php`; tiap subclass hanya menunjuk tabel, alias, dan filternya sendiri.
- **Stage progres dipetakan ke handler:** `BrowserProgressObserver::STAGE_HANDLERS` menggantikan satu `switch` panjang, jadi stage yang dipancarkan tapi tak terpetakan tidak hilang diam-diam.
- **Aturan protokol proxy validasi dipisah dari berkas server:** `modules/auth/ValidatingProxyProtocol.php` — parsing, penerusan, dan relay bisa diuji tanpa menjalankan server.
- **Dokumentasi dikonsolidasikan:** 28 berkas markdown jadi 24, 22.372 baris jadi 17.588, nav viewer 13 dokumen jadi 12. `info.md` masuk ke `modules.md`, `upload-issues.md` masuk ke `troubleshooting.md`, dan `problem-solved.md` dihapus. Rincian lengkap di `docs/`.

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

<!--
## [1.0.0] - YYYY-MM-DD

### Added
-
-->
