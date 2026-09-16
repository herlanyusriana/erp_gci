"""
Extract the BOM sheet into database/seeders/data/bom.json.

The BOM sheet is a routing-BOM: one row per step, where:
  - `Parent Part No.` is the assembly/WIP being produced at this step,
  - `Child Part No.` is the component consumed to make it,
  - `Seq`, `Process Name`, `Machine Name`, `spesial`, `Source` describe the routing.

Grouped by `FG Part No.` -> one BOM header per finished good (the `No` column).
"""
import openpyxl
import json
import os
import re

XLSX = r"C:\Users\HYPE AMD\Downloads\master data 20260908.xlsx"
OUT_DIR = r"C:\Users\HYPE AMD\Documents\erp_gci\database\seeders\data"


def s(v):
    if v is None:
        return None
    t = str(v).strip()
    return t if t != "" else None


def num(v):
    if v is None:
        return None
    if isinstance(v, (int, float)):
        return float(v)
    t = str(v).strip().replace(",", ".")
    try:
        return float(t)
    except ValueError:
        return None


PROCESS_NORM = {
    "PRESS": "Press",
}
SOURCE_NORM = {
    "PROD": "Prod", "Prod": "Prod",
    "VENDOR": "Vendor", "Vendor": "Vendor",
    "Subcon": "Subcon",
    "FREE_ISSUE": "FREE_ISSUE",
}


def norm_process(v):
    if not v:
        return None
    return PROCESS_NORM.get(v, v)


def norm_source(v):
    if not v:
        return None
    return SOURCE_NORM.get(v, v)


wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)
ws = wb["BOM"]

boms = {}       # fg_part_no -> header
bom_ids = {}    # fg_part_no -> bom_no (int)
items = []      # raw lines

for i, row in enumerate(ws.iter_rows(values_only=True), start=1):
    # locate header (row 5)
    vals = [v for v in row if v is not None and str(v).strip() != ""]
    if not vals:
        continue
    # skip until data rows: data rows have a numeric/valued child part in idx 12
    fg_no = s(row[5])
    child = s(row[12])
    # skip header row itself
    joined = " ".join(str(v) for v in vals)
    if "Child Part No." in joined:
        continue
    if fg_no is None and child is None:
        continue

    bom_no = row[1]
    seq = row[2]
    fg_name = s(row[3])
    fg_model = s(row[4])
    process_name = s(row[6])
    machine_name = s(row[7])
    parent_no = s(row[8])
    parent_name = s(row[9])
    parent_qty = num(row[10])
    parent_uom = s(row[11])
    child_no = child
    child_name = s(row[13])
    size = s(row[14])
    child_qty = num(row[15])
    uom_rm = s(row[16])
    special = s(row[17])
    source = s(row[18])

    if fg_no:
        boms.setdefault(fg_no, {
            "part_number": fg_no,
            "part_name": fg_name or "",
            "model": fg_model,
            "bom_no": int(bom_no) if isinstance(bom_no, (int, float)) else None,
        })

    if child_no is None and parent_no is None:
        continue

    items.append({
        "fg_part_number": fg_no,
        "sequence": int(seq) if isinstance(seq, (int, float)) else None,
        "process_name": norm_process(process_name),
        "machine_name": machine_name,
        "parent_part_number": parent_no,
        "parent_part_name": parent_name,
        "parent_qty": parent_qty,
        "parent_uom": parent_uom,
        "child_part_number": child_no,
        "child_part_name": child_name,
        "size": size,
        "child_qty": child_qty,
        "uom_rm": uom_rm,
        "special_code": special,
        "source": norm_source(source),
    })

out = {
    "boms": sorted(boms.values(), key=lambda b: (b["bom_no"] or 0, b["part_number"])),
    "items": items,
}

os.makedirs(OUT_DIR, exist_ok=True)
path = os.path.join(OUT_DIR, "bom.json")
with open(path, "w", encoding="utf-8") as f:
    json.dump(out, f, ensure_ascii=False, indent=2)

print("wrote", path)
print("boms (FG):", len(out["boms"]))
print("items:", len(out["items"]))
# quick sanity
from collections import Counter
print("source:", Counter(i["source"] for i in items))
print("special:", Counter(i["special_code"] for i in items))
print("processes:", sorted({i["process_name"] for i in items if i["process_name"]}))