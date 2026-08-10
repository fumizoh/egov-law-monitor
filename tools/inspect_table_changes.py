from pathlib import Path
import sys

sys.path.append(
    str(Path(__file__).resolve().parents[1] / "src")
)

from comparison import parse_compare_result
from sources.compare_api import fetch_compare
from sources.toc_api import fetch_law_toc
from toc_parser import parse_toc


LAW_ID = "413M60000100001"
LAW_NAME = "厚生労働省組織規則"
LAW_DATA_ID = 641309
SUB_REVISION = "1"


def collect_table_nodes(
    node,
    *,
    xpath: str = "",
    results: list[dict],
) -> None:
    """Collect TOC nodes that appear to represent tables."""

    if isinstance(node, dict):
        object_id = node.get("-ObjectId")
        label = node.get("-Label")
        node_xpath = node.get("-Xpath", xpath)

        if object_id:
            # 今回は「別表」というLabelを持つノードを対象にする。
            if label and "別表" in label:
                results.append(
                    {
                        "object_id": object_id,
                        "label": label,
                        "xpath": node_xpath,
                    }
                )

        for value in node.values():
            collect_table_nodes(
                value,
                xpath=node_xpath,
                results=results,
            )

    elif isinstance(node, list):
        for child in node:
            collect_table_nodes(
                child,
                xpath=xpath,
                results=results,
            )


def main() -> None:
    print("=" * 70)
    print(f"LAW: {LAW_NAME}")
    print(f"LAW_ID: {LAW_ID}")
    print(f"LAW_DATA_ID: {LAW_DATA_ID}")
    print(f"SUB_REVISION: {SUB_REVISION}")
    print("=" * 70)

    # ------------------------------------------------------------
    # 1. TOC取得
    # ------------------------------------------------------------
    print()
    print("## 1. Fetch TOC")

    toc_json = fetch_law_toc(
        law_data_id=LAW_DATA_ID,
        sub_revision=SUB_REVISION,
    )

    toc_body = toc_json["result"]["Toc_Data"]["TocBody"]

    print("TOC fetched.")

    # ------------------------------------------------------------
    # 2. TOCから「別表」ノードを抽出
    # ------------------------------------------------------------
    print()
    print("## 2. Table nodes in TOC")

    table_nodes: list[dict] = []

    collect_table_nodes(
        toc_body,
        results=table_nodes,
    )

    if not table_nodes:
        print("No table nodes found.")
        return

    for i, table in enumerate(table_nodes, start=1):
        print()
        print(f"[TABLE {i}]")
        print(f"  ObjectId : {table['object_id']}")
        print(f"  Label    : {table['label']}")
        print(f"  Xpath    : {table['xpath']}")

    # ------------------------------------------------------------
    # 3. 現在の toc_parser による sel_text_list
    # ------------------------------------------------------------
    print()
    print("## 3. Current sel_text_list")

    toc_index = parse_toc(toc_body)

    sel_text_list = toc_index.sel_text_list

    print(f"Count: {len(sel_text_list)}")

    for table in table_nodes:
        object_id = table["object_id"].lstrip("#")

        print()
        print(f"[CHECK] {table['label']}")
        print(f"  ObjectId        : {object_id}")
        print(
            "  In sel_text_list:",
            object_id in sel_text_list,
        )

    # ------------------------------------------------------------
    # 4. Compare API
    # ------------------------------------------------------------
    print()
    print("## 4. Fetch Compare API")

    compare_json = fetch_compare(
        new_law_data_id=LAW_DATA_ID,
        new_sub_revision=SUB_REVISION,
    )

    if compare_json is None:
        print("Compare API returned None.")
        return

    print("Compare API fetched.")

    # ------------------------------------------------------------
    # 5. CompareResultへ正規化
    # ------------------------------------------------------------
    print()
    print("## 5. Parse CompareResult")

    compare_result = parse_compare_result(compare_json)

    print(f"Law ID       : {compare_result.law_id}")
    print(f"Old revision : {compare_result.old}")
    print(f"New revision : {compare_result.new}")
    print(f"Block count  : {len(compare_result.blocks)}")

    # ------------------------------------------------------------
    # 6. CompareBlock 全件を表示
    # ------------------------------------------------------------
    print()
    print("## 6. All CompareBlocks")

    for i, block in enumerate(compare_result.blocks, start=1):
        print()
        print(f"[BLOCK {i}]")
        print(f"  object_id   : {block.object_id}")
        print(f"  xpath       : {block.xpath}")
        print(f"  change_type : {block.change_type}")
        print(f"  old_text    : {block.old_text}")
        print(f"  new_text    : {block.new_text}")

    # ------------------------------------------------------------
    # 7. Xpathから別表に属するBlockを確認
    # ------------------------------------------------------------
    print()
    print("## 7. CompareBlocks under AppdxTable")

    for i, block in enumerate(compare_result.blocks, start=1):
        if "/AppdxTable[" not in block.xpath:
            continue

        print()
        print(f"[TABLE BLOCK {i}]")
        print(f"  object_id   : {block.object_id}")
        print(f"  xpath       : {block.xpath}")
        print(f"  change_type : {block.change_type}")
        print(f"  old_text    : {block.old_text}")
        print(f"  new_text    : {block.new_text}")

    # ------------------------------------------------------------
    # 8. 別表ごとにCompareBlockをXpathで分類
    # ------------------------------------------------------------
    print()
    print("## 8. CompareBlocks grouped by table")

    for table in table_nodes:
        table_xpath = table["xpath"]

        blocks = [
            block
            for block in compare_result.blocks
            if block.xpath.startswith(table_xpath)
        ]

        print()
        print(f"[TABLE] {table['label']}")
        print(f"  ObjectId : {table['object_id']}")
        print(f"  Xpath    : {table_xpath}")
        print(f"  Blocks   : {len(blocks)}")

        for block in blocks:
            print(
                f"    - {block.change_type}: "
                f"{block.object_id}"
            )
            print(
                f"      xpath: {block.xpath}"
            )
            print(
                f"      old  : {block.old_text}"
            )
            print(
                f"      new  : {block.new_text}"
            )


if __name__ == "__main__":
    main()