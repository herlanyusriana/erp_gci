# Task 01: Backend PO Remaining Qty & Supplier Filter

**Source:** docs/prd/incoming-po-filter-and-wo-bom-breakdown.md — Technical Decisions (Modul Incoming)

**What to build:**
Penyempurnaan query data Purchase Order di `IncomingArrivalController` (method `create` dan `edit`) agar menghitung sisa kuantitas PO yang belum tiba (`remaining_qty = po_items.qty - arrival_items.qty_goods`). PO yang dikirim ke form Arrival hanya PO aktif yang masih memiliki sisa kuantitas (`remaining_qty > 0`) dan terikat ke `supplier_id`.

## Acceptance criteria

- [ ] Query PO menghitung kuantitas yang sudah dibuatkan kedatangan (arrival).
- [ ] PO yang seluruh kuantitasnya sudah diterima 100% (`remaining_qty <= 0`) tidak dikirimkan ke pilihan form kedatangan baru.
- [ ] Setiap record PO menyertakan data `supplier_id` dan `remaining_qty` per item / total agar frontend dapat memvalidasi dan memfilter secara akurat.
- [ ] PO berstatus `cancelled` atau `completed` dieksklusikan.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingArrivalPoFilterTest.php`
- [ ] Build succeeds: `vendor/bin/pint --format agent`
- [ ] Output matches: payload `purchaseOrders` pada inertia response `Incoming/Arrival/Form` hanya berisi PO aktif dengan remaining qty > 0.
- [ ] No regressions: `vendor/bin/pest tests/Feature/IncomingArrivalTest.php`
- [ ] Manual check: periksa response Inertia pada `/incoming-arrivals/create`, verifikasi struktur PO.

## Blocked by

None — can start immediately.

## Files likely touched

- `app/Http/Controllers/IncomingArrivalController.php`
- `tests/Feature/IncomingArrivalPoFilterTest.php`

## Estimated scope

S — 2 files

## Rollback

Revert commit perubahan query di `IncomingArrivalController.php` dan hapus file test baru.

## Notes

Pastikan perhitungan sisa kuantitas memperhitungkan baik PO impor maupun lokal tanpa merusak relasi `items.part`.
