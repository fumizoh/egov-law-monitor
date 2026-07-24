from models import RevisionHistory


def find_revision(
    amendment_num: str,
    revisions: list[RevisionHistory],
) -> RevisionHistory | None:

    for revision in revisions:
        if revision.amendment_num == amendment_num:
            return revision

    return None