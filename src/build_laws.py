"""
Build public law models.
"""

from models import Law, LawGroup

from law_view import create_law_view


def build_laws(
    law_groups: list[LawGroup],
) -> list[Law]:
    """
    Build public Law models.
    """

    return create_law_view(law_groups)