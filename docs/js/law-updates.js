function renderLaws(laws, keywords) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    laws.forEach(law => {

        const latest = law.updates[0];

        const card =
            document.createElement("div");

        card.className = "card";

        const effectiveDate =
            latest.pending
                ? `${latest.effective_date}（未施行）`
                : latest.effective_date;

        card.innerHTML = `

            <h2>
                ${highlightKeywords(law.law_name, keywords)}
            </h2>

            <p>
                <strong>種別</strong>
                ${law.law_type}
            </p>

            <p>
                <strong>公布日</strong>
                ${latest.published_date}
            </p>

            <p>
                <strong>施行日</strong>
                ${effectiveDate}
            </p>

            <p>

                <a
                    href="${law.url}"
                    class="button"
                    target="_blank"
                >
                    e-Govで見る
                </a>

            </p>

        `;

        container.appendChild(card);

    });

}

async function main() {

    const laws =
        await fetchJson(
            "data/laws.json"
        );

    const keywords =
        await fetchJson(
            "data/keywords.json"
        );

    renderLaws(
        laws,
        keywords,
    );

}

main();