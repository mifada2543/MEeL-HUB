# Changelog — MEeL-HUB Core (UTAMA)

Mencatat perubahan pada inti platform: video, musik, buku, cloud drive, autentikasi, dan admin.
Modul arcade telah di-extract menjadi **ekstensi terpisah** — dicatat di [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `core-vX.Y.Z`.

## [Unreleased]

### Added
- **Pintasan keyboard `B` — Ambient Glow:** `assets/js/video/watch/misc.js` sekarang memetakan `B` ke `window.toggleGlow()` (diekspor di `assets/js/video/watch/player-events.js`). Efek ambient glow sebelumnya hanya bisa diubah lewat bar menu `#plyr-setting-glow`; sekarang bisa dari keyboard tanpa membuka menu Settings. `B` tidak bentrok dengan shortcut bawaan Plyr (`0–9`, `Space`/`K`, `←`/`→`, `↑`/`↓`, `M`, `F`, `C`) maupun shortcut MEeL lain (`L`, `A`, `S`, `N`, `I`), dan tetap terblokir selama modal "Lanjutkan Sesi?" tampil karena early-return `window.meelResumeModalActive`. Ditambahkan juga ke `introduction.php` dan tabel shortcut di `docs/{id,en}/modules.md`

### Fixed
- **Status Loop Playback ter-reset setiap refresh:** `assets/js/video/watch/state.js` kini membaca `MEEL_KEYS.VIDEO_LOOP` (`meel_video_loop`) saat boot dan mengekspor tiga helper — `persistVideoLoop()`, `restoreVideoLoop()`, `clearVideoLoop()`. `setupMeelPlayerEvents()` memanggil `restoreVideoLoop()`, sehingga loop dipulihkan di **semua** jalur pembuatan player — load awal, auto-recovery, rebuild karena ganti kualitas, dan pergantian video via mini player — bukan hanya saat refresh biasa. Lawanannya, `clearVideoLoop()` dipanggil saat pindah ke video lain (`skipToNextVideo`, klik kartu mini player, guard klik `.rekomendasi-item`) dan saat Auto Next diaktifkan, sehingga loop tidak ikut terbawa ke video berikutnya. Auto Next dan Loop tetap saling meniadakan dua arah: menyalakan Auto Next membersihkan key loop, menyalakan Loop mematikan Auto Next, dan state stale ketika keduanya aktif dibersihkan saat boot