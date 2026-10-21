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
 *   no-body         -> skrip dimuat di dalam <head>, jadi document.body
 *                      masih null saat file itu jalan. Listener tetap
 *                      harus terdaftar dan swap berikutnya tetap merender.
 *
 * Event htmx disimulasikan dengan gelembung: listener di body DAN di
 * document sama-sama menerima, seperti di browser sungguhan.
 */

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const scenario = process.argv[2] || 'with-stub';
const root = path.join(__dirname, '..', '..');

const result = { scenario: scenario, threw: null };
const calls = [];
const bodyListeners = {};
const docListeners = {};

const body = {
  __id: 'document.body',
  addEventListener(type, fn) {
    (bodyListeners[type] = bodyListeners[type] || []).push(fn);
  }
};

const documentStub = {
  body: body,
  readyState: 'complete',
  addEventListener(type, fn) {
    (docListeners[type] = docListeners[type] || []).push(fn);
  }
};

const sandbox = vm.createContext({
  console: console,
  document: documentStub
});

function listenerCount(type) {
  return (bodyListeners[type] || []).length + (docListeners[type] || []).length;
}

function fireHtmxAfterOnLoad(detail) {
  const event = detail === undefined ? {} : { detail: detail };
  (bodyListeners['htmx:afterOnLoad'] || []).slice().forEach((fn) => fn(event));
  (docListeners['htmx:afterOnLoad'] || []).slice().forEach((fn) => fn(event));
}

function run() {
  vm.runInContext(
    fs.readFileSync(path.join(root, 'assets/js/shared/htmx-lucide.js'), 'utf8'),
    sandbox,
    { filename: 'htmx-lucide.js' }
  );
}

try {
  if (scenario === 'no-body') {
    // Skrip ada di <head>: document.body belum ada saat file ini jalan.
    documentStub.body = null;
    sandbox.lucide = {
      createIcons: function () {
        calls.push(Array.prototype.slice.call(arguments));
      }
    };
    run();

    result.bodyWasNullAtLoad = true;
    result.listenerRegistered = listenerCount('htmx:afterOnLoad');
    result.listenerRegisteredOnDocument = (docListeners['htmx:afterOnLoad'] || []).length;
    result.callsOnLoad = calls.length;

    // Tubuh baru ada setelah dokumen selesai di-parse.
    documentStub.body = body;
    fireHtmxAfterOnLoad({ target: 'swapped-node' });

    result.callsAfterSwap = calls.length;
    result.lastScope = calls.length ? calls[calls.length - 1][1] : null;
  } else if (scenario === 'no-lucide') {
    run();
    result.listenerRegisteredAfterThrow = listenerCount('htmx:afterOnLoad');
    result.callsWhileLucideMissing = calls.length;

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

    result.listenerRegistered = listenerCount('htmx:afterOnLoad');
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
