# Task 02: Frontend Arrival Form Supplier-PO Reactive Filter & Pipeline Status

**Source:** docs/prd/incoming-po-filter-and-wo-bom-breakdown.md — User Stories 1, 2, 3

**What to build:**
Pembaruan antarmuka `Incoming/Arrival/Form.vue` agar alur pemilihan input dimulai dari Supplier terlebih dahulu, kemudian dropdown PO hanya menampilkan PO aktif milik supplier terpilih yang belum selesai (`remaining_qty > 0`). Serta penegasan label status pipeline kedatangan di `Index.vue` & `Show.vue`: `Arrival` -> `IQC Cek` -> `Received`.

## Acceptance criteria

- [ ] Di form Arrival (baik tab Lokal maupun Impor), field Supplier berada di posisi utama sebelum Purchase Order.
- [ ] Memilih Supplier langsung menyaring dropdown PO: hanya menampilkan PO yang dimiliki oleh supplier tersebut.
- [ ] Jika user mengubah Supplier, field `purchase_order_id` otomatis di-reset jika PO sebelumnya bukan milik supplier baru.
- [ ] Dropdown PO menampilkan info nomor PO beserta status atau indikator sisa kuantitas.
- [ ] Di daftar `Incoming/Arrival/Index.vue` dan `Show.vue`, indikator tahapan pipeline menampilkan badge yang jelas: `Arrival`, `IQC Cek`, dan `Received`.

## Verification

- [ ] Tests pass: `npm run build` (vue-tsc typecheck lolos)
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: dropdown PO reaktif terhadap pilihan Supplier.
- [ ] No regressions: `vendor/bin/pest`
- [ ] Manual check: buka `/incoming-arrivals/create`, ganti supplier dan pastikan opsi PO menyesuaikan secara instan.

## Blocked by

Task 01 — Backend PO Remaining Qty & Supplier Filter

## Files likely touched

- `resources/js/Pages/Incoming/Arrival/Form.vue`
- `resources/js/Pages/Incoming/Arrival/Index.vue`
- `resources/js/Pages/Incoming/Arrival/Show.vue`

## Estimated scope

M — 3 files

## Rollback

Revert perubahan pada `resources/js/Pages/Incoming/Arrival/*.vue`.

## Notes

Gunakan token tema Tailwind yang ada dan gunakan `useI18n()` untuk teks label baru.
