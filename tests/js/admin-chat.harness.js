const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function setup(file) {
    const nodes = {};
    function element() {
        return { innerHTML: '', textContent: '', value: '', dataset: {}, disabled: false,
            scrollTop: 0, scrollHeight: 1000, clientHeight: 200,
            setAttribute(name, value) { this[name] = value; },
            appendChild(child) { if (child.id) nodes[child.id] = child; },
            querySelectorAll() { return []; } };
    }
    ['admin-chat-data', 'chat-messages', 'chat-input', 'chat-form', 'chat-send-btn',
        'chat-status', 'user-results', 'recent-list'].forEach(id => nodes[id] = element());
    nodes['admin-chat-data'].dataset = { apiBase: '/nested/api/chat', chatRoot: '/nested/admin/chat' };
    const requests = [], intervals = new Map(), timeouts = new Map();
    let timer = 0;
    const context = {
        document: { getElementById: id => nodes[id] || null, createElement: element, body: element() },
        MEEL_BASE: '/nested', MEEL_CSRF: 'csrf-test',
        escapeHtml: value => String(value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
        timeAgo: () => 'now', confirm: () => true, alert() {}, console, AbortController,
        FormData: class { constructor() { this.values = {}; } append(k, v) { this.values[k] = String(v); } },
        fetch(url, options = {}) {
            return new Promise((resolve, reject) => requests.push({ url, options, resolve, reject }));
        },
        setInterval(fn) { intervals.set(++timer, fn); return timer; }, clearInterval(id) { intervals.delete(id); },
        setTimeout(fn) { timeouts.set(++timer, fn); return timer; }, clearTimeout(id) { timeouts.delete(id); }
    };
    context.window = context;
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../', file), 'utf8'), context);
    return { context, nodes, requests, intervals,
        tick() { [...intervals.values()].forEach(fn => fn()); },
        debounce() { const callbacks = [...timeouts.values()]; timeouts.clear(); callbacks.forEach(fn => fn()); } };
}
const flush = async () => { for (let i = 0; i < 15; i++) await Promise.resolve(); };
function respond(request, data, status = 200, type = 'application/json') {
    request.resolve({ ok: status < 400, status, headers: { get: () => type }, json: () => Promise.resolve(data) });
}
const message = { from: 'admin', message: '<hello>', created_at: '2026-01-01' };
const payload = (revision = 'a'.repeat(64)) => ({ ok: true, messages: [message], revision });
const tests = {};
tests.polling = async () => {
    const h = setup('assets/js/admin/chat.js');
    assert.equal(h.intervals.size, 0);
    h.context.loadChatMessages(7);
    h.context.loadChatMessages(7);
    assert.equal(h.intervals.size, 1, 'loading a conversation starts exactly one poll timer');
    assert.equal(h.requests.length, 1, 'GET requests must not overlap');
    h.tick();
    assert.equal(h.requests.length, 1);
    respond(h.requests[0], payload()); await flush();
    assert.match(h.nodes['chat-messages'].innerHTML, /&lt;hello&gt;/);
    assert.equal(h.nodes['chat-messages'].scrollTop, 1000);
    h.nodes['chat-messages'].scrollTop = 100;
    h.tick(); respond(h.requests[1], payload('b'.repeat(64))); await flush();
    assert.equal(h.nodes['chat-messages'].scrollTop, 100, 'background polling preserves reading position');
};
tests.errors = async () => {
    for (const [data, status, type] of [[{ok:false,error:'Denied'},200,'application/json'], [{ok:true},503,'application/json'], [{},200,'text/html']]) {
        const h = setup('assets/js/admin/chat.js');
        h.nodes['chat-messages'].innerHTML = 'existing messages';
        h.context.loadChatMessages(7); respond(h.requests[0], data, status, type); await flush();
        assert.equal(h.nodes['chat-messages'].innerHTML, 'existing messages', 'errors do not masquerade as empty history');
        assert.notEqual(h.nodes['chat-status'].textContent, '', 'failed fetch has a nonblocking error status');
    }
};
tests.readingDuringFetch = async () => {
    const h = setup('assets/js/admin/chat.js');
    h.context.loadChatMessages(7); respond(h.requests[0], payload()); await flush();
    h.tick();
    h.nodes['chat-messages'].scrollTop = 120;
    respond(h.requests[1], payload('b'.repeat(64))); await flush();
    assert.equal(h.nodes['chat-messages'].scrollTop, 120, 'scrolling up during a pending poll must be respected');
};
tests.deleteDuringPoll = async () => {
    const h = setup('assets/js/admin/chat.js');
    h.context.loadChatMessages(7); respond(h.requests[0], payload()); await flush();
    h.tick();
    h.context.deleteChatMsg(0);
    respond(h.requests[2], {ok:true}); await flush();
    respond(h.requests[1], payload()); await flush();
    assert.equal(h.requests.length, 4, 'delete queues a fresh GET after an already pending poll');
    h.context.deleteChatMsg(0);
    assert.equal(h.requests.length, 4, 'pre-delete snapshot cannot be reused while awaiting new history');
    respond(h.requests[3], {ok:true,messages:[],revision:'b'.repeat(64)}); await flush();
    assert.match(h.nodes['chat-messages'].innerHTML, /Belum ada pesan/);
};
tests.send = async () => {
    const h = setup('assets/js/admin/chat.js');
    h.context.loadChatMessages(7); respond(h.requests[0], payload()); await flush();
    h.nodes['chat-input'].value = 'draft';
    const event = { preventDefault() {} };
    h.context.sendChat(event); h.context.sendChat(event);
    assert.equal(h.requests.length, 2, 'double submit sends only once');
    assert.equal(h.nodes['chat-send-btn'].disabled, true);
    assert.equal(h.requests[1].options.body.values.csrf_token, 'csrf-test');
    respond(h.requests[1], {ok:false,error:'Try again'}, 500); await flush();
    assert.equal(h.nodes['chat-input'].value, 'draft');
    assert.equal(h.nodes['chat-send-btn'].disabled, false);
    assert.match(h.nodes['chat-status'].textContent, /Try again|Gagal/);
    h.context.sendChat(event); respond(h.requests[2], {ok:true}); await flush();
    assert.equal(h.nodes['chat-input'].value, '');
    respond(h.requests[3], payload()); await flush();
    assert.equal(h.nodes['chat-messages'].scrollTop, 1000);
    h.nodes['chat-input'].value = '😀'.repeat(5001);
    h.context.sendChat(event);
    assert.equal(h.requests.length, 4, 'reject messages longer than 5000 Unicode characters');
};
tests.deletion = async () => {
    const h = setup('assets/js/admin/chat.js');
    h.context.loadChatMessages(7); h.context.deleteChatMsg(0);
    assert.equal(h.requests.length, 1, 'never delete without the displayed revision');
    respond(h.requests[0], payload()); await flush();
    h.context.deleteChatMsg(0);
    assert.equal(h.requests[1].options.body.values.revision, 'a'.repeat(64));
    assert.equal(h.requests[1].options.body.values.index, '0');
    respond(h.requests[1], {ok:false,error:'Conversation changed'}, 409); await flush();
    assert.notEqual(h.nodes['chat-status'].textContent, '');
    assert.equal(h.requests.length, 3, 'stale delete refreshes the conversation');
    h.context.deleteChatMsg(0);
    assert.equal(h.requests.length, 3, 'stale revision cannot be reused');
};
tests.search = async () => {
    const h = setup('assets/js/admin/chat/list.js');
    h.context.searchUsers('old'); h.context.searchUsers('new');
    assert.equal(h.requests.length, 1, 'search waits for debounce (only initial recent request exists)');
    h.debounce(); assert.equal(h.requests.length, 2);
    h.context.searchUsers('<new>'); h.debounce();
    assert.equal(h.requests[1].options.signal.aborted, true);
    respond(h.requests[2], {ok:true,list:[{username:'<"/x',role:'<role>'}]}); await flush();
    const html = h.nodes['user-results'].innerHTML;
    assert.match(html, /%3C%22%2Fx/);
    assert.ok(!html.includes('<role>') && !html.includes('<"/x'));
    respond(h.requests[1], {ok:true,list:[{username:'obsolete',role:'user'}]}); await flush();
    assert.equal(h.nodes['user-results'].innerHTML, html, 'older search cannot replace current results');
    h.context.searchUsers(''); assert.equal(h.nodes['user-results'].innerHTML, '');
};
tests.recent = async () => {
    const h = setup('assets/js/admin/chat/list.js');
    h.tick(); h.tick(); assert.equal(h.requests.length, 1, 'recent refreshes cannot overlap');
    respond(h.requests[0], {ok:false,error:'offline'}); await flush();
    assert.match(h.nodes['recent-list'].innerHTML, /Gagal/);
    h.tick(); respond(h.requests[1], {ok:true,list:[{username:'<img>',last_message:'<script>',last_at:'now'}]}); await flush();
    assert.ok(!h.nodes['recent-list'].innerHTML.includes('<img>'));
    assert.match(h.nodes['recent-list'].innerHTML, /%3Cimg%3E/);
    h.context.searchUsers('bad'); h.debounce(); h.requests[2].reject(new Error('offline')); await flush();
    assert.match(h.nodes['user-results'].innerHTML, /Gagal/);
};
(async () => {
    let failed = 0;
    for (const [name, test] of Object.entries(tests)) {
        try { await test(); console.log('PASS ' + name); }
        catch (error) { failed++; console.error('FAIL ' + name + ': ' + error.message); }
    }
    process.exitCode = failed ? 1 : 0;
})();
