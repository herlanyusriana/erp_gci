# Task 03: Multi-Container Label Printing

**Source:** `docs/prd/wip-label-packaging.md` — Technical Decisions, Section 3

**What to build:** Memperbarui `ProductionLabelController::printLabel` dan template cetak `resources/views/production/labels/wip.blade.php` agar mampu merender sejumlah N lembar label kontainer dalam satu print job. Setiap lembar label memuat nomor kontainer berurutan (`LABEL 1 / N` s/d `LABEL N / N`) di kotak kanan bawah (menggantikan `# LOCATION #`), mencantumkan jenis kemasan, dan baris `TAG #` tetap mengacu ke tag invoice asal.

## Acceptance criteria

- [ ] Route `production-labels.print` menerima parameter: `packing_type`, `packing_qty`, dan `include_remainder`.
- [ ] Backend menghitung daftar lembar label (misal: 352 pcs @ 100 dengan sisa dicetak menghasilkan 4 lembar: 3x 100 EA + 1x 52 EA; jika sisa ditunda menghasilkan 3x 100 EA).
- [ ] Kotak kanan bawah pada template label fisik bertuliskan nomor urut kontainer: `LABEL 1 / 4`, `LABEL 2 / 4`, dst.
- [ ] Tipe kemasan (Troli/Box/Pallet) tercantum jelas di samping kuantitas (misal: `QUANTITY (EA - BOX)`).
- [ ] Baris `TAG # : X` di kolom kiri tetap mengacu ke nomor tag invoice material asal yang dikonsumsi.
- [ ] CSS print menggunakan `.label-card { page-break-after: always; }` sehingga seluruh lembar label tercetak berurutan dalam satu dialog cetak browser.

## Verification

- [ ] Tests pass: `./vendor/bin/pest tests/Feature/ProductionLabelQueueTest.php`
- [ ] Build succeeds: `vendor/bin/pint --dirty --format agent`
- [ ] Output matches: HTML render menghasilkan N container `.label-card` berurutan dengan teks `LABEL 1 / N`
- [ ] No regressions: `./vendor/bin/pest`
- [ ] Manual check: buka URL cetak di browser memuat lembaran multi-label yang rapi

## Blocked by

Task 02 — Modal Dialog Packaging di Web

## Files likely touched

- `app/Http/Controllers/ProductionLabelController.php`
- `resources/views/production/labels/wip.blade.php`
- `tests/Feature/ProductionLabelQueueTest.php`

## Estimated scope

M — 3 files

## Rollback

Revert commit view `wip.blade.php` dan `ProductionLabelController.php`.
