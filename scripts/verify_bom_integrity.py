import re, os
MD = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
TMP = os.environ.get("TEMP", r"C:\Users\HYPE AMD\Documents\erp_gci")

text = open(MD, encoding="utf-8").read()
# Extract BOM section lines only (between "## BOM" and next "## " or EOF)
lines = text.splitlines()
start = None
bom_lines = []
for i, l in enumerate(lines):
    if l.startswith("## BOM"):
        start = i
    elif start is not None and l.startswith("## "):
        break
    elif start is not None and l.startswith("|"):
        bom_lines.append(l)

def cells(r): return [c.strip() for c in r.strip().strip("|").split("|")]

# True columns (from xlsx): 19 cells, index0 blank
# [1] No [2] Seq [3] FG Name [4] FG Model [5] FG Part No [6] Process Name [7] Machine Name
# [8] Parent Part No [9] Parent Part Name [10] Parent Qty [11] Parent UOM
# [12] Child Part No [13] Child Part Name|Model [14] Size [15] Child Qty [16] UOM_RM [17] spesial [18] Source
COL = ["No","Seq","FG Name","FG Model","FG Part No.","Process Name","Machine Name",
       "Parent Part No.","Parent Part Name","Parent Qty","Parent UOM",
       "Child Part No.","Child Part Name|Model","Size","Child Qty","UOM_RM","spesial","Source"]

rows = []
for r in bom_lines:
    c = cells(r)
    if len(c) < 12: continue
    # skip pure separator
    if all(re.fullmatch(r"-{3,}", x or "---") for x in c): continue
    # skip header
    joined = " ".join(c)
    if "Child Part No." in joined: continue
    rows.append(c)

print("BOM data rows:", len(rows))

# read DB parts master
parts = {}
for line in open(os.path.join(TMP, "db_parts.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    pnum, code, pname, size, nw = line.split("\t")
    parts[pnum.strip()] = code.strip()

def col(r, name): return r[COL.index(name)] if len(r) > COL.index(name) else ""

missing_child = set()
missing_parent = set()
fg_set = set()
machine_names = set()
process_names = set()
source_set = set()
special_set = set()
uom_set = set()
parent_uom_set = set()

for r in rows:
    fg = col(r, "FG Part No.")
    parent = col(r, "Parent Part No.")
    child = col(r, "Child Part No.")
    mach = col(r, "Machine Name")
    proc = col(r, "Process Name")
    src = col(r, "Source")
    spc = col(r, "spesial")
    uom = col(r, "UOM_RM")
    puom = col(r, "Parent UOM")

    if fg: fg_set.add(fg)
    if child and child not in parts: missing_child.add(child)
    if parent and parent not in parts: missing_parent.add(parent)
    if mach: machine_names.add(mach)
    if proc: process_names.add(proc)
    if src: source_set.add(src)
    if spc: special_set.add(spc)
    if uom: uom_set.add(uom)
    if puom: parent_uom_set.add(puom)

print("\nFG in BOM:", len(fg_set))
print("\nChild part# MISSING from master:", sorted(missing_child))
print("Parent part# MISSING from master:", sorted(missing_parent))

# machine/process coverage vs master
mach_master = {'TPL COMP BASE','KUKIL 110 TON'}  # placeholder; recompute from db
# read machines/processes master
mach_known = set()
for line in open(os.path.join(TMP, "db_mp.tsv"), encoding="utf-8-sig"):
    line=line.rstrip("\n")
    if not line.strip(): continue
    m,p = line.split("\t"); mach_known.add(m.strip())

print("\nBOM machine names not in machines master:", sorted(machine_names - mach_known))
print("BOM process names:", sorted(process_names))
print("BOM Source values:", sorted(source_set))
print("BOM spesial values:", sorted(special_set))
print("BOM UOM_RM values:", sorted(uom_set))
print("BOM Parent UOM values:", sorted(parent_uom_set))