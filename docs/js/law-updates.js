function renderLaws(
    laws,
    lawSummaries,
    keywords,
) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    const summaryMap = new Map(
        lawSummaries.map(summary => [
            summary.summary_input.law_id,
            summary,
        ])
    );

    laws.forEach(law => {

        const summary =
            summaryMap.get(
                law.law_id,
            );

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
                <strong>種別</strong>
                ${law.law_type}
            </p>

            ${summary ? `

                <div class="ai-summary">

                    <h3>
                        🤖 ${summary.response.summary.title}
                    </h3>

                    <div class="summary-body">
                        ${summary.response.summary.body}
                    </div>

                </div>

            ` : ""}

            <details>

                <summary>
                    今回の更新（${law.updates.length}件）
                </summary>

                <p>
                    <strong>施行済</strong>
                    ${activeCount}件
                    /
                    <strong>未施行</strong>
                    ${pendingCount}件
                </p>

                ${law.updates.map(update => `

                    <div class="update-history">

                        <p>
                            ${update.pending
                ? `${update.effective_date}（未施行）`
                : update.effective_date
            }
                        </p>

                        <p>
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

    const [
        laws,
        lawSummaries,
        keywords,
    ] = await Promise.all([
        fetchJson("data/laws.json"),
        fetchJson("data/law_summaries.json"),
        fetchJson("data/keywords.json"),
    ]);

    renderLaws(
        laws,
        lawSummaries,
        keywords,
    );

}

main();