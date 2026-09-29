#!/usr/bin/env node
/**
 * Pelatih FSRCNN ×2 (RGB) untuk AI Upscale MEeL — vanilla JS, TANPA dependensi
 * (tanpa numpy/torch; tooling mesin ini memang tidak punya keduanya).
 *
 *   node scripts/train-fsrcnn.js [--steps N] [--patch N] [--batch N]
 *                                 [--fps N] [--bench] [--dump-ref out.json]
 *                                 [--weights file.bin]
 *
 * Data latih: frame (fps) dari video lokal — BUKAN unduhan bobot pihak ketiga:
 *   ~/Downloads/chrome/Woman_smiling_shyly_at_man_20260920201000.mp4 (640×360)
 *   ~/Downloads/chrome/604638876-...mp4 (2560×1600)
 * Frame hanya dibaca sementara; yang di-commit cuma bobot ~52 KB (base64 ~70 KB).
 *
 * Arsitektur (harus IDENTIK dengan upscaler-fsrcnn.js):
 *   input  : LR [3][P][P] dikurangi 0.5 (zero-centering)
 *   conv1  : 5×5 pad2, 3→24,  PReLU per-channel
 *   shrink : 1×1,        24→16, PReLU per-channel
 *   map×3  : 3×3 pad1,   16→16, PReLU per-channel   (mapping)
 *   deconv : 9×9 stride2, 16→3, fase c=4 (idx=(n+4-j)/2), linier, clamp 0..1
 *   output : 2×P (skala 2×), nilai [0,1]
 *
 * Inisialisasi "identitas + bicubic": jalur rgb = identitas, deconv = kernel
 * Catmull-Rom fase kotak (LR[m] = rata-rata HR[2m],HR[2m+1]) → titik awal PSNR
 * = baseline bicubic, lalu dilatih melawannya (Adam + MSE, loss bermasker
 * interior aman radius RF = 7 piksel LR: conv1 2 + map 3 + deconv 2).
 *
 * Layout Float32Array(TOTAL=13163) — offset yang sama di shader WGSL:
 *   c1w 0(1800) c1b 1800(24) c1p 1824(24) sw 1848(384) sb 2232(16)
 *   sp 2248(16) m1w 2264(2304) m1b 4568(16) m1p 4584(16) m2w 4600(2304)
 *   m2b 6904(16) m2p 6920(16) m3w 6936(2304) m3b 9240(16) m3p 9256(16)
 *   dw 9272(3888) db 13160(3)  → TOTAL 13163
 *
 * Output:
 *   assets/js/video/watch/fsrcnn-weights.js   (base64 Float32Array)
 *   /tmp/opencode/fsrcnn-train/weights.bin    (dump periodik, jaring pengaman)
 *   /tmp/opencode/wgpu-test/fsrcnn-ref.json   (referensi CPU untuk uji piksel)
 */
"use strict";

const { spawn } = require("child_process");
const fs = require("fs");
const path = require("path");
const os = require("os");

/* ======================================================================
 * 0. Konfigurasi
 * ==================================================================== */
const REPO = path.resolve(__dirname, "..");
const TMP = "/tmp/opencode/fsrcnn-train";
const REF_OUT = "/tmp/opencode/wgpu-test/fsrcnn-ref.json";

const OFF = {
  c1w: 0, c1b: 1800, c1p: 1824,
  sw: 1848, sb: 2232, sp: 2248,
  m1w: 2264, m1b: 4568, m1p: 4584,
  m2w: 4600, m2b: 6904, m2p: 6920,
  m3w: 6936, m3b: 9240, m3p: 9256,
  dw: 9272, db: 13160,
};
const TOTAL = 13163;

const C1 = 24, D = 16, MAPS = 3;   // kanal per lapisan
const K1 = 5, KM = 3, KD = 9;      // ukuran kernel
const RF = 7;                      // radius RF pada grid LR (2+3+2)

const args = parseArgs();
const PATCH = args.patch;          // sisi HR patch (LR = PATCH/2)
const LP = PATCH / 2;
const BATCH = args.batch;

if (PATCH < 32 || PATCH % 2 !== 0) fail("patch harus >= 32 dan genap");

/* ======================================================================
 * 1. Utilitas
 * ==================================================================== */
function parseArgs() {
  const a = {
    steps: 1500, patch: 64, batch: 8, fps: 2,
    bench: false, dumpRef: null, weights: null,
    maxTrain: 8000, maxVal: 400, lrHi: 1e-4, lrLo: 1e-5, stepsSet: false,
  };
  const av = process.argv.slice(2);
  for (let i = 0; i < av.length; i++) {
    const k = av[i], v = av[i + 1];
    if (k === "--steps") { a.steps = +v; a.stepsSet = true; i++; }
    else if (k === "--patch") { a.patch = +v; i++; }
    else if (k === "--batch") { a.batch = +v; i++; }
    else if (k === "--fps") { a.fps = +v; i++; }
    else if (k === "--lr-hi") { a.lrHi = +v; i++; }
    else if (k === "--lr-lo") { a.lrLo = +v; i++; }
    else if (k === "--max-train") { a.maxTrain = +v; i++; }
    else if (k === "--max-val") { a.maxVal = +v; i++; }
    else if (k === "--dump-ref") { a.dumpRef = v || REF_OUT; i++; }
    else if (k === "--ref-w") { a.refW = +v; i++; }
    else if (k === "--ref-h") { a.refH = +v; i++; }
    else if (k === "--weights") { a.weights = v; i++; }
    else if (k === "--bench") a.bench = true;
    else if (k === "--help" || k === "-h") { console.log(fs.readFileSync(__filename, "utf8").split("*/")[0]); process.exit(0); }
  }
  return a;
}
function fail(msg) { console.error("[train-fsrcnn] GAGAL:", msg); process.exit(1); }
function mulberry32(seed) {
  let a = seed >>> 0;
  return function () {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
function gauss(rng, sigma) { // Box-Muller
  if (sigma === 0) return 0;
  const u = Math.max(rng(), 1e-12), v = rng();
  return sigma * Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
}
function fmtSec(s) {
  s = Math.max(0, Math.round(s));
  const m = Math.floor(s / 60);
  return m + ":" + String(s % 60).padStart(2, "0");
}

/* ======================================================================
 * 2. Kernel bicubic (baseline fase kotak) & inisialisasi bobot
 * ==================================================================== */
function catmullRom(x) {
  const a = Math.abs(x);
  if (a < 1) return 1.5 * a * a * a - 2.5 * a * a + 1;
  if (a < 2) return -0.5 * a * a * a + 2.5 * a * a - 4 * a + 2;
  return 0;
}
/** Tap 1D deconv fase c=4: arg(j) = (j-4)/2 - 0.25. Σ per-paritas = 1. */
function bicubicTaps() {
  const k = new Float32Array(KD);
  for (let j = 0; j < KD; j++) k[j] = catmullRom((j - 4) / 2 - 0.25);
  return k;
}

function initWeights(rng) {
  const w = new Float32Array(TOTAL);
  // Inisialisasi "identitas + bicubic" dengan noise serendah mungkin agar
  // PSNR awal ≈ baseline (noise besar justru bikin jaringan start jauh di
  // bawah baseline). Syarat struktural: conv1 kanal 3..23 HARUS non-nol
  // (kalau nol, gradien ke kanal itu mati permanen).
  // conv1: identitas center-tap untuk rgb (c<3, ci=c), sisanya acak kecil
  for (let c = 0; c < C1; c++) {
    for (let ci = 0; ci < 3; ci++) {
      for (let ky = 0; ky < K1; ky++) {
        for (let kx = 0; kx < K1; kx++) {
          const id = c < 3 && ci === c && ky === 2 && kx === 2;
          const sig = id ? 0.0003 : c < 3 ? 0.0002 : 0.01;
          w[OFF.c1w + (c * 3 + ci) * 25 + ky * K1 + kx] = (id ? 1 : 0) + gauss(rng, sig);
        }
      }
    }
  }
  // shrink 1×1: identitas pada diagonal
  for (let c = 0; c < D; c++) {
    for (let ci = 0; ci < C1; ci++) {
      const id = c === ci;
      w[OFF.sw + c * C1 + ci] = (id ? 1 : 0) + gauss(rng, id ? 0.0001 : 0.0003);
    }
  }
  // map×3: identitas center-tap
  const moff = [OFF.m1w, OFF.m2w, OFF.m3w];
  for (const base of moff) {
    for (let c = 0; c < D; c++) {
      for (let ci = 0; ci < D; ci++) {
        for (let ky = 0; ky < KM; ky++) {
          for (let kx = 0; kx < KM; kx++) {
            const id = c === ci && ky === 1 && kx === 1;
            w[base + (c * D + ci) * 9 + ky * KM + kx] = (id ? 1 : 0) + gauss(rng, 0.0001);
          }
        }
      }
    }
  }
  // deconv: diag rgb = K⊗K (bicubic), sisanya noise kecil (gerbang gradien)
  const k = bicubicTaps();
  for (let co = 0; co < 3; co++) {
    for (let ci = 0; ci < D; ci++) {
      for (let jy = 0; jy < KD; jy++) {
        for (let jx = 0; jx < KD; jx++) {
          const id = co === ci;
          w[OFF.dw + (co * D + ci) * 81 + jy * KD + jx] =
            (id ? k[jy] * k[jx] : 0) + gauss(rng, id ? 0.0001 : 0.0002);
        }
      }
    }
  }
  // bias: deconv 0.5 (kompensasi zero-centering), lainnya 0
  for (let co = 0; co < 3; co++) w[OFF.db + co] = 0.5;
  // PReLU: semua 1.0 (identitas)
  for (let i = 0; i < 24; i++) w[OFF.c1p + i] = 1;
  for (let i = 0; i < 16; i++) w[OFF.sp + i] = 1;
  for (let i = 0; i < 16; i++) w[OFF.m1p + i] = 1;
  for (let i = 0; i < 16; i++) w[OFF.m2p + i] = 1;
  for (let i = 0; i < 16; i++) w[OFF.m3p + i] = 1;
  return w;
}

/**
 * Upsample ×2 generik dengan tap deconv fase kotak yang sama:
 * arg(j) = (j-4)/2 - 0.25, paritas n≡j (mod 2). dipakai untuk baseline
 * bicubic (Catmull-Rom) dan bilinear (triangle).
 */
function kernelUpsample(lr, out, p, kf) {
  const PH = p * 2, k = new Float32Array(KD);
  for (let j = 0; j < KD; j++) k[j] = kf((j - 4) / 2 - 0.25);
  out.fill(0);
  for (let c = 0; c < 3; c++) {
    for (let y = 0; y < PH; y++) {
      for (let jy = y & 1; jy < KD; jy += 2) {
        const iy = (y + 4 - jy) >> 1;
        if (iy < 0 || iy >= p) continue;
        const kv = k[jy];
        if (kv === 0) continue;
        const rowIn = (c * p + iy) * p, rowOut = (c * PH + y) * PH;
        for (let x = 0; x < PH; x++) {
          let s = 0;
          for (let jx = x & 1; jx < KD; jx += 2) {
            const ix = (x + 4 - jx) >> 1;
            if (ix < 0 || ix >= p) continue;
            s += k[jx] * lr[rowIn + ix];
          }
          out[rowOut + x] += kv * s;
        }
      }
    }
  }
  return out;
}
function bicubicUpsample(lr, out, p) {
  return kernelUpsample(lr, out, p, catmullRom);
}
function bilinearUpsample(lr, out, p) {
  return kernelUpsample(lr, out, p, function (x) {
    const a = Math.abs(x);
    return a < 1 ? 1 - a : 0;
  });
}

/* ======================================================================
 * 3. Operasi lapisan (forward + backward), layout tipis
 * ==================================================================== */
// y[Cout][H][W] = b + Σ w[Cout][Cin][K][K] * x[Cin][H][W] (same padding)
function convFwd(x, w, b, Cin, Cout, H, W, K, pad, y) {
  const HW = H * W, KK = K * K;
  y.fill(0);
  for (let c = 0; c < Cout; c++) {
    const bc = b[c], yo = c * HW;
    if (bc !== 0) for (let i = 0; i < HW; i++) y[yo + i] = bc;
    for (let ci = 0; ci < Cin; ci++) {
      const xo = ci * HW;
      for (let ky = 0; ky < K; ky++) {
        for (let kx = 0; kx < K; kx++) {
          const wv = w[((c * Cin + ci) * K + ky) * K + kx];
          if (wv === 0) continue;
          const off = kx - pad;
          for (let oy = 0; oy < H; oy++) {
            const iy = oy + ky - pad;
            if (iy < 0 || iy >= H) continue;
            const rowX = xo + iy * W, rowY = yo + oy * W;
            if (off >= 0) {
              for (let ox = 0; ox < W; ox++) {
                const ix = ox + off;
                if (ix >= W) break;
                y[rowY + ox] += wv * x[rowX + ix];
              }
            } else {
              for (let ox = -off; ox < W; ox++) y[rowY + ox] += wv * x[rowX + ox + off];
            }
          }
        }
      }
    }
  }
}

// dx (opsional), dw, db dari dy. dx harus sudah diisi 0 bila dipakai.
function convBwd(dy, x, w, dx, dw, db, Cin, Cout, H, W, K, pad) {
  const HW = H * W, KK = K * K;
  for (let c = 0; c < Cout; c++) {
    let s = 0;
    const o = c * HW;
    for (let i = 0; i < HW; i++) s += dy[o + i];
    db[c] += s;
  }
  for (let c = 0; c < Cout; c++) {
    const dyo = c * HW;
    for (let ci = 0; ci < Cin; ci++) {
      const xo = ci * HW;
      for (let ky = 0; ky < K; ky++) {
        for (let kx = 0; kx < K; kx++) {
          const wi = ((c * Cin + ci) * K + ky) * K + kx;
          const wv = w[wi];
          const off = kx - pad;
          let sum = 0;
          for (let oy = 0; oy < H; oy++) {
            const iy = oy + ky - pad;
            if (iy < 0 || iy >= H) continue;
            const rowX = xo + iy * W, rowDY = dyo + oy * W;
            if (off >= 0) {
              for (let ox = 0; ox < W; ox++) {
                const ix = ox + off;
                if (ix >= W) break;
                sum += dy[rowDY + ox] * x[rowX + ix];
              }
            } else {
              for (let ox = -off; ox < W; ox++) sum += dy[rowDY + ox] * x[rowX + ox + off];
            }
          }
          dw[wi] += sum;
          if (dx && wv !== 0) {
            for (let oy = 0; oy < H; oy++) {
              const iy = oy + ky - pad;
              if (iy < 0 || iy >= H) continue;
              const rowDY = dyo + oy * W, rowDX = ci * HW + iy * W;
              if (off >= 0) {
                for (let ox = 0; ox < W; ox++) {
                  const ix = ox + off;
                  if (ix >= W) break;
                  dx[rowDX + ix] += wv * dy[rowDY + ox];
                }
              } else {
                for (let ox = -off; ox < W; ox++) dx[rowDX + ox + off] += wv * dy[rowDY + ox];
              }
            }
          }
        }
      }
    }
  }
}

function preluFwd(x, a, C, HW, y) {
  for (let c = 0; c < C; c++) {
    const aa = a[c], o = c * HW;
    for (let i = 0; i < HW; i++) {
      const v = x[o + i];
      y[o + i] = v > 0 ? v : v * aa;
    }
  }
}
function preluBwd(dy, pre, a, da, C, HW, dx) {
  for (let c = 0; c < C; c++) {
    const aa = a[c], o = c * HW;
    let das = 0;
    for (let i = 0; i < HW; i++) {
      const v = pre[o + i];
      if (v >= 0) dx[o + i] = dy[o + i];
      else { dx[o + i] = dy[o + i] * aa; das += dy[o + i] * v; }
    }
    da[c] += das;
  }
}

// out[3][2H][2W] = b + Σ w[3][16][9][9] * x[16][H][W], idx=(n+4-j)/2, paritas n≡j
function deconvFwd(x, w, b, H, W, out, OH, OW) {
  const Cin = D, Cout = 3, HW = H * W;
  out.fill(0);
  for (let co = 0; co < Cout; co++) {
    const bo = b[co], oo = co * OH * OW;
    if (bo !== 0) for (let i = 0; i < OH * OW; i++) out[oo + i] = bo;
    for (let oy = 0; oy < OH; oy++) {
      const rowO = oo + oy * OW;
      for (let jy = oy & 1; jy < KD; jy += 2) {
        const iy = (oy + 4 - jy) >> 1;
        if (iy < 0 || iy >= H) continue;
        for (let ox = 0; ox < OW; ox++) {
          let acc = rowO + ox;
          let s = out[acc];
          for (let jx = ox & 1; jx < KD; jx += 2) {
            const ix = (ox + 4 - jx) >> 1;
            if (ix < 0 || ix >= W) continue;
            const wb = ((co * Cin) * KD + jy) * KD + jx;
            for (let ci = 0; ci < Cin; ci++) s += w[wb + ci * 81] * x[ci * HW + iy * W + ix];
          }
          out[acc] = s;
        }
      }
    }
  }
}

// dw + db + dx (opsional) dari dy — loop tergabung per piksel output
function deconvBwd(dy, x, w, H, W, dx, dw, db, OH, OW) {
  const Cin = D, Cout = 3, HW = H * W;
  for (let co = 0; co < Cout; co++) {
    let s = 0;
    const o = co * OH * OW;
    for (let i = 0; i < OH * OW; i++) s += dy[o + i];
    db[co] += s;
  }
  for (let oy = 0; oy < OH; oy++) {
    for (let jy = oy & 1; jy < KD; jy += 2) {
      const iy = (oy + 4 - jy) >> 1;
      if (iy < 0 || iy >= H) continue;
      for (let ox = 0; ox < OW; ox++) {
        for (let jx = ox & 1; jx < KD; jx += 2) {
          const ix = (ox + 4 - jx) >> 1;
          if (ix < 0 || ix >= W) continue;
          const xi = iy * W + ix;
          for (let co = 0; co < Cout; co++) {
            const dyv = dy[co * OH * OW + oy * OW + ox];
            if (dyv === 0) continue;
            const wb = ((co * Cin) * KD + jy) * KD + jx;
            for (let ci = 0; ci < Cin; ci++) {
              const wi = wb + ci * 81;
              dw[wi] += dyv * x[ci * HW + xi];
              if (dx) dx[ci * HW + xi] += w[wi] * dyv;
            }
          }
        }
      }
    }
  }
}

/* ======================================================================
 * 4. Jaringan per sampel (forward + backward ke grad akumulator)
 * ==================================================================== */
function makeBuffers(p) {
  const HW = p * p, OH = p * 2, OW = p * 2;
  return {
    xc: new Float32Array(3 * HW),
    c1pre: new Float32Array(C1 * HW), c1: new Float32Array(C1 * HW),
    shpre: new Float32Array(D * HW), sh: new Float32Array(D * HW),
    m1pre: new Float32Array(D * HW), m1: new Float32Array(D * HW),
    m2pre: new Float32Array(D * HW), m2: new Float32Array(D * HW),
    m3pre: new Float32Array(D * HW), m3: new Float32Array(D * HW),
    out: new Float32Array(3 * OH * OW),
    // grad input antar lapisan (diisi 0 tiap backward)
    g_c1: new Float32Array(C1 * HW), g_sh: new Float32Array(D * HW),
    g_m1: new Float32Array(D * HW), g_m2: new Float32Array(D * HW),
    g_m3: new Float32Array(D * HW),
    g_out: new Float32Array(3 * OH * OW),
  };
}

/** lr[3][P][P] (sudah /255) → out; x pada [0,1] (belum clamp). */
function forward(w, lr, b, p) {
  const HW = p * p, OH = p * 2, OW = p * 2;
  for (let i = 0; i < 3 * HW; i++) b.xc[i] = lr[i] - 0.5;
  convFwd(b.xc, w.subarray(OFF.c1w, OFF.c1b), w.subarray(OFF.c1b, OFF.c1p), 3, C1, p, p, K1, 2, b.c1pre);
  preluFwd(b.c1pre, w.subarray(OFF.c1p, OFF.sw), C1, HW, b.c1);
  convFwd(b.c1, w.subarray(OFF.sw, OFF.sb), w.subarray(OFF.sb, OFF.sp), C1, D, p, p, 1, 0, b.shpre);
  preluFwd(b.shpre, w.subarray(OFF.sp, OFF.m1w), D, HW, b.sh);
  convFwd(b.sh, w.subarray(OFF.m1w, OFF.m1b), w.subarray(OFF.m1b, OFF.m1p), D, D, p, p, KM, 1, b.m1pre);
  preluFwd(b.m1pre, w.subarray(OFF.m1p, OFF.m2w), D, HW, b.m1);
  convFwd(b.m1, w.subarray(OFF.m2w, OFF.m2b), w.subarray(OFF.m2b, OFF.m2p), D, D, p, p, KM, 1, b.m2pre);
  preluFwd(b.m2pre, w.subarray(OFF.m2p, OFF.m3w), D, HW, b.m2);
  convFwd(b.m2, w.subarray(OFF.m3w, OFF.m3b), w.subarray(OFF.m3b, OFF.m3p), D, D, p, p, KM, 1, b.m3pre);
  preluFwd(b.m3pre, w.subarray(OFF.m3p, OFF.dw), D, HW, b.m3);
  deconvFwd(b.m3, w.subarray(OFF.dw, OFF.db), w.subarray(OFF.db), p, p, b.out, OH, OW);
  return b.out;
}

/** dy_out → grad akumulator g (sudah termasuk mask interior aman). */
function backward(w, g, b, p) {
  const HW = p * p, OH = p * 2, OW = p * 2;
  // mask: n aman bila RF ≤ floor(n/2) ≤ P-1-RF → n ∈ [2*RF, 2*(P-1-RF)+1]
  const lo = 2 * RF, hi = 2 * (p - 1 - RF) + 1;
  for (let n = 0; n < OH; n++) {
    const safe = n >= lo && n <= hi;
    for (let m = 0; m < OW; m++) {
      if (!safe || m < lo || m > hi) b.g_out[n * OW + m] = 0;
    }
  }
  deconvBwd(b.g_out, b.m3, w.subarray(OFF.dw, OFF.db), p, p, b.g_m3,
    g.subarray(OFF.dw, OFF.db), g.subarray(OFF.db), OH, OW);
  // preluBwd in-place: g_X dari ∂L/∂aktivasi → ∂L/∂pra-aktivasi
  preluBwd(b.g_m3, b.m3pre, w.subarray(OFF.m3p, OFF.dw), g.subarray(OFF.m3p, OFF.dw), D, HW, b.g_m3);
  b.g_m2.fill(0);
  convBwd(b.g_m3, b.m2, w.subarray(OFF.m3w, OFF.m3b), b.g_m2,
    g.subarray(OFF.m3w, OFF.m3b), g.subarray(OFF.m3b, OFF.m3p), D, D, p, p, KM, 1);
  preluBwd(b.g_m2, b.m2pre, w.subarray(OFF.m2p, OFF.m3w), g.subarray(OFF.m2p, OFF.m3w), D, HW, b.g_m2);
  b.g_m1.fill(0);
  convBwd(b.g_m2, b.m1, w.subarray(OFF.m2w, OFF.m2b), b.g_m1,
    g.subarray(OFF.m2w, OFF.m2b), g.subarray(OFF.m2b, OFF.m2p), D, D, p, p, KM, 1);
  preluBwd(b.g_m1, b.m1pre, w.subarray(OFF.m1p, OFF.m2w), g.subarray(OFF.m1p, OFF.m2w), D, HW, b.g_m1);
  b.g_sh.fill(0);
  convBwd(b.g_m1, b.sh, w.subarray(OFF.m1w, OFF.m1b), b.g_sh,
    g.subarray(OFF.m1w, OFF.m1b), g.subarray(OFF.m1b, OFF.m1p), D, D, p, p, KM, 1);
  preluBwd(b.g_sh, b.shpre, w.subarray(OFF.sp, OFF.m1w), g.subarray(OFF.sp, OFF.m1w), D, HW, b.g_sh);
  b.g_c1.fill(0);
  convBwd(b.g_sh, b.c1, w.subarray(OFF.sw, OFF.sb), b.g_c1,
    g.subarray(OFF.sw, OFF.sb), g.subarray(OFF.sb, OFF.sp), C1, D, p, p, 1, 0);
  preluBwd(b.g_c1, b.c1pre, w.subarray(OFF.c1p, OFF.sw), g.subarray(OFF.c1p, OFF.sw), C1, HW, b.g_c1);
  // conv1: input tak butuh grad
  convBwd(b.g_c1, b.xc, w.subarray(OFF.c1w, OFF.c1b), null,
    g.subarray(OFF.c1w, OFF.c1b), g.subarray(OFF.c1b, OFF.c1p), 3, C1, p, p, K1, 2);
}

/** HR uint8 64×64×3 → lr[3][P][P] (box 2×2) + tgt[3][64][64]. */
function makePair(hr, p, lr, tgt) {
  const PH = p * 2;
  for (let c = 0; c < 3; c++) {
    for (let y = 0; y < p; y++) {
      for (let x = 0; x < p; x++) {
        const i0 = ((2 * y) * PH + 2 * x) * 3 + c;
        const v = hr[i0] + hr[i0 + 3] + hr[i0 + PH * 3] + hr[i0 + PH * 3 + 3];
        lr[(c * p + y) * p + x] = v / (4 * 255);
      }
    }
    for (let y = 0; y < PH; y++) {
      for (let x = 0; x < PH; x++) tgt[(c * PH + y) * PH + x] = hr[(y * PH + x) * 3 + c] / 255;
    }
  }
}

const SAFE_LO = 2 * RF;
function safeHi(p) { return 2 * (p - 1 - RF) + 1; }

/** MSE bermasker interior aman (pred di-clamp 0..1 — sama seperti shader). */
function maskedMSE(pred, tgt, p) {
  const PH = p * 2, lo = SAFE_LO, hi = safeHi(p);
  let s = 0, n = 0;
  for (let y = lo; y <= hi; y++) {
    for (let x = lo; x <= hi; x++) {
      for (let c = 0; c < 3; c++) {
        const v = pred[(c * PH + y) * PH + x];
        const d = (v < 0 ? 0 : v > 1 ? 1 : v) - tgt[(c * PH + y) * PH + x];
        s += d * d;
        n++;
      }
    }
  }
  return { mse: s / Math.max(n, 1), n };
}

/* ======================================================================
 * 5. Data: ekstraksi frame (ffmpeg) + crop acak
 * ==================================================================== */
function probeWH(file) {
  return new Promise((res, rej) => {
    const ps = spawn("ffprobe", ["-v", "error", "-select_streams", "v:0",
      "-show_entries", "stream=width,height", "-of", "csv=p=0", file]);
    let out = "", err = "";
    ps.stdout.on("data", (d) => (out += d));
    ps.stderr.on("data", (d) => (err += d));
    ps.on("close", (code) => {
      if (code !== 0) return rej(new Error("ffprobe gagal: " + err.trim()));
      const m = out.trim().match(/^(\d+)\s*,\s*(\d+)/);
      if (!m) return rej(new Error("ffprobe output tak dikenal: " + out));
      res({ w: +m[1], h: +m[2] });
    });
    ps.on("error", rej);
  });
}

/** Baca frame rgb24 dari video @ fps → onFrame(buf, w, h, idx). */
async function extractFrames(file, fps, onFrame) {
  const { w, h } = await probeWH(file);
  const size = w * h * 3;
  return new Promise((resolve, reject) => {
    const ps = spawn("ffmpeg", ["-v", "error", "-i", file, "-vf", "fps=" + fps,
      "-f", "rawvideo", "-pix_fmt", "rgb24", "-"]);
    let rest = Buffer.alloc(0), idx = 0;
    ps.stdout.on("data", (chunk) => {
      const buf = rest.length ? Buffer.concat([rest, chunk]) : chunk;
      let off = 0;
      while (buf.length - off >= size) {
        onFrame(buf.subarray(off, off + size), w, h, idx++);
        off += size;
      }
      rest = off < buf.length ? Buffer.from(buf.subarray(off)) : Buffer.alloc(0);
    });
    ps.stderr.resume();
    ps.on("close", () => resolve({ w, h, frames: idx }));
    ps.on("error", reject);
  });
}

/**
 * Kumpulkan crop HR patch×patch (uint8) dari semua video.
 * Frame ganjil-per-6 (idx%6===5) → val; sisanya train.
 */
async function buildDataset(cfg) {
  const HR = PATCH * PATCH * 3;
  const big = new Uint8Array(cfg.maxTrain * HR + cfg.maxVal * HR);
  const trainIdx = [], valIdx = [];
  let slot = 0;
  const rng = mulberry32(0x9e3779b9);

  for (const file of cfg.videos) {
    if (trainIdx.length >= cfg.maxTrain && valIdx.length >= cfg.maxVal) break;
    if (!fs.existsSync(file)) {
      console.warn("[data] lewati (tidak ada):", file);
      continue;
    }
    const total0 = trainIdx.length + valIdx.length;
    const res = await extractFrames(file, cfg.fps, (buf, w, h, fi) => {
      if (w < PATCH || h < PATCH) return;
      const isVal = fi % 6 === 5;
      if (isVal && valIdx.length >= cfg.maxVal) return;
      if (!isVal && trainIdx.length >= cfg.maxTrain) return;
      const perFrame = isVal ? 10 : 50;
      for (let i = 0; i < perFrame; i++) {
        if (isVal && valIdx.length >= cfg.maxVal) break;
        if (!isVal && trainIdx.length >= cfg.maxTrain) break;
        const x = Math.floor(rng() * (w - PATCH));
        const y = Math.floor(rng() * (h - PATCH));
        // crop PATCH×PATCH tidak kontigu di frame → salin per baris
        const dst = slot * HR;
        for (let r = 0; r < PATCH; r++) {
          const rowOff = ((y + r) * w + x) * 3;
          big.set(buf.subarray(rowOff, rowOff + PATCH * 3), dst + r * PATCH * 3);
        }
        (isVal ? valIdx : trainIdx).push(slot);
        slot++;
      }
    });
    console.log("[data] " + path.basename(file) + ": " + res.w + "×" + res.h +
      ", " + res.frames + " frame, kumulatif train=" + trainIdx.length +
      " val=" + valIdx.length + " (" + (trainIdx.length + valIdx.length - total0) + " crop baru)");
  }
  if (!trainIdx.length) fail("dataset latih kosong — cek video lokal");
  if (!valIdx.length) fail("dataset validasi kosong");
  return { big, trainIdx, valIdx, HR };
}

/* ======================================================================
 * 6. Adam
 * ==================================================================== */
function makeAdam(n) {
  return { m: new Float32Array(n), v: new Float32Array(n) };
}
function adamStep(w, g, st, t, lr, scale) {
  const b1 = 0.9, b2 = 0.999, eps = 1e-8;
  const bc1 = 1 - Math.pow(b1, t), bc2 = 1 - Math.pow(b2, t);
  for (let i = 0; i < w.length; i++) {
    const gi = g[i] * scale;
    st.m[i] = b1 * st.m[i] + (1 - b1) * gi;
    st.v[i] = b2 * st.v[i] + (1 - b2) * gi * gi;
    w[i] -= lr * (st.m[i] / bc1) / (Math.sqrt(st.v[i] / bc2) + eps);
  }
}
function cosineLR(t, total, hi, lo) {
  if (total <= 1) return hi;
  return lo + 0.5 * (hi - lo) * (1 + Math.cos(Math.PI * (t - 1) / (total - 1)));
}

/* ======================================================================
 * 7. Mode: dump referensi CPU (untuk uji piksel harness Chrome)
 * ==================================================================== */
// Pola piksel yang sama dengan shader pola di harness:
//   R=((x*13+y*7)%16)/16, G=((x*5+y*3)%16)/16, B=((x+y*3)%16)/16
function patternAt(x, y, c) {
  if (c === 0) return ((x * 13 + y * 7) % 16) / 16;
  if (c === 1) return ((x * 5 + y * 3) % 16) / 16;
  return ((x + y * 3) % 16) / 16;
}
function dumpRef(w, outPath) {
  // forward() hanya mendukung patch persegi (p×p) — referensi ikut persegi.
  const pw = args.refW || 48, ph = pw;
  if (pw < 8 || pw > 1024) fail("--ref-w di luar rentang 8..1024");
  if (args.refH != null && args.refH !== pw) {
    fail("forward() hanya patch persegi — --ref-h harus sama dengan --ref-w");
  }
  const lr = new Float32Array(3 * ph * pw);
  for (let c = 0; c < 3; c++)
    for (let y = 0; y < ph; y++)
      for (let x = 0; x < pw; x++) lr[(c * ph + y) * pw + x] = patternAt(x, y, c);
  const b = makeBuffers(pw);
  const out = forward(w, lr, b, pw);
  const oh = ph * 2, ow = pw * 2;
  const clean = [];
  for (let i = 0; i < out.length; i++) {
    clean.push(Math.round(Math.min(1, Math.max(0, out[i])) * 1e6) / 1e6);
  }
  fs.mkdirSync(path.dirname(outPath), { recursive: true });
  fs.writeFileSync(outPath, JSON.stringify({ w: pw, h: ph, oh, ow, out: clean }));
  console.log("[ref] referensi CPU → " + outPath + " (" + out.length + " nilai)");
}

/* ======================================================================
 * 8. Main
 * ==================================================================== */
async function main() {
  const videos = [
    path.join(os.homedir(), "Downloads/chrome/Woman_smiling_shyly_at_man_20260920201000.mp4"),
    path.join(os.homedir(), "Downloads/chrome/604638876-fb1cd5a1-242c-45d7-b302-952a15aaa24d.mp4"),
  ];

  // Mode --dump-ref: pakai bobot yang sudah dilatih
  if (args.dumpRef) {
    let w;
    if (args.weights) w = loadBin(args.weights);
    else if (fs.existsSync(path.join(TMP, "weights.bin"))) w = loadBin(path.join(TMP, "weights.bin"));
    else fail("--dump-ref butuh --weights atau weights.bin hasil training");
    dumpRef(w, args.dumpRef === true ? REF_OUT : args.dumpRef);
    return;
  }

  fs.mkdirSync(TMP, { recursive: true });
  const ds = await buildDataset({
    videos,
    fps: args.fps,
    maxTrain: args.bench ? 400 : args.maxTrain,
    maxVal: args.bench ? 60 : args.maxVal,
  });

  const rng = mulberry32(0x1234abcd);
  const w = initWeights(rng);
  const g = new Float32Array(TOTAL);
  const adam = makeAdam(TOTAL);
  const bTrain = makeBuffers(LP), bVal = makeBuffers(LP);

  const lr = new Float32Array(3 * LP * LP);
  const tgt = new Float32Array(3 * PATCH * PATCH);

  // Baseline bilinear + bicubic sekali di seluruh val
  const biOut = new Float32Array(3 * PATCH * PATCH);
  let biMSE = 0, biN = 0, liMSE = 0;
  for (const s of ds.valIdx) {
    makePair(ds.big.subarray(s * ds.HR, (s + 1) * ds.HR), LP, lr, tgt);
    bicubicUpsample(lr, biOut, LP);
    let r = maskedMSE(biOut, tgt, LP);
    biMSE += r.mse * r.n;
    biN += r.n;
    bilinearUpsample(lr, biOut, LP);
    r = maskedMSE(biOut, tgt, LP);
    liMSE += r.mse * r.n;
  }
  biMSE /= Math.max(biN, 1);
  liMSE /= Math.max(biN, 1);
  const biPSNR = -10 * Math.log10(Math.max(biMSE, 1e-12));
  const liPSNR = -10 * Math.log10(Math.max(liMSE, 1e-12));
  console.log("[init] baseline (val n=" + ds.valIdx.length + "): bicubic=" +
    biPSNR.toFixed(3) + " dB, bilinear=" + liPSNR.toFixed(3) + " dB");

  const steps = args.bench && !args.stepsSet ? 10 : args.steps;
  console.log("[train] steps=" + steps + " batch=" + BATCH + " patch HR=" + PATCH +
    " (LR " + LP + ") train=" + ds.trainIdx.length + " val=" + ds.valIdx.length);

  function evalVal(subset) {
    const idx = subset || ds.valIdx;
    let s = 0, n = 0;
    for (const si of idx) {
      makePair(ds.big.subarray(si * ds.HR, (si + 1) * ds.HR), LP, lr, tgt);
      const out = forward(w, lr, bVal, LP);
      const r = maskedMSE(out, tgt, LP);
      s += r.mse * r.n;
      n += r.n;
    }
    return -10 * Math.log10(Math.max(s / Math.max(n, 1), 1e-12));
  }

  const t0 = Date.now();
  let done = 0, report = [];
  const valEvery = args.bench ? steps + 1 : Math.max(1, Math.round(steps / 8));
  const valSub = ds.valIdx.slice(0, Math.min(120, ds.valIdx.length));

  // Cek inisialisasi: net (identitas+bicubic) harus ≈ baseline bicubic
  const initPSNR = evalVal(valSub);
  console.log("[init] PSNR jaringan di step 0 (subset val) = " + initPSNR.toFixed(3) +
    " dB vs bicubic " + biPSNR.toFixed(3) + " dB, bilinear " + liPSNR.toFixed(3) + " dB");

  while (done < steps) {
    done++;
    const lrNow = cosineLR(done, steps, args.lrHi, args.lrLo);
    g.fill(0);
    let lossSum = 0;
    for (let bi = 0; bi < BATCH; bi++) {
      const s = ds.trainIdx[Math.floor(rng() * ds.trainIdx.length)];
      makePair(ds.big.subarray(s * ds.HR, (s + 1) * ds.HR), LP, lr, tgt);
      const out = forward(w, lr, bTrain, LP);
      // grad MSE bermasker (rata-rata interior aman)
      const lo = SAFE_LO, hi = safeHi(LP), PH = PATCH;
      let sse = 0, cnt = 0;
      bTrain.g_out.fill(0);
      for (let y = lo; y <= hi; y++) {
        for (let x = lo; x <= hi; x++) {
          for (let c = 0; c < 3; c++) {
            const oi = (c * PH + y) * PH + x;
            const d = out[oi] - tgt[oi];
            sse += d * d;
            cnt++;
            bTrain.g_out[oi] = d;
          }
        }
      }
      const inv = 2 / Math.max(cnt, 1);
      for (let i = 0; i < bTrain.g_out.length; i++) bTrain.g_out[i] *= inv;
      lossSum += sse / Math.max(cnt, 1);
      backward(w, g, bTrain, LP);
    }
    const loss = lossSum / BATCH;
    if (!isFinite(loss)) fail("loss NaN di step " + done + " — berhenti");
    adamStep(w, g, adam, done, lrNow, 1 / BATCH);
    if (args.bench) console.log("  [bench] step " + done + " loss=" + loss.toExponential(3));

    if (done % valEvery === 0 || done === steps) {
      const v = evalVal(valSub);
      const el = (Date.now() - t0) / 1000;
      const ips = done / el;
      const line = "step " + done + "/" + steps + " loss=" + loss.toExponential(3) +
        " psnr(val-sub)=" + v.toFixed(3) + " dB bicubic=" + biPSNR.toFixed(3) +
        " lr=" + lrNow.toExponential(1) + " " + ips.toFixed(1) + " it/s ETA " +
        fmtSec((steps - done) / Math.max(ips, 1e-9));
      console.log("[train] " + line);
      report.push({ step: done, loss, psnr: v, bicubic: biPSNR });
    }
    if (done % 500 === 0 && done < steps) {
      fs.writeFileSync(path.join(TMP, "weights.bin"), Buffer.from(w.buffer));
    }
  }

  const el = (Date.now() - t0) / 1000;
  console.log("[train] selesai dalam " + fmtSec(el));

  // Evaluasi penuh
  const finalPSNR = evalVal(null);
  console.log("[eval] PSNR jaringan (val penuh n=" + ds.valIdx.length + ") = " +
    finalPSNR.toFixed(3) + " dB");
  console.log("[eval] baseline: bicubic = " + biPSNR.toFixed(3) + " dB, bilinear = " +
    liPSNR.toFixed(3) + " dB");
  console.log("[eval] vs bicubic = " + (finalPSNR - biPSNR >= 0 ? "+" : "") +
    (finalPSNR - biPSNR).toFixed(3) + " dB, vs bilinear = " +
    (finalPSNR - liPSNR >= 0 ? "+" : "") + (finalPSNR - liPSNR).toFixed(3) + " dB " +
    (finalPSNR >= liPSNR ? "→ LULUS (net ≥ bilinear)" : "→ PERINGATAN: net < bilinear"));

  // Simpan
  fs.writeFileSync(path.join(TMP, "weights.bin"), Buffer.from(w.buffer));
  exportJS(w, {
    net: finalPSNR, bicubic: biPSNR, bilinear: liPSNR,
    val: ds.valIdx.length, train: ds.trainIdx.length, steps, el,
  });
  dumpRef(w, REF_OUT);
  console.log("[out] assets/js/video/watch/fsrcnn-weights.js");
}

function loadBin(file) {
  const buf = fs.readFileSync(file);
  const w = new Float32Array(buf.buffer, buf.byteOffset, buf.byteLength / 4);
  if (w.length !== TOTAL) fail("panjang " + file + " = " + w.length + " ≠ " + TOTAL);
  return new Float32Array(w); // copy
}

function exportJS(w, meta) {
  const bytes = Buffer.from(w.buffer, w.byteOffset, w.byteLength);
  const b64 = bytes.toString("base64");
  const lines = [
    "/**",
    " * Bobot FSRCNN ×2 — DILATIH LOKAL (vanilla JS trainer, bukan unduhan).",
    " * Hasilkan ulang: node scripts/train-fsrcnn.js",
    " * Arsitektur & layout Float32Array(" + TOTAL + "):",
    " *   conv1 5×5 pad2 3→24 +PReLU, shrink 1×1 24→16 +PReLU,",
    " *   3× map 3×3 pad1 16→16 +PReLU, deconv 9×9 stride2 fase c=4 16→3,",
    " *   input LR dikurangi 0.5, deconv bias 0.5, output clamp 0..1.",
    " * Offset: " + Object.keys(OFF).map(function (k) { return k + " " + OFF[k]; }).join(", "),
    " * Metrik val (n=" + meta.val + ", patch " + PATCH + "×" + PATCH + " HR, " + meta.steps + " step, " + fmtSec(meta.el) + "):",
    " *   PSNR jaringan = " + meta.net.toFixed(3) + " dB, baseline bicubic = " +
      meta.bicubic.toFixed(3) + " dB, baseline bilinear = " + meta.bilinear.toFixed(3) + " dB.",
    " * Data latih: frame lokal (ffmpeg) dari dua video di ~/Downloads/chrome/.",
    " */",
    "window.MEEL_FSRCNN_WEIGHTS_B64 =",
    '  "' + b64 + '";',
    "",
  ];
  const out = path.join(REPO, "assets/js/video/watch/fsrcnn-weights.js");
  fs.writeFileSync(out, lines.join("\n"));
  console.log("[out] " + out + " (" + (fs.statSync(out).size / 1024).toFixed(1) + " KB, " +
    TOTAL + " float)");
}

if (require.main === module) {
  main().catch((e) => {
    console.error("[train-fsrcnn] error:", e && e.stack || e);
    process.exit(1);
  });
}

module.exports = {
  OFF, TOTAL, RF, bicubicTaps, bicubicUpsample, initWeights, makeBuffers,
  forward, backward, makePair, maskedMSE, SAFE_LO, safeHi, mulberry32, deconvFwd, convFwd,
};
