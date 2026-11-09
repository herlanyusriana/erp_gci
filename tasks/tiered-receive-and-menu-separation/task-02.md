# Task 02: Menu Separation: Clean Arrival Show & Dedicated Receive Workspace

**Source:** docs/prd/tiered-receive-and-menu-separation.md — Technical Decisions 2

**What to build:**
Membersihkan halaman Detail Arrival (`resources/js/Pages/Incoming/Arrival/Show.vue`) dari tombol "Terima" dan tabel tag receive agar murni berfokus pada dokumen/data kedatangan. Memperbarui `ReceiveController` dan membuat rute serta halaman workspace khusus Receive (`/receive/arrivals/{arrival}` atau halaman terpadu di modul Receive) sehingga tombol "Terima" di `Incoming/Receive/Index.vue` langsung membuka alur kerja penerimaan barang tanpa melempar operator ke modul Arrival.

## Acceptance criteria

- [ ] Di halaman `Arrival/Show.vue`, tombol aksi `receiveAction` ("Terima") dan bagian tabel tag penerimaan fisik (`receiveTag`) dihilangkan seluruhnya.
- [ ] Di halaman `Receive/Index.vue`, tombol "Terima" mengarahkan operator ke workspace alur penerimaan Receive (bukan lagi ke `incoming-arrivals.show`).
- [ ] Dibuat endpoint/halaman detail penerimaan kedatangan di `ReceiveController` yang menyajikan item kedatangan siap-terima dan riwayat tag yang sudah diterbitkan.
- [ ] Operator dapat memulai penerimaan item secara langsung dari modul Receive.

## Verification

- [ ] Tests pass: `npm run build`
- [ ] Build succeeds: `vendor/bin/pint --format agent`
- [ ] Output matches: Halaman Arrival bersih dari aksi penerimaan barang fisik; modul Receive memiliki alur kerja mandiri.
- [ ] No regressions: `vendor/bin/pest tests/Feature/IncomingPoArrivalTest.php`
- [ ] Manual check: Buka `/incoming-arrivals/{id}` dan pastikan tidak ada tombol Terima; buka `/receive` dan pastikan tombol Terima langsung masuk ke workspace Receive.

## Blocked by

Task 01 — Database Migration & Model: Multi-Tier Packaging Fields

## Files likely touched

- `resources/js/Pages/Incoming/Arrival/Show.vue`
- `resources/js/Pages/Incoming/Receive/Index.vue`
- `resources/js/Pages/Incoming/Receive/Workspace.vue` (atau `Show.vue`)
- `app/Http/Controllers/ReceiveController.php`
- `routes/web.php`

## Estimated scope

M — 5 files

## Rollback

Revert commit perubahan `Arrival/Show.vue`, `Receive/Index.vue`, dan route terkait di `routes/web.php`.

## Notes

Pertahankan otorisasi gate `receive.create` dan `receive.view` pada workspace Receive yang baru.
