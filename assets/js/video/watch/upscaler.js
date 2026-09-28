/**
 * MEeL Video — AI Upscale (WebGPU)
 *
 * Overlay upscaler bergaya mpv/Anime4K di atas <video> memakai WebGPU:
 *   video → copyExternalImageToTexture → rantai pipeline model → blit → <canvas>
 *
 * Arsitudur model pluggable (registry):
 *   MEEL_UPSCALER.registerModel({
 *     id, label, short,
 *     modes: [{ id, label, short }, ...],
 *     load: function () { return Promise; },          // lazy-load berat (mis. bundle)
 *     buildChain: function ({ device, modeId, inputTexture, native, target }) {
 *       return [pipeline, ...];                       // objek dengan .pass(encoder)
 *     },
 *   });
 *
 * Model bawaan: "anime4k" (bundle vendor assets/js/compatibilitas/anime4k-webgpu.js).
 * Panel settings Plyr: home → "AI Upscale" → sub-panel Model / Mode / Skala.
 */
(function () {
  "use strict";

  /* ======================================================================
   * 1. Kapabilitas
   * ==================================================================== */
  var hasGPU = !!(navigator.gpu);
  var hasRVFC = !!HTMLVideoElement.prototype.requestVideoFrameCallback;
  var supported = hasGPU;

  /* ======================================================================
   * 2. State + persistensi
   * ==================================================================== */
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

  /* ======================================================================
   * 3. Registry model
   * ==================================================================== */
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

  var ANIME4K_LIB_URL = (function () {
    var src = (document.currentScript && document.currentScript.src) || "";
    var base = src.replace(/video\/watch\/[^\/]*$/, "");
    if (!base) base = "../assets/js/";
    return base + "compatibilitas/anime4k-webgpu.js?v=1.0.0";
  })();

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

  /* ======================================================================
   * 4. Helper DOM
   * ==================================================================== */
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
  function toast(text) {
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
    }, 1900);
  }
  var CHECK_ON =
    'On <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:inline-block;vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>';
  var CHECK_OFF =
    'Off <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" style="display:none;vertical-align:middle;margin-left:4px"><polyline points="20 6 9 17 4 12"/></svg>';

  /* ======================================================================
   * 5. Engine WebGPU
   * ==================================================================== */
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
  var ro = null;
  var lastBox = { w: 0, h: 0 };
  var frameErrors = 0;
  var stats = { renders: 0, copies: 0, lastError: null };

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
    v.addEventListener("loadeddata", function () {
      if (state.enabled && engine.built) {
        loop.dirty = true;
        renderFrame(true, v);
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

  function doRebuild(seq, opts) {
    if (!state.enabled) return Promise.resolve();
    var v = getVideo();
    if (!v) return Promise.resolve();
    var waitMeta = v.videoWidth ? Promise.resolve() : once(v, "loadedmetadata");
    return waitMeta
      .then(function () {
        if (seq !== buildSeq || !state.enabled) return;
        var model = getModel(state.modelId);
        if (!model) throw new Error("Model upscale tidak dikenal: " + state.modelId);
        return loadModel(model).then(function () {
          if (seq !== buildSeq || !state.enabled) return;
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
            if (cont) {
              lastBox = { w: cont.clientWidth, h: cont.clientHeight };
              cont.classList.add("meel-upscale-active");
            }
            renderFrame(true);
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

  function renderFrame(force, videoOverride) {
    if (!engine.built || !engine.device) return;
    var v = videoOverride || getVideo();
    if (!v || v.readyState < 2 || !v.videoWidth) return;
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
    } catch (err) {
      frameErrors++;
      stats.lastError = String(err && err.message ? err.message : err);
      if (frameErrors <= 3) console.warn("[MEeL][upscaler] frame error:", err);
      if (frameErrors >= 60) fail(err);
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
      rebuild(0);
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
  }

  function fail(err) {
    console.warn("[MEeL][upscaler]", err);
    stats.lastError = String(err && err.message ? err.message : err);
    teardownDevice();
    state.enabled = false;
    lsSet(KEY_ENABLED, "false");
    var cont = getContainer();
    if (cont) cont.classList.remove("meel-upscale-active");
    toast("Upscale gagal: " + (err && err.message ? err.message : err));
    updateUI();
  }

  function setEnabled(on, opts) {
    if (on === state.enabled) return;
    if (on) {
      if (!supported) {
        toast("WebGPU tidak didukung di browser ini");
        return;
      }
      state.enabled = true;
      lsSet(KEY_ENABLED, "true");
      updateUI();
      rebuild(0, opts && opts.announce ? { announce: true } : undefined);
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

  /* ======================================================================
   * 6. Panel settings Plyr
   * ==================================================================== */
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

  function buildSubPanel(name, listBuilder) {
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
      '<span aria-hidden="true">AI Upscale</span><span class="plyr__sr-only">Kembali</span>';
    back.addEventListener("click", function (e) {
      e.stopPropagation();
      showPanel("upscale");
    });
    pane.appendChild(back);

    var menu = document.createElement("div");
    menu.setAttribute("role", "menu");
    pane.appendChild(menu);

    pane.addEventListener("keydown", function (e) {
      if (e.key === "ArrowLeft") {
        e.preventDefault();
        e.stopPropagation();
        showPanel("upscale");
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

  function homeValueHtml() {
    if (state.loading) return '<span class="meel-upscale-spin" aria-label="Memuat"></span>';
    if (!supported) return "Tidak didukung";
    if (!state.enabled) return "Mati";
    return esc(currentModel().short) + " · " + esc(currentMode().short) + " · " + esc(currentScale().short);
  }

  function setHomeRowValue() {
    var row = document.getElementById("plyr-setting-upsale");
    if (!row) return;
    var v = row.querySelector(".plyr__menu__value");
    if (v) v.innerHTML = homeValueHtml();
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
    b.addEventListener("click", function (e) {
      e.stopPropagation();
      showPanel("upscale");
    });
    return b;
  }

  function updateUI() {
    if (!plyrReady()) return;
    var S = plyr.elements.settings;

    var tog = S.panels.upscale.querySelector("#plyr-setting-upscale");
    if (tog) {
      var on = state.enabled;
      tog.setAttribute("aria-checked", on ? "true" : "false");
      tog.setAttribute("aria-disabled", state.loading || !supported ? "true" : "false");
      var tv = tog.querySelector(".plyr__menu__value");
      if (tv) tv.innerHTML = state.loading ? "" : on ? CHECK_ON : CHECK_OFF;
    }

    setRowValue("model", currentModel().label);
    setRowValue("mode", currentMode().short);
    setRowValue("scale", currentScale().short);

    setHomeRowValue();

    syncRadios("upscale-model", state.modelId);
    syncRadios("upscale-mode", state.modeId);
    syncRadios("upscale-scale", state.scaleId);
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
          rebuild(0);
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
    if (state.enabled) rebuild(0);
    showPanel("upscale");
  }

  function selectScale(id) {
    state.scaleId = id;
    lsSet(KEY_SCALE, id);
    updateUI();
    if (state.enabled) rebuild(0);
    showPanel("upscale");
  }

  var adapterChecked = false;
  function preflightAdapter() {
    if (adapterChecked || !navigator.gpu) return;
    adapterChecked = true;
    var p = navigator.gpu.requestAdapter();
    if (p && p.then) {
      p.then(function (a) {
        if (!a) {
          supported = false;
          updateUI();
        }
      }).catch(function () {});
    }
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
      rebuild(300);
    });
    ro.observe(cont);
  }

  /* ======================================================================
   * 7. Lifecycle global
   * ==================================================================== */
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
    preflightAdapter();
    if (state.enabled && supported) rebuild(0);
    else if (state.enabled) {
      state.enabled = false;
      lsSet(KEY_ENABLED, "false");
      updateUI();
    }
  }

  function reviveLoop() {
    if (!state.enabled || !engine.built) return;
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
    hasRVFC: function () {
      return hasRVFC;
    },
    stats: function () {
      return {
        renders: stats.renders,
        copies: stats.copies,
        lastError: stats.lastError,
        built: engine.built,
        target: engine.target ? { width: engine.target.width, height: engine.target.height } : null,
        native: engine.native ? { width: engine.native.width, height: engine.native.height } : null
      };
    },
    registerModel: registerModel,
    attach: attach,
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
