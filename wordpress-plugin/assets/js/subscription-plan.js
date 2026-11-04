(function () {
    function initSubscriptionCheckout() {
        const settings = window.egovSubscriptionPlan;
        const buttons = document.querySelectorAll('.egov-subscription-checkout-button');
        if (!settings || !settings.restUrl || !settings.restNonce || !buttons.length) {
            return;
        }

        buttons.forEach((button) => {
            button.addEventListener('click', async () => {
                if (button.disabled) {
                    return;
                }

                const status = button.parentElement.querySelector('.egov-subscription-checkout-status');
                const originalLabel = button.textContent;
                button.disabled = true;
                button.textContent = '決済ページを準備しています…';
                if (status) {
                    status.textContent = '';
                }

                try {
                    const response = await fetch(settings.restUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'X-WP-Nonce': settings.restNonce,
                            'Accept': 'application/json',
                        },
                    });
                    const payload = await response.json();

                    if (!response.ok) {
                        if (payload && payload.code === 'checkout_status_pending') {
                            throw new Error('決済状況を確認中です。しばらくしてからお試しください。');
                        }
                        if (payload && payload.code === 'checkout_standard_active') {
                            throw new Error('Standardプランはすでに有効です。ページを再読み込みしてください。');
                        }
                        throw new Error('決済ページを準備できませんでした。時間をおいて再度お試しください。');
                    }

                    const checkoutUrl = new URL(payload.checkout_url);
                    const allowedHosts = ['square.link', 'checkout.square.site'];
                    if (
                        checkoutUrl.protocol !== 'https:' ||
                        !allowedHosts.includes(checkoutUrl.hostname.toLowerCase()) ||
                        checkoutUrl.username ||
                        checkoutUrl.password ||
                        checkoutUrl.port
                    ) {
                        throw new Error('安全な決済ページを確認できませんでした。時間をおいて再度お試しください。');
                    }

                    window.location.assign(checkoutUrl.href);
                } catch (error) {
                    if (status) {
                        status.textContent = error instanceof Error
                            ? error.message
                            : '決済ページを準備できませんでした。時間をおいて再度お試しください。';
                    }
                    button.disabled = false;
                    button.textContent = originalLabel;
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSubscriptionCheckout);
    } else {
        initSubscriptionCheckout();
    }
})();
