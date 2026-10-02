# Versi & Atribusi Library Pihak Ketiga (assets/)

Inventaris versi eksak artefak **pihak ketiga** yang di-vendor (diunduh manual dan
di-commit ke repo) di seluruh `assets/` — JavaScript, CSS, font, dan model. File ini
dibuat dari hasil audit keamanan (2026-07-31), dipindah dari `assets/js/VERSIONS.md`
pada 2026-10-02 (ruang lingkupnya ternyata seluruh `assets/`, bukan hanya `assets/js/`),
dan **WAJIB diperbarui setiap kali file library di-update**.

> ⚠️ **Scope:** Hanya artefak pihak ketiga. File **custom** milik proyek
> (`script.min.js`, `player_*.js`, semua file di `assets/js/{video,music,drive,admin,shared}/`)
> **TIDAK** masuk daftar versi. Atribusi lisensi untuk adaptasi first-party berbasis
> kode pihak ketiga (mis. FidelityFX) dicatat terpisah di bawah.

## 📋 Daftar Versi — JavaScript / CSS

| Library | Versi | Sumber/CDN | Terakhir diverifikasi |
|---------|-------|------------|----------------------|
| `hls.js` | 1.6.15 | https://github.com/video-dev/hls.js/releases | 2026-07-31 |
| `plyr.min.js` | 3.8.4 | https://github.com/sampotts/plyr/releases | 2026-07-31 |
| `plyr.css` | 3.8.4 * | https://github.com/sampotts/plyr/releases | 2026-07-31 |
| `sweetalert2.all.min.js` | 11.26.25 | https://github.com/sweetalert2/sweetalert2/releases | 2026-07-31 |
| `htmx.min.js` | 1.9.10 | https://unpkg.com/htmx.org/ | 2026-07-31 |
| `lucide.js` | 0.575.0 | https://unpkg.com/lucide@latest/ | 2026-07-31 |
| `chart.umd.min.js` | 4.4.7 | https://www.jsdelivr.com/package/npm/chart.js | 2026-07-31 |
| `marked.min.js` | 15.0.12 | https://github.com/markedjs/marked/releases | 2026-07-31 |
| `qrcode.min.js` | **UNKNOWN** — lihat TODO | (tidak ada string versi; md5 `517b55d3688ce9ef1085a3d9632bcb97`) | 2026-10-02 |
| `anime4k-webgpu.js` (`assets/models/anime4k/model.js`) | 1.0.0 | https://www.npmjs.com/package/anime4k-webgpu | 2026-09-28 |
| `tailwind.min.css` | **3.4.19** | https://github.com/tailwindlabs/tailwindcss | 2026-10-02 |
| `script.min.js` | N/A — **file custom** (wrapper meelAlert/meelConfirm) | — | 2026-07-31 |
| `player_music.js` / `player_video.js` | N/A — **file custom** (dipecah ke `assets/js/music/` & `assets/js/video/`) | — | 2026-07-31 |

## 🎨 Font (di-vendor lokal)

Loader tunggal: **`assets/css/font.css`** (satu-satunya `@font-face` loader di proyek).

| Font | Berkas | Sumber | Lisensi | Terverifikasi |
|------|--------|--------|---------|---------------|
| JetBrains Mono (variable, wght 100–800) | `assets/css/font/{latin,latin-ext,cyrillic,vietnamese}.woff2` | Google Fonts build `jetbrainsmono/v24` (semula URL `fonts.gstatic.com` di commit `30704d7`, diunduh lokal 2026-05-21) | SIL OFL 1.1 | 2026-10-02 |
| Inter (variable, wght 100–900) | `assets/css/font/inter-{latin,latin-ext}.woff2` | `@fontsource-variable/inter@5.3.0` (jsDelivr/unpkg, dipin per versi) | SIL OFL 1.1 | 2026-10-02 |

**Dedupe 2026-10-02:** sebelumnya ada 18 file (±194 KB) — 12 di antaranya duplikat
byte-identik (variable font yang disalin 3× per subset untuk weight 400/700/800), plus
subset `greek` & `cyrillic-ext` yang tidak pernah dipakai. Kini tersisa 6 file
(JetBrains 57.816 B + Inter 133.324 B) dan `font.css` punya 6 `@font-face`
(4× `font-weight:100 800`, 2× `100 900`).

Subset disimpan: `latin`, `latin-ext` (pranaskah utama), `cyrillic` (label `'ru' =>
'Русский'` di `modules/core/helpers/subtitle.php`), `vietnamese` (label `'vi' =>
'Tiếng Việt'`). Subset `greek`/`cyrillic-ext` dihapus karena nol pemakaian.

## 🖋️ Font yang DIDEKLARASIKAN TANPA LOADER (keputusan: fallback sistem)

Keluarga berikut **dirujuk tapi sengaja tidak diunduh** — selalu memakai font sistem
fallback. Keputusan: **biarkan fallback** (audit 2026-10-02, agar tidak menambah beban
unduhan). Bila suatu saat ingin tampil sesuai desain, vendor keluarga ini dan tambahkan
baris di tabel font di atas.

| Family | Dirujuk di |
|--------|-----------|
| DM Sans | `.font-sans` (`assets/css/tailwind.min.css`) |
| Fraunces | `.font-display`, inline `index.html:38` |
| Syne | `.font-syne` |
| IBM Plex Mono | `.font-mono` |
| Fira Code | fallback `--font-mono` (`arcade/rhythm/assets/css/lobby.css`) |

*(Catatan: `Inter` sebelumnya juga tanpa loader di `assets/css/admin/catur.css:2` —
sejak 2026-10-02 terlayani oleh `font.css`.)*

## ☁️ Dependensi Runtime CDN — DIMINSALKAN (lokal semua sejak 2026-10-02)

Sebelum 2026-10-02 ada **4 referensi runtime ke CDN luar**; semuanya sudah diganti
dengan salinan lokal, sehingga repo kini **nol dependensi runtime CDN**:

| Referensi lama | Lokasi dahulu | Pengganti lokal |
|----------------|--------------|-----------------|
| `https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js` | `admin/activity_log.php:933` | `assets/js/compatibilitas/chart.umd.min.js` (v4.4.7) |
| `https://fonts.googleapis.com/css2?family=Inter…&family=JetBrains+Mono…` (3× `@import`) | `arcade/rhythm/assets/css/{game,lobby,editor}.css` | `assets/css/font.css` (Inter + JetBrains lokal) |

Seiring dengan itu, 2 link `font.css` yang rusak di rhythm (`../assets/` → `arcade/assets/…`
yang tidak ada) dan `@import` rusak di `assets/css/introduction.css` ikut diperbaiki
(menggunakan `<?= $root ?>` seperti halaman manage rhythm).

## 📝 Keterangan Verifikasi

- **hls.js 1.6.15** — diekstrak dari string versi di dalam bundle minified (`version 1.6.15`).
- **plyr 3.8.4** — diekstrak dari string versi di dalam `plyr.min.js`. *(Catatan: file
  `VENDOR_VERSIONS.md` lama menyebut 3.7.8 — itu perkiraan dan tidak akurat.)*
- **plyr.css `*`** — versi **diasumsikan sama dengan `plyr.min.js`** (satu paket rilis plyr),
  belum diverifikasi langsung dari isi file CSS.
- **sweetalert2 11.26.25** — dari header lisensi (`sweetalert2 v11.26.25`) + atribut `version`.
- **htmx 1.9.10** — dari string `version:"1.9.10"` di dalam bundle.
- **lucide 0.575.0** — dari header lisensi (`@license lucide v0.575.0 - ISC`).
- **chart.js 4.4.7** — dari header lisensi + path asal jsDelivr (`/npm/chart.js@4.4.7`).
- **tailwind.min.css 3.4.19** — dari banner di dalam file:
  `/*! tailwindcss v3.4.19 | MIT License | https://tailwindcss.com */` (TODO versi
  tertutup 2026-10-02; klaim lama "93909 bytes ~3.4.x" tidak dipakai lagi).
- **anime4k-webgpu 1.0.0** — dari `package.json` tarball npm (`anime4k-webgpu-1.0.0.tgz`);
  file `lib/index.js` di-vendor apa adanya + header atribusi MIT di bagian atas. Dipakai
  fitur AI Upscale di pemutar video (`assets/js/video/watch/upscaler.js`), di-load
  on-demand. Lokasi bundle: `assets/models/anime4k/model.js` (sebelumnya
  `assets/js/compatibilitas/anime4k-webgpu.js`).
- **qrcode.min.js** — pola kode (`var QRCode;!function(){…MODE_8BIT_BYTE…}`) konsisten
  dengan **qrcodejs** (davidshimjs/qrcodejs) namun **tanpa string versi internal**;
  dipakai `auth/mfa_setup.php`. Belum teridentifikasi eksak — lihat TODO.
- **Font** — lihat tabel font di atas (sumber & tanggal per baris).

## 🧩 Atribusi — Adaptasi First-Party (bukan file vendor mentah)

| File | Basis | Lisensi basis | Catatan |
|------|-------|---------------|---------|
| `assets/models/meelsharp/model.js`, `assets/models/meelscale/model.js` | AMD FidelityFX CAS | MIT (atribusi wajib di dalam file — **jangan dihapus**) | adaptasi/olah-ulang kode first-party |
| `assets/models/meelvision/*` | proyek first-party | MIT (header di dalam file) | model vision bawaan MEeL |

## 🛡️ Proses Pengecekan CVE / Security Advisory

Developer **wajib** melakukan hal berikut secara berkala:

1. **Cek advisory** untuk setiap library di atas minimal **setiap 6 bulan** (atau
   setiap ada rilis baru / release announcement), melalui:
   - https://github.com/advisories (cari `npm:<package>`)
   - https://www.cvedetails.com/ atau https://osv.dev/
   - Halaman releases masing-masing library (link di tabel).
2. Jika ada **CVE kritis/tinggi** yang belum di-patch → upgrade library, ganti file
   di `assets/`, lalu **update tabel di file ini**.
3. Setiap kali file library di-update → **update baris versinya di file ini** di
   commit yang sama, agar inventaris selalu sinkron dengan isi repo.
4. Untuk versi yang ditandai **UNKNOWN** → lakukan verifikasi manual (lihat TODO)
   lalu isi versi eksaknya.

## 📌 TODO — Verifikasi Manual

- [ ] **`assets/js/compatibilitas/qrcode.min.js`** — identifikasi versi eksak (coba
       bandingkan hash dengan rilis qrcodejs / paket npm terkait), lalu isi baris tabelnya.
- [ ] Konfirmasi ulang versi **`plyr.css`** saat upgrade plyr berikutnya (pastikan
       konsisten dengan `plyr.min.js`).

## 📌 Konvensi

- **`marks.php` `$excludes` = daftar resmi file pihak-ketiga** (tidak diberi marker
  `reference build:`). Saat ini memuat: `assets/css/{tailwind.min.css,plyr.css,font.css}`,
  seluruh `assets/js/compatibilitas/*` (per file), **`assets/models/anime4k/` (ditambahkan
  2026-10-02)**, `vendor/`, `docs/`, `temp/`, `storage/`, `logs`, `.env*`, `marks.php`.
  Jika menambah library pihak-ketiga baru → tambahkan ke `$excludes` **dan** ke tabel di file ini.
- File pihak-ketiga tidak boleh diedit isinya (kecuali penyesuaian path `@import`/`url()`).

---

> Terakhir diverifikasi: **2026-10-02** — audit font (dedupe + subset), lokalisasi seluruh
> dependensi CDN runtime, penyelesaian TODO tailwind (3.4.19 dari banner), penambahan
> baris font & qrcode. Versi hls/plyr/htmx/lucide/chart/sweetalert2 dibaca langsung dari
> isi bundle (2026-07-31); `anime4k-webgpu` dari metadata npm (2026-09-28).
