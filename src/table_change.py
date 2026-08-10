from models import CompareResult, TableChange, TocIndex


def build_table_changes(
    compare_result: CompareResult,
    index: TocIndex,
) -> list[TableChange]:
    """Build detected changes to supplementary tables."""

    names: list[str] = []
    seen: set[str] = set()

    for block in compare_result.blocks:

        if block.change_type == "same":
            continue

        for table_xpath, table_name in index.table_lookup.items():

            if block.xpath.startswith(table_xpath):
                if table_name not in seen:
                    seen.add(table_name)
                    names.append(table_name)
                break

    return [
        TableChange(name=name)
        for name in names
    ]
