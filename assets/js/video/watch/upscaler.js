/**
 * MEeL Video — AI Upscale (WebGPU): video → copyExternalImageToTexture →
 * rantai pipeline model → blit → <canvas>. Registry model pluggable lewat
 * MEEL_UPSCALER.registerModel(); kontrak API-nya di docs/id/development.md
 * ("Video — AI Upscale & Play Recovery").
 */
(function () {
  "use strict";

  var hasGPU = !!(navigator.gpu);
  var hasRVFC = !!HTMLVideoElement.prototype.requestVideoFrameCallback;
  var supported = hasGPU;
  var supportChecked = false;
  var supportChecking = false;
  var supportPromise = null;
  var lastSupportCheck = 0;

  var K = window.MEEL_KEYS;
  function lsGet(key, def) {
    try {
      var v = localStorage.getItem(key);
      return v === null ? def : v;
    } catch (e) {
      return def;
    }
  }
  function lsSet(key, val) {
    try {
      localStorage.setItem(key, val);
    } catch (e) {}
  }
  var KEY_ENABLED = K.UPSCALE_ENABLED;
  var KEY_MODEL = K.UPSCALE_MODEL;
  var KEY_MODE = K.UPSCALE_MODE;
  var KEY_SCALE = K.UPSCALE_SCALE;

  var SCALES = [
    { id: "auto", label: "Auto (ikuti layar)", short: "Auto" },
    { id: "1.5", label: "1.5× resolusi video", short: "1.5×" },
    { id: "2", label: "2× resolusi video", short: "2×" },
  ];

  var state = {
    enabled: lsGet(KEY_ENABLED, "false") === "true",
    modelId: lsGet(KEY_MODEL, "anime4k"),
    modeId: lsGet(KEY_MODE, "a"),
    scaleId: lsGet(KEY_SCALE, "auto"),
    loading: false,
  };

  // Migrasi pilihan model lama: id "fsrcnn" sudah diganti "meelvision".
  if (state.modelId === "fsrcnn") {
    state.modelId = "meelvision";
    lsSet(KEY_MODEL, "meelvision");
  }

  var models = {};
  function registerModel(model) {
    if (!model || !model.id || !model.modes || !model.modes.length) return;
    models[model.id] = model;
    if (attachedPlayer && plyrReady()) buildPanels();
  }
  function getModel(id) {
    return models[id] || null;
  }
  function validateState() {
    if (!models[state.modelId]) state.modelId = "anime4k";
    var m = models[state.modelId] || Object.keys(models).map(function (k) { return models[k]; })[0];
    if (!m) return;
    var ok = m.modes.some(function (x) { return x.id === state.modeId; });
    if (!ok) state.modeId = m.modes[0].id;
    if (!SCALES.some(function (x) { return x.id === state.scaleId; })) state.scaleId = "auto";
  }

  function loadScript(url) {
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

  var MODELS_BASE = (function () {
    var src = (document.currentScript && document.currentScript.src) || "";
    var base = src.replace(/video\/watch\/[^\/]*$/, "");
    if (!base) base = "../assets/js/";
    return base.replace(/assets\/js\/$/, "assets/models/");
  })();
  var MODEL_QS = (function () {
    var src = (document.currentScript && document.currentScript.src) || "";
    var m = src.match(/[?&]v=([^&]+)/);
    return m ? "?v=" + encodeURIComponent(m[1]) : "";
  })();

  var ANIME4K_LIB_URL = MODELS_BASE + "anime4k/model.js?v=1.0.0";

  // File model diinjeksi sekali dan wajib mendaftarkan buildChain.
  function modelFileLoader(id, relPath) {
    return function () {
      return loadScript(MODELS_BASE + relPath + MODEL_QS).then(function () {
        var m = models[id];
        if (!m || !m.buildChain) {
          throw new Error("File model " + id + " tidak mendaftarkan buildChain");
        }
        // Muat aset berat milik impl (mis. bobot MEeLVision) sekali jalan.
        return loadModel(m);
      });
    };
  }

  registerModel({
    id: "anime4k",
    label: "Anime4K",
    short: "Anime4K",
    modes: [
      { id: "a", label: "Mode A — Restore → Upscale", short: "A" },
      { id: "b", label: "Mode B — Restore Soft → Upscale", short: "B" },
      { id: "c", label: "Mode C — Denoise Upscale", short: "C" },
      { id: "aa", label: "Mode AA — Restore → Upscale → Restore", short: "AA" },
      { id: "bb", label: "Mode BB — Restore Soft → Upscale → Restore Soft", short: "BB" },
      { id: "ca", label: "Mode CA — Upscale Denoise → Restore", short: "CA" },
    ],
    load: function () {
      return loadScript(ANIME4K_LIB_URL).then(function () {
        var lib = window["anime4k-webgpu"];
        if (!lib || !lib.ModeA) throw new Error("Bundle Anime4K tidak valid");
        return lib;
      });
    },
    buildChain: function (o) {
      var lib = window["anime4k-webgpu"];
      var map = {
        a: lib.ModeA,
        b: lib.ModeB,
        c: lib.ModeC,
        aa: lib.ModeAA,
        bb: lib.ModeBB,
        ca: lib.ModeCA,
      };
      var Ctor = map[o.modeId] || lib.ModeA;
      return [
        new Ctor({
          device: o.device,
          inputTexture: o.inputTexture,
          nativeDimensions: o.native,
          targetDimensions: o.target,
        }),
      ];
    },
  });

  // Deskriptor statis agar model tampil di UI sebelum file-nya dimuat; saat
  // file diinjeksi, registerModel() dari file itu menimpa entri ini.
  registerModel({
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
    load: modelFileLoader("meelscale", "meelscale/model.js"),
  });
  registerModel({
    id: "meelvision",
    label: "MEeLVision",
    short: "MEeLVision",
    modes: [{ id: "x2", label: "FSRCNN ×2", short: "×2" }],
    load: modelFileLoader("meelvision", "meelvision/model.js"),
  });

  function loadModel(model) {
    if (!model.load) {
      model._loaded = true;
      return Promise.resolve();
    }
    if (model._loaded) return Promise.resolve();
    if (model._loading) return model._loading;
    state.loading = true;
    updateUI();
    model._loading = Promise.resolve()
      .then(function () {
        return model.load();
      })
      .then(
        function () {
          model._loaded = true;
          model._loading = null;
          state.loading = false;
          updateUI();
        },
        function (err) {
          model._loading = null;
          state.loading = false;
          updateUI();
          throw err;
        },
      );
    return model._loading;
  }

  var plyr = null;
  var attachedPlayer = null;

  function getVideo() {
    return document.getElementById("main-video");
  }
  function getContainer() {
    if (plyr && plyr.elements && plyr.elements.container && plyr.elements.container.isConnected)
      return plyr.elements.container;
    var v = getVideo();
    return v ? v.parentElement : null;
  }
  function plyrReady() {
    return !!(
      plyr &&
      plyr.elements &&
      plyr.elements.settings &&
      plyr.elements.settings.panels &&
      plyr.elements.settings.panels.home &&
      plyr.elements.settings.panels.upscale &&
      plyr.elements.settings.buttons &&
      plyr.elements.settings.buttons.upscale
    );
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function once(el, evt) {
    return new Promise(function (resolve) {
      el.addEventListener(evt, resolve, { once: true });
    });
  }
  function errText(err) {
    if (!err) return "error tidak diketahui";
    var msg = err.message || "";
    if (!msg || /^\[object \w+\]$/.test(msg)) msg = err.name || "";
    if (!msg) msg = String(err);
    return msg;
  }
  function toast(text, ms) {
    var c = getContainer();
    if (!c) return;
    var old = c.querySelector(".meel-toggle-toast");
    if (old) old.remove();
    var d = document.createElement("div");
    d.className = "meel-toggle-toast";
    d.innerHTML = "<span>" + esc(text) + "</span>";
    c.appendChild(d);
    setTimeout(function () {
      d.remove();
    }, ms || 1900);
  }
  var CHECK_ON =
    'On <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:inline-block;vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>';
  var CHECK_OFF =
    'Off <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:none;vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>';
  var SPIN_LOAD = '<span class="meel-upscale-spin" aria-label="Memuat"></span>';
  var SPIN_CHECK = '<span class="meel-upscale-spin" aria-label="Memeriksa"></span>';

  var MAX_W = 3840;
  var MAX_H = 2160;
  var engine = {
    canvas: null,
    ctx: null,
    device: null,
    inputTex: null,
    chain: null,
    native: null,
    target: null,
    blit: null,
    built: false,
  };
  var loop = { pending: false, rvfc: 0, raf: 0, video: null, dirty: true };
  var rebuildTimer = null;
  var buildSeq = 0;
  var resolutionTimer = null;
  var ro = null;
  var lastBox = { w: 0, h: 0 };
  var frameErrors = 0;
  var stats = {
    renders: 0,
    copies: 0,
    completed: 0,
    gpuErrors: 0,
    lastError: null,
    skipped: 0,
    rebuilds: 0,
    rebuildReason: null,
  };
  var gpuWatchdog = null;

  // Backpressure: hanya satu submit GPU in-flight (loopBp.busy); frame yang
  // dilewati ditandai pendingRender dan dirender ulang saat GPU selesai.
  // Metrik frame-nya dibaca lewat diagnose().perf (lihat docs/id/development.md).
  var loopBp = { busy: false, pendingRender: false, timer: null, token: 0, timeouts: 0, at: 0 };
  var perf = { gpu: [], ticks: [], renders: [], lastTick: 0 };
  var PERF_CAP = 120;

  function perfPush(arr, v) {
    arr.push(v);
    if (arr.length > PERF_CAP) arr.shift();
  }
  function perfAvg(a) {
    if (!a.length) return 0;
    var s = 0;
    for (var i = 0; i < a.length; i++) s += a[i];
    return s / a.length;
  }
  function perfP95(a) {
    if (!a.length) return 0;
    var b = a.slice().sort(function (x, y) { return x - y; });
    return b[Math.min(b.length - 1, Math.ceil(b.length * 0.95) - 1)];
  }
  function computePerf() {
    var videoFps = perf.ticks.length > 1 ? 1000 / perfAvg(perf.ticks) : 0;
    var renderFps = 0;
    if (perf.renders.length > 1) {
      var d = [];
      for (var i = 1; i < perf.renders.length; i++) d.push(perf.renders[i] - perf.renders[i - 1]);
      renderFps = 1000 / perfAvg(d);
    }
    var msAvg = perfAvg(perf.gpu);
    var budgetMs = videoFps > 0 ? 1000 / videoFps : 0;
    return {
      msAvg: Math.round(msAvg * 10) / 10,
      msP95: Math.round(perfP95(perf.gpu) * 10) / 10,
      videoFps: Math.round(videoFps * 10) / 10,
      renderFps: Math.round(renderFps * 10) / 10,
      budgetMs: Math.round(budgetMs * 10) / 10,
      fitsBudget: budgetMs > 0 && msAvg > 0 ? msAvg <= budgetMs : null,
      samples: perf.gpu.length,
      skipped: stats.skipped,
      timeouts: loopBp.timeouts,
    };
  }

  var BLIT_WGSL = [
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
    "@group(0) @binding(0) var samp : sampler;",
    "@group(0) @binding(1) var tex : texture_2d<f32>;",
    "@fragment",
    "fn fs_main(input : VSOut) -> @location(0) vec4<f32> {",
    "  return textureSample(tex, samp, input.uv);",
    "}",
  ].join("\n");

  function displayBox() {
    var c = getContainer() || getVideo();
    return {
      w: (c && c.clientWidth) || 640,
      h: (c && c.clientHeight) || 360,
    };
  }

  function computeTarget(native) {
    var w, h;
    if (state.scaleId === "auto") {
      var box = displayBox();
      var dpr = Math.min(window.devicePixelRatio || 1, 2);
      var bw = Math.max(box.w * dpr, 1);
      var bh = Math.max(box.h * dpr, 1);
      var s = Math.min(bw / native.width, bh / native.height);
      w = Math.round(native.width * s);
      h = Math.round(native.height * s);
    } else {
      var f = state.scaleId === "1.5" ? 1.5 : 2;
      w = Math.round(native.width * f);
      h = Math.round(native.height * f);
    }
    w = Math.max(w, 16);
    h = Math.max(h, 16);
    if (w > MAX_W || h > MAX_H) {
      var k = Math.min(MAX_W / w, MAX_H / h);
      w = Math.round(w * k);
      h = Math.round(h * k);
    }
    return { width: w, height: h };
  }

  function getDevice() {
    if (engine.device) return Promise.resolve(engine.device);
    return navigator.gpu.requestAdapter().then(function (adapter) {
      if (!adapter) throw new Error("WebGPU tidak tersedia di perangkat ini");
      return adapter.requestDevice().then(function (device) {
        device.lost.then(function (info) {
          if (engine.device !== device || info.reason === "destroyed") return;
          fail(new Error("GPU device hilang"));
        });
        return device;
      });
    });
  }

  function destroyChainTextures() {
    var stages = [];
    var push = function (node) {
      if (!node) return;
      if (typeof node.destroy === "function") {
        try {
          node.destroy();
        } catch (e) {}
        return;
      }
      stages.push(node);
      if (node.pipelines && node.pipelines.length) {
        for (var j = 0; j < node.pipelines.length; j++) stages.push(node.pipelines[j]);
      }
    };
    if (engine.chain) for (var i = 0; i < engine.chain.length; i++) push(engine.chain[i]);
    for (var k = 0; k < stages.length; k++) {
      try {
        var t = stages[k].getOutputTexture && stages[k].getOutputTexture();
        if (t) t.destroy();
      } catch (e) {}
    }
    if (engine.inputTex) {
      try {
        engine.inputTex.destroy();
      } catch (e) {}
    }
  }

  function releaseChain() {
    stopLoop();
    clearTimeout(gpuWatchdog);
    if (resolutionTimer) {
      clearTimeout(resolutionTimer);
      resolutionTimer = null;
    }
    // Token membuang janji submit GPU lama, supaya render baru boleh langsung jalan.
    if (loopBp.timer) {
      clearTimeout(loopBp.timer);
      loopBp.timer = null;
    }
    loopBp.busy = false;
    loopBp.pendingRender = false;
    loopBp.timeouts = 0;
    loopBp.token++;
    destroyChainTextures();
    engine.inputTex = null;
    engine.chain = null;
    engine.blit = null;
    engine.native = null;
    engine.target = null;
    engine.built = false;
  }

  function teardownDevice() {
    releaseChain();
    if (engine.device) {
      try {
        engine.device.destroy();
      } catch (e) {}
    }
    engine.device = null;
  }

  function ensureCanvas() {
    var v = getVideo();
    var cont = getContainer();
    if (!v || !cont) throw new Error("Kontainer video tidak ditemukan");
    bindVideo(v);
    var c = document.getElementById("meel-upscale-canvas");
    if (!c) {
      c = document.createElement("canvas");
      c.id = "meel-upscale-canvas";
      c.setAttribute("aria-hidden", "true");
    }
    var vp = v.parentNode;
    if (c.parentNode !== vp || c.previousSibling !== v) vp.insertBefore(c, v.nextSibling);
    var ctx = c.getContext("webgpu");
    if (!ctx) throw new Error("Konteks WebGPU tidak didukungi canvas ini");
    engine.canvas = c;
    engine.ctx = ctx;
    return c;
  }

  function bindVideo(v) {
    if (!v || v._meelUpscaleBound) return;
    v._meelUpscaleBound = true;
    v.addEventListener("seeked", function () {
      if (state.enabled && engine.built) renderFrame(true, v);
    });
    v.addEventListener("loadstart", function () {
      // load() menggugurkan rVFC yang masih tertunda; tanpa reset ini
      // loop.pending mentok "true" dan loop render berhenti senyap.
      if (loop.rvfc && v.cancelVideoFrameCallback) {
        try {
          v.cancelVideoFrameCallback(loop.rvfc);
        } catch (e) {}
      }
      loop.rvfc = 0;
      loop.pending = false;
    });
    v.addEventListener("loadeddata", function () {
      if (state.enabled && engine.built) {
        loop.dirty = true;
        renderFrame(true, v);
        if (!loop.pending && !document.hidden) scheduleFrame();
      }
    });
    v.addEventListener("play", function () {
      if (state.enabled && engine.built && !loop.pending) scheduleFrame();
    });
  }

  function buildBlit(device, format) {
    var bgl = device.createBindGroupLayout({
      label: "meel-upscale-blit-bgl",
      entries: [
        { binding: 0, visibility: GPUShaderStage.FRAGMENT, sampler: {} },
        { binding: 1, visibility: GPUShaderStage.FRAGMENT, texture: {} },
      ],
    });
    var module = device.createShaderModule({ label: "meel-upscale-blit", code: BLIT_WGSL });
    var pipeline = device.createRenderPipeline({
      label: "meel-upscale-blit-pipeline",
      layout: device.createPipelineLayout({ bindGroupLayouts: [bgl] }),
      vertex: { module: module, entryPoint: "vs_main" },
      fragment: { module: module, entryPoint: "fs_main", targets: [{ format: format }] },
      primitive: { topology: "triangle-list" },
    });
    var bind = device.createBindGroup({
      label: "meel-upscale-blit-bind",
      layout: bgl,
      entries: [
        {
          binding: 0,
          resource: device.createSampler({ magFilter: "linear", minFilter: "linear" }),
        },
        { binding: 1, resource: engine.chain[engine.chain.length - 1].getOutputTexture().createView() },
      ],
    });
    return { pipeline: pipeline, bind: bind };
  }

  function rebuild(delay, opts) {
    clearTimeout(rebuildTimer);
    var seq = ++buildSeq;
    rebuildTimer = setTimeout(function () {
      doRebuild(seq, opts);
    }, delay || 0);
  }

  // Debounce resolusi: ganti kualitas membuat videoWidth berubah beberapa kali
  // sebelum stabil. Selama menunggu class dilepas (video asli tampil, tanpa
  // frame hitam) dan copy lintas ukuran dilewati.
  function scheduleResolutionRebuild(v) {
    if (resolutionTimer) return;
    var cont = getContainer();
    if (cont) cont.classList.remove("meel-upscale-active");
    resolutionTimer = setTimeout(function () {
      resolutionTimer = null;
      if (!state.enabled || !engine.built || !engine.native) return;
      var vv = getVideo();
      if (!vv) return;
      if (!vv.videoWidth) {
        // Metadata belum siap (baru ganti src) — tunggu lagi, jangan menyerah.
        scheduleResolutionRebuild(vv);
        return;
      }
      if (vv.videoWidth === engine.native.width && vv.videoHeight === engine.native.height) {
        // Ganti kualitas ternyata dibatalkan (ukurannya sama) — cukup lanjutkan loop.
        loop.dirty = true;
        scheduleFrame();
        return;
      }
      rebuild(0, { reason: "resolution" });
    }, 600);
  }

  function doRebuild(seq, opts) {
    if (!state.enabled) return Promise.resolve();
    var v = getVideo();
    if (!v) return Promise.resolve();
    stats.rebuilds++;
    stats.rebuildReason = (opts && opts.reason) || "manual";
    // Anti-hitam: tampilkan video asli selama rebuild — class dilepas di sini
    // dan dipasang kembali oleh renderFrame() hanya setelah frame sukses.
    var contStart = getContainer();
    if (contStart) contStart.classList.remove("meel-upscale-active");
    var waitMeta = v.videoWidth ? Promise.resolve() : once(v, "loadedmetadata");
    return waitMeta
      .then(function () {
        if (seq !== buildSeq || !state.enabled) return;
        var model = getModel(state.modelId);
        if (!model) throw new Error("Model upscale tidak dikenal: " + state.modelId);
        return loadModel(model).then(function () {
          if (seq !== buildSeq || !state.enabled) return;
          // File model baru diinjeksi dan menimpa deskriptor — ambil entri terbaru.
          model = getModel(state.modelId) || model;
          ensureCanvas();
          var native = { width: v.videoWidth, height: v.videoHeight };
          if (!native.width || !native.height) throw new Error("Resolusi video belum siap");
          var target = computeTarget(native);
          releaseChain();
          return getDevice().then(function (device) {
            if (seq !== buildSeq || !state.enabled) {
              if (engine.device !== device) {
                try {
                  device.destroy();
                } catch (e) {}
              }
              return;
            }
            engine.device = device;
            engine.native = native;
            engine.target = target;
            stats.gpuErrors = 0;
            device.onuncapturederror = function (ev) {
              stats.gpuErrors++;
              var msg = ev && ev.error && ev.error.message ? ev.error.message : "GPU error tak dikenal";
              stats.lastError = msg;
              if (stats.gpuErrors <= 3) console.warn("[MEeL][upscaler] GPU async:", msg);
              if (stats.gpuErrors >= 6 && seq === buildSeq && state.enabled) {
                fail(new Error("GPU error: " + msg));
              }
            };
            engine.inputTex = device.createTexture({
              label: "meel-upscale-input",
              size: [native.width, native.height, 1],
              format: "rgba16float",
              usage: GPUTextureUsage.TEXTURE_BINDING | GPUTextureUsage.COPY_DST | GPUTextureUsage.RENDER_ATTACHMENT,
            });
            engine.chain = model.buildChain({
              device: device,
              modeId: state.modeId,
              inputTexture: engine.inputTex,
              native: native,
              target: target,
            });
            if (!engine.chain || !engine.chain.length) throw new Error("Pipeline model kosong");
            engine.canvas.width = target.width;
            engine.canvas.height = target.height;
            engine.ctx = engine.canvas.getContext("webgpu");
            engine.ctx.configure({
              device: device,
              format: navigator.gpu.getPreferredCanvasFormat(),
              alphaMode: "premultiplied",
            });
            engine.blit = buildBlit(device, navigator.gpu.getPreferredCanvasFormat());
            engine.built = true;
            loop.dirty = true;
            frameErrors = 0;
            var cont = getContainer();
            if (cont) lastBox = { w: cont.clientWidth, h: cont.clientHeight };
            renderFrame(true);
            armGpuWatchdog(seq);
            scheduleFrame();
            updateUI();
            if (opts && opts.announce) toast("AI Upscale On");
          });
        });
      })
      .catch(function (err) {
        if (seq === buildSeq) fail(err);
      });
  }

  function armGpuWatchdog(seq) {
    clearTimeout(gpuWatchdog);
    var dev = engine.device;
    if (!dev || !dev.queue || typeof dev.queue.onSubmittedWorkDone !== "function") return;
    var done = false;
    var p = dev.queue.onSubmittedWorkDone();
    if (p && p.then) {
      p.then(
        function () {
          if (done) return;
          done = true;
          clearTimeout(gpuWatchdog);
          stats.completed++;
        },
        function (err) {
          if (done) return;
          done = true;
          clearTimeout(gpuWatchdog);
          stats.lastError = errText(err);
        },
      );
    }
    gpuWatchdog = setTimeout(function () {
      if (done) return;
      done = true;
      if (seq !== buildSeq || !state.enabled) return;
      fail(new Error("GPU tidak merespons render (queue macet)"));
    }, 6000);
  }

  // GPU selesai (atau timeout): bebaskan backpressure lalu render tangkapan
  // bila ada frame yang tadi di-skip. Token menolak promise yang datang terlambat.
  function setGpuSettled(tok, note) {
    if (tok !== loopBp.token || !loopBp.busy) return;
    loopBp.busy = false;
    loopBp.pendingRender = loopBp.pendingRender && !note;
    if (loopBp.timer) {
      clearTimeout(loopBp.timer);
      loopBp.timer = null;
    }
    if (note) {
      loopBp.timeouts++;
      stats.lastError = note;
      console.warn("[MEeL][upscaler] " + note);
      if (loopBp.timeouts >= 3 && state.enabled && engine.built) {
        fail(new Error(note));
        return;
      }
    } else {
      loopBp.timeouts = 0;
    }
    if (loopBp.pendingRender && state.enabled && engine.built && !document.hidden) {
      loopBp.pendingRender = false;
      renderFrame(false);
    }
    if (!loop.pending && state.enabled && engine.built) scheduleFrame();
  }

  function markGpuBusy() {
    loopBp.at = performance.now();
    perfPush(perf.renders, loopBp.at);
    // Tanpa API sinyal GPU, jalani tanpa backpressure — jangan sampai jadi frame error.
    var q = engine.device.queue;
    if (typeof q.onSubmittedWorkDone !== "function") return;
    var p = q.onSubmittedWorkDone();
    if (!p || !p.then) return;
    loopBp.busy = true;
    loopBp.pendingRender = false;
    loopBp.token++;
    var tok = loopBp.token;
    p.then(
      function () {
        if (tok !== loopBp.token) return;
        perfPush(perf.gpu, performance.now() - loopBp.at);
        setGpuSettled(tok, null);
      },
      function (err) {
        if (tok !== loopBp.token) return;
        perfPush(perf.gpu, performance.now() - loopBp.at);
        setGpuSettled(tok, "GPU queue error: " + errText(err));
      },
    );
    if (loopBp.timer) clearTimeout(loopBp.timer);
    loopBp.timer = setTimeout(function () {
      loopBp.timer = null;
      setGpuSettled(tok, "GPU tidak merespons render (timeout 3 dtk)");
    }, 3000);
  }

  function renderFrame(force, videoOverride) {
    if (!engine.built || !engine.device) return;
    var v = videoOverride || getVideo();
    if (!v || v.readyState < 2 || !v.videoWidth) return;
    if (v.videoWidth !== engine.native.width || v.videoHeight !== engine.native.height) {
      // Ukuran beda dari pipeline: tunggu rebuild, jangan copy lintas ukuran (picu error antrian GPU).
      stats.skipped++;
      return;
    }
    if (loopBp.busy) {
      // Backpressure: jangan tumpuk antrian (antrian menumpuk = freeze) —
      // tandai perlu render, jalankan setelah onSubmittedWorkDone() selesai.
      loopBp.pendingRender = true;
      stats.skipped++;
      return;
    }
    var needCopy = force || hasRVFC || !v.paused || loop.dirty;
    try {
      if (needCopy) {
        engine.device.queue.copyExternalImageToTexture(
          { source: v },
          { texture: engine.inputTex },
          [engine.native.width, engine.native.height],
        );
        stats.copies++;
      }
      var enc = engine.device.createCommandEncoder();
      for (var i = 0; i < engine.chain.length; i++) engine.chain[i].pass(enc);
      var rp = enc.beginRenderPass({
        colorAttachments: [
          {
            view: engine.ctx.getCurrentTexture().createView(),
            clearValue: { r: 0, g: 0, b: 0, a: 1 },
            loadOp: "clear",
            storeOp: "store",
          },
        ],
      });
      rp.setPipeline(engine.blit.pipeline);
      rp.setBindGroup(0, engine.blit.bind);
      rp.draw(6);
      rp.end();
      engine.device.queue.submit([enc.finish()]);
      frameErrors = 0;
      stats.renders++;
      markGpuBusy();
      if (state.enabled && !document.hidden) {
        var actCont = getContainer();
        if (actCont && !actCont.classList.contains("meel-upscale-active"))
          actCont.classList.add("meel-upscale-active");
      }
    } catch (err) {
      frameErrors++;
      stats.lastError = errText(err);
      if (frameErrors <= 3) console.warn("[MEeL][upscaler] frame error:", err);
      if (frameErrors >= 60) fail(new Error("frame error: " + stats.lastError));
    }
  }

  function scheduleFrame() {
    if (!state.enabled || !engine.built || loop.pending || document.hidden) return;
    var v = getVideo();
    if (!v) return;
    loop.pending = true;
    loop.video = v;
    if (hasRVFC) {
      loop.rvfc = v.requestVideoFrameCallback(function () {
        loop.pending = false;
        loop.rvfc = 0;
        onFrameTick();
      });
    } else {
      loop.raf = requestAnimationFrame(function () {
        loop.pending = false;
        loop.raf = 0;
        onFrameTick();
      });
    }
  }

  function onFrameTick() {
    if (!state.enabled) return;
    var tnow = performance.now();
    if (perf.lastTick > 0) {
      var dt = tnow - perf.lastTick;
      // Hanya jeda wajar (≥4 fps); jeda pause/hidden/seek tidak dihitung.
      if (dt > 0 && dt <= 250) perfPush(perf.ticks, dt);
    }
    perf.lastTick = tnow;
    var v = loop.video;
    if (!v || !v.isConnected) {
      stopLoop();
      return;
    }
    if (
      engine.built &&
      v.videoWidth &&
      (v.videoWidth !== engine.native.width || v.videoHeight !== engine.native.height)
    ) {
      scheduleResolutionRebuild(v);
      return;
    }
    renderFrame(false, v);
    if (hasRVFC || !v.paused) {
      scheduleFrame();
    } else {
      loop.pending = false;
      loop.video = null;
    }
  }

  function stopLoop() {
    if (loop.rvfc && loop.video && loop.video.cancelVideoFrameCallback) {
      try {
        loop.video.cancelVideoFrameCallback(loop.rvfc);
      } catch (e) {}
    }
    if (loop.raf) cancelAnimationFrame(loop.raf);
    loop.rvfc = 0;
    loop.raf = 0;
    loop.pending = false;
    loop.video = null;
    loopBp.pendingRender = false;
    perf.lastTick = 0;
  }

  function fail(err) {
    console.warn("[MEeL][upscaler]", err);
    stats.lastError = errText(err);
    teardownDevice();
    state.enabled = false;
    lsSet(KEY_ENABLED, "false");
    var cont = getContainer();
    if (cont) cont.classList.remove("meel-upscale-active");
    toast("Upscale gagal: " + stats.lastError, 5000);
    updateUI();
  }

  function setEnabled(on, opts) {
    if (on === state.enabled) return;
    if (on) {
      if (!supported) {
        if (supportChecking || supportPromise) {
          toast("Memeriksa WebGPU…");
          checkSupport(true).then(function (ok) {
            if (ok && !state.enabled) setEnabled(true, opts);
          });
          return;
        }
        checkSupport(true).then(function (ok) {
          if (ok) {
            if (!state.enabled) setEnabled(true, opts);
          } else {
            toast(supportLabel() === "Butuh HTTPS" ? "WebGPU butuh halaman HTTPS" : "WebGPU tidak didukung di browser ini", 4000);
          }
        });
        return;
      }
      state.enabled = true;
      lsSet(KEY_ENABLED, "true");
      updateUI();
      rebuild(0, { reason: "enable", announce: !!(opts && opts.announce) });
    } else {
      state.enabled = false;
      lsSet(KEY_ENABLED, "false");
      teardownDevice();
      var cont = getContainer();
      if (cont) cont.classList.remove("meel-upscale-active");
      updateUI();
      if (!(opts && opts.silent)) toast("AI Upscale Off");
    }
  }

  function showPanel(name) {
    if (!plyrReady()) return;
    var inner = plyr.elements.settings.panels.home.parentNode;
    var target = inner.querySelector("#plyr-settings-" + plyr.id + "-" + name);
    if (!target) return;
    inner.style.width = "";
    inner.style.height = "";
    Array.prototype.forEach.call(inner.children, function (c) {
      c.hidden = c !== target;
    });
  }

  function radioItem(value, label, checked, onPick, disabled) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "plyr__control meel-upscale-item";
    b.setAttribute("role", "menuitemradio");
    b.setAttribute("aria-checked", checked ? "true" : "false");
    b.value = value;
    if (disabled) b.setAttribute("aria-disabled", "true");
    var s = document.createElement("span");
    s.textContent = label;
    b.appendChild(s);
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      if (state.loading) return;
      onPick(value);
    });
    return b;
  }

  function syncRadios(panelName, value) {
    if (!plyrReady()) return;
    var pane = plyr.elements.settings.panels[panelName];
    if (!pane) return;
    var items = pane.querySelectorAll('[role="menuitemradio"]');
    Array.prototype.forEach.call(items, function (it) {
      it.setAttribute("aria-checked", it.value === value ? "true" : "false");
    });
  }

  function buildForwardRow(key, label) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "plyr__control plyr__control--forward meel-upscale-item";
    b.setAttribute("role", "menuitem");
    b.setAttribute("aria-haspopup", "true");
    b.dataset.upscaleRow = key;
    var flex = document.createElement("span");
    flex.appendChild(document.createTextNode(label));
    var val = document.createElement("span");
    val.className = "plyr__menu__value";
    flex.appendChild(val);
    b.appendChild(flex);
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      showPanel("upscale-" + key);
    });
    return b;
  }

  function buildToggleRow() {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "plyr__control meel-upscale-item";
    b.setAttribute("role", "menuitemcheckbox");
    b.id = "plyr-setting-upscale";
    var flex = document.createElement("span");
    flex.appendChild(document.createTextNode("AI Upscale"));
    var val = document.createElement("span");
    val.className = "plyr__menu__value";
    flex.appendChild(val);
    b.appendChild(flex);
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      if (state.loading) return;
      setEnabled(b.getAttribute("aria-checked") !== "true", { announce: true });
      updateUI();
    });
    return b;
  }

  function buildSubPanel(name, listBuilder, opts) {
    opts = opts || {};
    var backTo = opts.backTo || "upscale";
    var backLabel = opts.backLabel || "AI Upscale";
    var inner = plyr.elements.settings.panels.home.parentNode;
    var id = "plyr-settings-" + plyr.id + "-" + name;
    var old = document.getElementById(id);
    if (old) old.remove();
    var pane = document.createElement("div");
    pane.id = id;
    pane.hidden = true;

    var back = document.createElement("button");
    back.type = "button";
    back.className = "plyr__control plyr__control--back";
    back.innerHTML =
      '<span aria-hidden="true">' +
      esc(backLabel) +
      '</span><span class="plyr__sr-only">Kembali</span>';
    back.addEventListener("click", function (e) {
      e.stopPropagation();
      showPanel(backTo);
    });
    pane.appendChild(back);

    var menu = document.createElement("div");
    menu.setAttribute("role", opts.role || "menu");
    pane.appendChild(menu);

    pane.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        e.stopPropagation();
        showPanel(backTo);
      }
    });

    inner.appendChild(pane);
    plyr.elements.settings.panels[name] = pane;
    listBuilder(menu);
  }

  function buildModelList(menu) {
    menu.replaceChildren();
    Object.keys(models).forEach(function (id) {
      var m = models[id];
      menu.appendChild(
        radioItem(id, m.label, state.modelId === id, function () {
          selectModel(id);
        }),
      );
    });
  }

  function buildModeList(menu) {
    menu.replaceChildren();
    var m = getModel(state.modelId);
    if (!m) return;
    m.modes.forEach(function (mode) {
      menu.appendChild(
        radioItem(mode.id, mode.label, state.modeId === mode.id, function () {
          selectMode(mode.id);
        }),
      );
    });
  }

  function buildScaleList(menu) {
    menu.replaceChildren();
    SCALES.forEach(function (sc) {
      menu.appendChild(
        radioItem(sc.id, sc.label, state.scaleId === sc.id, function () {
          selectScale(sc.id);
        }),
      );
    });
  }

  // Panel read-only saat dukungan absen: alasan spesifik + persyaratan +
  // cek ulang tanpa reload. Baris menu disabled mengarah ke sini.
  function buildWhyPanel(menu) {
    menu.replaceChildren();

    var box = document.createElement("div");
    box.className = "meel-upscale-why";

    var title = document.createElement("p");
    title.className = "meel-upscale-why-title";
    title.textContent = "AI Upscale tidak tersedia";

    var body = document.createElement("p");
    body.className = "meel-upscale-why-body";
    body.textContent = whyBodyText();

    var req = document.createElement("ul");
    req.className = "meel-upscale-why-req";
    [
      "Browser dengan WebGPU: Chrome/Edge 113+ desktop, Firefox 141+, atau Safari 18+",
      "Halaman dibuka lewat HTTPS atau http://localhost",
      "GPU/driver mengizinkan WebGPU (flag browser tidak mematikannya)",
    ].forEach(function (t) {
      var li = document.createElement("li");
      li.textContent = t;
      req.appendChild(li);
    });

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "plyr__control meel-upscale-item";
    btn.innerHTML = "<span>Cek ulang dukungan</span>";
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      if (supportChecking) return;
      btn.setAttribute("aria-disabled", "true");
      btn.querySelector("span").textContent = "Memeriksa…";
      checkSupport(true).then(function (ok) {
        btn.removeAttribute("aria-disabled");
        btn.querySelector("span").textContent = "Cek ulang dukungan";
        updateUI();
        if (ok) {
          toast("WebGPU tersedia — AI Upscale bisa diaktifkan", 3000);
          showPanel("upscale");
        }
      });
    });

    box.appendChild(title);
    box.appendChild(body);
    box.appendChild(req);
    box.appendChild(btn);
    menu.appendChild(box);
  }

  function buildPanels() {
    if (!plyrReady()) return;
    var S = plyr.elements.settings;
    S.buttons.upscale.hidden = true;

    var mainMenu = S.panels.upscale.querySelector('[role="menu"]');
    if (!mainMenu) return;
    mainMenu.replaceChildren();
    mainMenu.appendChild(buildToggleRow());
    mainMenu.appendChild(buildForwardRow("model", "Model"));
    mainMenu.appendChild(buildForwardRow("mode", "Mode"));
    mainMenu.appendChild(buildForwardRow("scale", "Skala"));

    buildSubPanel("upscale-model", buildModelList);
    buildSubPanel("upscale-mode", buildModeList);
    buildSubPanel("upscale-scale", buildScaleList);
    buildSubPanel("upscale-why", buildWhyPanel, {
      backTo: "home",
      backLabel: "Settings",
      role: "group",
    });
  }

  function currentModel() {
    return getModel(state.modelId) || { label: "-", short: "-" };
  }
  function currentMode() {
    var m = getModel(state.modelId);
    if (!m) return { short: "-" };
    return (
      m.modes.filter(function (x) {
        return x.id === state.modeId;
      })[0] || m.modes[0]
    );
  }
  function currentScale() {
    return (
      SCALES.filter(function (x) {
        return x.id === state.scaleId;
      })[0] || SCALES[0]
    );
  }

  function setRowValue(key, text) {
    if (!plyrReady()) return;
    var row = plyr.elements.settings.panels.upscale.querySelector(
      '[data-upscale-row="' + key + '"] .plyr__menu__value',
    );
    if (row) row.textContent = text;
  }

  function setRowDisabled(key, disabled) {
    if (!plyrReady()) return;
    var row = plyr.elements.settings.panels.upscale.querySelector('[data-upscale-row="' + key + '"]');
    if (!row) return;
    if (disabled) row.setAttribute("aria-disabled", "true");
    else row.removeAttribute("aria-disabled");
  }

  function homeValueHtml() {
    if (supportChecking) return SPIN_CHECK;
    if (!supported) return "Tidak tersedia";
    if (state.loading) return SPIN_LOAD;
    if (!state.enabled) return "Mati";
    return esc(currentModel().short) + " · " + esc(currentMode().short) + " · " + esc(currentScale().short);
  }

  function setHomeRowValue() {
    var row = document.getElementById("plyr-setting-upsale");
    if (!row) return;
    var v = row.querySelector(".plyr__menu__value");
    if (v) v.innerHTML = homeValueHtml();
    applyHomeRowState(row);
  }

  function buildHomeRow() {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "plyr__control plyr__control--forward";
    b.setAttribute("role", "menuitem");
    b.setAttribute("aria-haspopup", "true");
    b.id = "plyr-setting-upsale";
    var flex = document.createElement("span");
    flex.appendChild(document.createTextNode("AI Upscale"));
    var val = document.createElement("span");
    val.className = "plyr__menu__value";
    val.innerHTML = homeValueHtml();
    flex.appendChild(val);
    b.appendChild(flex);
    applyHomeRowState(b);
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      if (unsupportedReason()) {
        showPanel("upscale-why");
        refreshWhyText();
        // Segarkan tanpa paksa (throttle 30 dtk): bila dukungan baru muncul
        // setelah user menyalakan flag GPU, panel langsung berganti.
        checkSupport(false).then(function (ok) {
          if (!ok || !plyrReady()) return;
          var cur = plyr.elements.settings.panels["upscale-why"];
          if (cur && !cur.hidden) showPanel("upscale");
        });
        return;
      }
      showPanel("upscale");
      if (!supported || supportChecking) checkSupport(true);
    });
    return b;
  }

  function updateUI() {
    if (!plyrReady()) return;
    var S = plyr.elements.settings;
    var avail = supported && !supportChecking;

    var tog = S.panels.upscale.querySelector("#plyr-setting-upscale");
    if (tog) {
      var on = state.enabled;
      tog.setAttribute("aria-checked", on ? "true" : "false");
      tog.setAttribute("aria-disabled", state.loading || !avail ? "true" : "false");
      var tv = tog.querySelector(".plyr__menu__value");
      if (tv) {
        if (supportChecking) tv.innerHTML = SPIN_CHECK;
        else if (!supported) tv.innerHTML = esc(supportLabel());
        else tv.innerHTML = state.loading ? "" : on ? CHECK_ON : CHECK_OFF;
      }
    }

    setRowValue("model", currentModel().label);
    setRowValue("mode", currentMode().short);
    setRowValue("scale", currentScale().short);
    setRowDisabled("model", !avail);
    setRowDisabled("mode", !avail);
    setRowDisabled("scale", !avail);

    setHomeRowValue();

    syncRadios("upscale-model", state.modelId);
    syncRadios("upscale-mode", state.modeId);
    syncRadios("upscale-scale", state.scaleId);

    refreshWhyText();
  }

  function selectModel(id) {
    if (state.loading || state.modelId === id) {
      if (!state.loading) showPanel("upscale");
      return;
    }
    var prev = state.modelId;
    var prevMode = state.modeId;
    state.modelId = id;
    var m = getModel(id);
    if (!m.modes.some(function (x) { return x.id === state.modeId; })) state.modeId = m.modes[0].id;
    lsSet(KEY_MODEL, state.modelId);
    lsSet(KEY_MODE, state.modeId);
    buildModeList(plyr.elements.settings.panels["upscale-mode"].querySelector('[role="menu"]'));
    updateUI();
    if (state.enabled) {
      loadModel(m).then(
        function () {
          rebuild(0, { reason: "model" });
          showPanel("upscale");
        },
        function (err) {
          state.modelId = prev;
          state.modeId = prevMode;
          lsSet(KEY_MODEL, prev);
          lsSet(KEY_MODE, prevMode);
          fail(err);
        },
      );
    } else {
      showPanel("upscale");
    }
  }

  function selectMode(id) {
    state.modeId = id;
    lsSet(KEY_MODE, id);
    updateUI();
    if (state.enabled) rebuild(0, { reason: "mode" });
    showPanel("upscale");
  }

  function selectScale(id) {
    state.scaleId = id;
    lsSet(KEY_SCALE, id);
    updateUI();
    if (state.enabled) rebuild(0, { reason: "scale" });
    showPanel("upscale");
  }

  // Satu sumber kebenaran untuk alasan "kenapa opsi ini tidak tersedia" —
  // dipakai panel alasan di menu dan diagnose(). Repair path: navigator.gpu
  // hilang/HTTP non-localhost sudah pasti final; adapter null baru diketahui
  // setelah checkSupport() selesai.
  var WHY = {
    insecure:
      "Halaman tidak aman (HTTP non-localhost) — WebGPU hanya tersedia di HTTPS atau http://localhost.",
    nogpu:
      "Browser ini tidak mendukung WebGPU (navigator.gpu tidak ada) — butuh Chrome/Edge 113+ desktop, Firefox 141+, atau Safari 18+.",
    adapter:
      "WebGPU terdeteksi tapi GPU tidak tersedia — GPU/driver diblokir, GPU sedang blank, atau flag WebGPU dinonaktifkan.",
  };

  function unsupportedReason() {
    if (supported || supportChecking) return null;
    if (!window.isSecureContext) return { code: "insecure", body: WHY.insecure };
    if (!navigator.gpu) return { code: "nogpu", body: WHY.nogpu };
    return { code: "no-adapter", body: WHY.adapter };
  }

  function whyBodyText() {
    if (supportChecking) return "Memeriksa dukungan WebGPU…";
    var r = unsupportedReason();
    return r ? r.body : "Dukungan WebGPU terdeteksi — opsi AI Upscale bisa diaktifkan.";
  }

  function supportLabel() {
    var r = unsupportedReason();
    if (!r) return "Tidak didukung";
    if (r.code === "insecure") return "Butuh HTTPS";
    if (r.code === "nogpu") return "Butuh browser WebGPU";
    return "Tidak didukung";
  }

  function refreshWhyText() {
    if (!plyrReady()) return;
    var pane = plyr.elements.settings.panels["upscale-why"];
    if (!pane) return;
    var body = pane.querySelector(".meel-upscale-why-body");
    if (body) body.textContent = whyBodyText();
  }

  function applyHomeRowState(row) {
    if (!row) return;
    if (unsupportedReason()) {
      row.setAttribute("aria-disabled", "true");
      row.classList.add("meel-upscale-unavail");
    } else {
      row.removeAttribute("aria-disabled");
      row.classList.remove("meel-upscale-unavail");
    }
  }

  function diagnose() {
    var d = {
      secureContext: !!window.isSecureContext,
      hasNavigatorGPU: !!navigator.gpu,
      userAgent: navigator.userAgent,
      supported: supported,
      supportChecked: supportChecked,
      adapter: "belum-dicek",
      adapterInfo: null,
      reason: "",
      perf: computePerf(),
    };
    if (!d.secureContext) {
      d.adapter = "dilewati";
      d.reason = WHY.insecure;
      return Promise.resolve(d);
    }
    if (!navigator.gpu) {
      d.adapter = "dilewati";
      d.reason = WHY.nogpu;
      return Promise.resolve(d);
    }
    var p;
    try {
      p = navigator.gpu.requestAdapter();
    } catch (e) {
      d.adapter = "error";
      d.reason = "requestAdapter() melempar: " + errText(e);
      return Promise.resolve(d);
    }
    return Promise.resolve(p)
      .then(
        function (a) {
          if (!a) {
            d.adapter = "null";
            d.reason = WHY.adapter;
            return d;
          }
          d.adapter = "ok";
          var info = a.info || (a.requestAdapterInfo ? null : null);
          if (info) {
            d.adapterInfo = {
              vendor: info.vendor || "",
              architecture: info.architecture || "",
              description: info.description || "",
              device: info.device || "",
            };
          }
          d.reason = "Adapter ditemukan.";
          return d;
        },
        function (e) {
          d.adapter = "error";
          d.reason = "requestAdapter() gagal: " + errText(e);
          return d;
        },
      );
  }
  function checkSupport(force) {
    if (!navigator.gpu) {
      supported = false;
      supportChecked = true;
      supportChecking = false;
      return Promise.resolve(false);
    }
    if (supportPromise) return supportPromise;
    if (supportChecked && supported && !force) return Promise.resolve(true);
    if (!force && supportChecked && Date.now() - lastSupportCheck < 30000) {
      return Promise.resolve(supported);
    }
    supportChecking = true;
    lastSupportCheck = Date.now();
    updateUI();
    var p;
    try {
      p = navigator.gpu.requestAdapter();
    } catch (e) {
      p = null;
    }
    supportPromise = Promise.resolve(p)
      .then(
        function (a) {
          return !!a;
        },
        function () {
          return false;
        },
      )
      .then(function (ok) {
        supportPromise = null;
        supportChecking = false;
        supportChecked = true;
        supported = ok;
        updateUI();
        return ok;
      });
    return supportPromise;
  }

  function observeContainer() {
    var cont = getContainer();
    if (!cont || typeof ResizeObserver === "undefined") return;
    if (ro) ro.disconnect();
    ro = new ResizeObserver(function (entries) {
      if (!state.enabled || !engine.built || state.scaleId !== "auto") return;
      var r = entries[0] ? entries[0].contentRect : null;
      if (!r) return;
      if (Math.abs(r.width - lastBox.w) < 2 && Math.abs(r.height - lastBox.h) < 2) return;
      rebuild(300, { reason: "resize" });
    });
    ro.observe(cont);
  }

  document.addEventListener("visibilitychange", function () {
    if (!state.enabled) return;
    if (document.hidden) {
      stopLoop();
    } else if (engine.built) {
      loop.dirty = true;
      renderFrame(true);
      scheduleFrame();
    }
  });

  // Watchdog loop: Chrome bisa membuang rVFC saat transisi hidden↔visible atau
  // saat load(); kalau pemicu pertama jatuh, loop.pending mentok "true" dan
  // canvas upscale membeku walau video terus bermain — daftarkan ulang dari awal.
  setInterval(function () {
    if (!state.enabled || !engine.built || document.hidden) return;
    var v = getVideo();
    if (!v || v.paused || !v.videoWidth) return;
    if (loop.raf) return; // jalur rAF (tanpa rVFC) masih berjalan
    var last = perf.lastTick || 0;
    if (last && performance.now() - last < 700) return; // loop sehat
    if (loop.rvfc && v.cancelVideoFrameCallback) {
      try {
        v.cancelVideoFrameCallback(loop.rvfc);
      } catch (e) {}
    }
    loop.rvfc = 0;
    loop.pending = false;
    loop.dirty = true;
    scheduleFrame();
  }, 500);

  function attach(plyrInstance) {
    if (!plyrInstance || !plyrInstance.elements || !plyrInstance.elements.settings) return;
    plyr = plyrInstance;
    var buttons = plyrInstance.elements.settings.buttons;
    if (!buttons || !buttons.upscale) return;
    if (attachedPlayer === plyrInstance) {
      reviveLoop();
      return;
    }
    attachedPlayer = plyrInstance;
    validateState();
    buildPanels();
    observeContainer();
    updateUI();
    checkSupport(false).then(function (ok) {
      if (attachedPlayer !== plyrInstance) return;
      if (state.enabled && ok) {
        rebuild(0, { reason: "attach" });
      } else if (state.enabled) {
        state.enabled = false;
        lsSet(KEY_ENABLED, "false");
        updateUI();
        toast("WebGPU tidak tersedia — AI Upscale nonaktif", 4000);
      }
    });
  }

  function reviveLoop() {
    if (!state.enabled || !engine.built) return;
    var rv = getVideo();
    if (
      rv &&
      rv.videoWidth &&
      engine.native &&
      (rv.videoWidth !== engine.native.width || rv.videoHeight !== engine.native.height)
    ) {
      // Resolusi belum di-rebuild: tampilkan video asli dulu, debounce jalan.
      scheduleResolutionRebuild(rv);
      return;
    }
    var cont = getContainer();
    if (cont) cont.classList.add("meel-upscale-active");
    try {
      ensureCanvas();
    } catch (e) {
      return;
    }
    observeContainer();
    updateUI();
    if (!loop.pending) {
      loop.dirty = true;
      scheduleFrame();
    }
  }

  window.MEEL_UPSCALER = {
    supported: function () {
      return supported;
    },
    unsupportedReason: unsupportedReason,
    hasRVFC: function () {
      return hasRVFC;
    },
    stats: function () {
      return {
        renders: stats.renders,
        copies: stats.copies,
        completed: stats.completed,
        gpuErrors: stats.gpuErrors,
        lastError: stats.lastError,
        skipped: stats.skipped,
        gpuBusy: loopBp.busy,
        pendingRender: loopBp.pendingRender,
        loopPending: loop.pending,
        loopRvfc: loop.rvfc,
        loopRaf: loop.raf,
        built: engine.built,
        rebuilds: stats.rebuilds,
        rebuildReason: stats.rebuildReason,
        target: engine.target ? { width: engine.target.width, height: engine.target.height } : null,
        native: engine.native ? { width: engine.native.width, height: engine.native.height } : null,
        perf: computePerf(),
      };
    },
    registerModel: registerModel,
    getModel: getModel,
    attach: attach,
    checkSupport: function () {
      return checkSupport(true);
    },
    diagnose: diagnose,
    buildHomeRow: buildHomeRow,
    refreshHomeRow: setHomeRowValue,
    enable: function () {
      setEnabled(true, { announce: true });
    },
    disable: function () {
      setEnabled(false);
    },
    state: state,
  };
})();

/* reference build: MEeL-video-upscaler */
