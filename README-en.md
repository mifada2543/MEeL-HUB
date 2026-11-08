# MEeL-HUB

<div align="center">

[🇮🇩 Indonesia](README.md) · **🇬🇧 English**

</div>

<div align="center">
  <img src="assets/MEeL.png" alt="MEeL Logo" width="420"/>
  <br><br>
  <strong>Self-hosted media platform — open source, no subscription fees.</strong>
  <br>
  <sub>Video streaming, music playback, e-book reader, and file storage — all from your own server. <a href="docs/en/arcade-optional.md">Arcade</a> is available as a separate extension.</sub>
  <br><br>

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://www.mysql.com/)
[![FFmpeg](https://img.shields.io/badge/FFmpeg-n6.1.1%20%28bundled%29-007808?style=flat-square&logo=ffmpeg&logoColor=white)](tools/ffmpeg-lite)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPLv3-blue?style=flat-square)](LICENSE)
[![CI](https://github.com/mifada2543/MEeL-HUB/actions/workflows/ci.yml/badge.svg)](https://github.com/mifada2543/MEeL-HUB/actions/workflows/ci.yml)
[![GitHub Stars](https://img.shields.io/github/stars/mifada2543/MEeL-HUB?style=social)](https://github.com/mifada2543/MEeL-HUB)

</div>
<div align="center">
  <a href="https://github.com/mifada2543/MEeL-HUB">
    <img src="assets/og-image.png" alt="MEeL-HUB — self-hosted PHP & MySQL media hub for HLS video streaming, lossless music, digital books, and cloud drive" width="100%"/>
  </a>
</div>
<table>
  <tr>
    <td align="center"><strong>HUB Home</strong></td>
    <td align="center"><strong>Video Library</strong></td>
    <td align="center"><strong>Music Discovery</strong></td>
  </tr>
  <tr>
    <td><img src="assets/img/screen-hub-home.webp" alt="MEeL HUB home page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-video-library.webp" alt="MEeL video library page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-music-library.webp" alt="MEeL music discovery page" width="100%" loading="lazy"/></td>
  </tr>
</table>

---

## 📑 Table of Contents

- [📸 Screenshot Gallery](#-screenshot-gallery)
- [✨ Features](#-features)
- [📋 Requirements](#-requirements)
- [⚡ Quick Start](#-quick-start)
- [🛠 Tech Stack](#-tech-stack)
- [📁 Project Structure](#-project-structure)
- [📚 Documentation](#-documentation)
- [🧪 Testing](#-testing)
- [👥 Role-Based Access Control](#-role-based-access-control)
- [🤝 Contributing](#-contributing)
- [🔐 Security](#-security)
- [📄 License](#-license)
- [⚠️ Disclaimer](#-disclaimer)
- [📬 Contact](#-contact)

---

## 📸 Screenshot Gallery

Every page below is reachable **without logging in** (guest mode). All images are captured straight from a running MEeL instance, so what you see here always matches the code that is currently running.

<details>
<summary><strong>🖼️ See 15 more screenshots (18 total)</strong></summary>

<h3 align="center">🎬 Video</h3>

<table>
  <tr>
    <td><img src="assets/img/screen-video-library-light.webp" alt="MEeL video library in light mode" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-video-player.webp" alt="MEeL video player with HLS streaming" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-video-player-2.webp" alt="MEeL video player" width="100%" loading="lazy"/></td>
  </tr>
  <tr>
    <td><img src="assets/img/screen-video-player-3.webp" alt="MEeL video player" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-guide.webp" alt="MEeL user guide page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-changelog.webp" alt="MEeL release notes page" width="100%" loading="lazy"/></td>
  </tr>
</table>

<h3 align="center">🎵 Music & Accounts</h3>

<table>
  <tr>
    <td><img src="assets/img/screen-music-library-light.webp" alt="MEeL music discovery in light mode" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-music-player.webp" alt="MEeL music player with WebAudio visualizer" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-auth-login.webp" alt="MEeL sign-in page" width="100%" loading="lazy"/></td>
  </tr>
  <tr>
    <td><img src="assets/img/screen-auth-register.webp" alt="MEeL sign-up page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-profile.webp" alt="MEeL user profile page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-error-404.webp" alt="MEeL 404 error page" width="100%" loading="lazy"/></td>
  </tr>
</table>

<h3 align="center">🖥️ System</h3>

<table>
  <tr>
    <td><img src="assets/img/screen-hub-home-light.webp" alt="MEeL HUB home page in light mode" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-landing.webp" alt="MEeL landing page" width="100%" loading="lazy"/></td>
    <td><img src="assets/img/screen-docs.webp" alt="MEeL documentation page" width="100%" loading="lazy"/></td>
  </tr>
</table>

</details>

---

## ✨ Features

<table>
  <tr>
    <td width="50%">
      <h3>🎬 Video</h3>
      <p>HLS adaptive streaming player with subtitle support, resolution switching, picture-in-picture, auto-resume, thumbnail previews, auto-next countdown, ambient glow, and connection recovery.</p>
    </td>
    <td width="50%">
      <h3>🎵 Music</h3>
      <p>Audio playback for MP3, FLAC, OGG, and M4A with a WebAudio visualizer. Real-time karaoke lyrics with timestamp synchronization (LRC). Playlist creation, smart queue management, and a persistent mini-player anchored at the bottom of the screen.</p>
    </td>
  </tr>
  <tr>
    <td width="50%">
      <h3>📚 Books</h3>
      <p>In-browser reader for manga (ZIP/CBZ) and PDF documents. Thumbnails generated automatically on upload, structured metadata, distraction-free reading interface.</p>
    </td>
    <td width="50%">
      <h3>☁️ Cloud Drive</h3>
      <p>File storage with public and private directories. Per-member quotas, automatic type filtering, in-browser preview, and magic byte validation to prevent corrupted uploads.</p>
    </td>
  </tr>
  <tr>
    <td colspan="2">
      <h3>🕹️ Arcade <sup>(separate extension)</sup></h3>
      <p>
        9 mini-games: <strong>Miku &amp; Teto Run</strong> · <strong>Chess</strong> (multiplayer) · <strong>Snake</strong> · <strong>2048</strong> · <strong>Tetris</strong> · <strong>Breakout</strong> · <strong>Simon Says</strong> · <strong>Ludo</strong> · <strong>MEeL!Mania</strong> (rhythm)
        <br><sub>Optional extension — installed separately from the HUB. See <a href="docs/en/arcade-optional.md">Arcade Guide</a>.</sub>
      </p>
    </td>
  </tr>
</table>

**Additional features:** Admin dashboard · Transcoder · Video download from URL (yt-dlp) · Comments & chat · Like/dislike · User profiles · Light/dark mode · PWA offline · MFA (TOTP) · Audit trail · MEeLCoin quota system · Rate limiting

> 🔒 **Built without frameworks** — the router, autoloader, and anti-SSRF validating proxy were all developed in-house. Refer to [docs/en/security.md](docs/en/security.md) for technical details.

---

## 📋 Requirements

| Component       | Minimum                                                                                                              |
| --------------- | -------------------------------------------------------------------------------------------------------------------- |
| **PHP**         | 8.2+ with `mysqli`, `pdo_mysql`, `fileinfo`, `mbstring`, `intl`, `gd`, `zip`, `xml`, and `curl`                      |
| **Database**    | MySQL 5.7+ / MariaDB 10.2+ with `utf8mb4` encoding                                                                   |
| **Web server**  | Apache 2.4+ with `mod_rewrite` and `mod_headers`                                                                     |
| **Media**       | `ffmpeg-lite` 6.1.1 (bundled in `tools/ffmpeg-lite`) · `yt-dlp` for URL downloads                                    |
| **Resources**   | 2 GB RAM (4 GB+ for transcoding) · 10 GB+ storage                                                                    |

> `install.sh` installs and verifies all of the dependencies above automatically.

---

## ⚡ Quick Start

```bash
git clone https://github.com/mifada2543/MEeL-HUB.git MEeL
cd MEeL
chmod +x install.sh
./install.sh
```

A single command to set up everything — database, configuration, storage directories, Apache, migrations, and verification.

<details>
<summary><strong>Manual installation (7 steps)</strong></summary>

```bash
# 1. Clone
git clone https://github.com/mifada2543/MEeL-HUB.git MEeL && cd MEeL

# 2. Database
mysql -u root -p -e "CREATE DATABASE MEeL DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
mysql -u root -p MEeL < database/schema.sql

# 3. Config
cp auth/settings.example.php auth/settings.php
cp auth/config.example.php auth/config.php
# Edit auth/settings.php — fill DB credentials & set MEEL_HDD_BASE

# 4. Storage
mkdir -p data_drive/public data_drive/private_admins temp profile/upload \
         music/upload/file music/upload/thumbnail \
         books/upload/manga books/upload/pdf books/upload/thumbnail
sudo chown -R www-data:www-data data_drive temp profile/upload music/upload books/upload

# 5. Apache
sudo a2enmod rewrite headers && sudo systemctl restart apache2

# 6. Migrate
php database/migrate.php

# 7. Verify
php tools/check_deploy.php
```

</details>

> 📖 [Full installation guide](docs/en/installation.md) · [Bahasa Indonesia](docs/id/installation.md)

---

## 🛠 Tech Stack

|              |                                               |
| ------------ | --------------------------------------------- |
| **Backend**  | PHP 8.2+ · MySQL/MariaDB · Apache             |
| **Frontend** | TailwindCSS · HTMX · Vanilla JS               |
| **Media**    | Plyr.js · HLS.js · FFmpeg · yt-dlp            |
| **Testing**  | PHPUnit 9.6 · GitHub Actions CI               |
| **Security** | CSRF · Rate Limiting · MFA · Audit Log · RBAC |
| **Extras**   | PWA · Service Worker · Class-Map Autoloader   |

---

## 📁 Project Structure

```
MEeL/
├── admin/          # Admin panel
├── arcade/         # Arcade extension (separate — see docs/en/arcade-optional.md)
├── assets/         # CSS, JS, images
├── auth/           # Authentication & config
├── books/          # Digital book reader
├── controllers/    # API endpoints
├── database/       # Schema & migrations
├── docs/           # Documentation (id + en)
├── drive/          # Cloud drive
├── modules/        # Core logic (OOP)
├── music/          # Music player
├── scripts/        # Utility scripts (CSS build, audits, backup)
├── tests/          # PHPUnit tests (source repo; not in the main ZIP)
├── tools/          # Toolchain + health check (tools/check_deploy.php)
├── video/          # Video player
└── install.sh      # One-command installer
```

---

## 📚 Documentation

|                       |              🇮🇩 ID               |              🇬🇧 EN               |
| --------------------- | :------------------------------: | :------------------------------: |
| Landing Page          |  [ID](docs/id/index.md)          |  [EN](docs/en/index.md)          |
| Installation          |  [ID](docs/id/installation.md)   |  [EN](docs/en/installation.md)   |
| Configuration         |  [ID](docs/id/configuration.md)  |  [EN](docs/en/configuration.md)  |
| Modules & Architecture |     [ID](docs/id/modules.md)     |     [EN](docs/en/modules.md)     |
| API                   |       [ID](docs/id/api.md)       |       [EN](docs/en/api.md)       |
| Security              |    [ID](docs/id/security.md)     |    [EN](docs/en/security.md)     |
| Development           |   [ID](docs/id/development.md)   |   [EN](docs/en/development.md)   |
| Testing               |     [ID](docs/id/testing.md)     |     [EN](docs/en/testing.md)     |
| Troubleshooting       | [ID](docs/id/troubleshooting.md) | [EN](docs/en/troubleshooting.md) |
| PWA                   |       [ID](docs/id/pwa.md)       |       [EN](docs/en/pwa.md)       |
| Arcade                | [ID](docs/id/arcade-optional.md) | [EN](docs/en/arcade-optional.md) |
| Download              |     [ID](docs/id/download.md)     |     [EN](docs/en/download.md)     |

Release history: [CHANGELOG-core.md](CHANGELOG-core.md) · [CHANGELOG-arcade.md](CHANGELOG-arcade.md).

---

## 🧪 Testing

> The test count grows over time. Run the commands below for current figures; CI runs the unit, integration, functional, and security suites.

```bash
composer install
vendor/bin/phpunit --testsuite='MEeL Core Unit Tests'    # Unit
vendor/bin/phpunit --testsuite='MEeL Integration Tests'   # Integration (MySQL)
php tests/functional_test.php                              # Functional
php tests/security_test.php                                # Security
php tools/check_deploy.php                                 # Deployment
```

---

## 👥 Role-Based Access Control

| Role       | Access                                                                          |
| ---------- | ------------------------------------------------------------------------------- |
| **Admin**  | Full control over all modules, admin panel, user management, transcode, audit log |
| **Member** | All media, comments, books, cloud drive (20 GB)                                 |
| **User**   | All media, comments, books (no drive)                                           |
| **Guest**  | View/listen only, theme toggle                                                  |

---

## 🤝 Contributing

Contributions in the form of bug reports, feature suggestions, or pull requests are welcome.

1. Fork the repository and create a branch (`feat/feature-name` or `fix/bug-name`).
2. Run `composer install`, then the relevant test suite.
3. Open a pull request with a description of the change and verification steps.

Refer to the [bug report template](.github/ISSUE_TEMPLATE/bug_report.md).

---

## 🔐 Security

If you discover a security vulnerability, please do not open a public issue. Report it privately to [mifada2543@gmail.com](mailto:mifada2543@gmail.com) so it can be addressed before disclosure.

---

## 📄 License

[GNU Affero General Public License v3.0 (AGPLv3)](LICENSE) · © 2026 Mifada

---

## ⚠️ Disclaimer

> [!IMPORTANT]
> Mifada (the developer) assumes no responsibility for media content uploaded, stored, or distributed by third parties who use or modify MEeL-HUB. All usage risks and copyright compliance obligations rest with each individual user.

---

## 📚 Further Documentation

- [Documentation Landing (EN)](docs/en/index.md) · [Landing Page Dokumentasi (ID)](docs/id/index.md)

---

## 📬 Contact

**Email:** mifada2543@gmail.com · **GitHub:** [github.com/mifada2543](https://github.com/mifada2543)
