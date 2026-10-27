# ffmpeg-lite 6.1.1 untuk MEeL

Biner `ffmpeg` dan `ffprobe` hanya berisi komponen yang dipakai MEeL v2.1.3
(6 encoder, 23 decoder, 13 muxer, 16 demuxer, 16 filter).

## Syarat

- Linux x86_64, **Ubuntu 24.04** (glibc >= 2.35 dan libsharpyuv0, libdav1d7). Distro lain: bangun ulang
  dengan `INSTALL_DEPS=1 ./build-ffmpeg-lite.sh` (sekitar 4 menit di 1 core).
- `sudo apt install libopus0 libmp3lame0 libwebp7 libsharpyuv0 libdav1d7 zlib1g`
- Untuk skrip uji: `ffmpeg` penuh di PATH (`sudo apt install ffmpeg`) sebagai pembanding.

## 1. Pasang (tar.gz menjaga izin eksekusi)

    sudo mkdir -p /opt && sudo tar xzf ffmpeg-lite-6.1.1-linux-x86_64.tar.gz -C /opt
    sudo ln -sfn /opt/ffmpeg-lite-6.1.1 /opt/ffmpeg-lite
    cd /opt/ffmpeg-lite && sha256sum -c SHA256SUMS
    ./ffmpeg -version | head -1

## 2. Uji kesetaraan (tanpa menyentuh MEeL)

    /opt/ffmpeg-lite/test-ffmpeg-lite.sh        # harapan: PASS=40 FAIL=0 SKIP=6, exit 0
    sudo -u daemon /opt/ffmpeg-lite/ffmpeg -version | head -1    # user web harus bisa menjalankan

## 3. Uji di dalam MEeL (pakai pembungkus pencatat dulu)

Di `auth/settings.php`:

    define('MEEL_FFMPEG_PATH',  '/opt/ffmpeg-lite/logging-wrapper/ffmpeg');
    define('MEEL_FFPROBE_PATH', '/opt/ffmpeg-lite/logging-wrapper/ffprobe');

    sudo rm -f /tmp/meel-ff.log
    sudo -u daemon touch /tmp/meel-ff.log
    sudo chmod 666 /tmp/meel-ff.log
    sudo systemctl reload php8.4-fpm    # atau reload apache2 bila mod_php

Lalu lakukan semua skenario di matriks (lihat jawaban di chat), dan periksa:

    cat /tmp/meel-ff.log | tail -50
    grep -iE "unknown (encoder|decoder)|no decoder|not found|Unable to find a suitable|Bitstream filter|Muxer" \
         /path/ke/MEeL/logs/php_error.log

Ringkasan opsi ffmpeg yang benar-benar dipakai MEeL:

    grep -oE -- " -(c:[vas]|codec|f|vf|af|map) +[^ ]+" /tmp/meel-ff.log | sort | uniq -c | sort -rn

## 4. Kembali ke ffmpeg bawaan (rollback)

Kosongkan kedua konstanta (`''`) di `auth/settings.php` dan reload PHP.

---

## Catatan penting untuk MEeL di mesin ini

- **PHP web**: 8.4.27 FPM (bukan 8.3)
- **User web**: `daemon` (bukan `www-data`)
- **systemd service**: `php8.4-fpm` (bukan `php8.3-fpm`)
- **Log permission**: `/tmp/meel-ff.log` **harus dibuat oleh user `daemon`** (karena `fs.protected_regular=2`).
  `sudo touch` + `chmod 666` **tidak cukup** jika file dimiliki root.
- **DownloadService/yt-dlp**: tidak memakai `--ffmpeg-location`; merge yt-dlp tetap pakai `/usr/bin/ffmpeg` penuh.
  Lite hanya dipakai untuk proses pasca-unduh: HLS copy, sprite, thumbnail.
- **Bug aplikasi yang perlu diperbaiki bersamaan**:
  1. `meel_ffmpeg_encode_opus()` dan `EncodeService::runOpusEncode` **harus** memakai `-map 0:a:0` atau `-vn`
     saat encode dari MP3 dengan cover, agar tidak memilih encoder video (Theora) yang tidak ada di lite.
  2. `admin/includes/media_editor.php` fallback `move_uploaded_file` ke nama `.webp` berbahaya:
     jika ffmpeg gagal, file JPEG/PNG/GIF/AVIF asli disimpan dengan ekstensi `.webp`.
     Perbaiki: kembalikan error atau konversi lewat GD/Imagick.

## Perubahan dari paket 6.1.1 lama

| Komponen  | Lama | Baru | Alasan |
|-----------|------|------|--------|
| demuxer   | -    | hls  | Probe durasi HLS (`FfmpegUtils::probeDuration`) |
| encoder   | -    | aac  | Transcode audio Vorbis/Opus/FLAC → HLS (`Uploader`) |
| filter    | -    | pad  | Cover auto-metadata, media editor thumbnail |
| filter    | -    | crop | Avatar WebP (`profile_edit.php`) |
| decoder   | -    | libdav1d,av1 | Dukungan AVIF (`media_editor.php`) |

Komponen yang **tidak** ditambahkan (tidak dibutuhkan):
- Encoder H.264/H.265 (video tetap di-copy)
- Encoder VP9/AV1
- Theora (encoder video untuk OGG) — bug cover MP3 diperbaiki di aplikasi
- Network — HLS lokal dibaca via protokol `file`