# MEeL-HUB

<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="420"/>
  <br><br>
  <strong>Netflix + Spotify + Dropbox pribadi kamu — self-hosted, open source.</strong>
  <br>
  <sub>Streaming video & musik, baca buku, simpan file, main game — semuanya dalam satu tempat.</sub>
  <br><br>

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![FFmpeg](https://img.shields.io/badge/FFmpeg-6.0%2B-007808?style=flat-square&logo=ffmpeg&logoColor=white)](https://ffmpeg.org/)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue?style=flat-square)](LICENSE)
[![CI](https://github.com/mifada2543/MEeL/actions/workflows/ci.yml/badge.svg)](https://github.com/mifada2543/MEeL/actions/workflows/ci.yml)
[![GitHub Stars](https://img.shields.io/github/stars/mifada2543/MEeL?style=social)](https://github.com/mifada2543/MEeL)

</div>

---

## 📸 Screenshots

<table>
  <tr>
    <td align="center"><strong>🎬 Video Library</strong></td>
    <td align="center"><strong>🎵 Music Discovery</strong></td>
  </tr>
  <tr>
    <td><img src="assets/img/video0.webp" alt="Video Library" width="100%"/></td>
    <td><img src="assets/img/music0.webp" alt="Music Discovery" width="100%"/></td>
  </tr>
</table>

---

## ✨ MEeL bisa apa aja?

<table>
  <tr>
    <td width="50%">
      <h3>🎬 Video</h3>
      <p>Streaming HLS adaptif dengan player kustom. Subtitle, quality selector, PiP, resume otomatis, thumbnail preview, auto-next countdown, ambient glow, dan recovery otomatis.</p>
    </td>
    <td width="50%">
      <h3>🎵 Musik</h3>
      <p>Audio streaming (MP3, FLAC, OGG, M4A) dengan visualizer WebAudio, playlist kustom, smart queue, dan mini-player persisten ala Spotify.</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <h3>📚 Buku</h3>
      <p>Pembaca buku digital di browser — support manga (ZIP/CBZ) dan PDF. Auto-thumbnail saat upload, manajemen metadata, dan antarmuka baca yang bersih.</p>
    </td>
    <td width="50%">
      <h3>☁️ Cloud Drive</h3>
      <p>Penyimpanan file pribadi dengan scope public & private, kuota per member, filter tipe otomatis, preview di browser, dan validasi magic bytes.</p>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <h3>🕹️ Arcade — 9 Game Built-in</h3>
      <p>
        <strong>Miku & Teto Run</strong> · <strong>Catur</strong> (multiplayer) · <strong>Snake</strong> · <strong>2048</strong> · <strong>Tetris</strong> · <strong>Breakout</strong> · <strong>Simon Says</strong> · <strong>Ludo</strong> · <strong>MEeL!Mania</strong> (rhythm)
      </p>
    </td>
  </tr>
</table>

**Dan lainnya:** Dashboard admin · Transcoder · Downloader URL (yt-dlp) · Komentar & chat · Like/dislike · Profil user · Light/Dark mode · PWA offline · MFA (TOTP) · Audit trail · Sistem kuota MEeLCoin · Rate limiting

---

## ⚡ Quick Start

```bash
git clone https://github.com/mifada2543/MEeL.git MEeL
cd MEeL
chmod +x install.sh
./install.sh
```

Selesai. Installer menangani semuanya: database, konfigurasi, storage, Apache, migrasi, dan verifikasi.

<details>
<summary><strong>Instalasi manual (7 langkah)</strong></summary>

```bash
# 1. Clone
git clone https://github.com/mifada2543/MEeL.git MEeL && cd MEeL

# 2. Database
mysql -u root -p -e "CREATE DATABASE MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p MEeL < database/schema.sql

# 3. Konfigurasi
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php
# Edit auth/settings.php — isi kredensial DB & set MEEL_HDD_BASE

# 4. Storage
mkdir -p data_drive/public data_drive/private_admins temp profile/upload \
         music/upload/file music/upload/thumbnail \
         books/upload/manga books/upload/pdf books/upload/thumbnail
sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload

# 5. Apache
sudo a2enmod rewrite && sudo systemctl restart apache2

# 6. Migrasi
php database/migrate.php

# 7. Verifikasi
php tests/check_deploy.php
```

</details>

> 📖 [Panduan instalasi lengkap](docs/id/installation.md) · [English](docs/en/installation.md)

---

## 🛠️ Tech Stack

| | |
|---|---|
| **Backend** | PHP 8.0+ · MySQL/MariaDB · Apache |
| **Frontend** | TailwindCSS · HTMX · Vanilla JS |
| **Media** | Plyr.js · HLS.js · FFmpeg · yt-dlp |
| **Testing** | PHPUnit 9.6 · GitHub Actions CI |
| **Keamanan** | CSRF · Rate Limiting · MFA · Audit Log · RBAC |
| **Lainnya** | PWA · Service Worker · Class-Map Autoloader |

> Butuh: PHP 8.0+ dengan ekstensi `mysqli`, `pdo_mysql`, `gd`, `intl`, `zip`, `curl` · FFmpeg 6.0+ · Apache 2.4+ dengan `mod_rewrite`

---

## 📁 Struktur Proyek

```
MEeL/
├── admin/          # Panel admin
├── arcade/         # 9 mini-game
├── assets/         # CSS, JS, gambar
├── auth/           # Autentikasi & config
├── books/          # Pembaca buku digital
├── controllers/    # API endpoints
├── database/       # Schema & migrasi
├── docs/           # Dokumentasi (id + en)
├── drive/          # Cloud drive
├── modules/        # Core logic (OOP)
├── music/          # Pemutar musik
├── tests/          # PHPUnit tests
├── video/          # Pemutar video
└── install.sh      # Installer satu komando
```

---

## 📚 Dokumentasi

| | 🇮🇩 ID | 🇬🇧 EN |
|---|:---:|:---:|
| Instalasi | [ID](docs/id/installation.md) | [EN](docs/en/installation.md) |
| Konfigurasi | [ID](docs/id/configuration.md) | [EN](docs/en/configuration.md) |
| Modul & Arsitektur | [ID](docs/id/modules.md) | [EN](docs/en/modules.md) |
| API | [ID](docs/id/api.md) | [EN](docs/en/api.md) |
| Keamanan | [ID](docs/id/security.md) | [EN](docs/en/security.md) |
| Development | [ID](docs/id/development.md) | [EN](docs/en/development.md) |
| Testing | [ID](docs/id/test.md) | [EN](docs/en/test.md) |
| Troubleshooting | [ID](docs/id/troubleshooting.md) | [EN](docs/en/troubleshooting.md) |
| PWA | [ID](docs/id/pwa.md) | [EN](docs/en/pwa.md) |

---

## 🧪 Testing

```bash
composer install
vendor/bin/phpunit --testsuite='MEeL Core Unit Tests'    # Unit
vendor/bin/phpunit --testsuite='MEeL Integration Tests'   # Integration (MySQL)
php tests/functional_test.php                              # Functional
php tests/security_test.php                                # Security
php tests/check_deploy.php                                 # Deployment
```

---

## 👥 Role-Based Access Control

| Role | Akses |
|------|-------|
| **Admin** | Kontrol penuh — semua modul, admin panel, manajemen user, transcode, audit log |
| **Member** | Semua media, komentar, buku, cloud drive (20 GB) |
| **User** | Semua media, komentar, buku (tanpa drive) |
| **Guest** | Tonton/dengar saja, theme toggle |

---

## 🤝 Contributing

Kontribusi, bug report, dan saran fitur dipersilakan. Lihat [template bug report](.github/ISSUE_TEMPLATE/bug_report.md).

---

## 📄 License

[GNU General Public License v3.0 (GPLv3)](LICENSE) · © 2026 Mifada

---

## ⚠️ Disclaimer

> [!IMPORTANT]
> Pembuat (Mifada) tidak bertanggung jawab atas berkas media yang diunggah, disimpan, atau disebarluaskan oleh pihak ketiga yang menggunakan atau memodifikasi MEeL-HUB. Seluruh risiko penggunaan dan kepatuhan hak cipta menjadi tanggung jawab masing-masing pengguna.

## 📬 Kontak

**Email:** mifada2543@gmail.com · **GitHub:** [github.com/mifada2543](https://github.com/mifada2543)
