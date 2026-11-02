'use strict';
/**
 * Harness untuk assets/js/profile/coin-countdown.js.
 *
 * Menjalankan skrip di dalam VM context dengan DOM/fetch palsu,
 * lalu melaporkan perilaku sinkronisasi waktu server ↔ browser.
 * Ini penting karena bug yang dijaga di sini (countdown bergantung
 * pada jam klien, tidak refetch, tidak handle "Maks") TIDAK AKAN
 * terlihat dari pembacaan source: file-nya looks fine, yang rusak
 * baru terasa saat browser menjalankan setInterval/paint.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const scenario = process.argv[2] || 'normal';
const root = path.join(__dirname, '..', '..');

const result = { scenario, threw: null };
const fetchCalls = [];
const intervals = [];

// ==== Mock DOM ====
const coinIndicator = {
  __id: 'coin-indicator',
  getAttribute(name) {
    const attrs = {
      'data-role': 'user',
      'data-countdown': '7200',           // 2 jam
      'data-balance': '10',
      'data-max': '25',
      'data-user-id': '42',
      'data-api-base': '../api/meelcoin',
    };
    return attrs[name] ?? null;
  },
  classList: {
    _classes: new Set(),
    add(c) { this._classes.add(c); },
    remove(c) { this._classes.delete(c); },
    contains(c) { return this._classes.has(c); },
  },
};

const countdownEl = {
  __id: 'coin-countdown',
  textContent: '',
};

const currentEl = {
  __id: 'coin-current',
  textContent: '10',
};

const maxEl = {
  __id: 'coin-max',
  textContent: '25',
};

const documentStub = {
  getElementById(id) {
    switch (id) {
      case 'coin-indicator': return coinIndicator;
      case 'coin-countdown': return countdownEl;
      case 'coin-current': return currentEl;
      case 'coin-max': return maxEl;
      default: return null;
    }
  },
  hidden: false,
  addEventListener(type, fn) {
    if (type === 'visibilitychange') this._visFn = fn;
  },
  _visFn: null,
};

const performanceStub = {
  now: 0,
  get now() { return this._now; },
  set now(v) { this._now = v; },
};
performanceStub.now = 0;

const windowStub = {
  performance: performanceStub,
  Date: Date,
  setInterval(fn, ms) {
    const id = intervals.push({ fn, ms, last: 0 }) - 1;
    return id;
  },
  clearInterval(id) {
    if (id >= 0 && id < intervals.length) intervals[id] = null;
  },
  addEventListener(type, fn) {
    if (type === 'focus') this._focusFn = fn;
    if (type === 'online') this._onlineFn = fn;
  },
  _focusFn: null,
  _onlineFn: null,
  fetch: async (url, opts) => {
    fetchCalls.push({ url, opts });
    if (scenario === 'network-fail') throw new Error('network');
    // Simulasi respons API dengan server_time & next_refill_at
    return {
      ok: true,
      json: async () => ({
        enabled: true,
        is_admin: false,
        balance: 10,
        max: 25,
        server_time: 1000000,
        next_refill_at: 1000000 + 7200,   // 2 jam lagi
        cycle_seconds: 18000,              // 5 jam
      }),
    };
  },
};

const sandbox = vm.createContext({
  console: console,
  document: documentStub,
  window: windowStub,
  window: windowStub, // duplicate for global
  fetch: windowStub.fetch.bind(windowStub),
  setInterval: windowStub.setInterval.bind(windowStub),
  clearInterval: windowStub.clearInterval.bind(windowStub),
  addEventListener: windowStub.addEventListener.bind(windowStub),
  performance: performanceStub,
});

function fireVisibilityChange(hidden) {
  documentStub.hidden = hidden;
  if (documentStub._visFn) documentStub._visFn({ type: 'visibilitychange' });
}

function fireFocus() {
  if (windowStub._focusFn) windowStub._focusFn({ type: 'focus' });
}

function advancePerformance(ms) {
  performanceStub.now += ms;
  // Jalankan interval yang sudah lewat
  for (let i = 0; i < intervals.length; i++) {
    const intv = intervals[i];
    if (!intv) continue;
    intv.last += ms;
    if (intv.last >= intv.ms) {
      intv.last = 0;
      try { intv.fn(); } catch (e) { result.threw = String(e); }
    }
  }
}

function runScript() {
  const code = fs.readFileSync(path.join(root, 'assets/js/profile/coin-countdown.js'), 'utf8');
  vm.runInContext(code, sandbox, { filename: 'coin-countdown.js' });
}

try {
  if (scenario === 'maxed') {
    // User sudah saldo penuh
    coinIndicator.getAttribute = (name) => {
      const attrs = {
        'data-role': 'user',
        'data-countdown': '0',
        'data-balance': '25',
        'data-max': '25',
        'data-user-id': '42',
        'data-api-base': '../api/meelcoin',
      };
      return attrs[name] ?? null;
    };
    currentEl.textContent = '25';
    runScript();

    result.initialPaint = countdownEl.textContent;
    result.hasCoinMaxed = coinIndicator.classList.contains('coin-maxed');

    // Simulasi 1 detik
    advancePerformance(1000);
    result.afterTick = countdownEl.textContent;

    // Simulasi focus — tidak seharusnya fetch karena saldo maks
    fireFocus();
    result.fetchAfterFocus = fetchCalls.length;

  } else if (scenario === 'admin') {
    // Admin tidak punya countdown
    coinIndicator.getAttribute = (name) => {
      const attrs = {
        'data-role': 'admin',
        'data-countdown': '0',
        'data-balance': '-1',
        'data-max': '-1',
        'data-user-id': '1',
        'data-api-base': '../api/meelcoin',
      };
      return attrs[name] ?? null;
    };
    runScript();

    result.initialPaint = countdownEl.textContent;
    result.intervalCount = intervals.length;

  } else if (scenario === 'countdown-expires') {
    // Countdown habis -> fetch dipicu
    coinIndicator.getAttribute = (name) => {
      const attrs = {
        'data-role': 'user',
        'data-countdown': '5',         // 5 detik
        'data-balance': '10',
        'data-max': '25',
        'data-user-id': '42',
        'data-api-base': '../api/meelcoin',
      };
      return attrs[name] ?? null;
    };
    runScript();

    result.initialPaint = countdownEl.textContent;

    // Maju 6 detik -> countdown 0 -> fetch retry (30s throttle tapi langsung karena remaining<=0)
    advancePerformance(6000);

    result.afterExpiryPaint = countdownEl.textContent;
    result.fetchCount = fetchCalls.length;

  } else if (scenario === 'server-sync') {
    // Verifikasi server_time + next_refill_at dipakai untuk menghitung remaining
    runScript();

    // Tunggu fetch selesai (microtask)
    advancePerformance(0);

    result.firstFetchCount = fetchCalls.length;
    result.remainingAfterFetch = countdownEl.textContent;

    // Maju 1 detik pakai performance.now -> remaining harus turun 1 detik
    const before = countdownEl.textContent;
    advancePerformance(1000);
    result.after1s = countdownEl.textContent;
    result.decremented = before !== countdownEl.textContent;

    // Maju 60 detik -> interval poll fetch
    advancePerformance(60000);
    result.pollFetchCount = fetchCalls.length;

  } else if (scenario === 'visibility-restore') {
    // Tab hidden lalu visible -> fetch dipicu
    runScript();
    advancePerformance(0);

    const initialFetch = fetchCalls.length;
    documentStub.hidden = true;
    fireVisibilityChange(true);
    advancePerformance(10000);

    fireVisibilityChange(false);
    advancePerformance(0);

    result.fetchOnVisible = fetchCalls.length - initialFetch;

  } else {
    // Normal: inisialisasi + fetch awal + poll
    runScript();
    advancePerformance(0);

    result.initialPaint = countdownEl.textContent;
    result.initialFetch = fetchCalls.length;
    result.hasCoinMaxed = coinIndicator.classList.contains('coin-maxed');

    // Maju 61 detik -> poll fetch
    advancePerformance(61000);
    result.pollFetch = fetchCalls.length;
  }
} catch (e) {
  result.threw = String(e && e.message ? e.message : e);
}

process.stdout.write(JSON.stringify(result) + '\n');