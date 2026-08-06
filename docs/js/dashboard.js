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

    //    document.getElementById("update-date").textContent =
    //        formatDate(egov.last_update);

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

function renderDailySummary(summary) {

    document.getElementById(
        "daily-summary-title"
    ).textContent =
        summary.summary.title;

    document.getElementById(
        "daily-summary-body"
    ).textContent =
        summary.summary.body;

}

async function loadDailySummary() {

    const summary =
        await fetchJson(
            "data/daily_summary.json"
        );

    renderDailySummary(summary);

}

function renderLaws(laws, keywords) {

    const ul =
        document.getElementById("update-list");

    ul.innerHTML = "";

    laws.forEach((law) => {

        const li =
            document.createElement("li");

        const count =
            law.updates.length;

        li.innerHTML =
            count === 1
                ? highlightKeywords(
                    law.law_name,
                    keywords,
                )
                : `${highlightKeywords(
                    law.law_name,
                    keywords,
                )}（${count}件）`;

        ul.appendChild(li);

    });

}

async function loadLaws() {

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

async function main() {

    await loadAppInfo();

    await loadStatistics();

    await loadDailySummary();

    await loadLaws();

}

main();