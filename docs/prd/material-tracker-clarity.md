# Capability Map: Material Tracker Clarity

Inisiatif perbaikan APK **Material Tracker** (`erp_gci_mobile`) dan API
pendukungnya di ERP (`erp_gci`).

Revisi 2026-09-25: tampilan daftar berubah dari **berbasis WO** menjadi
**berbasis material** (permintaan Pak Arnold), dan alur issue bertambah langkah
**scan lokasi rak**.

## Modules

| Module id | Responsibility | Depends on |
|---|---|---|
| `daily-planned-wo-feed` | APK hanya menerima WO yang punya jadwal pada tanggal berjalan, dan hanya menampilkan qty hari itu | — |
| `material-scan-context` | Material berstok, ukuran, invoice, supplier, dan tag yang jelas | — |
| `material-daily-board` | Daftar **per material** untuk tanggal D dan D+1, beserta qty kebutuhan dan daftar WO di baliknya | `daily-planned-wo-feed`, `material-scan-context` |
| `location-scan` | Master lokasi rak, QR lokasi, pencatatan lokasi saat penerimaan dan saat issue | — |
| `tracker-dashboard` | Layar issue: empat angka, progres per material, tag terlipat, langkah scan lokasi | `material-scan-context`, `location-scan` |

## Dependency Direction

```
daily-planned-wo-feed ──┐
                        ├──> material-daily-board ──┐
material-scan-context ──┘                          │
                                                   ├──> tracker-dashboard
location-scan ─────────────────────────────────────┘
```

Tidak ada siklus. `material-daily-board` dan `location-scan` tidak saling
bergantung, sehingga dapat dikerjakan paralel.

## Build Order

1. `daily-planned-wo-feed` — **selesai**
2. `material-scan-context` — **selesai**
3. `material-daily-board`
4. `location-scan`
5. `tracker-dashboard` (revisi)

## Schedule Rule (disepakati)

Tanggal berjalan membaca Production Plan sesuai jarak dari `plan_date`:

| Tanggal | Kolom Production Plan |
|---|---|
| tanggal `plan_date` | `target_d` |
| `plan_date` + 1 hari | `target_d1` |
| `plan_date` + 2 hari | `target_d2` |
| di luar rentang itu | tidak tampil |

Target kosong atau `0` tidak ditampilkan.

## Perubahan yang membatalkan keputusan lama

| Keputusan lama | Status baru | Alasan |
|---|---|---|
| "Kolom D+1 dan D+2 di APK" masuk Out of Scope | **Dibatalkan** — D dan D+1 sekarang wajib | Permintaan Pak Arnold |
| "Main part BOM tidak pernah ditampilkan sebagai material" | **Dilunakkan** — main part BOM menjadi **baris kebutuhan**; yang tetap dilarang adalah menampilkannya sebagai **material yang discan** | Tampilan baru berbasis material acuan BOM |
| Daftar berbasis WO (`tracker-dashboard`) | **Digantikan** `material-daily-board` | Permintaan Pak Arnold |

## Module PRDs

- `docs/prd/daily-planned-wo-feed.md` (selesai)
- `docs/prd/material-scan-context.md` (selesai, aturan main part dilunakkan)
- `docs/prd/material-daily-board.md`
- `docs/prd/location-scan.md`
- `docs/prd/tracker-dashboard.md` (revisi)
