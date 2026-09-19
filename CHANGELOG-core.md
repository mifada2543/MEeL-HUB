# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

## [Unreleased]
### Added
-
### Changed
-
### Fixed
-

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
