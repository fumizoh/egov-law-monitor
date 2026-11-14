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
        getAttribute(name) { assert.equal(name, 'aria-checked'); return this.pressed; },
        setAttribute(name, value) { assert.equal(name, 'aria-checked'); this.pressed = value; },
        closest: () => ({ querySelector: (selector) => selector.includes('result') ? result : off }),
        querySelector: (selector) => { assert.equal(selector, '.egov-watch-mail-value'); return label; },
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
    assert.equal(result.textContent, '');
    const saving = click(); await click();
    assert.equal(calls, 1); assert.equal(button.disabled, true);
    assert.equal(button.pressed, 'true'); // Keep the saved position while saving.
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
    context.fetch = async () => ({ ok: true, json: async () => ({ notifications: true }) });
    await click();
    assert.equal(button.pressed, 'true'); assert.equal(label.textContent, 'ON'); assert.equal(off.hidden, true);
    const css = fs.readFileSync(path.join(__dirname, '../wordpress-plugin/assets/css/egov-law-post.css'), 'utf8');
    assert.match(css, /min-height: 44px/);
    assert.match(css, /aria-checked="true"[\s\S]*translateX\(20px\)/);
    assert.match(css, /focus-visible/); assert.match(css, /prefers-reduced-motion/);
    console.log('Notification UI success, duplicate click, API error and network error checks passed.');
})().catch((error) => { console.error(error); process.exitCode = 1; });
