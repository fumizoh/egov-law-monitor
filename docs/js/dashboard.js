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

    document.getElementById("update-date").textContent =
        formatDate(egov.last_update);

    document.getElementById("update-count").textContent =
        egov.update_count;

    document.getElementById("updated-law-count").textContent =
        egov.updated_law_count;

    const div =
        document.getElementById("law-type-summary");

    div.innerHTML = "";

    Object.entries(egov.law_type)
        .forEach(([name, count]) => {

            const p =
                document.createElement("p");

            p.textContent =
                `${name}：${count}件（${egov.law_count[name]}法令）`;

            div.appendChild(p);

        });

}

async function loadStatistics() {

    const statistics =
        await fetchJson("data/statistics.json");

    renderStatistics(statistics);

}

function renderLaws(laws) {

    const ul =
        document.getElementById("update-list");

    ul.innerHTML = "";

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

        ul.appendChild(li);

    });

}

async function loadLaws() {

    const laws = await fetchJson("data/laws.json");

    renderLaws(laws);

}

async function main() {

    await loadAppInfo();

    await loadStatistics();

    await loadLaws();

}

main();