def create_statistics(updates, latest_date):
    """
    更新情報の統計を作成する。
    """

    law_type = {}
    source = {}

    for update in updates:

        src = update["source"]

        source[src] = source.get(src, 0) + 1

        if src == "egov":

            kind = update["metadata"]["law_type"]

            law_type[kind] = law_type.get(kind, 0) + 1

    return {
        "last_update": latest_date,
        "update_count": len(updates),
        "source": source,
        "law_type": law_type,
    }