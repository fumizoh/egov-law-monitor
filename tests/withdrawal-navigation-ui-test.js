const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
let init, click, assigned = [], prevented = 0, stopped = 0;
const document = {
    addEventListener(type, handler, capture) {
        if (type === 'DOMContentLoaded') init = handler;
        if (type === 'click') { assert.equal(capture, true); click = handler; }
    },
    querySelectorAll: () => [], getElementById: () => null,
};
vm.runInNewContext(fs.readFileSync('wordpress-plugin/assets/js/law-watch.js', 'utf8'), {
    document, URL, window: { location: { href: 'https://example.test/mypage/#plan-status', assign: url => assigned.push(url) } },
});
(async () => {
    await init();
    function run(href, options = {}) {
        const link = { href, target: options.target || '', hasAttribute: name => name === 'download' && !!options.download };
        click({ button: 0, ...options, target: { closest: selector => {
            assert.equal(selector, 'a[data-egov-withdrawal-navigation]');
            return options.unrelated ? null : link;
        } }, preventDefault: () => prevented++, stopImmediatePropagation: () => stopped++ });
    }
    for (const plan of ['Free', 'Standard']) {
        for (const query of ['?egov_withdrawal_confirm=1', '', '?keep=1&egov_withdrawal_confirm=1']) {
            const url = 'https://example.test/mypage/' + query + '#plan-status';
            const before = assigned.length; run(url);
            assert.equal(assigned.length, before + 1, plan);
            assert.equal(assigned.at(-1), url);
        }
    }
    const before = assigned.length;
    const url = 'https://example.test/mypage/?egov_withdrawal_confirm=1#plan-status';
    for (const options of [{ctrlKey:true}, {shiftKey:true}, {altKey:true}, {metaKey:true}, {button:1}, {target:'_blank'}, {download:true}, {unrelated:true}]) run(url, options);
    run('https://other.test/#plan-status'); run('https://example.test/#law-watch');
    assert.equal(assigned.length, before);
    assert.equal(prevented, before); assert.equal(stopped, before);
    const php = fs.readFileSync('wordpress-plugin/includes/user-withdrawal-request.php', 'utf8');
    assert.equal((php.match(/<a data-egov-withdrawal-navigation/g) || []).length, 2);
    console.log('Withdrawal navigation tests passed (offline events; live Gutenberg unverified).');
})().catch(error => { console.error(error); process.exitCode = 1; });
