"""
Build public Law models.
"""

from models import (
    Law,
    LawGroup,
)

from law_builder import create_law


def build_law(
    group: LawGroup,
) -> Law:
    """
    Build one public Law model.
    """

    return create_law(group)


def build_laws(
    law_groups: list[LawGroup],
) -> list[Law]:
    """
    Build public Law models.
    """

    laws: list[Law] = []

    for group in law_groups:
        laws.append(build_law(group))

    return laws