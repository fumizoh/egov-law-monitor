const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(
    path.resolve(__dirname, '../../wordpress-plugin/assets/js/subscription-plan.js'),
    'utf8'
);

async function clickCheckout(response, buttonCount = 1) {
    const clickHandlers = [];
    const requests = [];
    let destination = '';
    const buttons = Array.from({ length: buttonCount }, () => {
        const status = { textContent: '' };
        return {
            disabled: false,
            textContent: 'Standardプランに申し込む',
            status,
            parentElement: {
                querySelector(selector) {
                    return selector === '.egov-subscription-checkout-status' ? status : null;
                },
            },
            addEventListener(name, handler) {
                if (name === 'click') clickHandlers.push(handler);
            },
        };
    });
    const document = {
        readyState: 'complete',
        querySelectorAll() { return buttons; },
        addEventListener() {},
    };
    const window = {
        egovSubscriptionPlan: {
            restUrl: '/wp-json/egov-law-monitor/v1/subscription-checkout',
            restNonce: 'test-nonce',
        },
        location: {
            assign(value) { destination = value; },
        },
    };

    vm.runInNewContext(script, {
        document,
        window,
        URL,
        fetch: async (url, options) => {
            requests.push({ url, options });
            return response;
        },
    });
    for (const clickHandler of clickHandlers) {
        await clickHandler();
    }
    return { buttons, destination, requests };
}

test('POSTs with the WordPress REST nonce and redirects only to a Square checkout URL', async () => {
    const result = await clickCheckout({
        ok: true,
        json: async () => ({ checkout_url: 'https://square.link/u/checkout-test' }),
    });

    assert.equal(result.requests[0].options.method, 'POST');
    assert.equal(result.requests[0].options.credentials, 'same-origin');
    assert.equal(result.requests[0].options.headers['X-WP-Nonce'], 'test-nonce');
    assert.equal(Object.hasOwn(result.requests[0].options, 'body'), false);
    assert.equal(result.destination, 'https://square.link/u/checkout-test');
});

test('redirects to the exact Square Sandbox checkout host', async () => {
    const result = await clickCheckout({
        ok: true,
        json: async () => ({ checkout_url: 'https://sandbox.square.link/u/sandbox-checkout' }),
    });

    assert.equal(result.destination, 'https://sandbox.square.link/u/sandbox-checkout');
});

test('shows the server reconciliation message and restores the button after API error', async () => {
    const result = await clickCheckout({
        ok: false,
        json: async () => ({ code: 'checkout_status_pending' }),
    });

    assert.match(result.buttons[0].status.textContent, /決済状況を確認中です/);
    assert.equal(result.destination, '');
    assert.equal(result.buttons[0].disabled, false);
    assert.equal(result.buttons[0].textContent, 'Standardプランに申し込む');
});

test('does not navigate to a non-Square or insecure URL', async () => {
    const result = await clickCheckout({
        ok: true,
        json: async () => ({ checkout_url: 'https://attacker.example/checkout' }),
    });

    assert.equal(result.destination, '');
    assert.match(result.buttons[0].status.textContent, /安全な決済ページ/);
});

test('registers checkout handling for every repeated shortcode instance', async () => {
    const result = await clickCheckout({
        ok: true,
        json: async () => ({ checkout_url: 'https://checkout.square.site/pay/test' }),
    }, 2);

    assert.equal(result.requests.length, 2);
    assert.equal(result.destination, 'https://checkout.square.site/pay/test');
});
