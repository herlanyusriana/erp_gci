# Task 04: Dynamic Material Display: Main BOM Part -> Substitute on Issue

**Source:** docs/prd/incoming-po-filter-and-wo-bom-breakdown.md — Technical Decisions (Modul Produksi)

**What to build:**
Implementasi logika penentuan tampilan part material di tabel routing Work Order:
- **Sebelum Issue (status `planned` / belum scan):** Kolom material menampilkan acuan standar **Main Part BOM** (`child_part`), dengan penanda `Main BOM`.
- **Setelah Issue (status `in_progress` / sudah ada alokasi issue & scan):** Kolom material beralih menampilkan **Part Substitute** (`selected_part` / part dari `allocations` / `material_issues`), lengkap dengan badge penanda bahwa part tersebut merupakan substitusi part BOM.

## Acceptance criteria

- [ ] Sebelum material di-issue, baris material menampilkan Part Number dan Part Name dari Main Part BOM (`child_part`).
- [ ] Setelah material di-issue (terbit `material_issues` atau terisi `selected_part_id`), tampilan material berganti menampilkan Part Substitute aktual yang dipakai.
- [ ] Ditampilkan label/badge pembeda yang jelas antara part standar BOM dan part substitusi.
- [ ] Informasi alokasi tag/stok yang di-issue tetap dapat dilihat secara detail.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/WorkOrderAllocationTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: WO yang belum di-issue menampilkan Main BOM, WO setelah issue menampilkan Substitute Part.
- [ ] No regressions: `vendor/bin/pest`
- [ ] Manual check: buat WO baru, cek sebelum issue (tampil Main Part), lakukan issue/release, cek sesudah issue (tampil Substitute).

## Blocked by

Task 03 — Work Order Process Breakdown: Multi-Input Assembly & TPL Continuous Line

## Files likely touched

- `resources/js/Pages/Production/WorkOrder/Show.vue`
- `app/Http/Controllers/WorkOrderController.php`

## Estimated scope

S — 2 files

## Rollback

Revert commit perubahan logika display part di `WorkOrder/Show.vue`.

## Notes

Pastikan jika part yang di-issue sama dengan Main Part (bukan substitusi), badge tetap informatif dan tidak membingungkan operator.
