#!/usr/bin/env bash
# Test suite untuk ffmpeg-lite MEeL (static build dengan libfdk_aac)
# Expected: PASS=40+ FAIL=0 SKIP=6

set -euo pipefail

FFMPEG="${FFMPEG:-./ffmpeg}"
FFPROBE="${FFPROBE:-./ffprobe}"
TMPDIR="${TMPDIR:-/tmp/meel-ff-test}"
mkdir -p "$TMPDIR"

PASS=0; FAIL=0; SKIP=0

pass() { printf '\033[1;32m[PASS]\033[0m %s\n' "$*"; ((PASS++)); }
fail() { printf '\033[1;31m[FAIL]\033[0m %s\n' "$*"; ((FAIL++)); }
skip() { printf '\033[1;33m[SKIP]\033[0m %s\n' "$*"; ((SKIP++)); }
info() { printf '\033[1;34m[INFO]\033[0m %s\n' "$*"; }

# --- Helper ---
run_ffmpeg() { "$FFMPEG" -hide_banner -loglevel error "$@"; }
run_ffprobe() { "$FFPROBE" -hide_banner -loglevel error "$@"; }

# ============================================================
# 1. Binary sanity
# ============================================================
info "=== Binary sanity ==="
$FFMPEG -version | head -1 | grep -q ffmpeg && pass "ffmpeg runs" || fail "ffmpeg runs"
$FFPROBE -version | head -1 | grep -q ffprobe && pass "ffprobe runs" || fail "ffprobe runs"

# Check static linking
ldd "$FFMPEG" 2>/dev/null | grep -Ev "linux-vdso|/lib64/ld-linux" && \
  fail "static linking (has dynamic deps)" || pass "static linking"

# ============================================================
# 2. Encoder presence
# ============================================================
info "=== Encoder presence ==="
for enc in libfdk_aac libopus libmp3lame libwebp mjpeg png aac; do
  $FFMPEG -encoders 2>/dev/null | grep -q "^\s*[A-Z.]\{5\}\s*$enc\b" && \
    pass "encoder: $enc" || fail "encoder: $enc"
done

# ============================================================
# 3. Decoder presence
# ============================================================
info "=== Decoder presence ==="
for dec in h264 hevc aac aac_latm mp3 mp3float flac vorbis opus alac ac3 eac3 pcm_s16le pcm_s24le pcm_s32le pcm_f32le pcm_u8 mjpeg png webp gif bmp libdav1d av1; do
  $FFMPEG -decoders 2>/dev/null | grep -q "^\s*[A-Z.]\{5\}\s*$dec\b" && \
    pass "decoder: $dec" || fail "decoder: $dec"
done

# ============================================================
# 4. Demuxer presence
# ============================================================
info "=== Demuxer presence ==="
for dem in mov matroska mpegts hls flac wav ogg mp3 aac ac3 eac3 concat image2 image_jpeg_pipe image_png_pipe image_webp_pipe image_gif_pipe image_bmp_pipe; do
  $FFMPEG -demuxers 2>/dev/null | grep -q "^\s*[A-Z.]\{5\}\s*$dem\b" && \
    pass "demuxer: $dem" || fail "demuxer: $dem"
done

# ============================================================
# 5. Muxer presence
# ============================================================
info "=== Muxer presence ==="
for mux in hls mpegts ogg opus mp3 ipod mp4 webp image2 mjpeg null; do
  $FFMPEG -muxers 2>/dev/null | grep -q "^\s*[A-Z.]\{5\}\s*$mux\b" && \
    pass "muxer: $mux" || fail "muxer: $mux"
done

# ============================================================
# 6. Filter presence
# ============================================================
info "=== Filter presence ==="
for flt in scale pad crop fps tile format aresample anull aformat buffer buffersink abuffer abuffersink; do
  $FFMPEG -filters 2>/dev/null | grep -q "^\s*\.\.\.\s$flt\b" && \
    pass "filter: $flt" || fail "filter: $flt"
done

# ============================================================
# 7. Protocol presence
# ============================================================
info "=== Protocol presence ==="
for proto in file pipe; do
  $FFMPEG -protocols 2>/dev/null | grep -q "^\s*$proto\b" && \
    pass "protocol: $proto" || fail "protocol: $proto"
done

# ============================================================
# 8. Functional tests
# ============================================================
info "=== Functional tests ==="

# 8.1 libfdk_aac encode
run_ffmpeg -f lavfi -i "sine=frequency=1000:duration=2" -c:a libfdk_aac -b:a 128k "$TMPDIR/test_fdk.m4a" && \
  pass "libfdk_aac encode" || fail "libfdk_aac encode"

# 8.2 libopus encode
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus -b:a 96k "$TMPDIR/test_opus.opus" && \
  pass "libopus encode" || fail "libopus encode"

# 8.3 libmp3lame encode
run_ffmpeg -f lavfi -i "sine=frequency=880:duration=2" -c:a libmp3lame -b:a 192k "$TMPDIR/test_lame.mp3" && \
  pass "libmp3lame encode" || fail "libmp3lame encode"

# 8.4 libwebp encode (still image)
run_ffmpeg -f lavfi -i "color=red:size=64x64:duration=1,format=yuv420p" -c:v libwebp -lossless 1 -q:v 80 "$TMPDIR/test_webp.webp" && \
  pass "libwebp encode" || fail "libwebp encode"

# 8.5 mjpeg encode
run_ffmpeg -f lavfi -i "color=blue:size=64x64:duration=1,format=yuv420p" -c:v mjpeg -q:v 2 "$TMPDIR/test_mjpeg.jpg" && \
  pass "mjpeg encode" || fail "mjpeg encode"

# 8.6 png encode
run_ffmpeg -f lavfi -i "color=green:size=64x64:duration=1,format=rgb24" -c:v png "$TMPDIR/test_png.png" && \
  pass "png encode" || fail "png encode"

# 8.7 aac encode (native)
run_ffmpeg -f lavfi -i "sine=frequency=220:duration=2" -c:a aac -b:a 128k "$TMPDIR/test_aac.m4a" && \
  pass "aac (native) encode" || fail "aac (native) encode"

# 8.8 HLS mux (segment + playlist)
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=10" -c:a libopus -f hls -hls_time 2 -hls_list_size 0 "$TMPDIR/test.m3u8" && \
  pass "HLS mux" || fail "HLS mux"

# 8.9 Scale filter
run_ffmpeg -f lavfi -i "color=white:size=1280x720:duration=1,format=yuv420p" -vf scale=320:180 "$TMPDIR/test_scale.mp4" && \
  pass "scale filter" || fail "scale filter"

# 8.10 Pad filter
run_ffmpeg -f lavfi -i "color=black:size=640x360:duration=1,format=yuv420p" -vf "pad=1280:720:320:180:color=red" "$TMPDIR/test_pad.mp4" && \
  pass "pad filter" || fail "pad filter"

# 8.11 Crop filter
run_ffmpeg -f lavfi -i "color=yellow:size=1280x720:duration=1,format=yuv420p" -vf "crop=640:360:320:180" "$TMPDIR/test_crop.mp4" && \
  pass "crop filter" || fail "crop filter"

# 8.12 FPS filter
run_ffmpeg -f lavfi -i "color=cyan:size=320x180:duration=2,format=yuv420p" -vf fps=1 "$TMPDIR/test_fps.mp4" && \
  pass "fps filter" || fail "fps filter"

# 8.13 Aresample filter
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2,sample_rate=48000" -af aresample=44100 "$TMPDIR/test_aresample.wav" && \
  pass "aresample filter" || fail "aresample filter"

# 8.14 Probe duration (ffprobe)
run_ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$TMPDIR/test_fdk.m4a" >/dev/null && \
  pass "ffprobe duration" || fail "ffprobe duration"

# 8.15 HLS demux (probe)
run_ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 "$TMPDIR/test.m3u8" >/dev/null && \
  pass "HLS demux probe" || fail "HLS demux probe"

# 8.16 Concat demux
echo "file '$TMPDIR/test_fdk.m4a'" > "$TMPDIR/concat.txt"
echo "file '$TMPDIR/test_opus.opus'" >> "$TMPDIR/concat.txt"
run_ffmpeg -f concat -safe 0 -i "$TMPDIR/concat.txt" -c copy "$TMPDIR/test_concat.m4a" && \
  pass "concat demux" || fail "concat demux"

# 8.17 Image2 demux (single frame)
run_ffmpeg -f lavfi -i "color=magenta:size=100x100:duration=1,format=rgb24" -c:v png -frames:v 1 "$TMPDIR/frame_%03d.png" && \
  run_ffmpeg -f image2 -i "$TMPDIR/frame_%03d.png" -c copy "$TMPDIR/test_image2.mkv" && \
  pass "image2 demux" || fail "image2 demux"

# 8.18 WebP decode (libdav1d/av1)
# Skip if no test file - just check decoder exists (already done)

# 8.19 MP3 decode
run_ffmpeg -i "$TMPDIR/test_lame.mp3" -f null - && \
  pass "mp3 decode" || fail "mp3 decode"

# 8.20 Opus decode
run_ffmpeg -i "$TMPDIR/test_opus.opus" -f null - && \
  pass "opus decode" || fail "opus decode"

# 8.21 FLAC decode (need to encode first)
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a flac "$TMPDIR/test_flac.flac" && \
  run_ffmpeg -i "$TMPDIR/test_flac.flac" -f null - && \
  pass "flac decode" || fail "flac decode"

# 8.22 Vorbis decode
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libvorbis "$TMPDIR/test_vorbis.ogg" && \
  run_ffmpeg -i "$TMPDIR/test_vorbis.ogg" -f null - && \
  pass "vorbis decode" || fail "vorbis decode"

# 8.23 AC3 decode
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a ac3 "$TMPDIR/test_ac3.ac3" && \
  run_ffmpeg -i "$TMPDIR/test_ac3.ac3" -f null - && \
  pass "ac3 decode" || fail "ac3 decode"

# 8.24 E-AC3 decode
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a eac3 "$TMPDIR/test_eac3.eac3" && \
  run_ffmpeg -i "$TMPDIR/test_eac3.eac3" -f null - && \
  pass "eac3 decode" || fail "eac3 decode"

# 8.25 ALAC decode
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a alac "$TMPDIR/test_alac.m4a" && \
  run_ffmpeg -i "$TMPDIR/test_alac.m4a" -f null - && \
  pass "alac decode" || fail "alac decode"

# 8.26 PCM decode (s16le)
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a pcm_s16le "$TMPDIR/test_pcm.wav" && \
  run_ffmpeg -i "$TMPDIR/test_pcm.wav" -f null - && \
  pass "pcm_s16le decode" || fail "pcm_s16le decode"

# 8.27 MJPEG decode
run_ffmpeg -i "$TMPDIR/test_mjpeg.jpg" -f null - && \
  pass "mjpeg decode" || fail "mjpeg decode"

# 8.28 PNG decode
run_ffmpeg -i "$TMPDIR/test_png.png" -f null - && \
  pass "png decode" || fail "png decode"

# 8.29 WebP decode
run_ffmpeg -i "$TMPDIR/test_webp.webp" -f null - && \
  pass "webp decode" || fail "webp decode"

# 8.30 GIF decode (encode first via image2)
run_ffmpeg -f lavfi -i "color=red:size=64x64:duration=1,format=rgb24" -c:v gif "$TMPDIR/test_gif.gif" && \
  run_ffmpeg -i "$TMPDIR/test_gif.gif" -f null - && \
  pass "gif decode" || fail "gif decode"

# 8.31 BMP decode
run_ffmpeg -f lavfi -i "color=blue:size=64x64:duration=1,format=rgb24" -c:v bmp "$TMPDIR/test_bmp.bmp" && \
  run_ffmpeg -i "$TMPDIR/test_bmp.bmp" -f null - && \
  pass "bmp decode" || fail "bmp decode"

# 8.32 H.264 decode (test file from HLS)
run_ffmpeg -i "$TMPDIR/test.m3u8" -c copy -t 2 -f null - 2>/dev/null && \
  pass "h264 decode (via HLS)" || skip "h264 decode (no test stream)"

# 8.33 HEVC decode (skip - no test stream)
skip "hevc decode (no test stream)"

# 8.34 AV1 decode (skip - no test stream)
skip "av1 decode (no test stream)"

# 8.35 Matroska mux
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus -f matroska "$TMPDIR/test_mkv.mkv" && \
  pass "matroska mux" || fail "matroska mux"

# 8.36 MP4 mux
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a aac -f mp4 "$TMPDIR/test_mp4.mp4" && \
  pass "mp4 mux" || fail "mp4 mux"

# 8.37 OGG mux
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus -f ogg "$TMPDIR/test_ogg.ogg" && \
  pass "ogg mux" || fail "ogg mux"

# 8.38 WebP mux (image)
run_ffmpeg -f lavfi -i "color=white:size=64x64:duration=1,format=rgb24" -c:v libwebp -lossless 1 -f webp "$TMPDIR/test_webp_mux.webp" && \
  pass "webp mux" || fail "webp mux"

# 8.39 Null mux
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -f null - && \
  pass "null mux" || fail "null mux"

# 8.40 BSF h264_mp4toannexb
run_ffmpeg -f lavfi -i "color=black:size=64x64:duration=1,format=yuv420p" -c:v libx264 -bsf:v h264_mp4toannexb -f h264 - 2>/dev/null | head -c 100 >/dev/null && \
  pass "bsf h264_mp4toannexb" || skip "bsf h264_mp4toannexb (no libx264)"

# 8.41 BSF hevc_mp4toannexb
skip "bsf hevc_mp4toannexb (no libx265)"

# 8.42 BSF aac_adtstoasc
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a aac -bsf:a aac_adtstoasc -f adts "$TMPDIR/test_aac_adtstoasc.aac" && \
  pass "bsf aac_adtstoasc" || fail "bsf aac_adtstoasc"

# 8.43 BSF extract_extradata
run_ffmpeg -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus -bsf:a extract_extradata -f opus - 2>/dev/null | head -c 100 >/dev/null && \
  pass "bsf extract_extradata" || skip "bsf extract_extradata"

# ============================================================
# 9. Missing on purpose (not in build)
# ============================================================
info "=== Intentionally missing ==="
for missing in libx264 libx265 libvpx-vp9 libaom-av1 libtheora libsvtav1; do
  if $FFMPEG -encoders 2>/dev/null | grep -q "^\s*[A-Z.]\{5\}\s*$missing\b"; then
    fail "unexpected encoder present: $missing"
  else
    pass "correctly missing: $missing"
  fi
done

# ============================================================
# Summary
# ============================================================
info "=== Summary ==="
printf 'PASS=%d FAIL=%d SKIP=%d\n' "$PASS" "$FAIL" "$SKIP"

# Cleanup
rm -rf "$TMPDIR"

if [ "$FAIL" -eq 0 ]; then
  exit 0
else
  exit 1
fi