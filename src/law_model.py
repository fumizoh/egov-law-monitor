"""
Application data model.

Converts raw CSV rows into the application's internal data model.
"""


def build_laws(rows):
    """
    Build Law objects from raw CSV rows.

    Currently this is a pass-through implementation.
    Grouping by law_id will be added in v0.6.0.
    """

    return rows