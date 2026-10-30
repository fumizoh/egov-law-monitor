const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const pluginSource = fs.readFileSync(
    path.resolve(__dirname, '../../wordpress-plugin/egov-law-monitor.php'),
    'utf8'
);
const inlineScript = pluginSource.match(/<<<'JS'\r?\n([\s\S]*?)\r?\nJS/)[1];

async function clickAiSummaryButton(apiResponse, legacySettings = {}) {
    const alerts = [];
    const elements = new Map();
    let readyHandler;
    let clickHandler;
    let modal;
    let destination = '';

    const button = {
        dataset: {
            lawId: 'test-law',
            lawName: 'テスト法令',
            effectiveDate: '2026-01-01',
            lawDataId: 'test-data',
            subRevision: 'test-revision',
            amendmentName: '改正法令',
            isNewLaw: 'false',
        },
        addEventListener(eventName, handler) {
            if (eventName === 'click') clickHandler = handler;
        },
    };

    const document = {
        readyState: 'loading',
        addEventListener(eventName, handler) {
            if (eventName === 'DOMContentLoaded') readyHandler = handler;
        },
        querySelectorAll() {
            return [button];
        },
        getElementById() {
            return null;
        },
        createElement() {
            const created = {
                attributes: {},
                listeners: {},
                setAttribute(name, value) {
                    this.attributes[name] = value;
                },
                querySelector(selector) {
                    if (!elements.has(selector)) {
                        elements.set(selector, {
                            addEventListener(eventName, handler) {
                                this.listeners ??= {};
                                this.listeners[eventName] = handler;
                            },
                            focus() {},
                        });
                    }
                    return elements.get(selector);
                },
                remove() {
                    this.removed = true;
                },
            };
            Object.defineProperty(created, 'innerHTML', {
                set(value) { this.html = value; },
            });
            return created;
        },
        body: {
            appendChild(element) { modal = element; },
        },
    };
    const window = {
        egovLawMonitor: {
            aiSummary: {
                isLoggedIn: true,
                url: '/summary',
                usageUrl: '/usage',
                restNonce: 'test-nonce',
                ...legacySettings,
            },
        },
        location: {
            origin: 'https://example.test',
            set href(value) { destination = value; },
            get href() { return destination; },
        },
        alert(message) { alerts.push(message); },
    };

    vm.runInNewContext(inlineScript, {
        document,
        window,
        URL,
        fetch: async () => ({
            ok: apiResponse.ok !== false,
            json: async () => apiResponse.data,
        }),
    });

    readyHandler();
    await clickHandler();
    return { alerts, elements, modal, getDestination: () => destination };
}

test('uses Standard wording and quota when legacy metadata says Free', async () => {
    const result = await clickAiSummaryButton(
        {
            data: {
                effectivePlan: 'standard',
                usagePeriod: 'calendar_month',
                remaining: 7,
                limit: 100,
                alreadyUsed: false,
                historyId: 0,
            },
        },
        { isFreePlan: true, remaining: 2, limit: 3 }
    );

    assert.match(result.modal.html, /今月のAI要約の残り/);
    assert.match(result.modal.html, /7 \/ 100回/);
    assert.doesNotMatch(result.modal.html, /お試し利用/);
});

test('uses Free wording and quota when legacy metadata says Standard', async () => {
    const result = await clickAiSummaryButton(
        {
            data: {
                effectivePlan: 'free',
                usagePeriod: 'lifetime',
                remaining: 2,
                limit: 3,
                alreadyUsed: false,
                historyId: 0,
            },
        },
        { isFreePlan: false, remaining: 0, limit: 100 }
    );

    assert.match(result.modal.html, /お試し利用の残り/);
    assert.match(result.modal.html, /2 \/ 3回/);
    assert.doesNotMatch(result.modal.html, /今月のAI要約の残り/);
});

test('does not fall back to legacy plan values when usage API fails', async () => {
    const result = await clickAiSummaryButton(
        { ok: false, data: {} },
        { isFreePlan: false, remaining: 100, limit: 100 }
    );

    assert.equal(result.modal, undefined);
    assert.equal(result.alerts.length, 1);
    assert.match(result.alerts[0], /利用状況を取得できませんでした/);
});

test('keeps the AI summary confirmation navigation', async () => {
    const result = await clickAiSummaryButton({
        data: {
            effectivePlan: 'standard',
            usagePeriod: 'calendar_month',
            remaining: 1,
            limit: 100,
            alreadyUsed: false,
            historyId: 0,
        },
    });
    result.elements.get('.egov-ai-summary-modal-confirm').listeners.click();

    const destination = new URL(result.getDestination());
    assert.equal(destination.pathname, '/summary');
    assert.equal(destination.searchParams.get('law_id'), 'test-law');
    assert.equal(destination.searchParams.get('effective_date'), '2026-01-01');
});
