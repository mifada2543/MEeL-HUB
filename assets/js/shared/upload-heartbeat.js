(function () {
  "use strict";

  var timers = new Map();

  function stop(form) {
    var timer = timers.get(form);
    if (timer) {
      clearInterval(timer);
      timers["delete"](form);
    }
  }

  window.meelStopUploadHeartbeat = function (form) {
    stop(form);
  };

  window.meelStartUploadHeartbeat = function (form) {
    form = form || document.querySelector("form");
    if (!form || timers.has(form)) return;

    var input = form.querySelector('input[name="upload_attempt"]');
    var csrf = form.querySelector('input[name="csrf_token"]');
    if (!input || !csrf || !input.value || !csrf.value) return;

    var endpoint = input.getAttribute("data-heartbeat-url") || "api/upload-heartbeat";
    var sending = false;
    var stopped = false;

    function beat() {
      if (sending || stopped) return;
      sending = true;
      var body = new URLSearchParams();
      body.set("upload_attempt", input.value);
      body.set("csrf_token", csrf.value);
      fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-Requested-With": "XMLHttpRequest",
        },
        body: body.toString(),
      })
        .then(function (response) {
          return response.json().catch(function () {
            return null;
          });
        })
        .then(function (data) {
          if (data && data.ok === false && data.status === "not_found") {
            stopped = true;
            stop(form);
          }
        })
        .catch(function () {})
        .finally(function () {
          sending = false;
        });
    }

    timers.set(form, setInterval(beat, 30000));
    beat();
  };

  window.addEventListener("pagehide", function () {
    timers.forEach(function (timer, form) {
      clearInterval(timer);
      var input = form.querySelector('input[name="upload_attempt"]');
      var csrf = form.querySelector('input[name="csrf_token"]');
      if (!input || !csrf || !input.value || !csrf.value) return;
      var endpoint = input.getAttribute("data-heartbeat-url") || "api/upload-heartbeat";
      var body = new URLSearchParams();
      body.set("upload_attempt", input.value);
      body.set("csrf_token", csrf.value);
      body.set("upload_abort", "1");
      try {
        var payload = new Blob([body.toString()], {
          type: "application/x-www-form-urlencoded;charset=UTF-8",
        });
        if (navigator.sendBeacon && navigator.sendBeacon(endpoint, payload)) {
          return;
        }
      } catch (e) {}
      fetch(endpoint, {
        method: "POST",
        credentials: "same-origin",
        cache: "no-store",
        keepalive: true,
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
        },
        body: body.toString(),
      }).catch(function () {});
    });
    timers.clear();
  });
})();

/* reference build: MEeL-C3H7NO2S [ffd332a] */
