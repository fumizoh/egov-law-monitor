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


    /*
     * Watch keyword
     */
    const watchForm = document.getElementById('egov-law-watch-form');

    if (watchForm) {
        watchForm.addEventListener('submit', async (event) => {
            event.preventDefault();

            const input =
                document.getElementById('egov-law-watch-input');

            if (!input) {
                return;
            }

            const keyword = input.value.trim();

            const submitButton =
                watchForm.querySelector('button[type="submit"]');

            if (!submitButton) {
                return;
            }

            const restUrl = egovLawMonitor.restUrl;

            const restNonce =
                window.egovLawMonitor?.restNonce;

            if (!restNonce) {
                alert('ウォッチ登録に必要な情報を取得できませんでした。');
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = '登録中…';

            try {
                const response = await fetch(restUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': restNonce,
                    },
                    body: JSON.stringify({
                        keyword: keyword,
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

                submitButton.disabled = false;
                submitButton.textContent = 'このキーワードをウォッチ';

                alert(
                    error.message ||
                    'ウォッチ登録に失敗しました。'
                );
            }
        });
    }


    /*
     * Unwatch keyword
     */
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


    /*
    * Check target laws
    */
    const targetButtons = document.querySelectorAll(
        '.egov-law-watch-targets-button'
    );

    targetButtons.forEach((button) => {
        button.addEventListener('click', async () => {

            const keyword = button.dataset.keyword;
            const targetContainer = button
                .closest('.egov-law-watch')
                ?.querySelector('.egov-law-watch-targets');

            if (!keyword || !targetContainer) {
                return;
            }

            const title = targetContainer.querySelector('h4');
            const list = targetContainer.querySelector('ul');
            const lawSearchUrl = window.egovLawMonitor?.lawSearchUrl;

            if (!title || !list || !lawSearchUrl) {
                return;
            }

            button.disabled = true;
            button.textContent = '確認中…';

            try {
                const url =
                    `${lawSearchUrl}?query=${encodeURIComponent(keyword)}`;

                const response = await fetch(url);

                if (!response.ok) {
                    throw new Error(
                        '対象法令の取得に失敗しました。'
                    );
                }

                const laws = await response.json();

                title.textContent =
                    `「${keyword}」の対象法令（${laws.length}件）`;

                const perPage = 20;
                let currentPage = 1;
                const totalPages = Math.ceil(laws.length / perPage);

                let pagination = targetContainer.querySelector(
                    '.egov-law-watch-pagination'
                );

                if (!pagination) {
                    pagination = document.createElement('div');
                    pagination.className =
                        'egov-law-watch-pagination';
                    targetContainer.appendChild(pagination);
                }

                const renderPage = (page) => {
                    currentPage = page;
                    list.replaceChildren();

                    if (laws.length === 0) {
                        const item = document.createElement('li');
                        item.textContent = '該当する法令がありません。';
                        list.appendChild(item);
                    } else {
                        const start = (currentPage - 1) * perPage;
                        const pageLaws = laws.slice(
                            start,
                            start + perPage
                        );

                        pageLaws.forEach((law) => {
                            const item = document.createElement('li');
                            item.textContent = law.law_name;
                            list.appendChild(item);
                        });
                    }

                    pagination.replaceChildren();

                    if (totalPages <= 1) {
                        return;
                    }

                    const createButton = (label, page, disabled = false) => {
                        const pageButton =
                            document.createElement('button');

                        pageButton.type = 'button';
                        pageButton.textContent = label;
                        pageButton.disabled = disabled;

                        pageButton.addEventListener('click', () => {
                            renderPage(page);
                        });

                        return pageButton;
                    };

                    pagination.appendChild(
                        createButton(
                            '前へ',
                            currentPage - 1,
                            currentPage === 1
                        )
                    );

                    const pageInfo = document.createElement('span');
                    pageInfo.textContent =
                        `${currentPage} / ${totalPages}`;
                    pagination.appendChild(pageInfo);

                    pagination.appendChild(
                        createButton(
                            '次へ',
                            currentPage + 1,
                            currentPage === totalPages
                        )
                    );
                };

                renderPage(1);

                targetContainer.hidden = false;

            } catch (error) {
                console.error(error);
                alert(
                    error.message ||
                    '対象法令の取得に失敗しました。'
                );
            } finally {
                button.disabled = false;
                button.textContent = '対象法令を確認';
            }
        });
    });
});