/**
 * MEeLScale — resampler WebGPU lokal untuk AI Upscale MEeL: tanpa unduhan,
 * seluruh kernel dihitung GPU dalam satu render pass fullscreen-triangle.
 * Kontrak registerModel() → docs/id/development.md ("Video — AI Upscale &
 * Play Recovery").
 *
 * Kernel separable; tap diperluas saat downscale (scale dibatasi 8×, maks 64
 * tap/sumbu), tepi di-clamp lalu hasil dinormalisasi.
 */
(function () {
  "use strict";
  if (!window.MEEL_UPSCALER) return;

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

  var WGSL_HEAD = [
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
    "@group(0) @binding(0) var src : texture_2d<f32>;",
  ].join("\n");

  function shaderFor(k, targetW, targetH) {
    return [
      WGSL_HEAD,
      k.body,
      "const SUPPORT : f32 = " + k.support.toFixed(1) + ";",
      "const OUT_W : f32 = " + Number(targetW).toFixed(1) + ";",
      "const OUT_H : f32 = " + Number(targetH).toFixed(1) + ";",
      "@fragment",
      "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
      "  let dims = textureDimensions(src);",
      "  let scale = vec2<f32>(f32(dims.x) / OUT_W, f32(dims.y) / OUT_H);",
      "  let srcx = (input.pos.x + 0.5) * scale.x - 0.5;",
      "  let srcy = (input.pos.y + 0.5) * scale.y - 0.5;",
      "  let rx = clamp(scale.x, 1.0, 8.0);",
      "  let ry = clamp(scale.y, 1.0, 8.0);",
      "  var x0 = i32(floor(srcx - SUPPORT * rx));",
      "  var x1 = i32(ceil(srcx + SUPPORT * rx));",
      "  var y0 = i32(floor(srcy - SUPPORT * ry));",
      "  var y1 = i32(ceil(srcy + SUPPORT * ry));",
      "  if (x1 - x0 > 63) { let cx = i32(floor(srcx)); x0 = cx - 31; x1 = cx + 31; }",
      "  if (y1 - y0 > 63) { let cy = i32(floor(srcy)); y0 = cy - 31; y1 = cy + 31; }",
      "  var acc = vec3<f32>(0.0);",
      "  var wsum = 0.0;",
      "  for (var j = y0; j <= y1; j = j + 1) {",
      "    let jy = clamp(j, 0, i32(dims.y) - 1);",
      "    let wy = kern((f32(j) - srcy) / ry);",
      "    if (wy == 0.0) { continue; }",
      "    for (var i = x0; i <= x1; i = i + 1) {",
      "      let ix = clamp(i, 0, i32(dims.x) - 1);",
      "      let wx = kern((f32(i) - srcx) / rx);",
      "      if (wx == 0.0) { continue; }",
      "      let w = wx * wy;",
      "      acc += textureLoad(src, vec2<i32>(ix, jy), 0).rgb * w;",
      "      wsum += w;",
      "    }",
      "  }",
      "  if (wsum <= 0.0) {",
      "    let q = textureLoad(src, vec2<i32>(clamp(i32(floor(srcx)), 0, i32(dims.x) - 1), clamp(i32(floor(srcy)), 0, i32(dims.y) - 1)), 0);",
      "    return vec4<f32>(q.rgb, 1.0);",
      "  }",
      "  return vec4<f32>(acc / wsum, 1.0);",
      "}",
    ].join("\n");
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
      var code = shaderFor(k, o.target.width, o.target.height);
      var outTex = device.createTexture({
        label: "meel-meelscale-out",
        size: [o.target.width, o.target.height, 1],
        format: "rgba16float",
        usage: GPUTextureUsage.RENDER_ATTACHMENT | GPUTextureUsage.TEXTURE_BINDING,
      });
      var module = device.createShaderModule({
        label: "meel-meelscale-" + modeId,
        code: code,
      });
      var bgl = device.createBindGroupLayout({
        label: "meel-meelscale-bgl",
        entries: [
          {
            binding: 0,
            visibility: GPUShaderStage.FRAGMENT,
            texture: { sampleType: "float" },
          },
        ],
      });
      var pipeline = device.createRenderPipeline({
        label: "meel-meelscale-pipe-" + modeId,
        layout: device.createPipelineLayout({ bindGroupLayouts: [bgl] }),
        vertex: { module: module, entryPoint: "vs_main" },
        fragment: {
          module: module,
          entryPoint: "fs_main",
          targets: [{ format: "rgba16float" }],
        },
        primitive: { topology: "triangle-list" },
      });
      var bind = device.createBindGroup({
        label: "meel-meelscale-bind",
        layout: bgl,
        entries: [{ binding: 0, resource: o.inputTexture.createView() }],
      });
      return [
        {
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
        },
      ];
    },
  });
})();
