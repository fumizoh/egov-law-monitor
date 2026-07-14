function renderUpdates(updates, keywords) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    updates.forEach(update => {

        const card =
            document.createElement("div");

        card.className = "card";

        const effectiveDate =
            update.metadata.future
                ? `${update.metadata.effective_date}（未施行）`
                : update.metadata.effective_date;

        card.innerHTML = `

            <h2>
                ${highlightKeywords(update.title, keywords)}
            </h2>

            <p>
                <strong>種別</strong>
                ${update.metadata.law_type}
            </p>

            <p>
                <strong>公布日</strong>
                ${update.metadata.published_date}
            </p>

            <p>
                <strong>施行日</strong>
                ${effectiveDate}
            </p>

            <p>

                <a
                    href="${update.url}"
                    class="button"
                    target="_blank"
                >
                    e-Gov本文を見る
                </a>

            </p>

        `;

        container.appendChild(card);

    });

}

async function main() {

    const updates =
        await fetchJson(
            "data/egov_updates.json"
        );

    const keywords =
        await fetchJson(
            "data/keywords.json"
        );

    renderUpdates(
        updates,
        keywords,
    );

}

main();