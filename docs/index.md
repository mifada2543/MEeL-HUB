# 📚 Dokumentasi MEeL-HUB

Selamat datang di dokumentasi resmi **MEeL** — Platform Media Hub Pribadi untuk streaming video, musik, buku digital, dan cloud storage.

---

## 📋 Peta Dokumentasi

| # | Dokumen | Deskripsi |
|---|---------|-----------|
| 1 | [🚀 Instalasi](installation.md) | Panduan instalasi lengkap dari awal hingga aplikasi berjalan |
| 2 | [⚙️ Konfigurasi](configuration.md) | Referensi semua file konfigurasi dan parameter |
| 3 | [🏗️ Modul & Arsitektur](modules.md) | Penjelasan mendalam setiap modul dan class |
| 4 | [🔌 API & Controller](api.md) | Dokumentasi semua endpoint AJAX/HTMX |
| 5 | [🔒 Keamanan](security.md) | Sistem keamanan, RBAC, CSRF, IP Banning |
| 6 | [🔧 Troubleshooting](troubleshooting.md) | Solusi untuk masalah umum |
| 7 | [👨‍💻 Panduan Development](development.md) | Standar koding, kontribusi, dan testing |
| 8 | [📥 Troubleshooting Advanced Upload](upload_issue.md) | Penanganan masalah yt-dlp & background queue |

---

## 📁 File Penting Baru

| File | Deskripsi |
|------|-----------|
| `database/schema.sql` | Skema database standalone — import langsung `mysql < database/schema.sql` |
| `auth/config.example.php` | Template konfigurasi (copy ke `config.php`) |

## 🔧 Perubahan Terbaru

- **Path terpusat:** Semua path penyimpanan media (Video, Music, Books, Drive) diatur dari `MEEL_HDD_BASE` di `auth/config.php` — cukup ubah 1 baris
- **Skema database standalone:** File `database/schema.sql` untuk import cepat
- **Type hints:** Properti class dan parameter constructor sekarang menggunakan type hints (`\mysqli`, `int`, `string`, dll.)

## 📖 Tentang Proyek

**MEeL** adalah platform media hub pribadi berbasis PHP & MySQL yang berjalan di atas Apache. Platform ini menggabungkan:

- **🎬 Video** — Streaming adaptif HLS dengan Plyr.js
- **🎵 Music** — Audio streaming dengan visualizer & mini player
- **📚 Books** — Pembaca manga/PDF digital
- **☁️ Cloud Drive** — Penyimpanan file pribadi dengan RBAC
- **🕹️ Arcade** — Mini-game (Dino Run, Chess)

### Tech Stack Utama

| Komponen | Teknologi |
|----------|-----------|
| Backend | PHP 8.0+, MySQL/MariaDB |
| Frontend | TailwindCSS, HTMX, Vanilla JS |
| Media Player | Plyr.js, HLS.js |
| Transcoding | FFmpeg 6.0+, FFprobe |
| Downloader | yt-dlp |
| Server | Apache 2.4+ (mod_rewrite) |

---

## 🔗 Tautan Penting

- [README.md](../README.md) — Ikhtisar proyek
- [LICENSE](../LICENSE) — Lisensi proyek
- [GitHub Repository](https://github.com/mifada2543/MEeL) — Repo sumber
- [Bug Report](../.github/ISSUE_TEMPLATE/bug_report.md) — Template laporan bug

---

## 👨‍💻 Kontak

- **Email:** minecraft.daffa2501@gmail.com
- **GitHub:** [github.com/mifada2543](https://github.com/mifada2543)

---

<div align="center">
  <sub>MEeL © 2025 — Mifada | Dokumentasi v1.0</sub>
</div>
