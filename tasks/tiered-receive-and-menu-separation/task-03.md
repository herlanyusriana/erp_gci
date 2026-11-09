# Task 03: Frontend Multi-Tier Packaging Form (Bundle x Packing x Qty) & KGM Weight

**Source:** docs/prd/tiered-receive-and-menu-separation.md — Technical Decisions 2, 3

**What to build:**
Merombak antarmuka `Incoming/Receive/Form.vue` agar mendukung penginputan kemasan 3 tingkat:
1. `Jumlah Bundle` (wadah besar, misal Pallet) & `Jenis Bundle`
2. `Isi Packing per Bundle` (jumlah kemasan dalam per wadah besar, misal Box) & `Jenis Packing`
3. `Qty per Packing` (isi unit per box) & `Satuan (UOM)`
4. Kalkulasi otomatis: $\text{Total Qty} = \text{Bundle} \times \text{Packing per Bundle} \times \text{Qty per Packing}$, dengan opsi koreksi manual bila ada sisa ganjil (*odd lot*).
5. Satuan berat bersih (`net_weight`) dan berat kotor (`gross_weight`) terkunci mutlak ke **KGM**.
Memperbarui validasi backend di `ReceiveController@store` dan `update`.

## Acceptance criteria

- [ ] Form Receive menyajikan input berurutan: Bundle Count & Unit $\rightarrow$ Packing Count & Unit $\rightarrow$ Qty per Packing & UOM.
- [ ] Perubahan pada nilai Bundle, Packing, atau Qty per Packing langsung mengalkulasi Total Qty secara instan.
- [ ] Operator tetap bisa menyesuaikan Total Qty secara manual jika ada barang sisa kemasan ganjil.
- [ ] Input berat bersih dan kotor berlabel tetap `KGM` dan backend selalu menyimpan dalam basis `KGM`.
- [ ] Validasi batas kuantitas PO tetap menolak jika total penerimaan melebihi sisa PO.

## Verification

- [ ] Tests pass: `npm run build`
- [ ] Build succeeds: `vendor/bin/pint --format agent`
- [ ] Output matches: Input $2 \times 5 \times 2.000$ menghasilkan Total Qty $20.000$ secara otomatis.
- [ ] No regressions: `vendor/bin/pest tests/Feature/ReceiveStockUnitTest.php`
- [ ] Manual check: Buka form receive, uji kalkulasi otomatis dan pastikan satuan berat terkunci KGM.

## Blocked by

Task 01 — Database Migration & Model: Multi-Tier Packaging Fields
Task 02 — Menu Separation: Clean Arrival Show & Dedicated Receive Workspace

## Files likely touched

- `resources/js/Pages/Incoming/Receive/Form.vue`
- `app/Http/Controllers/ReceiveController.php`
- `resources/js/types/index.d.ts`
- `resources/js/i18n/catalogs/incoming.ts`

## Estimated scope

M — 4 files

## Rollback

Revert commit perubahan `Receive/Form.vue` dan validasi controller.

## Notes

Pastikan jika barang datang tanpa wadah bertingkat, nilai `packing_count` otomatis default `1`.
