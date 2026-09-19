# Changelog — MEeL Arcade (Ekstensi Terpisah)

Mencatat perubahan pada **ekstensi arcade** (9 game: Miku & Teto Run, Chess, Snake, 2048,
Tetris, Breakout, Simon Says, Ludo, MEeL!Mania). Arcade adalah ekstensi terpisah dari
MEeL-HUB core — mengelola database sendiri (`arcade/schema.sql` + `arcade/migrate.php`).

Perubahan core HUB dicatat terpisah di [CHANGELOG-core.md](CHANGELOG-core.md).

Format mengikuti [Keep a Changelog](https://keepachangelog.com/), versi mengikuti
[Semantic Versioning](https://semver.org/) dengan tag `arcade-vX.Y.Z`.

Kompatibilitas minimum versi core HUB dicatat di setiap entri (mis. "Membutuhkan core >= v1.0.0").

> **Catatan:** Sebelum menjadi ekstensi terpisah, arcade adalah modul bawaan
> HUB. Tabel `rooms`, `moves` sebelumnya ada di `database/schema.sql` core
> dan kolom chess room (`white_user_id`, `black_user_id`) ditambahkan di
> migrasi v12. Sekarang semua tabel arcade dielola oleh `arcade/schema.sql`
> + `arcade/migrate.php`, dan v12 core menjadi no-op.

## [Unreleased]
### Added
-
### Changed
-
### Fixed
-

## [1.0.0] - 2026-09-19

> Membutuhkan core >= v1.0.0

### Added

- **Miku & Teto Run:** Game endless runner dengan karakter Miku dan Teto
- **Chess:** Catur multiplayer online dengan room system
- **Snake:** Klasik snake game
- **2048:** Puzzle game angka
- **Tetris:** Klasik block stacking
- **Breakout:** Pemecah brick dengan bola
- **Simon Says:** Memory game dengan pola warna dan suara
- **Ludo:** Board game klasik
- **MEeL!Mania:** Rhythm game dengan musik dari library MEeL Music
- **Room System:** Pembuatan dan pengelolaan room untuk chess multiplayer
- **Score System:** High score tracking untuk semua game
- **Separate Database:** Schema dan migration terpisah dari core (`arcade/schema.sql` + `arcade/migrate.php`)
- **Module System:** 3-lapis keputusan (Physical, Flag, Toggle) untuk enable/disable arcade
- **Admin Toggle:** Panel admin untuk mengaktifkan/menonaktifkan arcade tanpa restart
- **HTMX Integration:** Pembaruan dinamis untuk leaderboard dan room status

### Changed
- Arcade di-extract dari core HUB menjadi ekstensi terpisah
- Database tables (`rooms`, `moves`, `arcade_song`, `arcade_score`) dipindahkan ke `arcade/schema.sql`
- Migration dijalankan terpisah via `php arcade/migrate.php`

### Fixed
- Garbage collector tidak error saat arcade tidak terpasang
- PHPUnit tests chess auto-skip saat arcade disabled
- Router mengarahkan arcade routes ke optional route map saat tidak tersedia

<!--
## [1.1.0] - YYYY-MM-DD
> Membutuhkan core >= v1.0.0
### Added
-
-->
