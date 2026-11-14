// Offline DOM/fetch doubles: exercise only notification toggle, no browser/network.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
(async () => {
    let init, click, calls = 0, finish;
    const result = { textContent: '' }, off = { hidden: true }, label = { textContent: 'ON' };
    const button = {
        disabled: false, pressed: 'true', dataset: { restUrl: '/watch-notifications', restNonce: 'nonce' },
        addEventListener: (_, handler) => { click = handler; },
        getAttribute() { return this.pressed; },
        setAttribute(_, value) { this.pressed = value; },
        closest: () => ({ querySelector: (selector) => selector.includes('result') ? result : off }),
        querySelector: () => label,
    };
    const context = {
        document: {
            addEventListener: (_, handler) => { init = handler; },
            getElementById: () => null,
            querySelectorAll: (selector) => selector === '.egov-watch-mail-toggle' ? [button] : [],
        },
        window: { location: { href: 'https://example.test/mypage/' } }, URL, console,
        fetch: async (_, request) => {
            calls++;
            assert.equal(request.headers['X-WP-Nonce'], 'nonce');
            assert.deepEqual(JSON.parse(request.body), { notifications: false });
            return new Promise((resolve) => { finish = resolve; });
        },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../wordpress-plugin/assets/js/law-watch.js'), 'utf8'), context);
    await init();
    const saving = click(); await click();
    assert.equal(calls, 1); assert.equal(button.disabled, true);
    finish({ ok: true, json: async () => ({ notifications: false }) }); await saving;
    assert.equal(button.pressed, 'false'); assert.equal(label.textContent, 'OFF');
    assert.equal(off.hidden, false); assert.equal(button.disabled, false);
    assert.match(result.textContent, /保存しました/);
    context.fetch = async () => ({ ok: false, json: async () => ({ message: '保存失敗' }) });
    await click();
    assert.equal(button.pressed, 'false'); assert.equal(off.hidden, false);
    assert.equal(button.disabled, false); assert.equal(result.textContent, '保存失敗');
    context.fetch = async () => { throw new Error('通信失敗'); };
    await click();
    assert.equal(label.textContent, 'OFF'); assert.equal(button.disabled, false);
    assert.equal(result.textContent, '通信失敗');
    console.log('Notification UI success, duplicate click, API error and network error checks passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
