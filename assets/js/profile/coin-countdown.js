(function () {
  var el = document.getElementById("coin-indicator");
  if (!el) return;

  var countdownEl = document.getElementById("coin-countdown");
  var currentEl = document.getElementById("coin-current");
  var maxEl = document.getElementById("coin-max");
  var role = el.getAttribute("data-role");
  if (role === "admin") return;

  var remaining = Math.max(0, parseInt(el.getAttribute("data-countdown"), 10) || 0);
  var balance = parseInt(el.getAttribute("data-balance"), 10);
  if (isNaN(balance)) {
    balance = parseInt(currentEl ? currentEl.textContent : "", 10) || 0;
  }
  var maxCoins = parseInt(el.getAttribute("data-max"), 10) || 0;
  var userId = parseInt(el.getAttribute("data-user-id"), 10) || 0;
  var apiBase = el.getAttribute("data-api-base") || "../api/meelcoin";
  var nextRefillAt = 0;
  var serverTime = 0;
  var receivedAt = 0;
  var inFlight = false;
  var retryAt = 0;

  function pad(n) {
    return n < 10 ? "0" + n : "" + n;
  }

  function formatTime(s) {
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    if (h > 0) return pad(h) + ":" + pad(m) + ":" + pad(sec);
    return pad(m) + ":" + pad(sec);
  }

  function clockMs() {
    return window.performance && typeof window.performance.now === "function"
      ? window.performance.now()
      : Date.now();
  }

  function paint() {
    if (!countdownEl) return;
    if (maxCoins > 0 && balance >= maxCoins) {
      el.classList.add("coin-maxed");
      countdownEl.textContent = "Maks";
      return;
    }
    el.classList.remove("coin-maxed");
    countdownEl.textContent = remaining > 0 ? formatTime(remaining) : "Siap";
  }

  function refreshRemaining() {
    if (!nextRefillAt || !serverTime) return;
    var elapsed = Math.max(0, (clockMs() - receivedAt) / 1000);
    remaining = Math.max(0, Math.round(nextRefillAt - (serverTime + elapsed)));
  }

  function fetchBalance() {
    if (inFlight || !userId) return;
    if (maxCoins > 0 && balance >= maxCoins) return;
    inFlight = true;
    fetch(apiBase + "?user_id=" + encodeURIComponent(userId), {
      cache: "no-store",
      credentials: "same-origin",
    })
      .then(function (response) {
        if (!response.ok) throw new Error("HTTP " + response.status);
        return response.json();
      })
      .then(function (data) {
        if (!data || !data.enabled || data.is_admin) return;

        if (typeof data.balance === "number") {
          balance = data.balance;
          if (currentEl) currentEl.textContent = data.balance;
        }
        if (typeof data.max === "number") {
          maxCoins = data.max;
          if (maxEl) maxEl.textContent = data.max;
        }

        if (
          typeof data.next_refill_at === "number" &&
          typeof data.server_time === "number"
        ) {
          nextRefillAt = data.next_refill_at;
          serverTime = data.server_time;
          receivedAt = clockMs();
          remaining = Math.max(0, nextRefillAt - serverTime);
        } else if (typeof data.countdown === "number") {
          nextRefillAt = 0;
          remaining = Math.max(0, data.countdown);
        }

        paint();
      })
      .catch(function () {})
      .then(function () {
        inFlight = false;
      });
  }

  function tick() {
    refreshRemaining();
    paint();
    if (maxCoins > 0 && balance >= maxCoins) return;
    if (remaining <= 0 && !inFlight && Date.now() >= retryAt) {
      retryAt = Date.now() + 30000;
      fetchBalance();
    }
  }

  paint();
  setInterval(tick, 1000);
  setInterval(fetchBalance, 60000);

  if (document.addEventListener) {
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) fetchBalance();
    });
  }
  window.addEventListener("focus", fetchBalance);
  window.addEventListener("online", fetchBalance);

  // Skip initial fetch if already at max — API will confirm, no need to round-trip
  if (!(maxCoins > 0 && balance >= maxCoins)) {
    fetchBalance();
  }
})();
