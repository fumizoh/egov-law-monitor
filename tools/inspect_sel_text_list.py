from pathlib import Path
import xml.etree.ElementTree as ET


tree = ET.parse("law.xml")
root = tree.getroot()

object_ids: list[str] = []

for element in root.iter():

    object_id = element.attrib.get("ObjectId")

    if object_id:
        object_ids.append(object_id)

print(root.tag)

count = 0

for element in root.iter():
    print(element.tag, element.attrib)
    count += 1
    if count == 20:
        break

print(ET.tostring(root, encoding="unicode")[:1000])