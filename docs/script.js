async function loadAppInfo() {

    const response = await fetch("data/app.json");

    const app = await response.json();

    document.getElementById("version").textContent =
        "v" + app.latest_version;

    document.getElementById("release-date").textContent =
        app.release_date;

    const ul = document.getElementById("changes");

    ul.innerHTML = "";

    app.changes.forEach(change => {

        const li = document.createElement("li");

        li.textContent = change;

        ul.appendChild(li);

    });

}

loadAppInfo();