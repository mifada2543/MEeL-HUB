/**
 * FSRCNN ×2 — neural upscaler WebGPU lokal (tanpa unduhan): bobot dilatih
 * lokal oleh trainer vanilla JS yang tidak ikut repo, dikirim sebagai
 * weights.js (window.MEEL_FSRCNN_WEIGHTS_B64).
 *
 * Arsitektur, layout bobot, dan strategi bertile ada di
 * docs/id/development.md ("Video — AI Upscale & Play Recovery"); kontrak
 * registerModel() ada di bagian yang sama.
 *
 * Semantik tepi wajib sama dengan trainer CPU: baca di luar batas frame
 * di-skip (zero-pad), posisi di luar frame tidak dikonsumsi.
 */
(function () {
  "use strict";
  if (!window.MEEL_UPSCALER) return;

  var TOTAL = 13163;
  var T = 512; // sisi tile (piksel LR)
  var TS = T + 10; // ukuran tekstur tile = T + halo 5 per sisi

  // Offset bobot — wajib identik dengan layout Float32Array(13163) di
  // docs/id/development.md (trainer lokal tidak ikut repo).
  var OFF = {
    c1w: 0, c1b: 1800, c1p: 1824,
    sw: 1848, sb: 2232, sp: 2248,
    m1w: 2264, m1b: 4568, m1p: 4584,
    m2w: 4600, m2b: 6904, m2p: 6920,
    m3w: 6936, m3b: 9240, m3p: 9256,
    dw: 9272, db: 13160,
  };

  var weightBuf = null; // ArrayBuffer hasil decode, dipakai ulang tiap build

  // weights.js satu folder dengan file ini; string versi (?v=) ikut file model.
  var WEIGHTS_URL = (function () {
    var src = (document.currentScript && document.currentScript.src) || "";
    var m = src.match(/[?&]v=([^&]+)/);
    var qs = m ? "?v=" + encodeURIComponent(m[1]) : "";
    return src ? src.replace(/[^\/?]+(\?[^\/]*)?$/, "weights.js" + qs) : "weights.js";
  })();

  function injectScript(url) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement("script");
      s.src = url;
      s.async = true;
      s.onload = function () {
        resolve();
      };
      s.onerror = function () {
        s.remove();
        reject(new Error("Gagal memuat " + url));
      };
      document.head.appendChild(s);
    });
  }

  function decodeWeights(b64) {
    var bin;
    try {
      bin = atob(b64);
    } catch (e) {
      throw new Error("bobot FSRCNN bukan base64 valid");
    }
    var n = bin.length;
    if (n % 4 !== 0 || n / 4 !== TOTAL) {
      throw new Error("panjang bobot FSRCNN tidak valid (" + n + " byte)");
    }
    var u8 = new Uint8Array(n);
    for (var i = 0; i < n; i++) u8[i] = bin.charCodeAt(i);
    weightBuf = u8.buffer;
  }

  var VS = [
    "struct VSOut {",
    "  @builtin(position) pos : vec4<f32>,",
    "};",
    "@vertex",
    "fn vs(@builtin(vertex_index) vi : u32) -> VSOut {",
    "  var pts = array<vec2<f32>, 6>(",
    "    vec2<f32>(-1.0, -1.0), vec2<f32>( 1.0, -1.0), vec2<f32>(-1.0,  1.0),",
    "    vec2<f32>(-1.0,  1.0), vec2<f32>( 1.0, -1.0), vec2<f32>( 1.0,  1.0)",
    "  );",
    "  var o : VSOut;",
    "  let p = pts[vi];",
    "  o.pos = vec4<f32>(p.x, p.y, 0.0, 1.0);",
    "  return o;",
    "}",
  ].join("\n");

  function head(w, h) {
    return [
      "struct U { x0 : i32, y0 : i32, _p0 : i32, _p1 : i32 };",
      "@group(0) @binding(0) var<uniform> u : U;",
      "@group(0) @binding(1) var<storage, read> wts : array<f32>;",
      "const W : i32 = " + w + ";",
      "const H : i32 = " + h + ";",
      "const TS : i32 = " + TS + ";",
      "fn prelu(v : f32, a : f32) -> f32 { return select(v * a, v, v > 0.0); }",
    ].join("\n");
  }

  // P1: conv1 5×5 pad2 3→24 + PReLU → shrink 1×1 24→16 + PReLU (4 MRT).
  function shaderP1(w, h) {
    return [
      VS,
      head(w, h),
      "@group(0) @binding(2) var src : texture_2d<f32>;",
      "struct Out {",
      "  @location(0) c0 : vec4<f32>,",
      "  @location(1) c1 : vec4<f32>,",
      "  @location(2) c2 : vec4<f32>,",
      "  @location(3) c3 : vec4<f32>,",
      "};",
      "@fragment",
      "fn fs(i : VSOut) -> Out {",
      "  let lx = u.x0 - 5 + i32(i.pos.x);",
      "  let ly = u.y0 - 5 + i32(i.pos.y);",
      "  var c1 = array<f32, 24>();",
      "  for (var c = 0; c < 24; c = c + 1) { c1[c] = wts[" + OFF.c1b + " + c]; }",
      "  for (var ky = 0; ky < 5; ky = ky + 1) {",
      "    let iy = ly + ky - 2;",
      "    if (iy < 0 || iy >= H) { continue; }",
      "    for (var kx = 0; kx < 5; kx = kx + 1) {",
      "      let ix = lx + kx - 2;",
      "      if (ix < 0 || ix >= W) { continue; }",
      "      let v = textureLoad(src, vec2<i32>(ix, iy), 0);",
      "      let xr = v.r - 0.5;",
      "      let xg = v.g - 0.5;",
      "      let xb = v.b - 0.5;",
      "      for (var c = 0; c < 24; c = c + 1) {",
      "        let b0 = c * 75 + ky * 5 + kx;",
      "        c1[c] = c1[c] + wts[" + OFF.c1w + " + b0] * xr",
      "          + wts[" + OFF.c1w + " + b0 + 25] * xg",
      "          + wts[" + OFF.c1w + " + b0 + 50] * xb;",
      "      }",
      "    }",
      "  }",
      "  var s = array<f32, 16>();",
      "  for (var g = 0; g < 16; g = g + 1) {",
      "    var acc = wts[" + OFF.sb + " + g];",
      "    for (var ci = 0; ci < 24; ci = ci + 1) {",
      "      acc = acc + wts[" + OFF.sw + " + g * 24 + ci] * prelu(c1[ci], wts[" + OFF.c1p + " + ci]);",
      "    }",
      "    s[g] = prelu(acc, wts[" + OFF.sp + " + g]);",
      "  }",
      "  var o : Out;",
      "  o.c0 = vec4<f32>(s[0], s[1], s[2], s[3]);",
      "  o.c1 = vec4<f32>(s[4], s[5], s[6], s[7]);",
      "  o.c2 = vec4<f32>(s[8], s[9], s[10], s[11]);",
      "  o.c3 = vec4<f32>(s[12], s[13], s[14], s[15]);",
      "  return o;",
      "}",
    ].join("\n");
  }

  // P2–P4: conv3 3×3 pad1 16→16 + PReLU (4 MRT). Koordinat tile dari pusat
  // tile (origin x0-5); tepi di-clamp ke tekstur, luar frame zero-pad.
  function shaderMap(w, h, base, bias, pre) {
    return [
      VS,
      head(w, h),
      "@group(0) @binding(2) var t0 : texture_2d<f32>;",
      "@group(0) @binding(3) var t1 : texture_2d<f32>;",
      "@group(0) @binding(4) var t2 : texture_2d<f32>;",
      "@group(0) @binding(5) var t3 : texture_2d<f32>;",
      "struct Out {",
      "  @location(0) c0 : vec4<f32>,",
      "  @location(1) c1 : vec4<f32>,",
      "  @location(2) c2 : vec4<f32>,",
      "  @location(3) c3 : vec4<f32>,",
      "};",
      "@fragment",
      "fn fs(i : VSOut) -> Out {",
      "  let lx = u.x0 - 5 + i32(i.pos.x);",
      "  let ly = u.y0 - 5 + i32(i.pos.y);",
      "  var a = array<f32, 16>();",
      "  for (var c = 0; c < 16; c = c + 1) { a[c] = wts[" + bias + " + c]; }",
      "  for (var ky = 0; ky < 3; ky = ky + 1) {",
      "    let iy = ly + ky - 1;",
      "    if (iy < 0 || iy >= H) { continue; }",
      "    for (var kx = 0; kx < 3; kx = kx + 1) {",
      "      let ix = lx + kx - 1;",
      "      if (ix < 0 || ix >= W) { continue; }",
      "      let p = vec2<i32>(clamp(ix - u.x0 + 5, 0, TS - 1), clamp(iy - u.y0 + 5, 0, TS - 1));",
      "      var vv = array<vec4<f32>, 4>();",
      "      vv[0] = textureLoad(t0, p, 0);",
      "      vv[1] = textureLoad(t1, p, 0);",
      "      vv[2] = textureLoad(t2, p, 0);",
      "      vv[3] = textureLoad(t3, p, 0);",
      "      for (var go = 0; go < 4; go = go + 1) {",
      "        for (var gi = 0; gi < 4; gi = gi + 1) {",
      "          let v = vv[gi];",
      "          for (var ro = 0; ro < 4; ro = ro + 1) {",
      "            let c = go * 4 + ro;",
      "            for (var ri = 0; ri < 4; ri = ri + 1) {",
      "              let ci = gi * 4 + ri;",
      "              a[c] = a[c] + wts[" + base + " + ((c * 16 + ci) * 3 + ky) * 3 + kx] * v[ri];",
      "            }",
      "          }",
      "        }",
      "      }",
      "    }",
      "  }",
      "  var o : Out;",
      "  o.c0 = vec4<f32>(prelu(a[0], wts[" + pre + "]), prelu(a[1], wts[" + pre + " + 1]),",
      "    prelu(a[2], wts[" + pre + " + 2]), prelu(a[3], wts[" + pre + " + 3]));",
      "  o.c1 = vec4<f32>(prelu(a[4], wts[" + pre + " + 4]), prelu(a[5], wts[" + pre + " + 5]),",
      "    prelu(a[6], wts[" + pre + " + 6]), prelu(a[7], wts[" + pre + " + 7]));",
      "  o.c2 = vec4<f32>(prelu(a[8], wts[" + pre + " + 8]), prelu(a[9], wts[" + pre + " + 9]),",
      "    prelu(a[10], wts[" + pre + " + 10]), prelu(a[11], wts[" + pre + " + 11]));",
      "  o.c3 = vec4<f32>(prelu(a[12], wts[" + pre + " + 12]), prelu(a[13], wts[" + pre + " + 13]),",
      "    prelu(a[14], wts[" + pre + " + 14]), prelu(a[15], wts[" + pre + " + 15]));",
      "  return o;",
      "}",
    ].join("\n");
  }

  // P5: deconv 9×9 stride2 fase 16→3 + bias, clamp 0..1. Posisi piksel =
  // koordinat absolut tekstur keluaran; scissor membatasi ke rect tile.
  function shaderP5(w, h) {
    return [
      VS,
      head(w, h),
      "@group(0) @binding(2) var t0 : texture_2d<f32>;",
      "@group(0) @binding(3) var t1 : texture_2d<f32>;",
      "@group(0) @binding(4) var t2 : texture_2d<f32>;",
      "@group(0) @binding(5) var t3 : texture_2d<f32>;",
      "@fragment",
      "fn fs(i : VSOut) -> @location(0) vec4<f32> {",
      "  let ox = i32(i.pos.x);",
      "  let oy = i32(i.pos.y);",
      "  var o0 = wts[" + OFF.db + "];",
      "  var o1 = wts[" + OFF.db + " + 1];",
      "  var o2 = wts[" + OFF.db + " + 2];",
      "  for (var jy = oy & 1; jy < 9; jy = jy + 2) {",
      "    let iy = (oy + 4 - jy) >> 1;",
      "    if (iy < 0 || iy >= H) { continue; }",
      "    for (var jx = ox & 1; jx < 9; jx = jx + 2) {",
      "      let ix = (ox + 4 - jx) >> 1;",
      "      if (ix < 0 || ix >= W) { continue; }",
      "      let p = vec2<i32>(ix - u.x0 + 5, iy - u.y0 + 5);",
      "      var vv = array<vec4<f32>, 4>();",
      "      vv[0] = textureLoad(t0, p, 0);",
      "      vv[1] = textureLoad(t1, p, 0);",
      "      vv[2] = textureLoad(t2, p, 0);",
      "      vv[3] = textureLoad(t3, p, 0);",
      "      for (var ci = 0; ci < 16; ci = ci + 1) {",
      "        let v = vv[ci >> 2][ci & 3];",
      "        o0 = o0 + wts[" + OFF.dw + " + ci * 81 + jy * 9 + jx] * v;",
      "        o1 = o1 + wts[" + (OFF.dw + 1296) + " + ci * 81 + jy * 9 + jx] * v;",
      "        o2 = o2 + wts[" + (OFF.dw + 2592) + " + ci * 81 + jy * 9 + jx] * v;",
      "      }",
      "    }",
      "  }",
      "  return vec4<f32>(clamp(o0, 0.0, 1.0), clamp(o1, 0.0, 1.0), clamp(o2, 0.0, 1.0), 1.0);",
      "}",
    ].join("\n");
  }

  window.MEEL_UPSCALER.registerModel({
    id: "fsrcnn",
    label: "FSRCNN",
    short: "FSRCNN",
    modes: [{ id: "x2", label: "FSRCNN ×2", short: "×2" }],
    load: function () {
      if (weightBuf) return Promise.resolve();
      var b64 = window.MEEL_FSRCNN_WEIGHTS_B64;
      if (typeof b64 === "string" && b64) {
        decodeWeights(b64);
        return Promise.resolve();
      }
      // Lazy-load: injeksi weights.js dulu, baru decode base64-nya.
      return injectScript(WEIGHTS_URL).then(function () {
        var b = window.MEEL_FSRCNN_WEIGHTS_B64;
        if (typeof b !== "string" || !b) {
          throw new Error("weights.js belum termuat setelah injeksi");
        }
        decodeWeights(b);
      });
    },
    buildChain: function (o) {
      var device = o.device;
      var w = o.native.width;
      var h = o.native.height;
      var ow = w * 2;
      var oh = h * 2;
      var maxDim = 8192;
      try {
        maxDim = device.limits.maxTextureDimension2D || 8192;
      } catch (e) {}
      if (ow > maxDim || oh > maxDim) {
        throw new Error(
          "FSRCNN: ukuran keluaran 2× (" + ow + "×" + oh + ") melebihi batas GPU (" + maxDim + "px)",
        );
      }
      if (!weightBuf) throw new Error("Bobot FSRCNN belum dimuat (load() belum dipanggil)");

      var wbuf = device.createBuffer({
        label: "meel-fsrcnn-w",
        size: TOTAL * 4,
        usage: GPUBufferUsage.STORAGE | GPUBufferUsage.COPY_DST,
      });
      device.queue.writeBuffer(wbuf, 0, weightBuf, 0, TOTAL * 4);

      var outTex = device.createTexture({
        label: "meel-fsrcnn-out",
        size: [ow, oh, 1],
        format: "rgba16float",
        usage: GPUTextureUsage.RENDER_ATTACHMENT | GPUTextureUsage.TEXTURE_BINDING,
      });

      function tileTex(label) {
        return device.createTexture({
          label: label,
          size: [TS, TS, 1],
          format: "rgba16float",
          usage: GPUTextureUsage.RENDER_ATTACHMENT | GPUTextureUsage.TEXTURE_BINDING,
        });
      }
      var gShrink = [tileTex("meel-fsrcnn-sh0"), tileTex("meel-fsrcnn-sh1"), tileTex("meel-fsrcnn-sh2"), tileTex("meel-fsrcnn-sh3")];
      var gM1 = [tileTex("meel-fsrcnn-m10"), tileTex("meel-fsrcnn-m11"), tileTex("meel-fsrcnn-m12"), tileTex("meel-fsrcnn-m13")];
      var gM2 = [tileTex("meel-fsrcnn-m20"), tileTex("meel-fsrcnn-m21"), tileTex("meel-fsrcnn-m22"), tileTex("meel-fsrcnn-m23")];
      var gM3 = [tileTex("meel-fsrcnn-m30"), tileTex("meel-fsrcnn-m31"), tileTex("meel-fsrcnn-m32"), tileTex("meel-fsrcnn-m33")];
      var allTex = gShrink.concat(gM1, gM2, gM3);

      // Daftar tile + slot uniform per tile (256 B/slot, penulisan sekali).
      var txN = Math.ceil(w / T);
      var tyN = Math.ceil(h / T);
      var SLOT = 256;
      var tiles = [];
      var udata = new ArrayBuffer(Math.max(txN * tyN, 1) * SLOT);
      var iv = new Int32Array(udata);
      for (var ty = 0; ty < tyN; ty++) {
        for (var tx = 0; tx < txN; tx++) {
          var idx = ty * txN + tx;
          var x0 = tx * T;
          var y0 = ty * T;
          iv[idx * (SLOT / 4)] = x0;
          iv[idx * (SLOT / 4) + 1] = y0;
          tiles.push({
            x0: x0,
            y0: y0,
            sx: 2 * x0,
            sy: 2 * y0,
            sw: Math.min(2 * x0 + 2 * T, ow) - 2 * x0,
            sh: Math.min(2 * y0 + 2 * T, oh) - 2 * y0,
          });
        }
      }
      var ubuf = device.createBuffer({
        label: "meel-fsrcnn-u",
        size: udata.byteLength,
        usage: GPUBufferUsage.UNIFORM | GPUBufferUsage.COPY_DST,
      });
      device.queue.writeBuffer(ubuf, 0, udata);

      // Tanpa dynamic offset: bind group dibuat per (pass, tile) saat build.
      function makePass(name, code, readTexs, views, opts) {
        var module = device.createShaderModule({ label: "meel-fsrcnn-" + name, code: code });
        var entries = [
          { binding: 0, visibility: GPUShaderStage.FRAGMENT, buffer: { type: "uniform" } },
          { binding: 1, visibility: GPUShaderStage.FRAGMENT, buffer: { type: "read-only-storage" } },
        ];
        for (var i = 0; i < readTexs.length; i++) {
          entries.push({ binding: 2 + i, visibility: GPUShaderStage.FRAGMENT, texture: { sampleType: "float" } });
        }
        var bgl = device.createBindGroupLayout({ label: "meel-fsrcnn-" + name + "-bgl", entries: entries });
        var targets = [];
        for (var t = 0; t < views.length; t++) targets.push({ format: "rgba16float" });
        var pipeline = device.createRenderPipeline({
          label: "meel-fsrcnn-" + name + "-pipe",
          layout: device.createPipelineLayout({ bindGroupLayouts: [bgl] }),
          vertex: { module: module, entryPoint: "vs" },
          fragment: { module: module, entryPoint: "fs", targets: targets },
          primitive: { topology: "triangle-list" },
        });
        var colorAttachments = [];
        for (var v = 0; v < views.length; v++) {
          colorAttachments.push(
            opts && opts.load
              ? { view: views[v], loadOp: "load", storeOp: "store" }
              : { view: views[v], clearValue: { r: 0, g: 0, b: 0, a: 1 }, loadOp: "clear", storeOp: "store" },
          );
        }
        var P = {
          name: name,
          pipeline: pipeline,
          bgl: bgl,
          reads: readTexs,
          colorAttachments: colorAttachments,
          scissor: !!(opts && opts.scissor),
          binds: [],
        };
        for (var ti = 0; ti < tiles.length; ti++) {
          var bents = [
            { binding: 0, resource: { buffer: ubuf, offset: ti * SLOT, size: SLOT } },
            { binding: 1, resource: { buffer: wbuf } },
          ];
          for (var r = 0; r < readTexs.length; r++) {
            bents.push({ binding: 2 + r, resource: readTexs[r].createView() });
          }
          P.binds.push(
            device.createBindGroup({
              label: "meel-fsrcnn-" + name + "-bind-" + ti,
              layout: bgl,
              entries: bents,
            }),
          );
        }
        return P;
      }

      var outView = outTex.createView();
      var p1 = makePass("p1", shaderP1(w, h), [o.inputTexture], gShrink.map(function (t) { return t.createView(); }), null);
      var p2 = makePass("p2", shaderMap(w, h, OFF.m1w, OFF.m1b, OFF.m1p), gShrink, gM1.map(function (t) { return t.createView(); }), null);
      var p3 = makePass("p3", shaderMap(w, h, OFF.m2w, OFF.m2b, OFF.m2p), gM1, gM2.map(function (t) { return t.createView(); }), null);
      var p4 = makePass("p4", shaderMap(w, h, OFF.m3w, OFF.m3b, OFF.m3p), gM2, gM3.map(function (t) { return t.createView(); }), null);
      var p5 = makePass("p5", shaderP5(w, h), gM3, [outView], { load: true, scissor: true });
      var passes = [p1, p2, p3, p4, p5];

      var destroyed = false;

      return [
        {
          pass: function (enc) {
            if (destroyed) return;
            // Tile-major: rantai P1→P5 satu tile selesai sebelum tile
            // berikutnya menimpa tekstur antara yang dipakai bersama.
            for (var t = 0; t < tiles.length; t++) {
              for (var pi = 0; pi < passes.length; pi++) {
                var P = passes[pi];
                var rp = enc.beginRenderPass({ colorAttachments: P.colorAttachments });
                rp.setPipeline(P.pipeline);
                rp.setBindGroup(0, P.binds[t]);
                if (P.scissor) {
                  var tl = tiles[t];
                  rp.setScissorRect(tl.sx, tl.sy, tl.sw, tl.sh);
                }
                rp.draw(6);
                rp.end();
              }
            }
          },
          getOutputTexture: function () {
            return outTex;
          },
          pipelines: passes.map(function (P) {
            return P.pipeline;
          }),
          destroy: function () {
            destroyed = true;
            for (var i = 0; i < allTex.length; i++) {
              try {
                allTex[i].destroy();
              } catch (e) {}
            }
            try {
              outTex.destroy();
            } catch (e) {}
            try {
              wbuf.destroy();
            } catch (e) {}
            try {
              ubuf.destroy();
            } catch (e) {}
          },
        },
      ];
    },
  });
})();
