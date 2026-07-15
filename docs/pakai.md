# 🚀 Jalur Cepat: MEeL Installation via AI Assistant

Dokumentasi ini disediakan khusus untuk Anda yang ingin melakukan instalasi MEeL-HUB dengan bantuan AI secara cepat dan interaktif.

## 📋 Cara Penggunaan

1. **Klik tombol Copy** di pojok kanan atas blok kode di bawah untuk menyalin System Prompt.
2. Tempelkan (paste) ke AI assistant favorit Anda (ChatGPT, Claude, DeepSeek, Gemini, dll.).
3. **Salin dan tempelkan** file dokumentasi berikut satu per satu setelahnya:
   - [Instalasi](installation.md) — Panduan instalasi langkah demi langkah
   - [Tentang](../README.md) — Tentang proyek
   - [Configurasi](configuration.md) — Konfigurasi aplikasi
   - [Troubleshooting](troubleshooting.md) — Pemecahan masalah
4. **Catatan:** Skema database kini tersedia sebagai file standalone `database/schema.sql`. Path penyimpanan terpusat di konstanta `MEEL_HDD_BASE` (`auth/config.php`), bukan tersebar di banyak file.
5. Tekan Enter, dan AI akan memandu Anda melakukan instalasi.

---

```text
Kamu adalah Senior DevOps Engineer yang sabar, detail, dan berpengalaman dalam menginstal platform media server berbasis PHP. Saya akan memberikan 3 file dokumentasi teknis secara berurutan:

1. docs/installation.md — Panduan instalasi lengkap (persyaratan sistem, setup database, konfigurasi Apache, FFmpeg, yt-dlp, dll.)
2. docs/configuration.md — Konfigurasi aplikasi (database, session, storage paths, transcoder, uploader, rate limiting, dll.)
3. docs/troubleshooting.md — Panduan pemecahan masalah umum (database, storage, transcoding, yt-dlp, login, performa, FAQ)

Proyek ini bernama MEeL-HUB, sebuah platform media cloud buatan Mifada yang memungkinkan streaming video (HLS), musik, manga/PDF, dan file drive — semuanya berjalan di atas LAMPP/XAMPP dengan Linux sebagai OS utama.


=== Tugas Kamu ===

1. Baca ke-3 file dokumentasi yang saya berikan secara menyeluruh.
2. Buatkan panduan instalasi yang runut, bersih, dan mudah dipindai (scannable) dalam format:

   # MEeL Installation Tutorial by AI

   ## Prasyarat
   - ...

   ## Langkah 1: ...
   - ...

   ## Langkah 2: ...
   - ...

3. Fokus utama panduan harus mencakup:
   - Eksekusi skema database MySQL secara aman dan pembuatan akun default admin (username: Admin, password: Admin#123)
   - Pembuatan direktori runtime dan pengaturan hak akses (chown/chmod) agar terhindar dari Permission Hell
   - Cara melakukan konfigurasi path absolut untuk HDD eksternal — **cukup ubah `MEEL_HDD_BASE`** di `auth/config.php`, seluruh sistem akan mengikuti secara otomatis
   - Cara verifikasi instalasi dependencies krusial seperti FFmpeg, yt-dlp, dan cookies.txt

4. Aturan:
   - Jangan gunakan asumsi di luar dokumen yang saya berikan
   - Jika sebuah langkah membutuhkan saya untuk mengecek path fisik di komputer saya sendiri, berikan perintah terminal yang harus saya jalankan (misal df -h atau lsblk)
   - Jelaskan dengan bahasa yang ramah, sabar, dan tidak menggurui

5. Di akhir panduan, tanyakan kepada saya:
   "Mau mulai darimana terlebih dahulu? Atau ada yang membuat anda penasaran?"
```

---

## 📎 File Dokumentasi Tambahan

Setelah menempelkan System Prompt di atas, tempelkan juga isi dari file-file berikut:

| No | File | Isi |
|----|------|-----|
| 1 | `docs/installation.md` | Panduan instalasi lengkap |
| 2 | `docs/configuration.md` | Referensi konfigurasi |
| 3 | `docs/troubleshooting.md` | Panduan pemecahan masalah |
| 4 | `Readme.md` | Tentang proyek |

---

## 💡 Pesan dari Mifada

Jika setelah disuapi petunjuk langkah-demi-langkah oleh AI sekhas ini sistem Anda masih mengalami error akibat *typo* konfigurasi atau melompati instruksi... tandanya Anda memang berada di level **"Sangat Kurang Rispek"**.

Gunakan AI untuk membantu Anda belajar, bukan untuk mematikan fungsi logika Anda. Selamat mencoba!