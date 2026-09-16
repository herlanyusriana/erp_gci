import re
MD = r"C:\Users\HYPE AMD\Documents\erp_gci\Excel.md"
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

sub = parse(sections["master substitute"], "Subs")

size_like = re.compile(r"^\d+(\.\d+)?\s*X\s*\d", re.I)
dirty = []
legit = []
for r in sub:
    sp = r.get("Subs Part #", "")
    if not sp:
        continue
    if size_like.match(sp):
        dirty.append((r["Material Part #"], r["Material Name"], sp, r["Supplier"], r["Source"]))
    else:
        legit.append(sp)

print("total substitute rows with Subs Part#:", len(dirty)+len(legit))
print("DIRTY (Subs Part# looks like a SIZE):", len(dirty))
for d in dirty:
    print("   ", d)
print("\nLEGIT unique substitute part numbers:", len(set(legit)))
# how many legit subs part# overlap with master mtrl part# (i.e. already material)
mtrl = parse(sections["master mtrl"], "Material")
mtrl_nums = {r["Material Part #"] for r in mtrl if r.get("Material Part #")}
overlap = set(legit) & mtrl_nums
print("legit subs part# that are ALSO in master mtrl:", len(overlap))
print("legit subs part# NOT in master mtrl (net new materials):", len(set(legit) - mtrl_nums))
# sample net-new substitute part numbers
print("sample net-new:", sorted(set(legit) - mtrl_nums)[:30])