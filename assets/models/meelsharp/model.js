/**
 * MEeLSharp — upscaler klasik WebGPU lokal: tanpa bobot, tanpa unduhan, tanpa
 * load() berat. Rantainya Lanczos-3 dua tahap (horizontal lalu vertikal,
 * tekstur antara rgba16float) lalu sharpening CAS pada resolusi tampil.
 *
 * Kontrak registerModel() → docs/id/development.md ("Video — AI Upscale &
 * Play Recovery"). Ukuran keluaran mengikuti o.target — bukan dikunci 2× —
 * sehingga video 1080p pada layar 2560×1600 berhenti di 2560×1440 dan tidak
 * dihitung 3840×2160 lalu dikecilkan lagi.
 *
 * Tepi frame memakai clamp-to-edge (indeks dibatasi ke batas frame, bukan
 * zero-pad seperti MEeLVision) supaya tidak muncul tepi gelap; hasil Lanczos
 * dibatasi min/max kotak sumber terdekat agar tidak ada halo/ringing.
 *
 * CAS (contrast adaptive sharpening) diadaptasi dari FidelityFX CAS milik
 * Advanced Micro Devices, Inc.; rumus dipetakan ke ruang warna masukan
 * (rgba16float, 0..1) dan bobotnya dikendalikan mode. Atribusi lisensi MIT
 * dipertahankan di bawah sesuai ketentuan lisensi.
 *
 * -----------------------------------------------------------------------------
 * FidelityFX CAS — Copyright (c) 2019 Advanced Micro Devices, Inc. All rights
 * reserved.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.  IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 * -----------------------------------------------------------------------------
 */
(function () {
  "use strict";
  if (!window.MEEL_UPSCALER) return;

  // Kekuatan CAS per mode (dipakai sebagai konstanta shader; ganti mode hanya
  // membangun ulang pipeline — tidak ada aset yang dimuat ulang).
  var SHARP = { lembut: 0.3, seimbang: 0.5, tajam: 0.8 };

  var VS = [
    "struct VSOut {",
    "  @builtin(position) pos : vec4<f32>,",
    "  @location(0) uv : vec2<f32>,",
    "};",
    "@vertex",
    "fn vs_main(@builtin(vertex_index) vi : u32) -> VSOut {",
    "  var pts = array<vec2<f32>, 6>(",
    "    vec2<f32>(-1.0, -1.0), vec2<f32>( 1.0, -1.0), vec2<f32>(-1.0,  1.0),",
    "    vec2<f32>(-1.0,  1.0), vec2<f32>( 1.0, -1.0), vec2<f32>( 1.0,  1.0)",
    "  );",
    "  var out : VSOut;",
    "  let p = pts[vi];",
    "  out.pos = vec4<f32>(p.x, p.y, 0.0, 1.0);",
    "  out.uv = vec2<f32>((p.x + 1.0) * 0.5, (1.0 - p.y) * 0.5);",
    "  return out;",
    "}",
  ].join("\n");

  var LANCZOS = [
    "fn sinc(x: f32) -> f32 {",
    "  if (abs(x) < 1e-5) { return 1.0; }",
    "  let px = 3.141592653589793 * x;",
    "  return sin(px) / px;",
    "}",
    "fn kern(x: f32) -> f32 {",
    "  let ax = abs(x);",
    "  if (ax < 3.0) { return sinc(x) * sinc(x / 3.0); }",
    "  return 0.0;",
    "}",
  ].join("\n");

  // Jendela tap diskalakan rasio (seperti MEeLScale): pada 1,33× jendela
  // sumber melebar jadi ±4 px (±3 pada ruang hasil) — konsekuensi normal
  // Lanczos-3 yang benar untuk rasio tak-integer, bukan 6 tap tetap.
  var WINDOW_X = [
    "  let rx = clamp(scaleX, 1.0, 8.0);",
    "  var x0 = i32(floor(srcx - 3.0 * rx));",
    "  var x1 = i32(ceil(srcx + 3.0 * rx));",
    "  if (x1 - x0 > 63) { let cx = i32(floor(srcx)); x0 = cx - 31; x1 = cx + 31; }",
  ].join("\n");

  var WINDOW_Y = [
    "  let ry = clamp(scaleY, 1.0, 8.0);",
    "  var y0 = i32(floor(srcy - 3.0 * ry));",
    "  var y1 = i32(ceil(srcy + 3.0 * ry));",
    "  if (y1 - y0 > 63) { let cy = i32(floor(srcy)); y0 = cy - 31; y1 = cy + 31; }",
  ].join("\n");

  // Batas anti-ringing dari kotak sumber terdekat: 2×2 saat upscale
  // (hx = hy = 0 → ax..ax+1) dan melebar mengikuti footprint filter saat
  // downscale ("setara" dari ketentuan 2×2). Butuh ds/src/srcx/srcy/rx/ry.
  var ANTI_RING = [
    "  let ax = clamp(i32(floor(srcx)), 0, i32(ds.x) - 1);",
    "  let ay = clamp(i32(floor(srcy)), 0, i32(ds.y) - 1);",
    "  let hx = clamp(i32(ceil(rx)) - 1, 0, 4);",
    "  let hy = clamp(i32(ceil(ry)) - 1, 0, 4);",
    "  var mn = vec3<f32>(1e9);",
    "  var mx = vec3<f32>(-1e9);",
    "  for (var dj = -hy; dj <= hy + 1; dj = dj + 1) {",
    "    let yy = clamp(ay + dj, 0, i32(ds.y) - 1);",
    "    for (var di = -hx; di <= hx + 1; di = di + 1) {",
    "      let xx = clamp(ax + di, 0, i32(ds.x) - 1);",
    "      let s = textureLoad(src, vec2<i32>(xx, yy), 0).rgb;",
    "      mn = min(mn, s);",
    "      mx = max(mx, s);",
    "    }",
    "  }",
  ].join("\n");

  // Tahap 1: Lanczos horizontal, satu baris sumber per piksel hasil
  // (tinggi hasil = tinggi sumber) → tekstur antara selebar target.
  function shaderH(targetW) {
    return [
      VS,
      LANCZOS,
      "@group(0) @binding(0) var src : texture_2d<f32>;",
      "const OUT_W : f32 = " + Number(targetW).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dims = textureDimensions(src);",
      "  let scaleX = f32(dims.x) / OUT_W;",
      "  let srcx = (input.pos.x + 0.5) * scaleX - 0.5;",
      WINDOW_X,
      "  let sy = clamp(i32(input.pos.y), 0, i32(dims.y) - 1);",
      "  var acc = vec3<f32>(0.0);",
      "  var wsum = 0.0;",
      "  for (var i = x0; i <= x1; i = i + 1) {",
      "    let ix = clamp(i, 0, i32(dims.x) - 1);",
      "    let w = kern((f32(i) - srcx) / rx);",
      "    if (w == 0.0) { continue; }",
      "    acc += textureLoad(src, vec2<i32>(ix, sy), 0).rgb * w;",
      "    wsum += w;",
      "  }",
      "  if (wsum <= 0.0) {",
      "    let q = textureLoad(src, vec2<i32>(clamp(i32(floor(srcx)), 0, i32(dims.x) - 1), sy), 0);",
      "    return vec4<f32>(q.rgb, 1.0);",
      "  }",
      "  return vec4<f32>(acc / wsum, 1.0);",
      "}",
    ].join("\n");
  }

  // Tahap 2: Lanczos vertikal + anti-ringing. Kotak sumber untuk batas
  // min/max: 2×2 saat upscale (hx = hy = 0 → ax..ax+1), melebar mengikuti
  // footprint filter saat downscale ("setara" dari spesifikasi 2×2).
  function shaderV(targetW, targetH) {
    return [
      VS,
      LANCZOS,
      "@group(0) @binding(0) var tmp : texture_2d<f32>;",
      "@group(0) @binding(1) var src : texture_2d<f32>;",
      "const OUT_W : f32 = " + Number(targetW).toFixed(1) + ";",
      "const OUT_H : f32 = " + Number(targetH).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dtmp = textureDimensions(tmp);",
      "  let ds = textureDimensions(src);",
      "  let scaleX = f32(ds.x) / OUT_W;",
      "  let scaleY = f32(ds.y) / OUT_H;",
      "  let srcx = (input.pos.x + 0.5) * scaleX - 0.5;",
      "  let srcy = (input.pos.y + 0.5) * scaleY - 0.5;",
      WINDOW_X,
      WINDOW_Y,
      "  let ix = clamp(i32(input.pos.x), 0, i32(dtmp.x) - 1);",
      "  var acc = vec3<f32>(0.0);",
      "  var wsum = 0.0;",
      "  for (var j = y0; j <= y1; j = j + 1) {",
      "    let jy = clamp(j, 0, i32(dtmp.y) - 1);",
      "    let w = kern((f32(j) - srcy) / ry);",
      "    if (w == 0.0) { continue; }",
      "    acc += textureLoad(tmp, vec2<i32>(ix, jy), 0).rgb * w;",
      "    wsum += w;",
      "  }",
      "  var res = acc / wsum;",
      "  if (wsum <= 0.0) {",
      "    res = textureLoad(tmp, vec2<i32>(ix, clamp(i32(floor(srcy)), 0, i32(dtmp.y) - 1)), 0).rgb;",
      "  }",
      ANTI_RING,
      "  return vec4<f32>(clamp(res, mn, mx), 1.0);",
      "}",
    ].join("\n");
  }

  // Varian pembanding performa: Lanczos 2D dalam satu pass (jendela penuh
  // rx×ry per piksel hasil, tanpa tekstur antara). Sengaja tidak terdaftar di
  // modes — hanya dipanggil benchmark lewat modeId berakhiran "-direct".
  function shader2D(targetW, targetH) {
    return [
      VS,
      LANCZOS,
      "@group(0) @binding(0) var src : texture_2d<f32>;",
      "const OUT_W : f32 = " + Number(targetW).toFixed(1) + ";",
      "const OUT_H : f32 = " + Number(targetH).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let ds = textureDimensions(src);",
      "  let scaleX = f32(ds.x) / OUT_W;",
      "  let scaleY = f32(ds.y) / OUT_H;",
      "  let srcx = (input.pos.x + 0.5) * scaleX - 0.5;",
      "  let srcy = (input.pos.y + 0.5) * scaleY - 0.5;",
      WINDOW_X,
      WINDOW_Y,
      "  var acc = vec3<f32>(0.0);",
      "  var wsum = 0.0;",
      "  for (var j = y0; j <= y1; j = j + 1) {",
      "    let jy = clamp(j, 0, i32(ds.y) - 1);",
      "    let wy = kern((f32(j) - srcy) / ry);",
      "    if (wy == 0.0) { continue; }",
      "    for (var i = x0; i <= x1; i = i + 1) {",
      "      let ix = clamp(i, 0, i32(ds.x) - 1);",
      "      let wx = kern((f32(i) - srcx) / rx);",
      "      if (wx == 0.0) { continue; }",
      "      let w = wy * wx;",
      "      acc += textureLoad(src, vec2<i32>(ix, jy), 0).rgb * w;",
      "      wsum += w;",
      "    }",
      "  }",
      "  var res = acc / wsum;",
      "  if (wsum <= 0.0) {",
      "    res = textureLoad(src, vec2<i32>(clamp(i32(floor(srcx)), 0, i32(ds.x) - 1),",
      "                                     clamp(i32(floor(srcy)), 0, i32(ds.y) - 1)), 0).rgb;",
      "  }",
      ANTI_RING,
      "  return vec4<f32>(clamp(res, mn, mx), 1.0);",
      "}",
    ].join("\n");
  }

  // Tahap 3: CAS pada resolusi tampil (butuh tetangga silang hasil Lanczos).
  function shaderCAS(sharpness) {
    return [
      VS,
      "@group(0) @binding(0) var src : texture_2d<f32>;",
      "const SHARPNESS : f32 = " + Number(sharpness).toFixed(7) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dims = textureDimensions(src);",
      "  let x = clamp(i32(input.pos.x), 0, i32(dims.x) - 1);",
      "  let y = clamp(i32(input.pos.y), 0, i32(dims.y) - 1);",
      "  let c = textureLoad(src, vec2<i32>(x, y), 0).rgb;",
      "  let t = textureLoad(src, vec2<i32>(x, max(y - 1, 0)), 0).rgb;",
      "  let b = textureLoad(src, vec2<i32>(x, min(y + 1, i32(dims.y) - 1)), 0).rgb;",
      "  let l = textureLoad(src, vec2<i32>(max(x - 1, 0), y), 0).rgb;",
      "  let r = textureLoad(src, vec2<i32>(min(x + 1, i32(dims.x) - 1), y), 0).rgb;",
      "  let mn = min(min(min(min(c, t), b), l), r);",
      "  let mx = max(max(max(max(c, t), b), l), r);",
      "  let amp = sqrt(clamp(min(mn, vec3<f32>(1.0) - mx) / max(mx, vec3<f32>(1e-6)),",
      "                      vec3<f32>(0.0), vec3<f32>(1.0)));",
      "  let w = amp * (-1.0 / mix(8.0, 5.0, SHARPNESS));",
      "  let res = ((t + b + l + r) * w + c) / (1.0 + 4.0 * w);",
      "  return vec4<f32>(clamp(res, vec3<f32>(0.0), vec3<f32>(1.0)), 1.0);",
      "}",
    ].join("\n");
  }

  function makeTexture(device, label, w, h) {
    return device.createTexture({
      label: label,
      size: [w, h, 1],
      format: "rgba16float",
      usage: GPUTextureUsage.RENDER_ATTACHMENT | GPUTextureUsage.TEXTURE_BINDING,
    });
  }

  function makeNode(device, label, code, bindings, outTex, views) {
    var module = device.createShaderModule({ label: label + "-mod", code: code });
    var bgl = device.createBindGroupLayout({
      label: label + "-bgl",
      entries: bindings.map(function (b) {
        return {
          binding: b,
          visibility: GPUShaderStage.FRAGMENT,
          texture: { sampleType: "float" },
        };
      }),
    });
    var pipeline = device.createRenderPipeline({
      label: label + "-pipe",
      layout: device.createPipelineLayout({ bindGroupLayouts: [bgl] }),
      vertex: { module: module, entryPoint: "vs_main" },
      fragment: { module: module, entryPoint: "fs_main", targets: [{ format: "rgba16float" }] },
      primitive: { topology: "triangle-list" },
    });
    var bind = device.createBindGroup({
      label: label + "-bind",
      layout: bgl,
      entries: views.map(function (v, i) {
        return { binding: bindings[i], resource: v };
      }),
    });
    return {
      pass: function (enc) {
        var rp = enc.beginRenderPass({
          colorAttachments: [
            {
              view: outTex.createView(),
              clearValue: { r: 0, g: 0, b: 0, a: 1 },
              loadOp: "clear",
              storeOp: "store",
            },
          ],
        });
        rp.setPipeline(pipeline);
        rp.setBindGroup(0, bind);
        rp.draw(6);
        rp.end();
      },
      getOutputTexture: function () {
        return outTex;
      },
      pipelines: [pipeline],
      destroy: function () {
        try {
          outTex.destroy();
        } catch (e) {}
      },
    };
  }

  window.MEEL_UPSCALER.registerModel({
    id: "meelsharp",
    label: "MEeLSharp",
    short: "MEeLSharp",
    modes: [
      { id: "seimbang", label: "Seimbang", short: "Seimbang" },
      { id: "lembut", label: "Lembut", short: "Lembut" },
      { id: "tajam", label: "Tajam", short: "Tajam" },
    ],
    buildChain: function (o) {
      var device = o.device;
      var modeId = String(o.modeId || "seimbang");
      var direct = modeId.slice(-7) === "-direct";
      var key = direct ? modeId.slice(0, -7) : modeId;
      if (!Object.prototype.hasOwnProperty.call(SHARP, key)) key = "seimbang";
      var sharpness = SHARP[key];
      var w = o.target.width;
      var h = o.target.height;

      var nodes = [];
      var casIn;
      if (direct) {
        // Pembanding: dua pass total (2D → CAS), tanpa tekstur antara.
        var tmpD = makeTexture(device, "meel-meelsharp-tmd", w, h);
        nodes.push(makeNode(device, "meel-meelsharp-2d", shader2D(w, h), [0], tmpD,
                            [o.inputTexture.createView()]));
        casIn = tmpD.createView();
      } else {
        // Jalur normal: horizontal → vertikal (antara rgba16float) → CAS.
        var tmpH = makeTexture(device, "meel-meelsharp-tmph", w, o.native.height);
        var tmpV = makeTexture(device, "meel-meelsharp-tmpv", w, h);
        nodes.push(makeNode(device, "meel-meelsharp-h", shaderH(w), [0], tmpH,
                            [o.inputTexture.createView()]));
        nodes.push(makeNode(device, "meel-meelsharp-v", shaderV(w, h), [0, 1], tmpV,
                            [tmpH.createView(), o.inputTexture.createView()]));
        casIn = tmpV.createView();
      }
      var out = makeTexture(device, "meel-meelsharp-out", w, h);
      nodes.push(makeNode(device, "meel-meelsharp-cas-" + key, shaderCAS(sharpness), [0], out, [casIn]));
      return nodes;
    },
  });
})();
