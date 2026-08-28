document.addEventListener('DOMContentLoaded', async () => {
    const lastChecked =
        document.getElementById('egov-law-watch-last-checked');

    if (lastChecked) {
        try {
            const response = await fetch(
                `${egovLawMonitor.dataUrl}data/watch_status.json`
            );

            if (!response.ok) {
                throw new Error('Watch status could not be loaded.');
            }

            const data = await response.json();

            if (!data.last_checked) {
                lastChecked.textContent = '未確認';
            } else {
                const date = new Date(data.last_checked);

                lastChecked.textContent =
                    new Intl.DateTimeFormat('ja-JP', {
                        year: 'numeric',
                        month: 'numeric',
                        day: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                    }).format(date);
            }
        } catch (error) {
            console.error(error);
            lastChecked.textContent = '確認できません';
        }
    }

    const buttons = document.querySelectorAll(
        '.egov-law-watch-button'
    );

    buttons.forEach((button) => {
        button.addEventListener('click', async () => {

            const lawId = button.dataset.lawId;
            const lawName = button.dataset.lawName;
            const lawNo = button.dataset.lawNo;
            const lawType = button.dataset.lawType;
            const restUrl = button.dataset.restUrl;
            const restNonce = button.dataset.restNonce;

            if (
                !lawId
                || !lawName
                || !lawNo
                || !lawType
                || !restUrl
                || !restNonce
            ) {
                return;
            }

            button.disabled = true;
            button.textContent = '登録中…';

            try {
                const response = await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce,
                    },
                    body: JSON.stringify({
                        law_id: lawId,
                        law_name: lawName,
                        law_no: lawNo,
                        law_type: lawType,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(
                        data.message || 'ウォッチ登録に失敗しました。'
                    );
                }

                window.location.reload();

            } catch (error) {
                console.error(error);

                button.disabled = false;
                button.textContent = 'ウォッチする';

                alert(
                    error.message ||
                    'ウォッチ登録に失敗しました。'
                );
            }
        });
    });


    const unwatchButtons = document.querySelectorAll(
        '.egov-law-unwatch-button'
    );

    unwatchButtons.forEach((button) => {
        button.addEventListener('click', async () => {

            const restUrl = button.dataset.restUrl;
            const restNonce = button.dataset.restNonce;

            if (!restUrl || !restNonce) {
                return;
            }

            button.disabled = true;
            button.textContent = '解除中…';

            try {
                const response = await fetch(restUrl, {
                    method: 'DELETE',
                    headers: {
                        'X-WP-Nonce': restNonce,
                    },
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(
                        data.message || 'ウォッチ解除に失敗しました。'
                    );
                }

                window.location.reload();

            } catch (error) {
                console.error(error);

                button.disabled = false;
                button.textContent = 'ウォッチ解除';

                alert(
                    error.message ||
                    'ウォッチ解除に失敗しました。'
                );
            }
        });
    });
});