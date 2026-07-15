function renderLaws(laws, keywords) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    laws.forEach(law => {

        const latest = law.updates[0];

        const pendingCount =
            law.updates.filter(
                update => update.pending
            ).length;

        const activeCount =
            law.updates.length - pendingCount;

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
                <strong>施行済</strong>
                ${activeCount}件
                /
                <strong>未施行</strong>
                ${pendingCount}件
            </p>

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

            <details>

                <summary>
                    改正履歴（${law.updates.length}件）
                </summary>

                ${law.updates.map(update => `

                    <div class="update-history">

                        <p>
                            <strong>施行日</strong>
                            ${update.pending
                ? `${update.effective_date}（未施行）`
                : update.effective_date
            }
                        </p>

                        <p>
                            <strong>改正法令</strong>
                            ${update.amend_name}
                        </p>

                    </div>

                `).join("")}

            </details>

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