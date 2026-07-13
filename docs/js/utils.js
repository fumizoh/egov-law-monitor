async function fetchJson(path) {

    const response = await fetch(path);

    if (!response.ok) {

        throw new Error(`Failed to load ${path}`);

    }

    return await response.json();

}

function highlightKeywords(text, keywords) {

    if (!text || !keywords || keywords.length === 0) {
        return text;
    }

    let result = text;

    // 長いキーワードを優先して置換
    const sortedKeywords = [...keywords].sort(
        (a, b) => b.length - a.length
    );

    sortedKeywords.forEach(keyword => {

        if (!keyword) {
            return;
        }

        // 正規表現で使われる文字をエスケープ
        const escaped = keyword.replace(
            /[.*+?^${}()|[\]\\]/g,
            "\\$&"
        );

        result = result.replace(
            new RegExp(escaped, "g"),
            `<mark>${keyword}</mark>`
        );

    });

    return result;

}