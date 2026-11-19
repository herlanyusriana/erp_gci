# Task 01: Progres loading per part di payload API

**Source:** `tasks/audit-fixes/mobile-audit.md` — C1

**What to build:** Payload loading menyatakan progres scan **per part** secara
eksplisit dan jujur, supaya klien tidak bisa salah membacanya sebagai per baris.

## Latar

`delivery_scans` tidak punya `delivery_item_id`; satu scan terikat ke
`part_stock_id`, jadi qty terpindai selalu milik **part**, bukan baris rencana.
`DeliveryService::loadingContext()` sudah mengirim angka per part pada field
`items[].scanned_qty`, tetapi namanya per baris dan penghitungnya bernama
`scanned_tags`/`total_tags` padahal isinya jumlah **part**. APK lalu mengira
setiap baris punya jatah sendiri.

## Acceptance criteria

- [x] `data.part_progress[]` ditambahkan: `part_id`, `part_number`, `part_name`,
      `planned_qty`, `scanned_qty`, `remaining_qty`, `covered` (bool) — satu
      entri per part, `planned_qty` = jumlah qty seluruh baris part itu.
- [x] `data.covered_parts` dan `data.total_parts` ditambahkan, nilainya sama
      dengan `scanned_tags`/`total_tags` yang lama (alias dipertahankan).
- [x] `items[].scanned_qty` didokumentasikan di PHPDoc sebagai angka **per
      part** (baris dengan part sama menerima nilai identik); nilainya tidak
      berubah sehingga web tetap kompatibel.
- [x] Endpoint daftar `GET /api/deliveries` juga mengirim `covered_parts` dan
      `total_parts` di samping field lama.
- [x] Test: dokumen dengan satu FG pada dua baris (sequence 1 dan 2) menghasilkan
      **satu** entri `part_progress` dengan `planned_qty` = jumlah kedua baris,
      dan kedua item membawa `scanned_qty` yang sama.

## Verification

- [x] Tests pass: `vendor/bin/pest tests/Feature/Api/DeliveryLoadingApiTest.php` — 2 passed / 68 assertions
- [x] No regressions: `vendor/bin/pest tests/Feature/DeliveryTest.php tests/Feature/PickingListTest.php` — 52 passed / 332 assertions
- [x] Lint: `vendor/bin/pint --dirty --format agent` — passed
- [x] Manual check: `GET /api/deliveries/{id}/loading-context` menampilkan
      `part_progress` dengan `remaining_qty` yang turun setelah scan

## Blocked by

None — can start immediately.

## Files likely touched

- `app/Services/DeliveryService.php`
- `app/Http/Controllers/Api/DeliveryApiController.php`
- `tests/Feature/Api/DeliveryLoadingApiTest.php`

## Estimated scope

M — 3 file

## Rollback

Aditif murni: menghapus field baru mengembalikan payload lama tanpa menyentuh
data. Tidak ada migration.

## Notes

- Jangan mengubah `Delivery::allTagsScanned()` / `uncoveredParts()`: keduanya
  sudah benar per part dan menjadi penjaga `moveToPreparation`.
- `total_tags`/`scanned_tags` **tidak** diganti nama: dipakai
  `Outgoing/Delivery/Show.vue`. Penggantian label web dicatat sebagai Open
  Question 3 di `plan.md`.
