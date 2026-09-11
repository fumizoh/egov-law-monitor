function openLawCard(card) {
    const details = card.querySelector('.egov-update-details');

    if (details) {
        details.open = true;
    }
}


function scrollToLawCard(card) {
    const title = card.querySelector('.egov-law-name');

    if (!title) {
        return;
    }

    title.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}


// ページ内の法令リンクをクリックした場合
document.addEventListener('click', (event) => {
    const link = event.target.closest('a[href^="#law-"]');

    if (!link) {
        return;
    }

    const targetId = decodeURIComponent(
        link.getAttribute('href').slice(1)
    );

    const card = document.getElementById(targetId);

    if (!card) {
        return;
    }

    openLawCard(card);
});


// 直接リンクでページを開いた場合
document.addEventListener('DOMContentLoaded', () => {
    const targetId = decodeURIComponent(
        window.location.hash.slice(1)
    );

    if (!targetId.startsWith('law-')) {
        return;
    }

    const card = document.getElementById(targetId);

    if (!card) {
        return;
    }

    openLawCard(card);

    setTimeout(() => {
        scrollToLawCard(card);
    }, 100);
});