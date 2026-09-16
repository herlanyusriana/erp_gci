"""
Verify the seeded DB against Excel.md (the cleaned reference).

Parses the markdown tables in Excel.md and prints per-sheet row counts,
unique keys, and any sample keys that should exist — then we compare with DB.
"""
import re, sys, io

PATH = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
text = open(PATH, encoding="utf-8").read()

# Split into sections by "## "
sections = {}
cur = None
for line in text.splitlines():
    if line.startswith("## "):
        cur = line[3:].strip()
        sections[cur] = []
    elif cur and line.startswith("|") and set(line.strip()) != {"|"}:
        sections[cur].append(line)

def cells(row):
    # strip outer pipes, split, strip
    s = row.strip().strip("|")
    return [c.strip() for c in s.split("|")]

def is_header(cells, title):
    # header row contains the title-ish token
    return any(title.lower() in c.lower() for c in cells if c)

def parse_table(rows, header_tokens):
    """Return list of dict rows (skip header + separator)."""
    data = []
    header = None
    for r in rows:
        c = cells(r)
        if header is None:
            # find header
            if is_header(c, header_tokens):
                header = [h for h in c]
            continue
        if all(re.fullmatch(r"-{3,}", x or "---") for x in c):
            continue  # separator
        # map
        rec = {}
        for i, h in enumerate(header):
            rec[h] = c[i] if i < len(c) else ""
        data.append(rec)
    return data, header

print("== Excel.md section raw row counts ==")
for name, rows in sections.items():
    data_rows = [r for r in rows if not r.startswith("| ---") and "---" not in r]
    print(f"{name}: raw_lines={len(rows)}")

# FG
fg, h1 = parse_table(sections["master FG"], "FG")
print("\nFG header:", h1)
print("FG data rows:", len(fg))
fg_parts = [r for r in fg if r.get("FG Part #")]
print("FG unique part#:", len({r["FG Part #"] for r in fg_parts}))

# material
mtrl, h2 = parse_table(sections["master mtrl"], "Material")
print("\nmtrl header:", h2)
print("mtrl data rows:", len(mtrl))
mtrl_parts = [r for r in mtrl if r.get("Material Part #")]
print("mtrl unique part#:", len({r["Material Part #"] for r in mtrl_parts}))

# substitute
sub, h3 = parse_table(sections["master substitute"], "Subs")
print("\nsubstitute header:", h3)
print("substitute rows:", len(sub))

# WIP
wip, h4 = parse_table(sections["master WIP"], "WIP")
print("\nWIP header:", h4)
print("WIP rows:", len(wip))
wip_parts = [r for r in wip if r.get("WIP Part #")]
print("WIP unique part#:", len({r["WIP Part #"] for r in wip_parts}))

# machine
mach, h5 = parse_table(sections["master machine"], "Machine")
print("\nmachine header:", h5)
print("machine rows:", len(mach))
print("machine unique names:", len({r["Machine Name"] for r in mach if r.get("Machine Name")}))
print("process unique names:", len({r["Process Name"] for r in mach if r.get("Process Name")}))

# BOM
bom, h6 = parse_table(sections["BOM"], "Seq")
print("\nBOM header:", h6)
print("BOM rows:", len(bom))
print("BOM unique FG part no:", len({r["FG Part No."] for r in bom if r.get("FG Part No.")}))
print("BOM unique child part no:", len({r["Child Part No."] for r in bom if r.get("Child Part No.")}))