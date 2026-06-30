def create_statistics(updates, latest_date):
    """
    更新情報のサマリーを作成する。
    """

    return {
        "last_update": latest_date,
        "update_count": len(updates),
    }