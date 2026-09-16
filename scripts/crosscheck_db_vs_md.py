"""
Cross-check seeded DB against Excel.md (the cleaned reference).

Reads TSVs dumped from Postgres and compares counts + keys with
the parsed Excel.md tables.
"""
import re, json, os

MD = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
TMP = os.environ.get("TEMP", r"C:\Users\HYPE AMD\Documents\erp_gci")

def parse_md_tables():
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
            rec = {h: (c[i] if i < len(c) else "") for i, h in enumerate(header)}
            data.append(rec)
        return data
    return sections, parse

sections, parse = parse_md_tables()

# FG
fg = parse(sections["master FG"], "FG")
fg_nums = {r["FG Part #"] for r in fg if r.get("FG Part #")}

mtrl = parse(sections["master mtrl"], "Material")
mtrl_nums = {r["Material Part #"] for r in mtrl if r.get("Material Part #")}

wip = parse(sections["master WIP"], "WIP")
wip_nums = {r["WIP Part #"] for r in wip if r.get("WIP Part #")}

sub = parse(sections["master substitute"], "Subs")
mach = parse(sections["master machine"], "Machine")
bom = parse(sections["BOM"], "Seq")

# Read DB dumps
parts = {}
for line in open(os.path.join(TMP, "db_parts.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    pnum, code, pname, size, nw = line.split("\t")
    parts[pnum] = (code, pname.strip(), size.strip(), nw.strip())

db_fg  = {p for p,(c,_,_,_) in parts.items() if c == "FG"}
db_mtrl = {p for p,(c,_,_,_) in parts.items() if c == "MATERIAL"}
db_wip = {p for p,(c,_,_,_) in parts.items() if c == "WIP"}

print("=== PARTS (part_type) ===")
print(f"FG      : Excel {len(fg_nums)}  DB {len(db_fg)}")
print(f"MATERIAL: Excel {len(mtrl_nums)}  DB {len(db_mtrl)}")
print(f"WIP     : Excel {len(wip_nums)}  DB {len(db_wip)}")
print(f"TOTAL   : Excel {len(fg_nums)+len(mtrl_nums)+len(wip_nums)}  DB {len(parts)}")

print("\nFG in Excel but NOT in DB:", sorted(fg_nums - db_fg)[:20])
print("FG in DB but NOT in Excel:", sorted(db_fg - fg_nums)[:20])
print("\nMaterial in Excel but NOT in DB:", sorted(mtrl_nums - db_mtrl)[:20])
print("Material in DB but NOT in Excel:", sorted(db_mtrl - mtrl_nums)[:20])
print("\nWIP in Excel but NOT in DB:", sorted(wip_nums - db_wip)[:20])
print("WIP in DB but NOT in Excel:", sorted(db_wip - wip_nums)[:20])

# machine_process
mp_db = set()
for line in open(os.path.join(TMP, "db_mp.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    m, p = line.split("\t")
    mp_db.add((m.strip(), p.strip()))

mp_excel = {(r["Machine Name"], r["Process Name"]) for r in mach if r.get("Machine Name") and r.get("Process Name")}
print("\n=== MACHINE-PROCESS ===")
print(f"Excel {len(mp_excel)}  DB {len(mp_db)}")
print("in Excel not DB:", sorted(mp_excel - mp_db)[:20])
print("in DB not Excel:", sorted(mp_db - mp_excel)[:20])

# suppliers
sup_db = set()
for line in open(os.path.join(TMP, "db_suppliers.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    sup_db.add(line.strip())
sup_excel = {r["Supplier"] for r in sub if r.get("Supplier")}
print("\n=== SUPPLIERS ===")
print(f"Excel {len(sup_excel)}  DB {len(sup_db)}")
print("in Excel not DB:", sorted(sup_excel - sup_db)[:20])
print("in DB not Excel:", sorted(sup_db - sup_excel)[:20])

# substitutes
sub_db = set()
for line in open(os.path.join(TMP, "db_sub.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    a, b, c = line.split("\t")
    sub_db.add((a.strip(), b.strip(), c.strip()))
sub_excel = {(r["Material Part #"], r["Subs Part #"], r["Supplier"]) for r in sub if r.get("Material Part #") and r.get("Subs Part #")}
print("\n=== SUBSTITUTES ===")
print(f"Excel {len(sub_excel)}  DB {len(sub_db)}")
print("in Excel not DB:", sorted(sub_excel - sub_db)[:20])
print("in DB not Excel:", sorted(sub_db - sub_excel)[:20])