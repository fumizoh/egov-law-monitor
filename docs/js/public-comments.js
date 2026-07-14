function renderPublicComments(comments) {

    const container =
        document.getElementById(
            "public-comments-detail"
        );

    container.innerHTML = "";

    comments.forEach(comment => {

        const card =
            document.createElement("div");

        card.className = "card";

        card.innerHTML = `

            <h2>

                ${comment.title}

            </h2>

            <p>

                <strong>公示日</strong>

                ${comment.metadata.published_date}

            </p>

            <p>

                <strong>締切</strong>

                ${comment.metadata.deadline}

            </p>

            <p>

                <strong>カテゴリ</strong>

                ${comment.metadata.category}

            </p>

            <p>

                <a
                    href="${comment.url}"
                    class="button"
                    target="_blank"
                >
                    e-Govで見る
                </a>

            </p>

        `;

        container.appendChild(card);

    });

}

async function main() {

    const comments =
        await fetchJson(
            "data/public_comments.json"
        );

    renderPublicComments(
        comments
    );

}

main();