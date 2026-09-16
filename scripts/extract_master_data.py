"""
Extract master data from `master data 20260908.xlsx` into JSON snapshots
consumed by the Laravel seeders (database/seeders/data/*.json).

Produces a single `master_data.json` with the normalized ERP shape:
  part_types, uoms, parts, suppliers, substitutes, machines, processes,
  machine_process.

Deduplication and code generation follow ERP_STARTER_MASTER_DATA_v0.3.md:
  - Part Number is the business key (dedupe by it).
  - FG / Material / WIP / Substitute all become `parts` (part_type discriminates).
  - Supplier / Machine / Process are their own masters, referenced by id in seeders.
"""
import openpyxl
import json
import os
import re

XLSX = r"C:\Users\HYPE AMD\Downloads\master data 20260908.xlsx"
OUT_DIR = r"C:\Users\HYPE AMD\Documents\erp_gci\database\seeders\data"

# A "size" text looks like "0.25 X 640 X 1480" or "1.2 X 91 X C".
# Used to reject substitute rows whose `Subs Part #` is actually a size
# (a source-data-entry error), which would otherwise fabricate a junk part.
SIZE_RE = re.compile(r"^\d+(?:\.\d+)?\s*X\s*\d", re.IGNORECASE)

# WIP intermediates referenced by the BOM but absent from the `master WIP`
# sheet. Derive from BOM `Parent Part No.` / `Child Part No.` so BOM FKs resolve.
# key: part_number -> (part_name, model)
EXTRA_WIP = {
    "AAN75770702-WIP6": ("Assembly Pin", "FREEZER GTF"),
    "AAN75770703-WIP6": ("Assembly Pin", "NEW FREEZER"),
    "AEH76183701-WIP4": ("Plating", "VT 6"),
    "AEH76183702-WIP4": ("Plating", "VT 6 HOLE"),
    "PINHC0101": ("Drilling", "VT 6 HOLE"),
}


def rows(ws, start):
    """Yield non-empty data rows from a worksheet starting at 1-based `start`."""
    for i, row in enumerate(ws.iter_rows(min_row=start, values_only=True), start=start):
        vals = [v for v in row if v is not None and str(v).strip() != ""]
        if not vals:
            continue
        yield row


def s(v):
    if v is None:
        return None
    t = str(v).strip()
    return t if t != "" else None


wb = openpyxl.load_workbook(XLSX, read_only=True, data_only=True)

parts = {}          # part_number -> dict
part_types = [{"code": "FG", "name": "Finished Good"},
              {"code": "MATERIAL", "name": "Material"},
              {"code": "WIP", "name": "WIP"}]
uoms = {}           # code -> True
suppliers = {}      # name -> True
substitutes = []    # raw rows
machines = {}       # name -> True
processes = {}      # name -> True
machine_process = {}  # (machine, process) -> True

FG = wb["master FG"]
for row in rows(FG, 6):
    name, model, pnum, uom, weight = s(row[1]), s(row[2]), s(row[3]), s(row[4]), row[5]
    if not pnum:
        continue
    parts[pnum] = {
        "part_number": pnum,
        "part_name": name or "",
        "part_type": "FG",
        "model": model,
        "uom": uom,
        "size": None,
        "nett_weight": float(weight) if isinstance(weight, (int, float)) else None,
    }
    if uom:
        uoms[uom] = True

MTRL = wb["master mtrl"]
for row in rows(MTRL, 6):
    name, model, pnum, size, uom = s(row[1]), s(row[2]), s(row[3]), s(row[4]), s(row[5])
    if not pnum:
        continue
    parts.setdefault(pnum, {
        "part_number": pnum, "part_name": name or "", "part_type": "MATERIAL",
        "model": model, "uom": uom, "size": size, "nett_weight": None,
    })
    if uom:
        uoms[uom] = True

SUB = wb["master substitute"]
for row in rows(SUB, 6):
    mname, mmodel, mpnum, msize = s(row[1]), s(row[2]), s(row[3]), s(row[4])
    spnum, ssize, suom = s(row[5]), s(row[6]), s(row[7])
    supplier, group, source = s(row[8]), s(row[9]), s(row[10])

    if mpnum:
        # ensure the base material part exists
        parts.setdefault(mpnum, {
            "part_number": mpnum, "part_name": mname or "", "part_type": "MATERIAL",
            "model": mmodel, "uom": None, "size": msize, "nett_weight": None,
        })
    # Skip rows whose substitute "part number" is actually a size (data error).
    if spnum and SIZE_RE.match(spnum):
        spnum = None
    if spnum:
        # substitute part is itself a Part (spec §3/§9)
        parts.setdefault(spnum, {
            "part_number": spnum, "part_name": mname or f"SUB {spnum}", "part_type": "MATERIAL",
            "model": mmodel, "uom": suom, "size": ssize, "nett_weight": None,
        })
        if suom:
            uoms[suom] = True
        if mpnum:
            substitutes.append({
                "part_number": mpnum,
                "substitute_part_number": spnum,
                "supplier_name": supplier,
                "material_group": group,
                "source": source,
            })
    if supplier:
        suppliers[supplier] = True

WIP = wb["master WIP"]
for row in rows(WIP, 6):
    pnum, name, model, uom = s(row[1]), s(row[2]), s(row[3]), s(row[4])
    if not pnum:
        continue
    parts.setdefault(pnum, {
        "part_number": pnum, "part_name": name or "", "part_type": "WIP",
        "model": model, "uom": uom, "size": None, "nett_weight": None,
    })
    if uom:
        uoms[uom] = True

# Backfill WIP intermediates referenced by BOM but missing from `master WIP`.
for pnum, (name, model) in EXTRA_WIP.items():
    parts.setdefault(pnum, {
        "part_number": pnum, "part_name": name, "part_type": "WIP",
        "model": model, "uom": "PCS", "size": None, "nett_weight": None,
    })
    uoms["PCS"] = True

MACH = wb["master machine"]
for row in rows(MACH, 4):
    mname, pname = s(row[2]), s(row[3])
    # "(blank)" is a data-entry placeholder meaning "no specific machine".
    if mname and mname.lower() == "(blank)":
        mname = None
    if mname:
        machines[mname] = True
    if pname:
        processes[pname] = True
    if mname and pname:
        machine_process[(mname, pname)] = True

# Build output in deterministic order.
out = {
    "part_types": part_types,
    "uoms": sorted(uoms.keys()),
    "parts": sorted(parts.values(), key=lambda p: p["part_number"]),
    "suppliers": sorted(suppliers.keys()),
    "substitutes": substitutes,
    "machines": sorted(machines.keys()),
    "processes": sorted(processes.keys()),
    "machine_process": [
        {"machine_name": m, "process_name": p}
        for (m, p) in sorted(machine_process.keys())
    ],
}

os.makedirs(OUT_DIR, exist_ok=True)
path = os.path.join(OUT_DIR, "master_data.json")
with open(path, "w", encoding="utf-8") as f:
    json.dump(out, f, ensure_ascii=False, indent=2)

print("wrote", path)
print("parts:", len(out["parts"]))
print("uoms:", out["uoms"])
print("suppliers:", len(out["suppliers"]))
print("substitutes:", len(out["substitutes"]))
print("machines:", len(out["machines"]))
print("processes:", len(out["processes"]))
print("machine_process:", len(out["machine_process"]))