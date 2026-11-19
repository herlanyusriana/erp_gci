# Task 02: Modal Dialog Packaging di Web

**Source:** `docs/prd/wip-label-packaging.md` — Technical Decisions, Section 1

**What to build:** Membangun modal dialog di halaman antrean label WIP (`resources/js/Pages/Production/Label/Index.vue`) yang muncul saat tombol `Print Good` ditekan. Modal memungkinkan user memilih tipe kemasan (`Troli`, `Box`, `Pallet`), menginput kapasitas kemasan (`Packing Qty`), melihat kalkulasi wadah penuh + sisa, serta memilih apakah sisa ganjil dicetak sekarang atau ditunda (carry-over).

## Acceptance criteria

- [x] Tombol `Print Good` pada baris antrean label membuka modal dialog kemasan, bukan langsung membuka tab cetak.
- [x] Dropdown kemasan menyediakan pilihan: `Troli`, `Box`, dan `Pallet`.
- [x] Input kapasitas kemasan (`Packing Qty`) menerima angka integer positif.
- [x] Modal menampilkan kalkulasi instan: `X kemasan penuh (@ Y EA)` dan `Sisa ganjil: Z EA`.
- [x] Terdapat checkbox opsi sisa ganjil: `Cetak label untuk sisa Z EA sekarang` vs `Tunda sisa Z EA ke periode berikutnya`.
- [x] Menampilkan notifikasi ringkasan: `Total X label akan dicetak`.
- [x] Konfirmasi cetak membuka tab baru dengan parameter kemasan yang dipilih dan mengirim update carry-over jika sisa ditunda.

## Verification

- [x] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [x] Build succeeds: `npm run build`
- [x] Output matches: komponen modal terpasang dan interaktif di `Index.vue`
- [x] No regressions: `./vendor/bin/pest`
- [ ] Manual check: klik Print Good di web memunculkan dialog kalkulasi kemasan

## Blocked by

Task 01 — Database & Model Carry-Over

## Files likely touched

- `resources/js/Pages/Production/Label/Index.vue`
- `resources/js/i18n/catalogs/production.ts`
- `app/Http/Controllers/ProductionLabelController.php`

## Estimated scope

M — 3 files

## Rollback

Revert commit perubahan `Index.vue` dan `ProductionLabelController.php`.
