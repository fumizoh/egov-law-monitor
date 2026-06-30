async function fetchJson(path) {

    const response = await fetch(path);

    if (!response.ok) {

        throw new Error(`Failed to load ${path}`);

    }

    return await response.json();

}

function renderUpdates(updates) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    updates.forEach(update => {

        const card =
            document.createElement("div");

        card.className = "card";

        card.innerHTML = `
            <h2>${update["法令名"]}</h2>

            <p><strong>種別 </strong>${update["法令種別"]}</p>

            <p><strong>施行日 </strong>${update["施行日"]}</p>

            <p><strong>公布日 </strong>${update["公布日"]}</p>

            <p>
                <a
                    href="${update["本文URL"]}"
                    class="button"
                    target="_blank"
                >
                    e-Gov本文を見る
                </a>
            </p>
        `;

        container.appendChild(card);

    });

}

async function main() {

    const updates =
        await fetchJson("data/updates.json");

    renderUpdates(updates);

}

main();