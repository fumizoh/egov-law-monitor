def create_statistics(updates, latest_date):
    """
    更新情報のサマリーを作成する。
    """

    law_type = {}

    for update in updates:

        kind = update["法令種別"]

        law_type[kind] = law_type.get(kind, 0) + 1

    return {
        "last_update": latest_date,
        "update_count": len(updates),
        "law_type": law_type
    }