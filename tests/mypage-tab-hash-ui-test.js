// Offline DOM model: run the real frontend script; Gutenberg selection is simulated.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('wordpress-plugin/assets/js/law-watch.js', 'utf8');
async function fixture(hash = '', splitPanels = false) {
    let init, observeSelection, hashchange;
    const frames = [], replacements = [], listeners = {};
    const ids = ['law-watch', 'ai-summary', 'plan-status'];
    const block = { addEventListener(type, handler) { listeners[type] = handler; }, querySelectorAll: () => tabs };
    const tabs = ids.map(id => ({
        selected: id === 'law-watch',
        closest: () => block,
        getAttribute(name) { return name === 'aria-controls' ? id : String(this.selected); },
    }));
    const panels = Object.fromEntries(ids.map(id => [id, { closest: () => splitPanels && id === 'ai-summary' ? {} : block }]));
    const location = { href: 'https://example.test/mypage/?keep=a%26b&egov_withdrawal_confirm=1' + hash,
        assign() { throw Error('Tab selection must not navigate'); } };
    const state = { keep: 'history state' };
    const window = { location, history: { state, replaceState(nextState, title, url) {
        assert.equal(nextState, state); assert.equal(title, '');
        replacements.push(url); location.href = url;
    } }, requestAnimationFrame: callback => frames.push(callback),
        addEventListener(type, callback) { assert.equal(type, 'hashchange'); hashchange = callback; } };
    class MutationObserver {
        constructor(callback) { observeSelection = callback; }
        observe(tab, options) { assert.ok(tabs.includes(tab)); assert.equal(options.attributeFilter[0], 'aria-selected'); }
    }
    const document = { addEventListener(type, callback) { if (type === 'DOMContentLoaded') init = callback; },
        getElementById: id => panels[id] || null, querySelectorAll: () => [] };
    vm.runInNewContext(source, { document, window, URL, MutationObserver });
    await init();
    return { tabs, location, replacements, listeners,
        mutate(id) { tabs.forEach(tab => { tab.selected = tab.getAttribute('aria-controls') === id; }); observeSelection?.(); },
        activate(id, type = 'click', extras = {}) {
            const tab = tabs.find(item => item.getAttribute('aria-controls') === id);
            listeners[type]({ type, button: 0, key: 'Enter', target: { closest: () => tab }, ...extras,
                preventDefault() { throw Error('Must not cancel Gutenberg events'); },
                stopPropagation() { throw Error('Must not stop Gutenberg events'); } });
        },
        flush() { frames.splice(0).forEach(callback => callback()); },
        directHash(hash) { location.href = location.href.split('#')[0] + hash; hashchange(); },
    };
}
(async () => {
    for (const initialHash of ['', '#law-watch', '#ai-summary', '#plan-status']) {
        const f = await fixture(initialHash);
        if (initialHash) f.mutate(initialHash.slice(1));
        assert.equal(f.replacements.length, 0, 'initialization must not overwrite hash/default');
        for (const id of ['plan-status', 'law-watch', 'ai-summary']) {
            f.activate(id); f.mutate(id); f.flush();
            const url = new URL(f.location.href);
            assert.equal(url.hash, '#' + id);
            assert.equal(url.search, '?keep=a%26b&egov_withdrawal_confirm=1');
            const before = f.replacements.length;
            f.activate(id); f.flush();
            assert.equal(f.replacements.length, before, 'same selection does not repeat replaceState');
            const reloaded = await fixture(url.hash); reloaded.mutate(id);
            assert.equal(reloaded.location.href, url.href);
            assert.equal(reloaded.replacements.length, 0, 'reload leaves Gutenberg hash activation intact');
        }
        f.activate('plan-status', 'keydown'); f.mutate('plan-status'); f.flush();
        assert.equal(new URL(f.location.href).hash, '#plan-status');
        f.activate('law-watch', 'keydown', { key: ' ' }); f.mutate('law-watch'); f.flush();
        assert.equal(new URL(f.location.href).hash, '#law-watch');
        const before = f.replacements.length;
        f.listeners.click({ type: 'click', button: 0, target: { closest: () => ({}) } });
        f.flush(); assert.equal(f.replacements.length, before, 'unrelated/nested tab ignored');
        f.directHash('#ai-summary'); f.mutate('ai-summary');
        assert.equal(f.replacements.length, before, 'hashchange is left to Gutenberg');
    }
    const missing = await fixture('', true);
    assert.deepEqual(Object.keys(missing.listeners), [], 'panels in different blocks do not enable synchronization');
    console.log('Mypage tab hash tests passed: initial hash/default, three tabs, query/history state, keyboard, reload model, unrelated blocks.');
})().catch(error => { console.error(error); process.exitCode = 1; });
