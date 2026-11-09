/* Confirmation is a UI safeguard; the server still validates nonce, owner and state. */
(() => {
    'use strict';
    document.querySelectorAll('.egov-standard-cancel-form').forEach((form) => {
        const dialog = form.querySelector('dialog');
        const open = form.querySelector('[data-cancel-open]');
        const back = form.querySelector('[data-cancel-back]');
        const confirm = form.querySelector('[data-cancel-confirm]');
        const confirmed = form.querySelector('[name="checkout_cancel_confirmed"]');
        let authorized = false;
        let submitted = false;
        // Fail closed when JavaScript or native dialog/form support is unavailable.
        if (!dialog || typeof dialog.showModal !== 'function' || typeof form.requestSubmit !== 'function') return;
        confirmed.value = '';
        form.addEventListener('submit', (event) => {
            if (submitted || !authorized) {
                event.preventDefault();
                if (!submitted && !dialog.open) dialog.showModal();
                return;
            }
            submitted = true;
            form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
            dialog.close();
        });
        open.addEventListener('click', () => {
            if (!submitted && !dialog.open) dialog.showModal();
        });
        back.addEventListener('click', () => { dialog.close(); });
        confirm.addEventListener('click', () => {
            if (submitted || !dialog.open) return;
            authorized = true;
            confirmed.value = '1';
            form.requestSubmit();
        });
        // Back/ESC never authorizes a later submission.
        dialog.addEventListener('close', () => {
            if (!submitted) { authorized = false; confirmed.value = ''; }
        });
        form.querySelector('[data-cancel-unavailable]').hidden = true;
        open.disabled = false;
    });
})();
