# 🕹️ Arcade sebagai Modul Opsional

> **Prinsip:** MEeL-HUB harus tetap berfungsi 100% walau Arcade tidak ada,
> dinonaktifkan, atau dihapus — tanpa satu pun error, broken link, atau
> jejak yang tersisa. Arcade adalah *add-on*, bukan dependensi.

---

## Daftar Isi

- [Ringkasan](#ringkasan)
- [Tiga Lapis Keputusan](#tiga-lapis-keputusan)
- [Cara Menonaktifkan / Mengaktifkan](#cara-menonaktifkan--mengaktifkan)
- [Menghapus Arcade Sepenuhnya](#menghapus-arcade-sepenuhnya)
- [Perilaku Saat Nonaktif](#perilaku-saat-nonaktif)
- [Titik Integrasi di Kode](#titik-integrasi-di-kode)
- [Database](#database)
- [FAQ](#faq)

---

## Ringkasan

Arcade (9 mini-game: Miku & Teto Run, Chess, Snake, 2048, Tetris, Breakout,
Simon Says, Ludo, MEeL!Mania) tidak lagi dianggap bagian wajib platform.
Semua akses, tampilan, dan pemeliharaannya melewati satu gate terpusat:

```
modules/core/Modules.php   ← satu-satunya sumber kebenaran
```

Class ini self-contained: tanpa autoloader, tanpa session, tanpa dependensi
wajib — aman dipanggil dari `router.php`, `sitemap.php`, halaman inti, maupun
controller arcade itu sendiri.

## Tiga Lapis Keputusan

`Modules::enabled('arcade')` bernilai **true** hanya jika ketiganya lolos.
Semua kegagalan bersifat *fail-closed* (gagal membaca → dianggap nonaktif):

| Lapis | Cek | Kontrol |
|---|---|---|
| 1. **Fisik** | `arcade/index.php` ada di disk | Hapus folder = modul hilang |
| 2. **Flag** | File `arcade/.disabled` ada (diabaikan saat `MEEL_ENV=development`) | Kill-switch tingkat deploy |
| 3. **Toggle** | `site_settings.modules_arcade` ≠ `'0'` | Admin panel (runtime, persisten) |

Koneksi DB untuk lapis toggle *reuse* koneksi global `$conn` bila sudah ada,
atau membuat koneksi sendiri dari `auth/settings.php` — dan **tidak pernah
melempar exception**: tanpa DB/tabel, modul dianggap aktif (lapis 1 & 2 sudah
cukup).

## Cara Menonaktifkan / Mengaktifkan

### Cara 1 — Admin panel (disarankan)

1. Login sebagai admin → menu **☰ Modules** (atau buka `/admin/modules`).
2. Kartu **MEeL Arcade** → geser toggle.
3. Selesai. Efeknya langsung untuk semua user — tanpa restart, tanpa deploy.

Perubahan disimpan di `site_settings` (key `modules_arcade`) dan tercatat di
activity log (`toggle_module_arcade_1` / `toggle_module_arcade_0`).

### Cara 2 — Flag file (tingkat deploy/CLI)

```bash
# nonaktifkan
touch arcade/.disabled

# aktifkan kembali
rm arcade/.disabled
```

Flag ini diabaikan otomatis di lingkungan `development` agar developer lokal
tidak terkunci. Cocok untuk pipeline deploy yang ingin memastikan arcade
mati di server publik tanpa menyentuh DB.

### Cara 3 — Hapus folder

```bash
rm -rf arcade/
```

Tidak ada langkah lain yang diperlukan (lihat bagian berikutnya).

## Menghapus Arcade Sepenuhnya

Menghapus folder `arcade/` **tidak memerlukan edit kode sedikit pun**:

- Router memindahkan seluruh rute arcade ke peta rute opsional — yang
  hilang secara fisik otomatis dilayani redirect 302 ke HUB.
- Semua link/menu/sitemap bergantung pada `Modules::enabled()` → otomatis
  tidak dirender.
- `GarbageCollector::cleanChessRooms()` menjadi no-op.

## Perilaku Saat Nonaktif

| Permukaan | Perilaku |
|---|---|
| `/arcade/*` (halaman: beranda, chess, rhythm, editor, manage, edit) | **302 → HUB** (`/`) — redirect 302, bukan 301, agar tidak di-cache permanen dan arcade bisa diaktifkan lagi kapan saja |
| `/arcade/rhythm/api/*` + semua endpoint `arcade/chess/controller/*.php` | **JSON 404** `{"error": "Module not available"}` — konsisten dengan pola error arcade |
| File fisik di bawah `arcade/` (game statis html/js/css, lagu rhythm) | Di-gate oleh `arcade/.htaccess` **hanya** saat flag `.disabled` dipasang (302 → HUB) |
| Logo MEeL di home HUB | Dirender sebagai gambar biasa (bukan link arcade) |
| Menu admin "Chess Room" | Tidak dirender |
| `sitemap.xml` | URL `/arcade/beranda` + 8 halaman game dikecualikan |
| Garbage Collector | `cleanChessRooms()` return 0 tanpa query — tabel legacy tidak disentuh |
| PHPUnit | Suite chess & GC chess otomatis **skipped** (`markTestSkipped` / guard file-level) |
| Database | **Tidak ada perubahan** — tabel & data aman; mengaktifkan kembali = semuanya kembali seperti semula |

## Titik Integrasi di Kode

| File | Peran |
|---|---|
| `modules/core/Modules.php` | Gate terpusat: `exists()`, `enabled()`, `guardRedirect()`, `guardJson()`, `guardJson404()` |
| `modules/core/Router.php` | `OPTIONAL_ROUTES` (dipisah dari `ROUTES` inti) + redirect/JSON-404 di `dispatch()` |
| `arcade/.htaccess` | Gate statis untuk file fisik saat flag `.disabled` terpasang |
| `arcade/chess/controller/chess_helpers.php` | Guard JSON 404 untuk semua endpoint chess (di-skip saat CLI/PHPUnit) |
| `index.php` | Logo HUB kondisional |
| `admin/header-admin.php` | Menu "Chess Room" kondisional + menu "Modules" |
| `admin/modules.php` + `controllers/admin/admin_actions.php` | Halaman & handler toggle admin |
| `modules/core/GarbageCollector.php` | `cleanChessRooms()` no-op saat nonaktif |
| `sitemap.php` | Entri arcade kondisional |
| `tests/integration/Chess*Test.php`, `GarbageCollectorChessRoomsIntegrationTest.php` | Guard skip |

## Database

Tidak ada perubahan skema. Tabel berikut tetap ada dan idempoten:

| Tabel | Asal | Status saat modul hilang |
|---|---|---|
| `rooms`, `moves` | `database/schema.sql` (inti) | Dibiarkan — dijaga GC legacy & bersih sendiri |
| `arcade_song`, `arcade_score` | `arcade/rhythm/migration.sql` | Impor **opsional** — hanya jika MEeL!Mania dipakai |

## FAQ

**Apakah menonaktifkan arcade menghapus data?**
Tidak. Room catur, skor, dan lagu custom tetap utuh di DB. Toggle kembali ON → semua kembali normal.

**Kenapa redirect-nya 302, bukan 301?**
301 di-cache browser secara permanen; 302 memungkinkan arcade diaktifkan ulang tanpa masalah cache.

**Apa yang terjadi kalau DB mati?**
Gate tetap bekerja dari deteksi fisik + flag. Toggle DB gagal dibaca → modul dianggap aktif (fail-open hanya pada lapis toggle; tidak pernah memblokir HUB).

**Bagaimana menambah modul opsional baru?**
Tambahkan entri baru di `Modules::OPTIONAL` (marker, flag_file, setting,
home_route), pindahkan rutenya ke `OPTIONAL_ROUTES` bila lewat router, lalu
tambahkan kartu modulnya di `admin/modules.php`.
