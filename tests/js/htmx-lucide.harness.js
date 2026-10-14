'use strict';
/**
 * Harness untuk assets/js/shared/htmx-lucide.js.
 *
 * Menjalankan file itu di dalam vm context dengan DOM/lucide palsu,
 * lalu melaporkan apa yang benar-benar terjadi — bukan sekadar mencocokkan
 * string. Ini penting karena bug yang dijaga di sini (ReferenceError saat
 * lucide belum ada) TIDAK AKAN terlihat dari pembacaan source: file-nya
 * tetap looks fine, yang rusak baru terasa saat htmx:afterOnLoad menyala
 * di browser.
 *
 * Skenario:
 *   with-stub       -> lucide tersedia; awas + htmx:afterOnLoad
 *   with-stub-blank -> htmx:afterOnLoad tanpa detail.target
 *   no-lucide       -> lucide BELUM ada; file tidak boleh melempar, dan
 *                      listener tetap harus hidup supaya htmx swap
 *                      berikutnya tetap dirender begitu lucide muncul
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const scenario = process.argv[2] || 'with-stub';
const root = path.join(__dirname, '..', '..');

const result = { scenario: scenario, threw: null };
const calls = [];
const listeners = {};

const body = {
  __id: 'document.body',
  addEventListener(type, fn) {
    (listeners[type] = listeners[type] || []).push(fn);
  }
};

const sandbox = vm.createContext({
  console: console,
  document: {
    body: body,
    readyState: 'complete',
    addEventListener() {}
  }
});

function fireHtmxAfterOnLoad(detail) {
  const event = detail === undefined ? {} : { detail: detail };
  const fns = listeners['htmx:afterOnLoad'] || [];
  fns.forEach((fn) => fn(event));
}

function run() {
  vm.runInContext(
    fs.readFileSync(path.join(root, 'assets/js/shared/htmx-lucide.js'), 'utf8'),
    sandbox,
    { filename: 'htmx-lucide.js' }
  );
}

try {
  if (scenario === 'no-lucide') {
    // Sengaja TIDAK memasang lucide sama sekali.
    run();
    result.listenerRegisteredAfterThrow =
      (listeners['htmx:afterOnLoad'] || []).length;
    result.callsWhileLucideMissing = calls.length;

    // Lucide baru muncul belakangan (skenario shim link.php: stub dulu,
    // lalu lib asli setelahnya). Listener harus masih hidup.
    sandbox.lucide = {
      createIcons: function () {
        calls.push(Array.prototype.slice.call(arguments));
      }
    };
    fireHtmxAfterOnLoad({ target: 'swapped-node' });
    result.callsAfterLucideArrived = calls.length;
    result.lastScope = calls.length ? calls[calls.length - 1][1] : null;
  } else {
    sandbox.lucide = {
      createIcons: function () {
        calls.push(Array.prototype.slice.call(arguments));
      }
    };
    run();

    result.listenerRegistered = (listeners['htmx:afterOnLoad'] || []).length;
    result.callsOnLoad = calls.length;
    result.argsOnLoad = calls.length ? calls[0].length : null;

    if (scenario === 'with-stub-blank') {
      fireHtmxAfterOnLoad(); // tanpa detail sama sekali
    } else {
      fireHtmxAfterOnLoad({ target: 'swapped-node' });
    }

    result.callsTotal = calls.length;
    result.lastArgCount = calls.length ? calls[calls.length - 1].length : null;
    result.lastScope = calls.length ? calls[calls.length - 1][1] : null;
  }
} catch (e) {
  result.threw = String(e && e.message ? e.message : e);
}

process.stdout.write(JSON.stringify(result) + '\n');