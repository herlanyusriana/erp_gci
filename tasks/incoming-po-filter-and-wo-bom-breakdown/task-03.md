# Task 03: Work Order Process Breakdown: Multi-Input Assembly & TPL Continuous Line

**Source:** docs/prd/incoming-po-filter-and-wo-bom-breakdown.md — Technical Decisions (Modul Produksi)

**What to build:**
Merombak rendering tabel routing di `resources/js/Pages/Production/WorkOrder/Show.vue`. Mengubah pengelompokan lama `head(g)` yang memotong material menjadi pengelompokan berbasis proses:
- Untuk **Mesin Non-TPL (Assembly, Welding, Stamping tunggal)**: Setiap proses menjabarkan **seluruh item input** yang masuk ke proses tersebut (baik child WIP maupun raw material). Jika proses Assembly butuh 4 material, keempatnya dirender secara lengkap dengan part number, nama part, qty required, dan qty consumed.
- Untuk **Mesin Khusus TPL (`TPL DONGSHIN` & `TPL KUKIL`)**: Tetap mempertahankan perlakuan continuous tandem line (1 baris mewakili stasiun awal ke output akhir, tidak memecah WIP intermediate).

## Acceptance criteria

- [ ] Fungsi helper deteksi TPL mendeteksi secara presisi mesin TPL (`MachineRouting::isTplPressLine` atau nama berawalan `TPL DONGSHIN` / `TPL KUKIL`).
- [ ] Mesin TPL tetap dirangkum sebagai 1 baris continuous tandem line (input awal -> output WIP akhir).
- [ ] Mesin Non-TPL (khususnya Assembly / Welding dengan multi-input) menampilkan seluruh item input dalam proses tersebut, tidak ada komponen yang tersembunyi.
- [ ] Kolom kuantitas kebutuhan (`qty_required`) dan konsumsi (`qty_consumed`) tertera akurat untuk setiap item material input.

## Verification

- [ ] Tests pass: `npm run build` & `vendor/bin/pest tests/Feature/WorkOrderHttpTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: Detail WO dengan proses Assembly menampilkan seluruh komponen inputnya secara transparan.
- [ ] No regressions: `vendor/bin/pest`
- [ ] Manual check: buka halaman `/work-orders/{id}` yang memiliki proses assembly multi-part, verifikasi seluruh material tampil.

## Blocked by

None — can start immediately (safe to parallelize with Task 01).

## Files likely touched

- `resources/js/Pages/Production/WorkOrder/Show.vue`
- `app/Support/MachineRouting.php`

## Estimated scope

M — 2 files

## Rollback

Revert commit perubahan visualisasi di `resources/js/Pages/Production/WorkOrder/Show.vue`.

## Notes

Desain tabel harus tetap rapi dengan visual hierarchy yang jelas (misal indentasi atau sub-baris berlatar halus untuk multi-material input di bawah nama proses).
