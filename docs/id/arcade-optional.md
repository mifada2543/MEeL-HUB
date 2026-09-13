# 🕹️ Arcade sebagai Ekstensi Terpisah

> **Prinsip:** MEeL-HUB harus tetap berfungsi 100% tanpa Arcade — tanpa
> satu pun error, broken link, atau jejak yang tersisa. Arcade adalah
> *ekstensi*, bukan dependensi core.

---

## Daftar Isi

- [Ringkasan](#ringkasan)
- [Perbedaan dengan Sebelumnya](#perbedaan-dengan-sebelumnya)
- [Tiga Lapis Keputusan](#tiga-lapis-keputusan)
- [Cara Memasang / Menghapus](#cara-memasang--menghapus)
- [Perilaku Saat Tidak Terpasang](#perilaku-saat-tidak-terpasang)
- [Database Arcade](#database-arcade)
- [Titik Integrasi di Kode](#titik-integrasi-di-kode)
- [FAQ](#faq)

---

## Ringkasan

Arcade (9 mini-game: Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout,
Simon Says, Ludo, MEeL!Mania) adalah **ekstensi terpisah** dari MEeL-HUB.
Folder `arcade/` tidak wajib ada — bisa diinstal kapan saja atau dihapus
sepenuhnya tanpa memengaruhi HUB.

Ekstensi arcade mengelola **database sendiri** (`arcade/schema.sql` +
`arcade/migrate.php`) — tabel `rooms`, `moves`, `arcade_song`, dan
`arcade_score` bukan bagian dari skema inti HUB.

### Perbedaan dengan Sebelumnya

| Aspek | Sebelumnya (built-in) | Sekarang (ekstensi) |
|---|---|---|
| Folder `arcade/` | Bagian dari repo HUB | Ekstensi terpisah, bisa absen |
| DB `rooms`, `moves` | Di `database/schema.sql` | Di `arcade/schema.sql` |
| DB `arcade_song`, `arcade_score` | Di `arcade/rhythm/migration.sql` | Di `arcade/schema.sql` |
| Migrasi | `php database/migrate.php` (v12) | `php arcade/migrate.php` (terpisah) |
| Install.sh | Hanya migrasi core | Prompts arcade opsional (langkah 6b) |

## Tiga Lapis Keputusan

`Modules::enabled('arcade')` bernilai **true** hanya jika ketiganya lolos.
Semua kegagalan bersifat *fail-closed* (gagal membaca → dianggap nonaktif):

| Lapis | Cek | Kontrol |
|---|---|---|
| 1. **Fisik** | `arcade/index.php` ada di disk | Folder absen = ekstensi tidak terpasang |
| 2. **Flag** | File `arcade/.disabled` ada (diabaikan saat `MEEL_ENV=development`) | Kill-switch tingkat deploy |
| 3. **Toggle** | `site_settings.modules_arcade` ≠ `'0'` | Admin panel (runtime, persisten) |

Koneksi DB untuk lapis toggle *reuse* koneksi global `$conn` bila sudah ada,
atau membuat koneksi sendiri dari `auth/settings.php` — dan **tidak pernah
melempar exception**: tanpa DB/tabel, modul dianggap aktif (lapis 1 & 2 sudah
cukup).

## Cara Memasang / Menghapus

### Memasang Arcade

1. Salin folder `arcade/` ke root proyek HUB.
2. Jalankan migrasi arcade:
   ```bash
   php arcade/migrate.php
   ```
   Atau gunakan `install.sh` yang menawarkan instalasi arcade secara interaktif.

### Menonaktifkan — Admin Panel (disarankan)

1. Login sebagai admin → menu **☰ Modules** (atau buka `/admin/modules`).
2. Kartu **MEeL Arcade** → geser toggle.
3. Selesai. Efeknya langsung untuk semua user — tanpa restart, tanpa deploy.

Perubahan disimpan di `site_settings` (key `modules_arcade`) dan tercatat di
activity log (`toggle_module_arcade_1` / `toggle_module_arcade_0`).

### Menonaktifkan — Flag file (tingkat deploy/CLI)

```bash
# nonaktifkan
touch arcade/.disabled

# aktifkan kembali
rm arcade/.disabled
```

Flag ini diabaikan otomatis di lingkungan `development` agar developer lokal
tidak terkunci.

### Menghapus Arcade Sepenuhnya

```bash
rm -rf arcade/
```

Tidak ada langkah lain yang diperlukan:

- Router memindahkan seluruh rute arcade ke peta rute opsional — yang
  hilang secara fisik otomatis dilayani redirect 302 ke HUB.
- Semua link/menu/sitemap bergantung pada `Modules::enabled()` → otomatis
  tidak dirender.
- `GarbageCollector::cleanChessRooms()` menjadi no-op.

## Perilaku Saat Tidak Terpasang

| Permukaan | Perilaku |
|---|---|
| `/arcade/*` (halaman: beranda, chess, rhythm, editor, manage, edit) | **302 → HUB** (`/`) — redirect 302, bukan 301, agar arcade bisa diaktifkan lagi kapan saja |
| `/arcade/rhythm/api/*` + semua endpoint `arcade/chess/controller/*.php` | **JSON 404** `{"error": "Module not available"}` |
| File fisik di bawah `arcade/` (game statis html/js/css, lagu rhythm) | Di-gate oleh `arcade/.htaccess` saat flag `.disabled` terpasang atau folder tidak ada |
| Logo MEeL di home HUB | Dirender sebagai gambar biasa (bukan link arcade) |
| Menu admin "Chess Room" | Tidak dirender |
| `sitemap.xml` | URL `/arcade/beranda` + 8 halaman game dikecualikan |
| Garbage Collector | `cleanChessRooms()` return 0 tanpa query |
| PHPUnit | Suite chess & GC chess otomatis **skipped** |
| Database | **Tidak ada perubahan** — tidak ada tabel arcade di skema core |

## Database Arcade

Ekstensi arcade mengelola database sendiri — **tidak ada tabel arcade di
`database/schema.sql`** atau `database/migrate.php`:

| Tabel | Asal | Keterangan |
|---|---|---|
| `rooms` | `arcade/schema.sql` | Room catur multiplayer |
| `moves` | `arcade/schema.sql` | Langkah catur |
| `arcade_song` | `arcade/schema.sql` | Lagu rhythm (built-in + custom) |
| `arcade_score` | `arcade/schema.sql` | Skor rhythm |

Migrasi dijalankan terpisah:
```bash
php arcade/migrate.php
```

Migrasi arcade menggunakan tabel `arcade_db_version` untuk tracking versi
(setara `db_version` di core). Saat ini hanya v1 yang membuat semua tabel.

## Titik Integrasi di Kode

| File | Peran |
|---|---|
| `modules/core/Modules.php` | Gate terpusat: `exists()`, `enabled()`, `guardRedirect()`, `guardJson()`, `guardJson404()` |
| `modules/core/Router.php` | `OPTIONAL_ROUTES` (dipisah dari `ROUTES` inti) + redirect/JSON-404 di `dispatch()` |
| `arcade/.htaccess` | Gate statis untuk file fisik saat flag `.disabled` terpasang |
| `arcade/_gate.php` | Guard PHP — modul opsional, page path → 302, API path → JSON 404 |
| `arcade/chess/controller/chess_helpers.php` | Guard JSON 404 untuk semua endpoint chess (di-skip saat CLI/PHPUnit) |
| `index.php` | Logo HUB kondisional |
| `admin/header-admin.php` | Menu "Chess Room" kondisional + menu "Modules" |
| `admin/modules.php` + `controllers/admin/admin_actions.php` | Halaman & handler toggle admin |
| `modules/core/GarbageCollector.php` | `cleanChessRooms()` no-op saat nonaktif |
| `sitemap.php` | Entri arcade kondisional |
| `tests/integration/Chess*Test.php`, `GarbageCollectorChessRoomsIntegrationTest.php` | Guard skip |

## FAQ

**Apakah menghapus arcade menghapus data?**
Tidak. Data arcade tersimpan di tabel DB ekstensi (`rooms`, `moves`,
`arcade_song`, `arcade_score`). Menghapus folder `arcade/` hanya menghapus
kode PHP/JS — data tetap aman di DB.

**Bagaimana cara memasang arcade setelah HUB berjalan?**
1. Salin folder `arcade/` ke root proyek
2. Jalankan `php arcade/migrate.php`
3. Aktifkan dari Admin → Modules (toggle)

**Kenapa redirect-nya 302, bukan 301?**
301 di-cache browser secara permanen; 302 memungkinkan arcade diaktifkan ulang tanpa masalah cache.

**Apa yang terjadi kalau DB mati?**
Gate tetap bekerja dari deteksi fisik + flag. Toggle DB gagal dibaca → modul dianggap aktif (fail-open hanya pada lapis toggle; tidak pernah memblokir HUB).

**Bagaimana menambah modul opsional baru?**
Tambahkan entri baru di `Modules::OPTIONAL` (marker, flag_file, setting,
home_route), pindahkan rutenya ke `OPTIONAL_ROUTES` bila lewat router, lalu
tambahkan kartu modulnya di `admin/modules.php`.
