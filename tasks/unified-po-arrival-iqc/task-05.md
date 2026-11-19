# Task 05: Integrasi IQC Pass ke Receive Gudang & Verifikasi End-to-End

**Source:** `docs/prd/unified-po-arrival-iqc.md` — Technical Decisions, Section 3

**What to build:** Mengikat alur pembuatan TAG barcode material di `ReceiveController` agar hanya mengizinkan kedatangan yang sudah berstatus `iqc_status === 'passed'`, menampilkan indikator status IQC di tabel antrean Receive, dan menjalankan full regression test suite, linting Pint, dan build frontend.

## Acceptance criteria

- [ ] `ReceiveController::create` dan `store` menolak / memblokir jika kedatangan terkait belum berstatus `passed` pada `iqc_status`.
- [x] Daftar kedatangan di `Receive/Index.vue` menampilkan badge status IQC (`IQC Pass` vs `Menunggu IQC`).
- [ ] Membuat test komprehensif di `tests/Feature/IncomingPoArrivalTest.php` yang mencakup alur end-to-end:
  Buat PO Lokal -> Catat Arrival Lokal (No PEN, AJU, Surat Jalan) -> Ceklis IQC Pass di Incoming Quality -> Gudang berhasil Receive & buat TAG barcode.
- [x] `vendor/bin/pint --dirty --format agent` lulus tanpa error formatting.
- [x] `npm run build` lulus typecheck dan bundling tanpa error.
- [x] Full test suite `./vendor/bin/pest` lulus hijau 100%.

## Verification

- [x] Tests pass: `./vendor/bin/pest tests/Feature/IncomingPoArrivalTest.php`
- [x] Build succeeds: `npm run build`
- [x] No regressions: `./vendor/bin/pest`

## Blocked by

Task 03, Task 04

## Files likely touched

- `app/Http/Controllers/ReceiveController.php`
- `resources/js/Pages/Incoming/Receive/Index.vue`
- `tests/Feature/IncomingPoArrivalTest.php`

## Estimated scope

M — 3 files

## Rollback

Revert validasi status IQC di `ReceiveController`.
