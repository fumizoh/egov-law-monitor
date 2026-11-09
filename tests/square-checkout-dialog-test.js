'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../wordpress-plugin/assets/js/square-checkout.js'), 'utf8');
let checks = 0;
function check(value) { assert.ok(value); checks++; }
function setup(supported = true) {
    function element() { return { disabled: false, events: {}, addEventListener(name, fn) { this.events[name] = fn; }, click() { if (!this.disabled) this.events.click(); } }; }
    const open = element(), back = element(), confirm = element(), dialog = element();
    const flag = { value: '' }, guidance = { hidden: false };
    open.disabled = true; dialog.open = false;
    dialog.showModal = supported ? function () { this.open = true; } : undefined;
    dialog.close = function () { this.open = false; this.events.close(); };
    const selectors = { dialog, '[data-cancel-open]': open, '[data-cancel-back]': back, '[data-cancel-confirm]': confirm, '[name="checkout_cancel_confirmed"]': flag, '[data-cancel-unavailable]': guidance };
    const form = element(); let sends = 0;
    form.querySelector = (selector) => selectors[selector];
    form.querySelectorAll = () => [open, back, confirm];
    form.requestSubmit = () => { let prevented = false; form.events.submit({ preventDefault() { prevented = true; } }); if (!prevented) sends++; };
    vm.runInNewContext(source, { document: { querySelectorAll: () => [form] } });
    return { form, open, back, confirm, dialog, flag, guidance, sends: () => sends };
}
let s = setup();
check(!s.open.disabled && s.guidance.hidden && !s.dialog.open && s.sends() === 0);
s.open.click(); check(s.dialog.open && s.sends() === 0 && s.flag.value === '');
s.back.click(); check(!s.dialog.open && s.sends() === 0 && s.flag.value === '');
s.open.click(); s.dialog.close(); check(s.sends() === 0 && s.flag.value === '');
s.form.requestSubmit(); check(s.dialog.open && s.sends() === 0);
s.confirm.click(); check(s.sends() === 1 && s.flag.value === '1' && !s.dialog.open);
check(s.open.disabled && s.back.disabled && s.confirm.disabled);
s.form.requestSubmit(); s.confirm.click(); s.open.click(); check(s.sends() === 1);
s = setup(false); check(s.open.disabled && !s.guidance.hidden && s.sends() === 0);
console.log(`${checks} dialog checks passed; no browser, network or DB access.`);
