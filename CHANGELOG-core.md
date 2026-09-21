# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

## [Unreleased]

### Added
- **Test integrasi MEeLCoin:** `tests/integration/MeelCoinIntegrationTest.php` (17 test) — mengunci kontrak atomik `spend`/`refund`/`refill`, semantik siklus refill, countdown per-user, dan idempotensi refund `QueueReconciler`
- **Migrasi v16 (`database/migrate.php`):** normalisasi state MEeLCoin — `meelcoin_upload_cost` & `meelcoin_advanced_cost` minimum `1`

### Changed
- **MEeLCoin atomik:** `MeelCoin::spend()`/`refund()`/`refill()` memakai satu `UPDATE` dengan guard saldo (bukan read-modify-write), sehingga potongan saldo tidak bisa tertimpa request lain yang berjalan bersamaan
- **Refill = siklus, bukan tabungan:** `refill()` meng-reset `meelcoin_last_refill` saat saldo penuh, dan cap ke `meelcoin_user_max`/`meelcoin_member_max` dihitung di DB (`LEAST`)
- **Countdown refill per-user:** `MeelCoin::getRefillCountdown()` mengikuti `meelcoin_last_refill` user tersebut (sebelumnya siklus wall-clock global yang identik untuk semua user); nilai `0` = siap refill
- **Biaya upload wajib ≥ 1:** panel admin MEeLCoin menolak biaya `0`; runtime melewati alur coin bila biaya ≤ 0
- **Refund lebih ketat:** `upload_advanced.php` hanya me-refund kegagalan terkonfirmasi (`''`, `DISCONNECTED`, `Download gagal*`, `File audio tidak ditemukan*`); hasil tak dikenal tidak direfund dan dicatat ke error log
- **`QueueReconciler` benar-benar me-refund:** idempotensi per-queue via `reason = 'reconcile_refund_q<id>'` (sebelumnya hanya menulis log tanpa refund)
- **Sinkronisasi saldo di UI:** `meelRefreshCoinBalance()` (`assets/js/engine/result.js`) menyegarkan `#coin-balance` setelah operasi selesai pada halaman upload & transcode

### Fixed
- **Saldo MEeLCoin kadang tidak terpotong setelah upload advanced berhasil:** user yang lama berada di saldo penuh menyimpan "refill tertunda" (timer tidak pernah di-reset saat saldo penuh) sehingga refill di request berikutnya menutup potongan coin
- Countdown refill di halaman profil menampilkan siklus penuh saat sudah waktunya refill (seharusnya "Siap")

## [1.0.0] - 2026-09-19

### Added

- **Video Streaming:** HLS adaptive streaming dengan subtitle, resolusi switching, PiP, resume otomatis, countdown auto-next, ambient glow, dan koneksi recovery
- **Music Player:** Pemutar audio (MP3, FLAC, OGG, M4A) dengan WebAudio visualizer, playlist, antrean cerdas, dan mini-player persisten
- **Lyrics/Karaoke:** Lirik karaoke real-time dengan sinkronisasi timestamp LRC, multi-bahasa, LRC Editor (mode Simple & Synced), keyboard shortcut `K`
- **Books:** Pembaca manga (ZIP/CBZ) dan PDF dengan thumbnail otomatis
- **Cloud Drive:** Penyimpanan file publik/privat dengan kuota per anggota, magic bytes validation
- **Authentication:** Login, registrasi, session management, CSRF protection, MFA (TOTP)
- **RBAC:** 4 level akses (Admin, Member, User, Guest) dengan permission checking di setiap endpoint
- **Admin Dashboard:** Manajemen konten, pengguna, modul, transcode, dan log audit
- **Transcoder:** FFmpeg-based transcoding untuk video dan audio
- **Video Downloader:** Integrasi yt-dlp untuk mengunduh video dari URL
- **Rate Limiting:** Pembatasan laju request per IP
- **Audit Trail:** Logging aktivitas pengguna untuk keamanan
- **IP Banning & Firewall:** Blokir IP dan proteksi brute-force
- **PWA:** Service worker, offline support, precache generator
- **Anti-SSRF Proxy:** Validasi URL untuk mencegah SSRF attacks
- **HTMX Integration:** Pembaruan halaman dinamis tanpa full reload
- **TailwindCSS:** Responsive design dengan dark/light mode
- **Testing:** 369+ test cases (unit, integration, functional, security)
- **Installer:** `install.sh` satu komando untuk setup lengkap

### Changed
- Arcade di-extract menjadi ekstensi terpisah (database dan migration terpisah)
- Router dan autoloader dikembangkan secara mandiri (tanpa framework)

### Fixed
- Duplikasi CSS `light-theme.css` di `index.php`
- Self-referencing link di `docs/en/arcade-optional.md`
- Sitemap `robots.txt` menggunakan path relatif (bukan localhost)

<!--
## [1.1.0] - YYYY-MM-DD
### Added
-
-->
