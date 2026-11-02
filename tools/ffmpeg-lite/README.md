# ffmpeg-lite untuk MEeL (Dynamic Build dengan Bundled Runtime Libs)

Biner `ffmpeg` dan `ffprobe` **dynamic dengan library runtime dibundel di `lib/`** (tidak bergantung library sistem untuk `libdav1d`, `libopus`, `libmp3lame`, `libwebp`, `libsharpyuv`, `libz`). Encoder AAC bawaan FFmpeg (native) digunakan, **bukan libfdk_aac** (license LGPL).

Dibangun dari source FFmpeg 6.1.1 + dependensi minimal (opus, dav1d, mp3lame, webp, zlib).
Target platform: **Ubuntu 22.04+ / Debian 12+** (glibc ≥ 2.35).

## 📦 Cara Pakai (User Download)

**Binary di-repo sudah siap pakai** (wrapper `ffmpeg`/`ffprobe` mengatur `LD_LIBRARY_PATH` ke `lib/`).

```bash
# 1. Verifikasi
./ffmpeg -version | head -1
./ffprobe -version | head -1
sha256sum -c SHA256SUMS

# 2. Jalankan test suite
./test-ffmpeg-lite.sh        # harapan: PASS=40+ FAIL=0 SKIP=6, exit 0
```

## 🔧 Konfigurasi MEeL

Di `auth/settings.php` (atau lewat `install.sh`):

```php
define('MEEL_FFMPEG_PATH',  '/path/ke/MEeL/tools/ffmpeg-lite/ffmpeg');
define('MEEL_FFPROBE_PATH', '/path/ke/MEeL/tools/ffmpeg-lite/ffprobe');
define('MEEL_YTDLP_PATH',   '/path/ke/MEeL/tools/yt-dlp/yt-dlp');  // hasil unduhan installer
```

Atau biarkan kosong `''` → auto-detect via `meel_ffmpeg_bin()` / `meel_ffprobe_bin()` (cari di
`tools/ffmpeg-lite/` terlebih dahulu, system PATH **hanya** jika `MEEL_FFMPEG_ALLOW_SYSTEM=true`).

```php
// Izinkan fallback ke system ffmpeg (default: false)
define('MEEL_FFMPEG_ALLOW_SYSTEM', false);
```

## 🧪 Test Suite

```bash
cd tools/ffmpeg-lite
./test-ffmpeg-lite.sh        # harapan: PASS=40+ FAIL=0 SKIP=6, exit 0
# Test native AAC encode:
./ffmpeg -f lavfi -i "sine=frequency=1000:duration=5" -c:a aac -b:a 128k /tmp/test.m4a
```

## 📋 Komponen Termasuk

| Kategori | Daftar |
|----------|--------|
| **Encoder** | aac (native), libopus, libmp3lame, libwebp, mjpeg, png |
| **Decoder** | h264, hevc, aac, mp3, flac, vorbis, opus, alac, ac3, eac3, pcm_*, mjpeg, png, webp, gif, bmp, libdav1d, av1 |
| **Demuxer** | mov, matroska, mpegts, hls, flac, wav, ogg, mp3, aac, ac3, eac3, concat, image2, image_*_pipe, ico |
| **Muxer** | hls, mpegts, ogg, opus, mp3, ipod, mp4, webp, image2, mjpeg, null |
| **Filter** | scale, pad, crop, fps, tile, format, aresample, anull, aformat, buffer, buffersink, abuffer, abuffersink |
| **Protocol** | file, pipe |
| **BSF** | h264_mp4toannexb, hevc_mp4toannexb, aac_adtstoasc, extract_extradata, null |

**Tidak termasuk** (tidak dibutuhkan MEeL):
- Encoder H.264/H.265/VP9/AV1 (video tetap stream copy)
- Theora encoder
- Network protocol (HLS lokal via `file`)
- libfdk_aac (non-free, GPL)

## ⚖️ Lisensi: **LGPL 2.1+**

Karena `--enable-libmp3lame` (LGPL) dan tanpa `--enable-nonfree --enable-libfdk-aac`, binary FFmpeg **berlisensi LGPL 2.1 atau lebih baru**.
- Source build tersedia di `tools/ffmpeg-build/` (memenuhi LGPL §6 offer source)
- `COPYING.LGPLv2.1` disertakan di direktori ini
- MEeL aplikasi **tidak** jadi LGPL (FFmpeg dipanggil via `exec()`, bukan link library)
- Library dibundel: lihat `lib/licenses/` untuk copyright masing-masing library

## 🛠 Build Ulang (Manual, Developer Only)

Butuh Ubuntu 22.04+ (glibc 2.35). Install build deps:

```bash
sudo apt install git build-essential nasm pkg-config \
    libopus-dev libmp3lame-dev libwebp-dev libdav1d-dev zlib1g-dev
```

Build:

```bash
cd tools/ffmpeg-lite
./build-ffmpeg-lite.sh
```

Output: `./ffmpeg.bin`, `./ffprobe.bin` (raw binary). Lalu buat wrapper dan bundel libs:

```bash
# Rename raw binaries
mv ffmpeg ffmpeg.bin
mv ffprobe ffprobe.bin

# Create wrappers
cat > ffmpeg <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export LD_LIBRARY_PATH="$DIR/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec "$DIR/ffmpeg.bin" "$@"
EOF
cat > ffprobe <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
export LD_LIBRARY_PATH="$DIR/lib${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}"
exec "$DIR/ffprobe.bin" "$@"
EOF
chmod +x ffmpeg ffprobe

# Bundle runtime libs (from build host, glibc ≥ 2.35)
mkdir -p lib
for l in libdav1d.so.7 libopus.so.0 libwebp.so.7 libsharpyuv.so.0 libmp3lame.so.0 libz.so.1; do
  cp -L /lib/x86_64-linux-gnu/"$l" lib/ 2>/dev/null || cp -L /usr/lib/x86_64-linux-gnu/"$l" lib/
done
# For libmp3lame: use Ubuntu 22.04 version (GLIBC 2.29 max) for broader compat
# download from http://archive.ubuntu.com/ubuntu/pool/main/l/lame/libmp3lame0_3.100-3build2_amd64.deb

# Generate checksums
sha256sum ffmpeg ffprobe ffmpeg.bin ffprobe.bin lib/*.so.* > SHA256SUMS
```

Env opsional:
- `FFMPEG_TAG=n6.1.1` (default n6.1.1, ganti ke tag stabil terbaru)
- `JOBS=4` (default: semua core)
- `INSTALL_DEPS=1` (install build deps via apt, butuh sudo)

## 📁 Struktur Direktori

```
tools/
├── ffmpeg-lite/               # Biner final (TRACKED di Git)
│   ├── ffmpeg                 # Wrapper → ffmpeg.bin + LD_LIBRARY_PATH
│   ├── ffprobe                # Wrapper → ffprobe.bin + LD_LIBRARY_PATH
│   ├── ffmpeg.bin             # Raw binary (dynamic, linked to lib/)
│   ├── ffprobe.bin            # Raw binary
│   ├── lib/                   # Bundled runtime libraries
│   │   ├── libdav1d.so.7
│   │   ├── libopus.so.0
│   │   ├── libwebp.so.7
│   │   ├── libsharpyuv.so.0
│   │   ├── libmp3lame.so.0
│   │   └── libz.so.1
│   ├── licenses/              # Library copyright files
│   ├── SHA256SUMS             # Checksum semua file di atas
│   ├── BUILD-INFO.txt         # Metadata build
│   ├── COPYING.LGPLv2.1       # Lisensi LGPL v2.1
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

- **v3 (2026-11-02)**: Dynamic build dengan **bundled runtime libs** di `lib/` (libdav1d, libopus, libmp3lame, libwebp, libsharpyuv, libz). Wrapper scripts mengatur `LD_LIBRARY_PATH`. Target: Ubuntu 22.04+/Debian 12+ (glibc ≥ 2.35). Native AAC encoder (LGPL). LGPL 2.1+.
- **v2 (2026-10-31)**: Static build + libfdk_aac + UPX compress + yt-dlp standalone (GPL v3)
- **v1 (6.1.1)**: Dynamic build dengan library sistem

## ⚠️ Catatan Penting

1. **Wrapper scripts** — `ffmpeg`/`ffprobe` adalah shell wrapper yang set `LD_LIBRARY_PATH` ke `lib/` lalu `exec` ke `.bin`. Binary mentah ada di `ffmpeg.bin`/`ffprobe.bin`.
2. **LGPL 2.1+** — distribusi biner wajib menyertakan offer source (build script di repo memenuhi). Library dibundel: lihat `licenses/`.
3. **Ubuntu 22.04+ / Debian 12+** — build di glibc 2.35, jalan di Ubuntu 22.04, 24.04, Debian 12+. `libmp3lame` dari Ubuntu 22.04 (GLIBC 2.29 max) untuk kompatibilitas luas.
4. **Hardware accel** — TIDAK termasuk (vaapi/qsv/nvenc). MEeL pakai stream copy, tidak transcode video.
5. **yt-dlp merge** — yt-dlp akan pakai `MEEL_FFMPEG_PATH` untuk merge post-download (HLS copy, sprite, thumb)
6. **System fallback** — hanya jika `MEEL_FFMPEG_ALLOW_SYSTEM=true` (explicit opt-in). Bundled binary yang rusak **tidak** akan jatuh ke system secara diam-diam.