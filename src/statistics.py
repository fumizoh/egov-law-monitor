def create_statistics(
    updates,
    latest_date
):

    return {

        "last_update": latest_date,

        "update_count": len(updates)

    }