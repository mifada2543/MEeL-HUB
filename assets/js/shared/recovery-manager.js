(function () {
  "use strict";

  window.meelCreateStuckDetector = function (opts) {
    var interval = opts.interval || 3000;
    var threshold = opts.threshold || 6;
    var timer = null;
    var lastTime = -1;
    var lastTs = Date.now();

    return {
      start: function () {
        this.stop();
        lastTime = -1;
        lastTs = Date.now();
        timer = setInterval(function () {
          if (opts.isPaused && opts.isPaused()) return;
          if (document.hidden) return;
          var ct = opts.getCurrentTime();
          var now = Date.now();
          if (ct === lastTime) {
            if ((now - lastTs) / 1000 >= threshold) {
              opts.onStuck();
            }
          } else {
            lastTime = ct;
            lastTs = now;
          }
        }, interval);
      },
      stop: function () {
        if (timer) {
          clearInterval(timer);
          timer = null;
        }
      },
    };
  };

  window.meelCreateWaitingTimeout = function (opts) {
    var timeoutMs = opts.timeout || 10000;
    var timer = null;

    return {
      start: function () {
        this.stop();
        timer = setTimeout(function () {
          opts.onTimeout();
        }, timeoutMs);
      },
      stop: function () {
        if (timer) {
          clearTimeout(timer);
          timer = null;
        }
      },
    };
  };

  window.meelCreateReconnectOverlay = function (opts) {
    var color = opts.color || "red";
    var containerId = opts.containerId;

    function getContainer() {
      return document.getElementById(containerId);
    }

    function makeSpinner() {
      return (
        '<div class="animate-spin h-7 w-7 border-2 border-' +
        color +
        '-500 border-t-transparent rounded-full"></div>'
      );
    }

    return {
      show: function () {
        var c = getContainer();
        if (!c) return;
        var existing = document.getElementById("meel-reconnect-overlay");
        if (existing) return;
        var el = document.createElement("div");
        el.id = "meel-reconnect-overlay";
        el.style.cssText =
          "position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.88);z-index:60;gap:10px;padding:20px;text-align:center;";
        el.innerHTML =
          makeSpinner() +
          '<div style="color:var(--meel-' +
          color +
          ',#f97316);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.15em;">Sambungan Terputus</div>' +
          '<div style="color:#6b7280;font-size:10px;">Menghubungkan kembali secara otomatis...</div>';
        c.appendChild(el);
      },
      hide: function () {
        var el = document.getElementById("meel-reconnect-overlay");
        if (el) el.remove();
      },
      showFailed: function () {
        var c = getContainer();
        if (!c) return;
        this.hide();
        var el = document.createElement("div");
        el.id = "meel-reconnect-overlay";
        el.style.cssText =
          "position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:rgba(8,10,15,.88);z-index:60;gap:12px;padding:20px;text-align:center;";
        el.innerHTML =
          '<div style="color:#6b7280;font-size:11px;">Tidak dapat terhubung ke media.</div>' +
          '<button onclick="window.location.reload()" style="background:var(--meel-' +
          color +
          ',#ea580c);color:#000;border:none;padding:8px 20px;border-radius:12px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;cursor:pointer;">Muat Ulang</button>';
        c.appendChild(el);
      },
    };
  };
})();

/* reference build: MEeL-C10H12N2O [a66490c6c485f191] */
