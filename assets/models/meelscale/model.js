/**
 * MEeLScale — resampler WebGPU lokal untuk AI Upscale MEeL: tanpa unduhan,
 * seluruh kernel dihitung GPU. Kontrak registerModel() →
 * docs/id/development.md ("Video — AI Upscale & Play Recovery").
 *
 * Rantai buildChain() memilih jalur termurah dari selisih target vs native:
 *   target == native   → CAS saja (resample dilewati, satu pass)
 *   hanya lebar beda   → H → CAS
 *   hanya tinggi beda  → V → CAS
 *   kedua sumbu beda   → H → V → CAS (teksel antara rgba16float)
 *
 * Resample separable: satu konvolusi per sumbu (tap rx/ry = clamp(scale, 1, 8),
 * maksimum 63 tap, tepi clamp-to-edge lalu bobot dinormalisasi) — jauh lebih
 * murah daripada jendela 2D penuh rx×ry per piksel.
 *
 * Konvensi koordinat: src = pos * scale - 0.5, dengan pos = pusat piksel hasil
 * (@builtin(posisi) sudah +0.5). Pada scale 1 hasilnya identik dengan sumber;
 * rumus lama (pos + 0.5) menambahkan geseran setengah piksel yang tampak sebagai
 * blur di resolusi native.
 *
 * Pass terakhir CAS (contrast adaptive sharpening) selalu aktif dengan
 * SHARPNESS 0,45: cukup tajam tanpa overshoot berlebih, dan pada jalur 1:1
 * inilah satu-satunya sumber penajeman.
 *
 * CAS diadaptasi dari FidelityFX CAS milik Advanced Micro Devices, Inc.;
 * atensi lisensi MIT dipertahankan di bawah sesuai ketentuan lisensi.
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

  // Kekuatan CAS: tetap (bukan per mode) supaya karakter tiap kernel tak
  // berubah; 0,45 ≈ tengah kisangan aman antara lembut dan tajam.
  var SHARPNESS = 0.45;

  var KERNELS = {
    bilinear: {
      short: "Bilinear",
      support: 1.0,
      body: [
        "fn kern(x: f32) -> f32 {",
        "  return max(0.0, 1.0 - abs(x));",
        "}",
      ].join("\n"),
    },
    mitchell: {
      short: "Mitchell",
      support: 2.0,
      body: bicubicBody(1.0 / 3.0, 1.0 / 3.0),
    },
    catrom: {
      short: "Catmull-Rom",
      support: 2.0,
      body: bicubicBody(0.0, 0.5),
    },
    lanczos2: {
      short: "Lanczos 2",
      support: 2.0,
      body: lanczosBody(2.0),
    },
    lanczos3: {
      short: "Lanczos 3",
      support: 3.0,
      body: lanczosBody(3.0),
    },
  };

  function bicubicBody(B, C) {
    return [
      "fn kern(x: f32) -> f32 {",
      "  let ax = abs(x);",
      "  if (ax < 1.0) {",
      "    let a = " + B.toFixed(7) + ";",
      "    let c = " + C.toFixed(7) + ";",
      "    return ((12.0 - 9.0 * a - 6.0 * c) * ax * ax * ax + (-18.0 + 12.0 * a + 6.0 * c) * ax * ax + (6.0 - 2.0 * a)) / 6.0;",
      "  } else if (ax < 2.0) {",
      "    let a = " + B.toFixed(7) + ";",
      "    let c = " + C.toFixed(7) + ";",
      "    return ((-a - 6.0 * c) * ax * ax * ax + (6.0 * a + 30.0 * c) * ax * ax + (-12.0 * a - 48.0 * c) * ax + (8.0 * a + 24.0 * c)) / 6.0;",
      "  }",
      "  return 0.0;",
      "}",
    ].join("\n");
  }

  function lanczosBody(support) {
    return [
      "fn sinc(x: f32) -> f32 {",
      "  if (abs(x) < 1e-5) { return 1.0; }",
      "  let px = 3.141592653589793 * x;",
      "  return sin(px) / px;",
      "}",
      "fn kern(x: f32) -> f32 {",
      "  let ax = abs(x);",
      "  if (ax < " + support.toFixed(1) + ") { return sinc(x) * sinc(x / " + support.toFixed(1) + "); }",
      "  return 0.0;",
      "}",
    ].join("\n");
  }

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

  var BIND_SRC = "@group(0) @binding(0) var src : texture_2d<f32>;";

  // Tahap horizontal: lebar = target, tinggi = tinggi sumber (1:1 vertikal,
  // baris sumber diambil langsung dari pos piksel hasil).
  function shaderH(k, targetW) {
    return [
      VS,
      BIND_SRC,
      k.body,
      "const SUPPORT : f32 = " + k.support.toFixed(1) + ";",
      "const OUT_W : f32 = " + Number(targetW).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dims = textureDimensions(src);",
      "  let scaleX = f32(dims.x) / OUT_W;",
      "  let srcx = input.pos.x * scaleX - 0.5;",
      "  let rx = clamp(scaleX, 1.0, 8.0);",
      "  var x0 = i32(floor(srcx - SUPPORT * rx));",
      "  var x1 = i32(ceil(srcx + SUPPORT * rx));",
      "  if (x1 - x0 > 63) { let cx = i32(floor(srcx)); x0 = cx - 31; x1 = cx + 31; }",
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

  // Tahap vertikal: tinggi = target, lebar = lebar teksel masukan (1:1
  // horizontal). Dipakai pada sumbu yang berubah saja.
  function shaderV(k, targetH) {
    return [
      VS,
      BIND_SRC,
      k.body,
      "const SUPPORT : f32 = " + k.support.toFixed(1) + ";",
      "const OUT_H : f32 = " + Number(targetH).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dims = textureDimensions(src);",
      "  let scaleY = f32(dims.y) / OUT_H;",
      "  let srcy = input.pos.y * scaleY - 0.5;",
      "  let ry = clamp(scaleY, 1.0, 8.0);",
      "  var y0 = i32(floor(srcy - SUPPORT * ry));",
      "  var y1 = i32(ceil(srcy + SUPPORT * ry));",
      "  if (y1 - y0 > 63) { let cy = i32(floor(srcy)); y0 = cy - 31; y1 = cy + 31; }",
      "  let sx = clamp(i32(input.pos.x), 0, i32(dims.x) - 1);",
      "  var acc = vec3<f32>(0.0);",
      "  var wsum = 0.0;",
      "  for (var j = y0; j <= y1; j = j + 1) {",
      "    let jy = clamp(j, 0, i32(dims.y) - 1);",
      "    let w = kern((f32(j) - srcy) / ry);",
      "    if (w == 0.0) { continue; }",
      "    acc += textureLoad(src, vec2<i32>(sx, jy), 0).rgb * w;",
      "    wsum += w;",
      "  }",
      "  if (wsum <= 0.0) {",
      "    let q = textureLoad(src, vec2<i32>(sx, clamp(i32(floor(srcy)), 0, i32(dims.y) - 1)), 0);",
      "    return vec4<f32>(q.rgb, 1.0);",
      "  }",
      "  return vec4<f32>(acc / wsum, 1.0);",
      "}",
    ].join("\n");
  }

  // CAS 1:1 pada resolusi keluaran (tetangga silang dari hasil resample).
  function shaderCAS(sharpness) {
    return [
      VS,
      BIND_SRC,
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

  function makeNode(device, label, code, outTex, srcView) {
    var module = device.createShaderModule({ label: label + "-mod", code: code });
    var bgl = device.createBindGroupLayout({
      label: label + "-bgl",
      entries: [
        {
          binding: 0,
          visibility: GPUShaderStage.FRAGMENT,
          texture: { sampleType: "float" },
        },
      ],
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
      entries: [{ binding: 0, resource: srcView }],
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
    id: "meelscale",
    label: "MEeLScale",
    short: "MEeLScale",
    modes: [
      { id: "bilinear", label: "Bilinear", short: "Bilinear" },
      { id: "mitchell", label: "Mitchell (Bicubic)", short: "Mitchell" },
      { id: "catrom", label: "Catmull-Rom (Bicubic)", short: "Catmull-Rom" },
      { id: "lanczos2", label: "Lanczos 2", short: "Lanczos 2" },
      { id: "lanczos3", label: "Lanczos 3", short: "Lanczos 3" },
    ],
    buildChain: function (o) {
      var device = o.device;
      var modeId = o.modeId;
      var k = KERNELS[modeId] || KERNELS.bilinear;
      var w = o.target.width;
      var h = o.target.height;
      var iw = (o.native && o.native.width) || o.inputTexture.width;
      var ih = (o.native && o.native.height) || o.inputTexture.height;
      var needW = w !== iw;
      var needH = h !== ih;

      var nodes = [];
      var casIn = o.inputTexture.createView();
      var tag = "meel-meelscale-" + modeId;

      if (needW && needH) {
        var tmpH = makeTexture(device, "meel-meelscale-tmph", w, ih);
        nodes.push(makeNode(device, tag + "-h", shaderH(k, w), tmpH, casIn));
        var tmpV = makeTexture(device, "meel-meelscale-tmpv", w, h);
        nodes.push(makeNode(device, tag + "-v", shaderV(k, h), tmpV, tmpH.createView()));
        casIn = tmpV.createView();
      } else if (needW) {
        var midW = makeTexture(device, "meel-meelscale-midw", w, h);
        nodes.push(makeNode(device, tag + "-h", shaderH(k, w), midW, casIn));
        casIn = midW.createView();
      } else if (needH) {
        var midH = makeTexture(device, "meel-meelscale-midh", w, h);
        nodes.push(makeNode(device, tag + "-v", shaderV(k, h), midH, casIn));
        casIn = midH.createView();
      }

      var out = makeTexture(device, "meel-meelscale-out", w, h);
      nodes.push(makeNode(device, tag + "-cas", shaderCAS(SHARPNESS), out, casIn));
      return nodes;
    },
  });
})();
