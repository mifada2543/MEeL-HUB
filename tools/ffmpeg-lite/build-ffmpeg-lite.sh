#!/usr/bin/env bash
# Membangun ffmpeg/ffprobe LITE untuk MEeL dari source FFmpeg resmi (tag terkunci).
# Pakai:  INSTALL_DEPS=1 ./build-ffmpeg-lite.sh     (Debian/Ubuntu; butuh sudo untuk apt)
# Env:    FFMPEG_TAG (default n6.1.1)  SRC  OUT  JOBS
set -euo pipefail
FFMPEG_TAG="${FFMPEG_TAG:-n6.1.1}"
SRC="${SRC:-$PWD/ffmpeg-src}"; OUT="${OUT:-$PWD/ffmpeg-lite-out}"; JOBS="${JOBS:-$(nproc)}"
if [ "${INSTALL_DEPS:-0}" = 1 ]; then
  sudo apt-get install -y build-essential nasm pkg-config git \
    libopus-dev libmp3lame-dev libwebp-dev libdav1d-dev zlib1g-dev
fi
[ -d "$SRC/.git" ] || git clone --depth 1 --branch "$FFMPEG_TAG" https://github.com/FFmpeg/FFmpeg.git "$SRC"
cd "$SRC"
./configure --prefix="$OUT" \
 --disable-everything --disable-autodetect --disable-doc --disable-debug \
 --disable-ffplay --disable-network --disable-avdevice \
 --enable-ffmpeg --enable-ffprobe --enable-zlib --enable-libopus --enable-libmp3lame --enable-libwebp \
 --enable-libdav1d \
 --enable-protocol=file,pipe \
 --enable-demuxer=mov,matroska,mpegts,hls,flac,wav,ogg,mp3,aac,ac3,eac3,concat,image2,image_jpeg_pipe,image_png_pipe,image_webp_pipe,image_gif_pipe,image_bmp_pipe \
 --enable-muxer=hls,mpegts,ogg,opus,mp3,ipod,mp4,webp,image2,mjpeg,null \
 --enable-decoder=h264,hevc,aac,aac_latm,mp3,mp3float,flac,vorbis,opus,alac,ac3,eac3,pcm_s16le,pcm_s24le,pcm_s32le,pcm_f32le,pcm_u8,mjpeg,png,webp,gif,bmp,libdav1d,av1 \
 --enable-encoder=libopus,libmp3lame,libwebp,mjpeg,png,aac \
 --enable-parser=h264,hevc,aac,aac_latm,mpegaudio,flac,vorbis,opus,ac3,mjpeg,png,webp,gif,bmp \
 --enable-bsf=h264_mp4toannexb,hevc_mp4toannexb,aac_adtstoasc,extract_extradata,null \
 --enable-filter=scale,pad,crop,fps,tile,format,aresample,anull,null,aformat,buffer,buffersink,abuffer,abuffersink
make -j"$JOBS"
mkdir -p "$OUT"; cp ffmpeg ffprobe "$OUT/"
echo "Selesai: $OUT/ffmpeg  $OUT/ffprobe"; "$OUT/ffmpeg" -version | head -1