import re, os
MD = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
TMP = os.environ.get("TEMP", r"C:\Users\HYPE AMD\Documents\erp_gci")

text = open(MD, encoding="utf-8").read()
sections = {}
cur = None
for line in text.splitlines():
    if line.startswith("## "):
        cur = line[3:].strip(); sections[cur] = []
    elif cur and line.startswith("|"):
        sections[cur].append(line)

def cells(r): return [c.strip() for c in r.strip().strip("|").split("|")]
def parse(rows, tok):
    data, header = [], None
    for r in rows:
        c = cells(r)
        if header is None:
            if any(tok.lower() in x.lower() for x in c if x):
                header = c
            continue
        if all(re.fullmatch(r"-{3,}", x or "---") for x in c):
            continue
        data.append({h: (c[i] if i < len(c) else "") for i, h in enumerate(header)})
    return data

bom = parse(sections["BOM"], "Seq")
# header had a weird "Child Part Name \\ Model" split; recompute clean header directly
# Print raw header cells for the BOM by finding header line
for r in sections["BOM"]:
    c = cells(r)
    if "Child Part No." in [x for x in c]:
        print("BOM raw header cells (", len(c), "):")
        for i, h in enumerate(c):
            print(f"  [{i}] {h!r}")
        break

# Count BOM rows and FG
print("\nBOM rows:", len(bom))
fg_in_bom = {r["FG Part No."] for r in bom if r.get("FG Part No.")}
print("FG Part No in BOM (unique):", len(fg_in_bom))

# childs
childs = [r.get("Child Part No.","") for r in bom]
childs = [c for c in childs if c]
print("child rows:", len(childs), "unique:", len(set(childs)))

# read DB parts
parts = {}
for line in open(os.path.join(TMP, "db_parts.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    pnum, code, pname, size, nw = line.split("\t")
    parts[pnum.strip()] = code.strip()

child_set = set(childs)
missing = sorted(c for c in child_set if c not in parts)
print("\nChild part# NOT in DB parts master:", len(missing))
for m in missing:
    print("   ", m)

# FG in BOM but not in FG master
fg_master = {p for p,c in parts.items() if c == "FG"}
print("\nFG in BOM but NOT in FG master parts:", sorted(fg_in_bom - fg_master))
print("FG master but NOT in BOM:", len(fg_master - fg_in_bom), "of", len(fg_master))

# parent part no coverage (WIP intermediates)
parents = {r["Parent Part No."] for r in bom if r.get("Parent Part No.")}
pmissing = sorted(p for p in parents if p not in parts)
print("\nParent Part No not in DB parts:", len(pmissing))
for p in pmissing: print("   ", p)

# source / special distribution
from collections import Counter
print("\nSource distribution:", Counter(r.get("Source","") for r in bom))
print("UOM_RM distribution:", Counter(r.get("UOM_RM","") for r in bom))
print("spesial distribution:", Counter(r.get("spesial","") for r in bom))
print("Process distribution:", Counter(r.get("Process Name","") for r in bom))