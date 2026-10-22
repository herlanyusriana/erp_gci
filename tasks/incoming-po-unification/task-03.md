# Task 03: Daftar PO gabungan dengan filter source

**Source:** docs/prd/incoming-po-unification.md — User Story 8, Success Criteria

**What to build:** Pengguna melihat PO import dan Local PO dalam satu daftar,
masing-masing dengan source yang jelas, dan dapat memfilter berdasarkan source.

## Acceptance criteria

- [ ] Satu halaman daftar menampilkan record PO import serta Local PO, lama dan
      baru.
- [ ] Setiap baris menampilkan source `local` atau `import` secara eksplisit,
      bukan hanya lewat warna.
- [ ] Filter source menyaring daftar; tanpa filter, kedua jenis tampil.
- [ ] Pencarian tetap berfungsi dan mencakup kedua jenis record.
- [ ] Tautan tindak lanjut mengarah ke halaman detail yang sesuai dengan source
      baris tersebut.
- [ ] Paginasi mempertahankan filter source pada query string.
- [ ] Teks baru tersedia di katalog i18n ID/EN/KO dengan jumlah kunci seimbang.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/IncomingPoUnificationTest.php`
- [ ] Build succeeds: `npm run build`
- [ ] Output matches: daftar memuat kedua source; filter `local` hanya
      mengembalikan record local
- [ ] No regressions: `vendor/bin/pest tests/Feature/LocalPoTest.php`
- [ ] Manual check: buka daftar tanpa filter, lalu filter ke `import` dan `local`

## Blocked by

Task 01 — kategori source supplier harus ada agar source tiap record dapat
ditentukan dan ditampilkan.

## Files likely touched

- `app/Http/Controllers/PurchaseOrderController.php`
- `resources/js/Pages/Incoming/PurchaseOrder/Index.vue`
- `resources/js/i18n/catalogs/incoming.ts`
- `tests/Feature/IncomingPoUnificationTest.php`

## Estimated scope

S — 4 files

## Rollback

Revert commit. Halaman daftar kembali menampilkan hanya PO import; halaman Local
PO tetap ada dan tidak terpengaruh.

## Notes

Source tiap record ditentukan oleh kategori supplier-nya, konsisten dengan
keputusan bahwa source berasal dari supplier. Jangan menyimpulkan source dari
keberadaan Arrival, karena Local PO juga punya record kedatangan.
