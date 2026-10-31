# ffmpeg-lite untuk MEeL (Static Build dengan libfdk_aac)

Biner `ffmpeg` dan `ffprobe` **fully static** (tidak bergantung library sistem) dengan encoder AAC terbaik **libfdk_aac**.
Dibangun dari source FFmpeg + libfdk_aac + dependensi minimal (opus, dav1d, mp3lame, webp, zlib).

## 📦 Cara Pakai (User Download)

**Binary di-repo sudah dikompres UPX** (~8-10 MB vs ~18 MB). Ekstrak sebelum pakai:

```bash
# 1. Ekstrak (UPX auto-decompress saat dijalankan, TAPI untuk GPL compliance & permission:
upx -d ffmpeg ffprobe   # decompress sekali
chmod +x ffmpeg ffprobe

# 2. Verifikasi
./ffmpeg -version | head -1
./ffprobe -version | head -1
sha256sum -c SHA256SUMS
```

## 🔧 Konfigurasi MEeL

Di `auth/settings.php` (atau lewat `install.sh`):

```php
define('MEEL_FFMPEG_PATH',  '/path/ke/MEeL/tools/ffmpeg-lite/ffmpeg');
define('MEEL_FFPROBE_PATH', '/path/ke/MEeL/tools/ffmpeg-lite/ffprobe');
define('MEEL_YTDLP_PATH',   '/path/ke/MEeL/tools/yt-dlp/yt-dlp');  // hasil unduhan installer
```

Atau biarkan kosong `''` → auto-detect via `resolveBinary()` (cari di PATH + lokasi umum).

## 🧪 Test Suite

```bash
# Restore test script dari Trash dulu (sekali saja):
cp /home/muhammaddaffa/.local/share/Trash/files/test-ffmpeg-lite.sh tools/ffmpeg-lite/

# Jalankan test
cd tools/ffmpeg-lite
./test-ffmpeg-lite.sh        # harapan: PASS=40+ FAIL=0 SKIP=6, exit 0
# Test libfdk_aac encode:
./ffmpeg -f lavfi -i "sine=frequency=1000:duration=5" -c:a libfdk_aac -b:a 128k /tmp/test.m4a
```

## 📋 Komponen Termasuk

| Kategori | Daftar |
|----------|--------|
| **Encoder** | libfdk_aac, libopus, libmp3lame, libwebp, mjpeg, png, aac |
| **Decoder** | h264, hevc, aac, mp3, flac, vorbis, opus, alac, ac3, eac3, pcm_*, mjpeg, png, webp, gif, bmp, libdav1d, av1 |
| **Demuxer** | mov, matroska, mpegts, hls, flac, wav, ogg, mp3, aac, ac3, eac3, concat, image2, image_*_pipe |
| **Muxer** | hls, mpegts, ogg, opus, mp3, ipod, mp4, webp, image2, mjpeg, null |
| **Filter** | scale, pad, crop, fps, tile, format, aresample, anull, aformat, buffer, buffersink, abuffer, abuffersink |
| **Protocol** | file, pipe |
| **BSF** | h264_mp4toannexb, hevc_mp4toannexb, aac_adtstoasc, extract_extradata, null |

**Tidak termasuk** (tidak dibutuhkan MEeL):
- Encoder H.264/H.265/VP9/AV1 (video tetap stream copy)
- Theora encoder
- Network protocol (HLS lokal via `file`)

## ⚖️ Lisensi: **GPL v3**

Karena `--enable-nonfree --enable-libfdk-aac`, binary FFmpeg **berlisensi GPL v3**.
- Source build tersedia di `tools/ffmpeg-build/` (memenuhi GPL §6 offer source)
- `COPYING.GPLv3` disertakan di direktori ini
- MEeL aplikasi **tidak** jadi GPL (FFmpeg dipanggil via `exec()`, bukan link library)

## 🛠 Build Ulang (Manual, Developer Only)

Butuh Ubuntu 22.04+ (glibc 2.35). Install build deps:

```bash
sudo apt install git build-essential nasm cmake autoconf automake libtool pkg-config meson ninja-build upx-ucl
```

Build:

```bash
cd tools/ffmpeg-build
./build-all.sh
```

Output: `../ffmpeg-lite/ffmpeg`, `../ffmpeg-lite/ffprobe` (static, stripped, UPX-compressed)

Env opsional:
- `FFMPEG_TAG=n7.1` (default n7.1, ganti ke tag stabil terbaru)
- `JOBS=4` (default: semua core)

## 📁 Struktur Direktori

```
tools/
├── ffmpeg-build/              # Build workspace (di .gitignore)
│   ├── build-all.sh           # Script build utama
│   ├── fdk-aac-src/           # libfdk_aac source
│   ├── ffmpeg-src/            # FFmpeg source
│   └── ffmpeg-out/            # Build output
├── ffmpeg-lite/               # Biner final (TRACKED di Git)
│   ├── ffmpeg                 # UPX-compressed
│   ├── ffprobe                # UPX-compressed
│   ├── SHA256SUMS             # Checksum
│   ├── BUILD-INFO.txt         # Metadata build
│   ├── COPYING.GPLv3          # Lisensi GPL v3
│   ├── COPYING.LGPLv2.1       # Lisensi LGPL (library dependensi)
│   ├── logging-wrapper/       # Wrapper logging untuk debug
│   │   ├── ffmpeg
│   │   └── ffprobe
│   └── README.md              # File ini
└── yt-dlp/                    # yt-dlp (diunduh installer, tidak ikut ZIP)
    ├── yt-dlp                 # Binary dari GitHub Releases
    ├── update.sh              # Script update manual
    └── VERSION.txt            # Versi & timestamp
```

## 🔄 Update yt-dlp

```bash
cd tools/yt-dlp
./update.sh
```

## 📝 Changelog Build

- **v2 (2026-10-31)**: Static build + libfdk_aac + UPX compress + yt-dlp standalone
- **v1 (6.1.1)**: Dynamic build dengan library sistem

## ⚠️ Catatan Penting

1. **Binary UPX-compressed** — wajib `upx -d` sebelum pakai di production (permission execute)
2. **GPL v3** — distribusi biner wajib menyertakan offer source (build script di repo memenuhi)
3. **Ubuntu 22.04+** — build di glibc 2.35, jalan di Ubuntu 22.04, 24.04, Debian 12+
4. **Hardware accel** — TIDAK शामिल (vaapi/qsv/nvenc). MEeL pakai stream copy, tidak transcode video.
5. **yt-dlp merge** — yt-dlp akan pakai `MEEL_FFMPEG_PATH` untuk merge post-download (HLS copy, sprite, thumb)