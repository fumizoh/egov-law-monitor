function renderLaws(
    laws,
    lawSummaries,
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

        card.id = `law-${law.law_id}`;

        const effectiveDate =
            latest.pending
                ? `${latest.effective_date}（未施行）`
                : latest.effective_date;

        card.innerHTML = `

            <h2>
                ${law.law_name}
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

                ${law.updates.map(update => {

            const effectiveDate =
                update.effective_date
                    ? update.effective_date.replaceAll("-", "")
                    : null;

            const compareUrl =
                update.amendment_id && effectiveDate
                    ? `https://laws.e-gov.go.jp/law/${law.law_id}/${effectiveDate}_${update.amendment_id}?occasion_date=${effectiveDate}&tab=compare`
                    : null;

            return `

                        <div class="update-history">

                            <div class="effective-info">

                                <span class="effective-date">
                                    ${update.pending
                    ? `${update.effective_date}（未施行）`
                    : update.effective_date
                }
                                </span>

                                ${update.effective_comment
                    ? `
                                        <span class="effective-comment">
                                            ${update.effective_comment}
                                        </span>
                                    `
                    : ""
                }

                            </div>

                            <p class="amend-name">
                                ${compareUrl
                    ? `<a href="${compareUrl}" target="_blank" rel="noopener noreferrer">${update.amend_name}　条文比較</a>`
                    : update.amend_name
                }
                            </p>

                        </div>

                    `;
        }).join("")}

            </details>

            <p>

                <a
                    href="https://laws.e-gov.go.jp/law/${law.law_id}"
                    class="button"
                    target="_blank"
                >
                    e-Govで見る
                </a>

            </p>

        `;

        container.appendChild(card);

    });

    const hash =
        window.location.hash;

    if (hash) {

        const target =
            document.querySelector(hash);

        if (target) {

            target.scrollIntoView({
                behavior: "smooth",
                block: "start",
            });

        }

        const details =
            target.querySelector("details");

        if (details) {

            details.open = true;

        }

        setTimeout(() => {

            target.classList.add("target");

            setTimeout(() => {

                target.classList.remove("target");

            }, 3000);

        }, 500);

    }

}

async function main() {

    const [
        laws,
        lawSummaries,
    ] = await Promise.all([
        fetchJson("data/laws.json"),
        fetchJson("data/law_summaries.json"),
    ]);

    renderLaws(
        laws,
        lawSummaries,
    );

}

main();