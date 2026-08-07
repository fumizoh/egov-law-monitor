function formatDate(dateString) {

    if (!dateString || dateString.length !== 8) {
        return dateString;
    }

    return (
        dateString.substring(0, 4) + "-" +
        dateString.substring(4, 6) + "-" +
        dateString.substring(6, 8)
    );

}

function renderAppInfo(app) {

    document.getElementById("app-version").textContent =
        "v" + app.latest_version;

    document.getElementById("release-date").textContent =
        app.release_date;

    document.getElementById("repo-link").href =
        app.repository;

    document.getElementById("release-link").href =
        app.download_url;

}

async function loadAppInfo() {

    const app = await fetchJson("data/app.json");

    renderAppInfo(app);

}

function renderStatistics(statistics) {

    const egov = statistics.egov;

    const publicComment =
        statistics.public_comment;

    document.getElementById("update-count").textContent =
        egov.update_count;

    const div =
        document.getElementById("law-type-summary");

    div.innerHTML = "";

    Object.entries(egov.law_type)
        .forEach(([name, count]) => {

            const p =
                document.createElement("p");

            p.textContent =
                `${name}：${count}件`;

            div.appendChild(p);

        });

}

async function loadStatistics() {

    const statistics =
        await fetchJson("data/statistics.json");

    renderStatistics(statistics);

}

function renderLaws(
    laws,
    summaries,
) {

    const ul =
        document.getElementById("update-list");

    ul.innerHTML = "";

    const summaryMap = new Map();

    summaries.forEach(summary => {

        summaryMap.set(
            summary.summary_input.law_id,
            summary.response.summary,
        );

    });

    laws.forEach((law) => {

        const li =
            document.createElement("li");

        const count =
            law.updates.length;

        const link =
            document.createElement("a");

        link.href =
            `law-updates.html#law-${law.law_id}`;

        link.textContent =
            count === 1
                ? law.law_name
                : `${law.law_name}（${count}件）`;

        li.appendChild(link);

        const summary =
            summaryMap.get(
                law.law_id,
            );

        if (summary) {

            const p =
                document.createElement("p");

            p.className =
                "law-summary-title";

            p.textContent =
                summary.title;

            li.appendChild(p);

        }

        ul.appendChild(li);

    });

}

async function loadLaws() {

    const laws =
        await fetchJson(
            "data/laws.json"
        );

    const summaries =
        await fetchJson(
            "data/law_summaries.json"
        );

    renderLaws(
        laws,
        summaries,
    );

}

async function main() {

    await loadAppInfo();

    await loadStatistics();

    await loadLaws();

}

main();