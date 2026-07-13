async function fetchJson(path) {

    const response = await fetch(path);

    if (!response.ok) {

        throw new Error(`Failed to load ${path}`);

    }

    return await response.json();

}

function groupUpdates(updates) {

    const grouped = {};

    updates.forEach(update => {

        const lawName = update["法令名"];

        if (!grouped[lawName]) {

            grouped[lawName] = {

                lawInfo: {

                    name: update["法令名"],
                    type: update["法令種別"],
                    publishDate: update["公布日"],
                    url: update["本文URL"]

                },

                updates: []

            };

        }

        grouped[lawName].updates.push({

            revisedLawName: update["改正法令名"],
            revisedPublishDate: update["改正法令公布日"],
            enforcementDate: update["施行日"],
            url: update["本文URL"]

        });

    });

    return grouped;

}

function renderUpdates(updates) {

    const container =
        document.getElementById("updates-detail");

    container.innerHTML = "";

    const grouped = groupUpdates(updates);

    console.log(grouped);

    Object.values(grouped).forEach(group => {

        const updatesHtml = group.updates
            .map((item, index) => {

                const divider =
                    index < group.updates.length - 1
                        ? '<hr class="update-divider">'
                        : '';

                return `

                    <div class="update-item">

                        <p>
                            <strong class="update-label">改正法令</strong><br>
                            ${item.revisedLawName}
                        </p>

                        <p>
                            <strong class="update-label">公布日</strong>
                            ${item.revisedPublishDate}
                        </p>

                        <p>
                            <strong class="update-label">施行日</strong>
                            ${item.enforcementDate}
                        </p>

                    </div>

                    ${divider}

                `;

            })
            .join("");

        const card =
            document.createElement("div");

        card.className = "card";

        card.innerHTML = `

            <h2>
                ${group.lawInfo.name}
                ${group.updates.length > 1
                ? `（${group.updates.length}件）`
                : ""}
            </h2>

            <h3>法令情報</h3>

            <hr>

            <p>
                <strong>種別</strong>
                ${group.lawInfo.type}
            </p>

            <p>
                <strong>公布日</strong>
                ${group.lawInfo.publishDate}
            </p>

            <hr>

            <h3>今回の更新</h3>

            ${updatesHtml}

            <p>

                <a
                    href="${group.lawInfo.url}"
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