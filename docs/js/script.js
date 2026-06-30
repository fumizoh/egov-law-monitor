async function fetchJson(path) {

    const response = await fetch(path);

    if (!response.ok) {
        throw new Error(`Failed to load ${path}`);
    }

    return await response.json();

}

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

function summarizeUpdates(updates) {

    const summary = new Map();

    updates.forEach(item => {

        const lawName = item["法令名"];

        summary.set(
            lawName,
            (summary.get(lawName) || 0) + 1
        );

    });

    return summary;

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

    document.getElementById("update-date").textContent =
        formatDate(statistics.last_update);

    document.getElementById("update-count").textContent =
        statistics.update_count;

    const div =
        document.getElementById("law-type-summary");

    div.innerHTML = "";

    Object.entries(statistics.law_type)
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

function renderUpdates(updates) {

    const ul =
        document.getElementById("update-list");

    ul.innerHTML = "";

    const summary = summarizeUpdates(updates);

    summary.forEach((count, name) => {

        const li = document.createElement("li");

        li.textContent =
            count === 1
                ? name
                : `${name}（${count}件）`;

        ul.appendChild(li);

    });

}

async function loadUpdates() {

    const updates =
        await fetchJson("data/updates.json");

    renderUpdates(updates);

}

async function main() {

    await loadAppInfo();

    await loadStatistics();

    await loadUpdates();

}

main();