/* No browser/network dependencies: execute actual UI event handlers with a small DOM double. */
const assert = require('node:assert/strict');
class Element {
    constructor(tag = 'div') { this.tag = tag; this.children = []; this.events = {}; this.dataset = {}; this.text = ''; this.hidden = false; this.disabled = false; this.attributes = {}; this.classList = {toggle() {}}; }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(x => x.textContent).join(''); }
    set innerHTML(_) { throw new Error('Unsafe HTML rendering'); }
    append(...els) { this.children.push(...els); }
    replaceChildren(...els) { this.text = ''; this.children = els; }
    addEventListener(event, callback) { this.events[event] = callback; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    click() { if (!this.disabled) this.events.click?.(); }
}
const names = ['search', 'status', 'laws', 'law-prev', 'law-next', 'law', 'revisions', 'rev-prev', 'rev-next', 'selected'];
function root() {
    const el = new Element(); el.parts = Object.fromEntries(names.map(name => [name, new Element(name.includes('prev') || name.includes('next') ? 'button' : 'div')]));
    el.querySelector = selector => el.parts[selector.slice(6, -1)];
    el.querySelectorAll = () => Object.values(el.parts).flatMap(walk).filter(x => ['button', 'input', 'select'].includes(x.tag));
    return el;
}
function walk(el) { return [el, ...el.children.flatMap(walk)]; }
function buttons(el) { return walk(el).filter(x => x.tag === 'button'); }
global.document = {createElement: tag => new Element(tag), querySelectorAll: () => []};
global.FormData = class { constructor(form) { this.form = form; } get(key) { return this.form.values[key]; } };
let calls = [], replies = [];
global.fetch = async (url, options) => { calls.push({url, options}); if (!replies.length) throw new Error('Unexpected fetch'); return replies.shift()(); };
const {enforcement, init} = require('../wordpress-plugin/assets/js/ondemand.js');
const tick = () => new Promise(resolve => setImmediate(resolve));
function reply(data, ok = true) { replies.push(async () => ({ok, json: async () => data})); }
const law = {id: '322AC0000000049', title: '<img src=x onerror=alert(1)>', number: '法令番号'};
function revision(i = 0) { return {law, official_revision_id: `official_${i}`, amendment: {id: '508AC0000000060', title: '改正法', number: '番号'}, enforcement: {api_date: '2027-04-01', pseudo_date: '2027-04-01', comment: '政令で定める日'}, revision_status: 'UnEnforced', internal: {status: 'not_checked', candidates: [], checked_at: null}}; }
(async () => {
    assert.equal(calls.length, 0, 'import is offline');
    assert.match(enforcement(revision().enforcement), /参考日.*確定した施行日とは扱いません/);
    assert.match(enforcement({api_date: null, pseudo_date: null}), /未定/);
    const page = root(); init(page, {api: 'https://example.test/wp-json/egov-law-monitor/v1/ondemand/', nonce: 'nonce'});
    assert.equal(calls.length, 0, 'initial display is offline');
    page.parts.search.values = {mode: 'title', query: '民法'};
    reply({items: [law], total: 1, next_offset: null});
    page.parts.search.events.submit({preventDefault() {}, currentTarget: page.parts.search}); await tick();
    assert.equal(calls[0].url.searchParams.get('query'), '民法');
    assert.equal(calls[0].options.headers['X-WP-Nonce'], 'nonce');
    assert.equal(calls[0].options.cache, 'no-store');
    assert.equal(page.parts.laws.children[0].children[0].children[0].textContent, `${law.title} ／ ${law.number} ／ ${law.id}`, 'untrusted title stays text');
    reply({items: Array.from({length: 21}, (_, i) => revision(i))});
    buttons(page.parts.laws)[0].click(); await tick();
    assert.equal(buttons(page.parts.revisions).length, 20);
    assert.equal(page.parts['rev-next'].hidden, false);
    const before = calls.length; page.parts['rev-next'].click();
    assert.equal(buttons(page.parts.revisions).length, 1); assert.equal(calls.length, before, 'history paging is local');
    assert.match(page.parts.revisions.textContent, /未施行/);
    buttons(page.parts.revisions)[0].click(); assert.equal(calls.length, before, 'selection alone does not match');
    assert.match(page.parts.selected.textContent, /official_20/);
    const matched = revision(20); matched.internal = {status: 'matched', candidates: [{law_data_id: '123', sub_revision: '3'}], checked_at: '2026-11-17T00:00:00+00:00'};
    let resolve;
    replies.push(() => new Promise(r => { resolve = r; }));
    buttons(page.parts.selected)[0].click();
    assert.equal(page.attributes['aria-busy'], 'true');
    assert.equal(buttons(page.parts.selected)[0].disabled, true);
    buttons(page.parts.selected)[0].click(); assert.equal(calls.length, before + 1, 'no duplicate pending request');
    resolve({ok: true, json: async () => matched}); await tick();
    assert.equal(calls.at(-1).options.method, 'POST');
    assert.deepEqual(JSON.parse(calls.at(-1).options.body), {law_id: law.id, revision_id: 'official_20'});
    assert.match(page.parts.selected.textContent, /メタデータ上の一意候補.*本文同一性は未確認/);
    assert.match(page.parts.selected.textContent, /123 \/ 3/);
    reply({message: '停止中です'}, false); buttons(page.parts.selected)[0].click(); await tick();
    assert.match(page.parts.status.textContent, /停止中/);
    assert.doesNotMatch(page.parts.selected.textContent, /一意候補/, 'failed recheck does not leave previous matched result');
    assert.equal(page.attributes['aria-busy'], undefined);
    assert.equal(replies.length, 0);
    assert(calls.every(c => !/summary|generate/.test(c.url.pathname)), 'no generation endpoints');
    console.log('PASS: on-demand UI navigation, escaping, dates, paging, nonce, busy/errors; no live fetch');
})().catch(error => { console.error(error); process.exitCode = 1; });
