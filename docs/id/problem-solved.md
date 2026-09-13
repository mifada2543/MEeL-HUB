# 🌍 Masalah Dunia Nyata yang MEeL Selesaikan

Dokumen ini menjelaskan masalah spesifik yang mendorong pembuatan MEeL dan bagaimana platform ini mengatasinya.

---

## Daftar Isi

- [🔰 Kisah di Balik MEeL](#-kisah-di-balik-meel)
- [💰 Biaya Langganan yang Menumpuk](#-biaya-langganan-yang-menumpuk)
- [🔒 Privasi & Kepemilikan Data](#-privasi--kepemilikan-data)
- [📂 Koleksi Media Tersebar](#-koleksi-media-tersebar)
- [🎞️ Keterbatasan Format & Kualitas](#%EF%B8%8F-keterbatasan-format--kualitas)
- [🌐 Akses di Jaringan Lokal Tanpa Internet](#-akses-di-jaringan-lokal-tanpa-internet)
- [👨‍👩‍👧‍👦 Berbagi Media dengan Keluarga/Teman](#-berbagi-media-dengan-keluargateman)
- [🎮 Ketergantungan pada Platform Komersial](#-ketergantungan-pada-platform-komersial)
- [🛠️ Kontrol Penuh atas Konten](#%EF%B8%8F-kontrol-penuh-atas-konten)
- [📊 Dampak Finansial: Setahun Pakai MEeL](#-dampak-finansial-setahun-pakai-meel)
- [🧩 Gambaran Besar](#-gambaran-besar)
- [💬 Testimonial](#-testimonial)
- [⚖️ Pertimbangan](#pertimbangan-sebelum-pakai-meel)

---

## 🔰 Kisah di Balik MEeL

### Sang Pencipta

Saya **Mifada** — developer yang menggunakan MEeL untuk kebutuhan media pribadi.

Saat menghitung pengeluaran bulanan untuk layanan digital, totalnya mencapai jutaan rupiah per tahun. Sebagian besar biaya tersebut dialokasikan untuk konten yang sebenarnya bisa dikelola sendiri.

Pertanyaan yang muncul:
> *"Kenapa harus membayar Netflix, Spotify, YouTube Premium, Google Drive, dan layanan lainnya — kalau semuanya bisa dikelola sendiri?"*

### Alasan Pembuatan MEeL

MEeL dibuat untuk mengatasi beberapa kendala praktis:

- Biaya langganan yang terus meningkat setiap tahun
- Konten yang hilang karena lisensi habis masa berlakunya
- Data pribadi yang dikumpulkan dan dijual ke pihak ketiga
- Koleksi media yang tersebar di berbagai platform
- Kualitas streaming yang ditentukan oleh algoritma platform

---

## 💰 Biaya Langganan yang Menumpuk

### ❌ Masalah

Rincian pengeluaran digital rata-rata per bulan:

| Layanan | Biaya/Bulan (Rp) | Keperluan | Frekuensi Pakai |
|---|---|---|---|
| Netflix | 54.000 - 186.000 | Film & Series | ⭐⭐⭐ Setiap hari |
| Spotify / Apple Music | 54.990 | Musik | ⭐⭐⭐ Setiap hari |
| YouTube Premium | 59.000 | Video bebas iklan | ⭐⭐⭐ Setiap hari |
| Google One (100GB) | 27.000 | Cloud storage | ⭐⭐ Kadang-kadang |
| iCloud / Dropbox | 16.000 - 169.000 | Backup data | ⭐⭐ Kadang-kadang |
| Max (HBO Max) | 79.000 | Hiburan tambahan | ⭐ Seminggu sekali |

**Total per bulan: Rp 290.000 - Rp 535.000**
**Total per tahun: Rp 3.480.000 - Rp 6.420.000**

Dan itu **belum termasuk**:
- Kenaikan harga langganan tiap tahun (Netflix menaikkan harga 1-2x setahun)
- Biaya internet untuk streaming (kuota cepat habis)
- VPN jika konten di-region-lock
- In-app purchases atau konten premium tambahan

### ✅ Solusi MEeL

**MEeL menggabungkan semua layanan dalam satu platform:**

```text
┌─────────────────────────────────────────────────────────┐
│                    MEeL HUB                             │
├───────────┬───────────┬──────────┬──────────┬───────────┤
│  🎬 Video  │  🎵 Musik  │ 📚 Buku  │ ☁️ Drive  │ 🕹️ Game   │
│  Streaming │  Pemutar  │  Pembaca │ Penyimpan │  Arkade   │
│  (HLS.js)  │  (Opus)   │ (PDF/ZIP)│ (RBAC)    │ (9 Games)   │
├───────────┴───────────┴──────────┴──────────┴───────────┤
│  Biaya per bulan: Rp 0 (nol)                            │
│  Biaya per tahun: Rp 0 (nol)                            │
│  Cukup sediakan: Server + HDD + Listrik                 │
└─────────────────────────────────────────────────────────┘
```

**Apa yang Anda dapatkan:**

| Layanan | Platform | Biaya | MEeL | Biaya |
|---|---|---|---|---|
| 🎬 Video | YouTube Premium | Rp 59.000/bln | ✅ HLS Streaming | **Gratis** |
| 🎵 Musik | Spotify | Rp 54.990/bln | ✅ Lossless Audio | **Gratis** |
| 📚 Buku | Langganan buku | Rp 50.000+/bln | ✅ Manga/PDF Reader | **Gratis** |
| ☁️ Drive | Google One | Rp 27.000/bln | ✅ Cloud Drive (20GB+) | **Gratis** |
| 🎞️ Converter | Software converter | Rp 100.000+/sekali | ✅ FFmpeg Transcoding | **Gratis** |
| 🕹️ Hiburan | Game pass | Rp 50.000+/bln | ✅ Mini Arcade Games | **Gratis** |

> **Total hemat: Rp 3,5 - 6,4 JUTA per tahun.**

---

## 🔒 Privasi & Kepemilikan Data

### ❌ Masalah

Platform komersial memperoleh pendapatan dari data pengguna. Model bisnis ini menjadi standar industri.

```text
Anda → [ Platform ] → Kumpulkan data → Analisis → Jual ke pengiklan → 💰
```

**Data yang dikumpulkan platform komersial:**

| Jenis Data | YouTube | Spotify | Google Drive |
|---|---|---|---|
| Riwayat tontonan/dengar | ✅ | ✅ | ❌ |
| Preferensi & minat | ✅ | ✅ | ❌ |
| Lokasi geografis | ✅ | ✅ | ✅ |
| Perangkat yang digunakan | ✅ | ✅ | ✅ |
| Konten file pribadi | ❌ | ❌ | ❌ (dipindai untuk malware, bukan untuk iklan) |
| Kebiasaan & jadwal | ✅ | ✅ | ❌ |
| Data untuk profiling psikologis | ✅ | ✅ | ❌ |

**Konsekuensi dari model ini:**
- Data digunakan untuk personalisasi iklan (kecuali Google Drive)
- Konten bisa dihapus tanpa pemberitahuan
- Lisensi musik/video bisa dicabut kapan saja
- Iklan ditargetkan berdasarkan data pribadi
- Algoritma menentukan apa yang ditampilkan

### ✅ Solusi MEeL

```text
Anda → [ 🖥️ MEeL (Server Lokal) ] → 100% Data milik Anda → 🔒
```

**MEeL berjalan di server pribadi:**
- ✅ **Zero data collection** — Data tidak keluar dari server
- ✅ **Zero ads** — Tidak ada iklan atau pelacakan
- ✅ **Zero scanning** — File tidak dipindai oleh pihak mana pun
- ✅ **100% ownership** — Konten sepenuhnya milik pengguna
- ✅ **No licensing BS** — Akses ke koleksi tidak bisa dicabut
- ✅ **LAN-only option** — Bisa berjalan tanpa koneksi internet

---

## 📂 Koleksi Media Tersebar

### ❌ Masalah

Distribusi media pada pengguna rata-rata:

| Jenis Media | Lokasi 1 | Lokasi 2 | Lokasi 3 |
|---|---|---|---|
| 🎬 Video | Laptop | YouTube | Google Drive |
| 🎵 Musik | HP | Spotify playlist | Laptop kantor |
| 📚 Komik/Manga | Folder laptop | HP | Flashdisk |
| 📄 Dokumen | Email | Google Drive | Flashdisk |
| 🖼️ Foto | HP | iCloud | Google Photos |

Mencari satu file memerlukan membuka beberapa aplikasi secara bergantian.

### ✅ Solusi MEeL

**Semua media dalam satu dashboard:**

```
                       ┌─────────────┐
                       │    MEeL     │
                       │   DASHBOARD │
                       └──────┬──────┘
                              │
          ┌───────────────────┼───────────────────┐
          │                   │                   │
     ┌────▼────┐        ┌────▼────┐         ┌────▼────┐
     │  VIDEO  │        │  MUSIC  │         │  BOOKS  │
     ├─────────┤        ├─────────┤         ├─────────┤
     │ HLS     │        │ Opus    │         │ Manga   │
     │ MP4     │        │ MP3     │         │ PDF     │
     │ MKV     │        │ FLAC    │         │ CBZ     │
     └─────────┘        └─────────┘         └─────────┘

     ┌─────────────┐   ┌─────────────┐   ┌─────────────┐
     │ CLOUD DRIVE │   │  TRANSCODER │   │   ARCADE    │
     ├─────────────┤   ├─────────────┤   ├─────────────┤
     │ Public      │   │ Video→Audio │   │ 9 Mini Games│
     │ Private     │   │ HLS→MP4     │   │ (Dino, Snake│
     │             │   │             │   │  2048, dll.)│
     └─────────────┘   └─────────────┘   └─────────────┘
```

- ✅ Dashboard menampilkan statistik dari semua modul media
- ✅ Pencarian terintegrasi di seluruh modul
- ✅ Navigasi cepat antar modul melalui navbar
- ✅ Tema gelap konsisten di semua halaman

---

## 🎞️ Keterbatasan Format & Kualitas

### ❌ Masalah

Platform komersial membatasi format yang didukung:

| Platform | Format | Kualitas | Catatan |
|---|---|---|---|
| YouTube | H.264/VP9/AV1 | 👍 Bagus | Video di-encode ulang ke beberapa codec |
| Spotify | Lossless 24-bit/44.1kHz FLAC | 🔥 Bagus | Tapi butuh koneksi stabil & perangkat kompatibel |
| Netflix | H.264/H.265/AV1 | 👍 Bagus | Tapi tergantung koneksi & paket |
| Google Drive | Tergantung upload | 👌 Original tersimpan | Preview browser di-stream 1080p |

**Keterbatasan lainnya:**
- FLAC tidak dapat diputar langsung di YouTube (di-encode ke AAC/Opus)
- MKV tidak dapat di-streaming di peramban biasa (tanpa transcoding)
- HEVC/x265 memiliki dukungan browser terbatas (Safari native, Chrome/Firefox terbatas)
- ZIP/CBZ (manga) tidak didukung oleh platform mainstream

### ✅ Solusi MEeL

**Transcoding otomatis dengan FFmpeg:**

| Input → Output | Engine |
|---|---|
| MP4, MKV, AVI, MOV, WEBM → **HLS (.m3u8 + .ts)** — adaptive bitrate | FFmpeg |
| MP3, FLAC, WAV, M4A, OGG → **Opus/OGG** — kompresi cerdas | FFmpeg |
| PDF, ZIP, CBZ → **In-browser Viewer** — tanpa konversi | PHP |
| Semua file → **Cloud Drive Preview** — video/audio/gambar | Native |
**Yang membedakan MEeL:**
- Kualitas asli terjaga — tidak ada kompresi paksa
- Format output bisa dipilih oleh pengguna
- Transcoding berjalan otomatis di background
- FFmpeg 6.0+ sebagai engine transcode

---

## 🌐 Akses di Jaringan Lokal Tanpa Internet

### ❌ Masalah

Platform streaming komersial memerlukan koneksi internet. Tanpa internet, konten tidak dapat diakses.

```text
[🏠 Rumah] ──koneksi internet──▶ [☁️ Server YouTube/Netflix]
                                  ↑
                            ┌─────┴─────┐
                            │ MATI GAYA │ ← Kalau internet mati
                            └───────────┘
```

**Dampaknya:**
- Buffering terus saat koneksi lambat
- Tidak bisa menonton saat internet mati
- Kuota data cepat habis (streaming 1 jam = 1-3GB)
- Latensi tinggi ke server luar negeri
- Di daerah 3T — internet mahal dan lambat

### ✅ Solusi MEeL

**Streaming via LAN — zero internet required:**

```text
[🖥️ Server MEeL] ──LAN/WiFi──▶ [💻 Laptop]   ✅ 1 Gbps
                  ──LAN/WiFi──▶ [📱 HP]       ✅ 1 Gbps
                  ──LAN/WiFi──▶ [📺 TV]       ✅ 1 Gbps
                  ──LAN/WiFi──▶ [👨‍👩‍👧‍👦 Keluarga] ✅ 1 Gbps per device
```

**Keuntungan LAN vs internet:**

| Aspek | Platform Komersial | MEeL (LAN) |
|---|---|---|
| Kecepatan | 10-50 Mbps (internet) | **1.000+ Mbps (LAN)** |
| Buffering | Sering (tergantung ISP) | **✅ Zero buffering** |
| Kuota data | Boros (1-3GB/jam) | **✅ Gratis (LAN)** |
| Akses tanpa internet | ❌ Tidak bisa | **✅ Tetap jalan** |
| Latensi | 50-200ms | **< 1ms** |
| Multi-device | Bergantung bandwidth | **Full bandwidth per device** |

> **Catatan:** MEeL juga bisa diakses dari luar jaringan via Cloudflare Tunnel atau VPN — namun fitur LAN tetap menjadi keunggulan utama.

---

## 👨‍👩‍👧‍👦 Berbagi Media dengan Keluarga/Teman

### ❌ Masalah

Metode berbagi file konvensional memiliki keterbatasan:

| Metode | Waktu | Kualitas | Batasan |
|---|---|---|---|
| WhatsApp | ⏳ 30 menit upload | 📉 Dikompres jadi 16MB | Maks 2GB (sebagai dokumen) |
| Email | ⏳ 15 menit | ✅ Original | Maks 25MB |
| Google Drive | ⏳ 20 menit | ✅ Original | Butuh akun Google |
| Discord | ⏳ 10 menit | 📉 Kualitas turun | Maks 20MB (free) |
| USB Flashdisk | 🚗 10 menit jalan | ✅ Original | Harus ketemu langsung |

### ✅ Solusi MEeL

```text
Admin upload ──▶ [MEeL] ──share link──▶ 👨 Ayah (member)  ✅
                                       ├── 👩 Ibu (user)    ✅
                                       ├── 👦 Anak (guest)  ✅
                                       └── 👨‍👩‍👧‍👦 Semua via LAN ✅
```

**Fitur berbagi MEeL:**
- ✅ **Multi-user** — Setiap anggota keluarga punya akun sendiri
- ✅ **Role-based** — Admin atur hak akses (member/user/guest)
- ✅ **Scope public/private** — Drive bisa di-share atau disembunyikan
- ✅ **Share link** — Cukup kirim URL lokal, langsung akses
- ✅ **Satu tempat** — Tidak perlu upload ulang, semua terpusat

---

## 🎮 Ketergantungan pada Platform Komersial

### ❌ Masalah

Platform komersial mengontrol ekosistem konten melalui kebijakan masing-masing:

```text
YouTube bisa:
├── Hapus video Anda kapan saja (copyright claim)
├── Tampilkan iklan yang tidak bisa di-skip
├── Ubah algoritma → turunkan views konten Anda
└── Batasi region tertentu

Netflix bisa:
├── Hapus film favorit Anda (lisensi habis)
├── Naikkan harga langganan
└── Batasi kualitas streaming berdasarkan paket

Google Drive bisa:
├── Batasi kuota penyimpanan
├── Pindai file untuk malware dan hak cipta
└── Batasi akses jika melanggar ToS
```

### ✅ Solusi MEeL

**Kendali penuh atas konten dan infrastruktur:**

| Aspek | Platform Komersial | MEeL |
|---|---|---|
| Kontrol konten | Mereka yang punya | **Anda yang punya** |
| Iklan | Wajib (kecuali premium) | **Zero iklan** |
| Algoritma | Manipulatif | **Tidak ada** |
| Region lock | Ada | **Tidak ada** |
| Harga | Naik tiap tahun | **Gratis selamanya** |
| Kualitas | Mereka yang tentukan | **Anda yang pilih** |

> **Kesimpulan:** MEeL bukan sekadar alat streaming — ini bentuk kemandirian digital.

---

## 🛠️ Kontrol Penuh atas Konten

### ❌ Masalah

Pengguna platform komersial tidak memiliki kepemilikan penuh atas konten.

```
┌──────────────────────────────────────────────┐
│         ANDA HANYA PENYEWA                   │
├──────────────────────────────────────────────┤
│ • Film bisa hilang kapan saja (lisensi)      │
│ • Kualitas streaming ditentukan server       │
│ • Ada batas durasi upload                    │
│ • Format output dipaksa                       │
│ • Data berada di server orang lain           │
│ • Backup tidak bisa dilakukan sendiri        │
└──────────────────────────────────────────────┘
```

### ✅ Solusi MEeL

```
┌──────────────────────────────────────────────┐
│         ANDA ADALAH PEMILIK                  │
├──────────────────────────────────────────────┤
│ ✅ Konten aman selama HDD berfungsi baik     │
│ ✅ Pilih kualitas sendiri (HLS adaptive)     │
│ ✅ Tidak ada batas durasi (selama storage)   │
│ ✅ Transcode ke format apapun                │
│ ✅ Full backup — media + database            │
│ ✅ Mode sehat 20-20-20 (pengingat istirahat) │
└──────────────────────────────────────────────┘
```

### Cara Backup Data

Fitur backup yang tidak tersedia di platform komersial:

```bash
# Backup database
mysqldump -u root -p MEeL > backup_meel_$(date +%Y%m%d).sql

# Backup semua media
tar -czf meel_media_backup_$(date +%Y%m%d).tar.gz /media/username/MEeL/media/

# Simpan di external HDD atau cloud backup pilihan Anda ✅
```

---

## 📊 Dampak Finansial: Setahun Pakai MEeL

### Perbandingan Tahunan

| Pos | Pakai Platform Komersial | Pakai MEeL |
|---|---|---|
| Langganan streaming | Rp 3.500.000 - 6.400.000/tahun | **Rp 0** |
| Storage cloud | Rp 324.000 - 2.028.000/tahun | **Rp 0** |
| Software converter | Rp 200.000 - 500.000/tahun | **Rp 0** |
| Biaya listrik server | — | Rp 187.000 - 1.250.000/tahun ⚡ |
| Pembelian HDD (sekali) | — | Rp 500.000 - 2.000.000 (sekali) |
| **Total tahun pertama** | **Rp 4.000.000 - 8.900.000** | **Rp 700.000 - 3.250.000*** 🔥 |
| **Total tahun kedua+** | **Rp 4.000.000 - 8.900.000** | **Rp 187.000 - 1.250.000*** 🔥🔥 |

### Proyeksi 5 Tahun

```text
Platform komersial: Rp 20.000.000 - Rp 44.500.000
MEeL:               Rp  1.600.000 - Rp  8.500.000 (termasuk HDD)

HEMAT:              Rp 18.400.000 - Rp 36.000.000 dalam 5 tahun!
```

Itu baru untuk **satu orang**. Jika dipakai 1 keluarga (4 orang), penghematan bisa 4x lipat.

> ⚡ **Catatan soal biaya listrik:** Estimasi di atas menggunakan tarif PLN non-subsidi (Rp 1.352 - 1.700/kWh per Agustus 2026). Jika Anda menggunakan perangkat **hemat daya**, biaya listriknya bisa jauh lebih rendah:
>
> | Perangkat | Konsumsi Daya | Estimasi Biaya Listrik/Tahun |
> |-----------|--------------|-----------------------------|
> | 🖥️ PC Desktop bekas | 100-150 watt | Rp 1.000.000 - 1.500.000 |
> | 💻 Laptop bekas | 30-60 watt | Rp 300.000 - 600.000 |
> | 🍓 Raspberry Pi 5 | 5-10 watt | **Rp 75.000 - 187.000** 🔥 |
> | 📦 Mini PC / STB / Thin Client | 10-25 watt | Rp 150.000 - 300.000 |
>
> > 💡 **Tip:** Dengan Raspberry Pi 5 (~Rp 700.000 - 2.500.000 sekali beli), biaya listrik Anda cuma **Rp 75.000 - 187.000 per tahun** — lebih murah dari segelas kopi tiap bulan! 🍓

---

## 🧩 Gambaran Besar

### Sebelum MEeL

```text
📱 Spotify ──────── Rp 54.990/bln ── Cuma musik
📺 YouTube ──────── Rp 59.000/bln ── Cuma video (premium)
🎬 Netflix ──────── Rp 120.000/bln ─ Cuma film (Standar)
☁️ Google One ─── Rp 27.000/bln ── Cuma storage
📚 Langganan Buku ─ Rp 50.000/bln ── Cuma buku
🎞️ Software Convert ─ Rp 100.000 ── Cuma converter

Total: Rp 410.000+/bln ≠ Yang didapat: terpisah-pisah
```

### Sesudah MEeL

```text
┌─────────────────────────────────────────────────┐
│                    🖥️ MEeL                      │
├─────────────────────────────────────────────────┤
│ 🎬 Video  🎵 Musik  📚 Buku  ☁️ Drive  🕹️ Games │
│─────────────────────────────────────────────────│
│ Semua fitur dalam SATU platform                 │
│ Biaya: Rp 0 (nol) per bulan                     │
│ Kontrol: 100% milik Anda                        │
│ Privasi: Data tidak ke mana-mana                │
└─────────────────────────────────────────────────┘
```

### Ringkasan Cepat

| Masalah Dunia Nyata | Dampak | Solusi MEeL |
|---|---|---|
| 💸 Biaya langganan | Rp 3,5-6,4 juta/tahun | **Gratis — cukup listrik + HDD** |
| 🔒 Privasi data | Data digunakan untuk iklan | **Server pribadi, 100% data milik Anda** |
| 📂 Koleksi tersebar | 5+ platform berbeda | **Satu hub terpadu untuk semua media** |
| 🎞️ Format terbatas | Tidak bisa putar FLAC/MKV/CBZ | **Transcoding otomatis FFmpeg** |
| 🌐 Butuh internet | Buffer & kuota boros | **Streaming via LAN lokal — zero internet** |
| 👨‍👩‍👧‍👦 Sulit berbagi | Upload ulang berkali-kali | **Multi-user dengan RBAC** |
| 🎮 Ketergantungan | Kontrol ada di platform | **Self-hosted, Anda yang pegang kendali** |

---

## 💬 Testimonial

> *"Setelah pakai MEeL, saya cancel semua langganan. Tagihan bulanan turun drastis, dan saya punya kendali penuh atas koleksi media saya."*
>
> — **Mifada**, Creator of MEeL

> *"Saya kaget melihat berapa banyak yang saya habiskan untuk layanan streaming. Sekarang semuanya ada di MEeL, diakses via TV dari server di ruang tamu."*
>
> — **Early Adopter**, Pengguna Anonim

> *"Data aman di server sendiri. Tidak perlu khawatir konten tiba-tiba hilang atau data bocor."*
>
> — **Beta Tester**, Pengguna Anonim

---

## 🔧 Pembersihan URL & Fitur Admin Terbaru

### Migrasi URL Bersih

Semua halaman watch sekarang menggunakan `watch?v=` alih-alih `watch?id=` untuk konsistensi dengan konvensi URL gaya YouTube. Perubahan ini diterapkan di 25+ file termasuk halaman PHP, modul JavaScript, controller, dan dokumentasi.

### Tab Upload Queue Admin

Tab "Upload Queue" baru ditambahkan ke Activity Log viewer, memungkinkan admin untuk:
- Memantau status upload queue (pending/processing/transcoding/completed/failed)
- Filter berdasarkan uploader, status, dan rentang waktu
- Export data queue sebagai CSV, JSON, atau XLS dengan preview
- Membersihkan entri lama yang selesai/gagal untuk maintenance

### MEeLCoin Admin Exclusion

User admin sekarang dikecualikan dari dropdown MEeLCoin manual adjustment untuk mencegah modifikasi saldo yang tidak disengaja. Saldo admin dikelola melalui auto-refill dan biaya upload saja.

---

## ⚖️ Pertimbangan Sebelum Pakai MEeL

MEeL gratis secara biaya, tetapi memerlukan waktu, tenaga, dan kesabaran untuk setup dan pemeliharaan. Berikut adalah trade-off yang perlu dipertimbangkan:

### ❓ Apa yang Perlu Anda Siapkan?

| Yang Dibutuhkan | Detail | Level Kesulitan |
|---|---|---|
| 🖥️ **Server / Komputer** | Butuh perangkat yang menyala 24/7 (bisa laptop bekas, VPS, atau Raspberry Pi) | 🟢 Mudah |
| 💾 **HDD/SSD Eksternal** | Media butuh tempat. Semakin besar koleksi, semakin besar HDD yang dibutuhkan | 🟢 Mudah |
| 🐧 **Pengetahuan Linux Dasar** | Instalasi FFmpeg, yt-dlp, permission filesystem, terminal commands | 🟡 Sedang |
| 🗄️ **Setup Database** | MySQL/MariaDB — buat database, import schema, konfigurasi user | 🟡 Sedang |
| 🐛 **Troubleshooting Mandiri** | Karena ini open-source, Anda harus bisa debugging sendiri atau bertanya ke komunitas | 🟡 Sedang |
| 🔧 **Konfigurasi Awal** | Setel path HDD, atur cookies.txt untuk yt-dlp, sesuaikan PHP config | 🟡 Sedang |
| 🔄 **Perawatan Berkala** | Backup database + media, update yt-dlp, bersihkan file orphan | 🟡 Sedang |
| 🌐 **Akses dari Luar (opsional)** | Butuh Cloudflare Tunnel, VPN, atau port forwarding — tidak semudah klik "share" | 🔴 Sulit |

### ⏱️ Estimasi Waktu

| Tahap | Perkiraan Waktu |
|---|---|
| Instalasi server + database | 30 - 60 menit |
| Konfigurasi aplikasi | 15 - 30 menit |
| Setup FFmpeg + yt-dlp | 15 - 30 menit |
| Upload media pertama | Tergantung ukuran file |
| **Total setup awal** | **1 - 2 jam (jika lancar)** |
| Pembiasaan diri | 1 - 3 hari |

### ⚠️ Hal yang Perlu Dipertimbangkan

| Aspek | Platform Komersial | MEeL |
|---|---|---|
| **Kemudahan** | ✅ Install app, login, langsung pakai | ❌ Perlu setup server sendiri |
| **Biaya bulanan** | ❌ Rp 290-535rb/bln | ✅ Rp 0 — cuma listrik |
| **Koleksi konten** | ✅ Jutaan judul siap pakai | ❌ Anda harus upload sendiri |
| **Pemeliharaan** | ✅ Dikelola perusahaan | ❌ Anda urus sendiri |
| **Kustomisasi** | ❌ Terbatas | ✅ Full kontrol |
| **Keamanan data** | ❌ Data di pihak ketiga | ✅ 100% milik Anda |
| **Dukungan teknis** | ✅ CS 24/7 | ❌ Community-based |
| **Akses offline** | ❌ Wajib internet | ✅ Bisa via LAN |

### 🎯 Jadi, MEeL Cocok untuk Siapa?

| Cocok Untuk | Kurang Cocok Untuk |
|---|---|
| ✅ Orang yang ingin **hemat biaya** langganan | ❌ Orang yang ingin **instant setup** tanpa ribet |
| ✅ Orang yang **peduli privasi** data | ❌ Orang yang **tidak mau ribet** dengan server |
| ✅ Orang yang punya **koleksi media sendiri** | ❌ Orang yang **hanya nonton konten baru** tiap hari |
| ✅ Orang yang **suka belajar** hal baru | ❌ Orang yang **gaptek** soal Linux/server |
| ✅ Orang yang punya **perangkat cadangan** buat server | ❌ Orang yang **tidak punya perangkat** tambahan |
| ✅ **Keluarga** yang ingin sharing media di rumah | ❌ Yang butuh **jutaan konten siap pakai** (seperti Netflix) |

> **Intinya:** MEeL seperti rumah sendiri — butuh effort untuk bangun dan rawat, tapi setelah jadi, Anda bisa hidup di dalamnya dengan bebas, aman, dan tanpa bayar kontrakan tiap bulan. 🏠

---

## 🔗 Lihat Juga

- [📚 Index Dokumentasi](index.md) — Peta semua dokumentasi
- [🚀 Instalasi](installation.md) — Cara install MEeL
- [⚙️ Konfigurasi](configuration.md) — Atur path dan database
- [🎬 Tentang MEeL](../../README.md) — Ikhtisar proyek

---

<div align="center">
  <sub><a href="index.md">← Kembali ke Index Dokumentasi</a></sub>
  <br><br>
  <sub>MEeL © 2026 — Mifada | Dibuat dengan ❤️ untuk kemandirian digital</sub>
</div>
