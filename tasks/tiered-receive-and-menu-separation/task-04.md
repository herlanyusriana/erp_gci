# Task 04: QR Tag Label Packaging String & Automated Regression Tests

**Source:** docs/prd/tiered-receive-and-menu-separation.md — Technical Decisions 3, Testing Strategy

**What to build:**
Memperbarui template label cetak QR penerimaan barang (`resources/views/receives/label.blade.php`) agar memuat rincian teks kemasan bertingkat (contoh: `2 PALLET @ 5 BOX @ 2.000 EA`). Menulis tes fitur otomatis (`tests/Feature/TieredReceiveTest.php`) untuk memverifikasi kalkulasi bertingkat, kepatuhan satuan berat KGM, ketiadaan tombol receive di Arrival Show, dan pembatasan PO.

## Acceptance criteria

- [ ] Template label QR receive menampilkan baris spesifikasi kemasan bertingkat jika data bundle & packing tersedia.
- [ ] Dibuat file test `tests/Feature/TieredReceiveTest.php` yang menguji:
  - Sukses menyimpan data receive dengan atribut kemasan bertingkat.
  - Penyimpanan nilai berat bersih dan kotor dalam basis KGM.
  - Halaman `incoming-arrivals.show` tidak merender tombol `receive.create`.
  - Halaman Receive mandiri dapat menyelesaikan proses penerimaan stok.
  - Skenario batas kuantitas PO tetap menolak jika melebihi batas.
- [ ] Seluruh suite test Pest lulus hijau.

## Verification

- [ ] Tests pass: `vendor/bin/pest tests/Feature/TieredReceiveTest.php`
- [ ] Build succeeds: `vendor/bin/pest` (seluruh suite lulus)
- [ ] Output matches: Preview cetak label QR memuat string breakdown kemasan yang benar.
- [ ] No regressions: `npm run build` & `vendor/bin/pint --format agent`
- [ ] Manual check: Cetak label dari salah satu receive bertingkat dan periksa teksnya.

## Blocked by

Task 03 — Frontend Multi-Tier Packaging Form (Bundle x Packing x Qty) & KGM Weight

## Files likely touched

- `resources/views/receives/label.blade.php`
- `tests/Feature/TieredReceiveTest.php`

## Estimated scope

S — 2 files

## Rollback

Revert commit perubahan `label.blade.php` dan hapus file `TieredReceiveTest.php`.

## Notes

Desain label QR harus tetap muat rapi pada ukuran kertas label printer thermal tanpa teks terpotong.
