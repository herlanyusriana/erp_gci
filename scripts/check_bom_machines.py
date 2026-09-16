import re, os
MD = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
TMP = os.environ.get("TEMP", r"C:\Users\HYPE AMD\Documents\erp_gci")

text = open(MD, encoding="utf-8").read()
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
COL = ["No","Seq","FG Name","FG Model","FG Part No.","Process Name","Machine Name",
       "Parent Part No.","Parent Part Name","Parent Qty","Parent UOM",
       "Child Part No.","Child Part Name|Model","Size","Child Qty","UOM_RM","spesial","Source"]

rows = []
for r in bom_lines:
    c = cells(r)
    if len(c) < 12: continue
    if all(re.fullmatch(r"-{3,}", x or "---") for x in c): continue
    if "Child Part No." in " ".join(c): continue
    rows.append(c)

def col(r, name): return r[COL.index(name)] if len(r) > COL.index(name) else ""

bom_machines = {}
for r in rows:
    m = col(r, "Machine Name")
    p = col(r, "Process Name")
    if m:
        bom_machines.setdefault(m, set()).add(p)

# machines master
machines_master = set()
for line in open(os.path.join(TMP, "db_mp.tsv"), encoding="utf-8-sig"):
    line = line.rstrip("\n")
    if not line.strip(): continue
    m,p = line.split("\t"); machines_master.add(m.strip())

print(f"BOM distinct machine names: {len(bom_machines)}")
print(f"machines master (from machine_process): {len(machines_master)}")
print("\nBOM machine names NOT in machines master:")
for m in sorted(set(bom_machines) - machines_master):
    print(f"   {m!r}  (processes: {sorted(bom_machines[m])})")

# Also show press-machine process names consistency
print("\nAll BOM machine -> process:")
for m in sorted(bom_machines):
    print(f"  {m}: {sorted(bom_machines[m])}")