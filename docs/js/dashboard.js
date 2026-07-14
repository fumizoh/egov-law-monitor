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

        const lawName = item.title;

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

    const egov = statistics.egov;

    const publicComment =
        statistics.public_comment;

    document.getElementById("update-date").textContent =
        formatDate(egov.last_update);

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

    renderPublicCommentSummary(
        statistics
    );

}

function renderPublicCommentSummary(
    statistics
) {

    const publicComment =
        statistics.public_comment;

    document.getElementById(
        "public-date"
    ).textContent =
        publicComment.last_update;

    document.getElementById(
        "public-count"
    ).textContent =
        publicComment.update_count;

    const div =
        document.getElementById(
            "public-category-summary"
        );

    div.innerHTML = "";

    Object.entries(
        publicComment.category
    ).forEach(([name, count]) => {

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

function renderUpdates(updates, keywords) {

    const ul =
        document.getElementById("update-list");

    ul.innerHTML = "";

    const summary = summarizeUpdates(updates);

    summary.forEach((count, name) => {

        const li = document.createElement("li");

        li.innerHTML =
            count === 1
                ? highlightKeywords(name, keywords)
                : `${highlightKeywords(name, keywords)}（${count}件）`;

        ul.appendChild(li);

    });

}

async function loadUpdates() {

    const updates =
        await fetchJson("data/egov_updates.json");

    const keywords = await fetchJson("data/keywords.json");

    renderUpdates(updates, keywords);

}

async function main() {

    await loadAppInfo();

    await loadStatistics();

    await loadUpdates();

}

main();