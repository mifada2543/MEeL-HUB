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

<!--
## [1.0.0] - YYYY-MM-DD
> Membutuhkan core >= v1.0.0
### Added
- Rilis stabil pertama modul arcade (9 game bawaan).
-->
