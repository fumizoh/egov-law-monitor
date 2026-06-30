async function fetchJson(path) {

    const response = await fetch(path);

    if (!response.ok) {
        throw new Error(`Failed to load ${path}`);
    }

    return await response.json();

}

function renderAppInfo(app) {

    document.getElementById("version").textContent =
        "v" + app.latest_version;

    document.getElementById("release-date").textContent =
        app.release_date;

    document.getElementById("repo-link").href =
        app.repository;

    document.getElementById("release-link").href =
        app.download_url;

    const ul = document.getElementById("changes");

    ul.innerHTML = "";

    app.changes.forEach(change => {

        const li = document.createElement("li");

        li.textContent = change;

        ul.appendChild(li);

    });

}

async function loadAppInfo() {

    const app = await fetchJson("data/app.json");

    renderAppInfo(app);

}

function renderStatistics(statistics) {

    document.getElementById("update-date").textContent =
        statistics.last_update;

    document.getElementById("update-count").textContent =
        statistics.update_count;

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

    updates.forEach(item => {

        const li =
            document.createElement("li");

        li.textContent =
            item["法令名"];

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