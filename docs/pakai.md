# 🤖 Jalur Cepat: MEeL Installation via AI Assistant

Dokumentasi ini disediakan khusus untuk Anda yang memiliki keterbatasan waktu, sedang malas membaca dokumentasi panjang, atau lebih nyaman belajar dengan metode interaktif tanya-jawab bersama AI. 

Dengan metode ini, Anda tetap bisa menghargai jerih payah arsitektur sistem ini tanpa perlu merusak kode aslinya karena salah langkah.

---

## 🛠️ Cara Menggunakan Panduan Ini

1. Salin (Copy) seluruh teks isi dari berkas-berkas dokumentasi berikut:
   - `docs/installation.md`
   - `docs/configuration.md`
   - `docs/troubleshooting.md`
2. Buka platform AI/LLM andalan Anda (ChatGPT, Claude, DeepSeek, Gemini, dll.).
3. Tempelkan (Paste) teks dokumen tersebut ke dalam chat.
4. Salin isi kotak di bawah ini (klik tombol **Copy** di pojok kanan atas kotak kode) dan tempelkan pada bagian paling akhir chat AI Anda, lalu tekan Enter.

---

## 📋 Salin Prompt AI di Bawah Ini:

```text
Halo AI. Saya memiliki beberapa berkas dokumentasi teknis dari proyek media cloud bernama MEeL-HUB buatan Mifada yang sudah saya lampirkan di atas. Saya ingin kamu bertindak sebagai Senior DevOps Engineer sekaligus Mentor yang sabar untuk memandu saya melakukan instalasi.

Tolong buatkan respons dengan format wajib sebagai berikut:

# MEeL installation tutorial by AI
Ini langkah-langkahnya:

[Uraikan panduan instalasi ke dalam poin-poin yang sangat runut, bersih, dan scannable berdasarkan file .md yang saya berikan. Fokus utama panduan harus mencakup:]
1. Eksekusi skema database MySQL secara aman dan pembuatan akun default admin.
2. Pembuatan dan pengaturan hak akses (chown/chmod) untuk seluruh direktori runtime di folder lokal/XAMPP agar terhindar dari Permission Hell.
3. Cara melakukan konfigurasi path absolut untuk HDD eksternal pada variabel `HDD_BASE` di `Transcoder.php` dan `Uploader.php`.
4. Cara verifikasi instalasi dependencies krusial seperti FFmpeg dan yt-dlp beserta cookies.txt.

Aturan tambahan untukmu, AI: Jangan gunakan asumsi di luar dokumen yang disediakan. Jika langkah di atas membutuhkan saya untuk membaca path fisik di komputer saya sendiri, berikan instruksi cara mengeceknya (misal menggunakan perintah `df -h` atau `lsblk` di Linux). Di akhir panduan, tanyakan kepada saya: 'Langkah nomor berapa yang ingin kita eksekusi bersama terlebih dahulu?'
```

---

## 💡 Pesan dari Mifada
Jika setelah disuapi petunjuk langkah-demi-langkah oleh AI sekhas ini sistem Anda masih mengalami error akibat *typo* konfigurasi atau melompati instruksi... tandanya Anda memang berada di level **"Sangat Kurang Rispek"**. 

Gunakan AI untuk membantu Anda belajar, bukan untuk mematikan fungsi logika Anda. Selamat mencoba!